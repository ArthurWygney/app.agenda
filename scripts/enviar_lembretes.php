<?php

/**
 * ManiAgenda — envio automático de notificações (RF04, documentação seção 9).
 *
 * O que este script faz a cada execução:
 *   1. Cria as notificações devidas: confirmação (logo após agendar/remarcar),
 *      lembrete 24h, lembrete 2h e aviso à manicure 2h antes;
 *   2. Envia por SMS tudo que está pendente na fila, incluindo as retentativas
 *      de envios que falharam antes.
 *
 * Deve rodar sozinho, a cada poucos minutos (recomendado: a cada 5).
 *
 * Linux (crontab -e):
 *   * /5 * * * * php /caminho/app.agenda/scripts/enviar_lembretes.php >> /caminho/app.agenda/scripts/logs/cron.out 2>&1
 *   (escreva "*" + "/5" sem o espaço entre eles)
 *
 * Windows/XAMPP: Agendador de Tarefas → ação "Iniciar um programa":
 *   C:\xampp\php\php.exe  com argumento  C:\xampp\htdocs\app.agenda\scripts\enviar_lembretes.php
 *   repetindo a cada 5 minutos.
 *
 * Teste sem enviar nem gravar nada:
 *   php scripts/enviar_lembretes.php --dry-run
 *
 * Com 'provedor' => 'simulado' (config/integracoes.php) nada é enviado de verdade;
 * o histórico fica na tela Admin > Notificações e em scripts/logs/lembretes.log.
 *
 * Segurança contra envio duplicado: a criação reivindica a coluna de controle do
 * agendamento com UPDATE condicional e o envio reivindica a linha da fila
 * (pendente → enviando); duas execuções sobrepostas não mandam a mesma mensagem.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado: este script só roda pela linha de comando.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/notificacoes.php';

$config = notificacoesConfig();
$dryRun = in_array('--dry-run', $argv ?? [], true);

// Impede duas execuções simultâneas (ex.: cron anterior ainda rodando)
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {

    $travaObtida = (int) $pdo->query("SELECT GET_LOCK('maniagenda_lembretes', 0)")->fetchColumn();

    if ($travaObtida !== 1) {
        notificacoesLog('INFO', 'Outra execução ainda está em andamento. Saindo.');
        exit(0);
    }
}

$criadas = ['confirmacao' => 0, 'lembrete_24h' => 0, 'lembrete_2h' => 0, 'aviso_manicure' => 0];
$envio   = [];

try {

    if (notificacoesModoSimulado($config)) {
        notificacoesLog('INFO', 'Modo SIMULADO: nenhuma mensagem real será enviada (sms.provedor = "simulado" em config/integracoes.php).');
    }

    if (notificacoesTelefoneManicure($config) === '') {
        notificacoesLog('INFO', "Aviso à manicure desativado: defina 'manicure_telefone' em config/integracoes.php.");
    }

    $criadas = notificacoesEnfileirarDevidas($pdo, $config, $dryRun);

    if (!$dryRun) {
        $envio = notificacoesProcessarFila($pdo, $config);
    }

} catch (Throwable $e) {

    notificacoesLog('ERRO', 'Falha inesperada: ' . $e->getMessage());

} finally {

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->query("SELECT RELEASE_LOCK('maniagenda_lembretes')");
    }
}

$resumoEnvio = $envio
    ? implode(', ', array_map(static fn ($s, $n) => "{$s}: {$n}", array_keys($envio), $envio))
    : 'nada na fila';

notificacoesLog(
    'RESUMO',
    ($dryRun ? '[dry-run] ' : '') .
    "criadas → confirmações: {$criadas['confirmacao']}, lembretes 24h: {$criadas['lembrete_24h']}, " .
    "lembretes 2h: {$criadas['lembrete_2h']}, avisos à manicure: {$criadas['aviso_manicure']} | envio → {$resumoEnvio}"
);
