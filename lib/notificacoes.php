<?php

/**
 * ManiAgenda — notificações por SMS (RF04, documentação seção 9).
 *
 * Como funciona:
 *   1. "Enfileirar": para cada agendamento ativo, decide quais mensagens estão
 *      devidas (confirmação, lembrete 24h, lembrete 2h, aviso à manicure) e grava
 *      uma linha na tabela `notificacoes` (status "pendente"). A coluna de controle
 *      em `agendamentos` (…_enviado_em) é reivindicada com UPDATE condicional, o que
 *      impede duas execuções de criarem a mesma mensagem.
 *   2. "Processar fila": envia cada notificação pendente por SMS, com retentativas de
 *      espera crescente e registro do erro / do id da mensagem no provedor.
 *   3. O webhook (webhooks/notificacoes.php) atualiza o status para entregue/falhou
 *      e trata a resposta "SIM" da cliente (confirma a presença).
 *
 * Disparo: o cron (scripts/enviar_lembretes.php) roda a cada 5 min; além disso as
 * telas de agendar/remarcar chamam notificacoesDespacharAposResposta() para a
 * confirmação sair na hora.
 */

require_once __DIR__ . '/telefone.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/../cliente/includes/funcoes.php'; // LIMITE_ALTERACAO_HORAS, STATUS_ATIVOS

const NOTIF_SEGUNDOS_24H = 24 * 3600;
const NOTIF_SEGUNDOS_2H  = 2 * 3600;

/** tipo da notificação → coluna de controle em `agendamentos` */
const NOTIF_COLUNAS = [
    'confirmacao'    => 'confirmacao_enviada_em',
    'lembrete_24h'   => 'lembrete_24h_enviado_em',
    'lembrete_2h'    => 'lembrete_2h_enviado_em',
    'aviso_manicure' => 'aviso_manicure_2h_enviado_em',
];

/** Ordem de "progresso" do status de entrega (nunca regride) */
const NOTIF_RANK = ['enviado' => 1, 'entregue' => 2, 'lido' => 3];

// ------------------------------------------------------------------
// Configuração, log e utilitários
// ------------------------------------------------------------------

function notificacoesConfig(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/integracoes.php';
    }

    return $config;
}

function notificacoesAgora(): string
{
    return date('Y-m-d H:i:s');
}

/** Registra em scripts/logs/lembretes.log (e na tela, se rodando no terminal). */
function notificacoesLog(string $nivel, string $mensagem): void
{
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $nivel . ' ' . $mensagem . PHP_EOL;

    $dir = __DIR__ . '/../scripts/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/lembretes.log', $linha, FILE_APPEND | LOCK_EX);

    if (PHP_SAPI === 'cli') {
        echo $linha;
    }
}

function notificacoesTelefoneManicure(array $config): string
{
    return (string) ($config['manicure_telefone'] ?? '');
}

/** Há um provedor de SMS real configurado? (vazio/'nenhum' = envio desligado) */
function notificacoesProvedorAtivo(array $config): bool
{
    $provedor = $config['sms']['provedor'] ?? '';

    return $provedor !== '' && $provedor !== 'nenhum';
}

/** Modo simulado = nada é realmente enviado (útil para avisar o admin). */
function notificacoesModoSimulado(array $config): bool
{
    return ($config['sms']['provedor'] ?? 'simulado') === 'simulado';
}

// ------------------------------------------------------------------
// Texto das mensagens
// ------------------------------------------------------------------

/**
 * Monta o texto de uma notificação. Textos curtos, para caber em poucos segmentos de SMS
 * (os acentos são removidos no envio; ver smsSemAcentos()).
 *
 * $ag precisa de: cliente_nome, cliente_telefone, servico_nome, data, hora_inicio.
 */
function notificacoesMontar(string $tipo, array $ag, array $config): string
{
    $salao   = $config['salao_nome'] ?? 'ManiAgenda';
    $link    = rtrim($config['url_base'] ?? '', '/') . '/cliente/meus-agendamentos.php';
    $inicio  = strtotime($ag['data'] . ' ' . $ag['hora_inicio']);
    $diaMes  = date('d/m', $inicio);
    $hora    = date('H:i', $inicio);
    $servico = $ag['servico_nome'];
    $nome    = explode(' ', trim($ag['cliente_nome']))[0];

    switch ($tipo) {

        case 'confirmacao':
            return "{$salao}: Olá, {$nome}! Agendamento registrado: {$servico}, {$diaMes} às {$hora}. "
                . 'Para cancelar ou remarcar (até ' . LIMITE_ALTERACAO_HORAS . "h antes): {$link}";

        case 'lembrete_24h':
            return "{$salao}: {$nome}, lembrete: {$servico} amanhã, {$diaMes}, às {$hora}. "
                . "Responda SIM para confirmar. Cancelar ou remarcar: {$link}";

        case 'lembrete_2h':
            return "{$salao}: {$nome}, seu horário de {$servico} é hoje às {$hora}. Te esperamos!";

        case 'aviso_manicure':
            return "{$salao}: atendimento em cerca de 2h: {$ag['cliente_nome']}, {$servico}, hoje às {$hora}. "
                . "Tel. da cliente: {$ag['cliente_telefone']}.";

        case 'confirmacao_presenca':
            return "{$salao}: Obrigada, {$nome}! Presença confirmada: {$servico}, {$diaMes} às {$hora}. Até lá!";

        case 'orientacao':
            return "{$salao}: {$nome}, para cancelar ou remarcar {$servico} ({$diaMes} às {$hora}) use {$link} "
                . '(até ' . LIMITE_ALTERACAO_HORAS . 'h antes) ou fale com a manicure.';
    }

    throw new InvalidArgumentException('Tipo de notificação desconhecido: ' . $tipo);
}

// ------------------------------------------------------------------
// Fila: criar notificações
// ------------------------------------------------------------------

/** Reivindica a coluna de controle. true = consegui; false = outra execução já pegou (ou status mudou). */
function notificacoesReivindicar(PDO $pdo, int $agendamentoId, string $tipo): bool
{
    $coluna = NOTIF_COLUNAS[$tipo];

    $stmt = $pdo->prepare(
        "UPDATE agendamentos SET {$coluna} = :agora
         WHERE id = :id AND {$coluna} IS NULL AND status IN ('agendado', 'confirmado')"
    );
    $stmt->execute([':agora' => notificacoesAgora(), ':id' => $agendamentoId]);

    return $stmt->rowCount() === 1;
}

function notificacoesDevolver(PDO $pdo, int $agendamentoId, string $tipo): void
{
    $coluna = NOTIF_COLUNAS[$tipo];
    $pdo->prepare("UPDATE agendamentos SET {$coluna} = NULL WHERE id = :id")->execute([':id' => $agendamentoId]);
}

/** Marca como "dispensada" uma janela que não precisa mais de mensagem (não cria notificação). */
function notificacoesDispensar(PDO $pdo, int $agendamentoId, string $tipo): void
{
    $coluna = NOTIF_COLUNAS[$tipo];

    $pdo->prepare("UPDATE agendamentos SET {$coluna} = :agora WHERE id = :id AND {$coluna} IS NULL")
        ->execute([':agora' => notificacoesAgora(), ':id' => $agendamentoId]);
}

/**
 * Insere uma notificação na fila. Telefone inválido já nasce como "falhou"
 * (erro permanente, visível no painel) em vez de ser tentado para sempre.
 *
 * @return int id da notificação
 */
function notificacoesCriar(
    PDO $pdo,
    ?int $agendamentoId,
    ?string $inicioAgendado,
    string $tipo,
    string $destinatario,
    string $telefone,
    string $mensagem
): int {

    $agora   = notificacoesAgora();
    $destino = telefoneInternacional($telefone);

    $stmt = $pdo->prepare(
        "INSERT INTO notificacoes
            (agendamento_id, inicio_agendado, tipo, destinatario, telefone, mensagem,
             status, tentativas, ultimo_erro, proxima_tentativa, criado_em, atualizado_em)
         VALUES
            (:ag, :inicio, :tipo, :dest, :tel, :msg,
             :status, 0, :erro, :prox, :agora, :agora2)"
    );
    $stmt->execute([
        ':ag'     => $agendamentoId,
        ':inicio' => $inicioAgendado,
        ':tipo'   => $tipo,
        ':dest'   => $destinatario,
        ':tel'    => $destino !== '' ? $destino : substr(trim($telefone), 0, 20),
        ':msg'    => $mensagem,
        ':status' => $destino === '' ? 'falhou' : 'pendente',
        ':erro'   => $destino === '' ? 'Telefone inválido (esperado celular com DDD).' : null,
        ':prox'   => $agora,
        ':agora'  => $agora,
        ':agora2' => $agora,
    ]);

    return (int) $pdo->lastInsertId();
}

/** UPDATE genérico de colunas de uma notificação (sempre atualiza atualizado_em). */
function notificacoesAtualizarCampos(PDO $pdo, int $id, array $campos): void
{
    $campos['atualizado_em'] = notificacoesAgora();

    $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($campos)));
    $bind = [':id__' => $id];

    foreach ($campos as $coluna => $valor) {
        $bind[":{$coluna}"] = $valor;
    }

    $pdo->prepare("UPDATE notificacoes SET {$sets} WHERE id = :id__")->execute($bind);
}

/**
 * Cria as notificações devidas de UM agendamento (confirmação, lembretes, aviso).
 *
 * $ag: id, data, hora_inicio, cliente_nome, cliente_telefone, servico_nome e as
 * quatro colunas …_enviado_em. Retorna quantas notificações foram criadas por tipo.
 * Em $dryRun só chama $log e não grava nada.
 *
 * @return array<string,int>
 */
function notificacoesEnfileirarAgendamento(PDO $pdo, array $config, array $ag, bool $dryRun = false, ?callable $log = null): array
{
    $log = $log ?? 'notificacoesLog';
    $criadas = ['confirmacao' => 0, 'lembrete_24h' => 0, 'lembrete_2h' => 0, 'aviso_manicure' => 0];

    $id     = (int) $ag['id'];
    $inicio = strtotime($ag['data'] . ' ' . $ag['hora_inicio']);

    if ($inicio === false || $inicio - time() <= 0) {
        return $criadas; // atendimento já começou
    }

    $faltam         = $inicio - time();
    $inicioSql      = date('Y-m-d H:i:s', $inicio);
    $telefoneCliente = (string) $ag['cliente_telefone'];

    $criar = function (string $tipo, string $destinatario, string $telefone) use (
        $pdo, $config, $ag, $id, $inicioSql, $dryRun, $log, &$criadas
    ): bool {

        $mensagem = notificacoesMontar($tipo, $ag, $config);

        if ($dryRun) {
            $log('DRY-RUN', "#{$id} {$tipo} → {$telefone}: {$mensagem}");
            $criadas[$tipo]++;
            return true;
        }

        if (!notificacoesReivindicar($pdo, $id, $tipo)) {
            return false; // outra execução já cuidou disso (ou o status mudou)
        }

        try {
            notificacoesCriar($pdo, $id, $inicioSql, $tipo, $destinatario, $telefone, $mensagem);
        } catch (Throwable $e) {
            notificacoesDevolver($pdo, $id, $tipo); // tenta de novo na próxima execução
            $log('ERRO', "#{$id} {$tipo}: não foi possível criar a notificação: " . $e->getMessage());
            return false;
        }

        $criadas[$tipo]++;
        return true;
    };

    $janela24 = $faltam <= NOTIF_SEGUNDOS_24H;
    $janela2  = $faltam <= NOTIF_SEGUNDOS_2H;

    // Lê $ag por referência: as janelas dispensadas abaixo precisam ser vistas pelas checagens seguintes
    $pendente = function (string $coluna) use (&$ag): bool {
        return ($ag[$coluna] ?? null) === null;
    };

    // 1) Confirmação — assim que o agendamento é criado (ou remarcado)
    if ($pendente(NOTIF_COLUNAS['confirmacao'])) {

        if ($criar('confirmacao', 'cliente', $telefoneCliente)) {

            // Agendamento feito já dentro de uma janela de lembrete: a confirmação
            // acabou de avisar a cliente, então a janela é dispensada para não
            // mandar duas mensagens quase juntas.
            if ($janela24 && $pendente(NOTIF_COLUNAS['lembrete_24h'])) {
                if (!$dryRun) {
                    notificacoesDispensar($pdo, $id, 'lembrete_24h');
                }
                $ag[NOTIF_COLUNAS['lembrete_24h']] = 'dispensado';
            }

            if ($janela2 && $pendente(NOTIF_COLUNAS['lembrete_2h'])) {
                if (!$dryRun) {
                    notificacoesDispensar($pdo, $id, 'lembrete_2h');
                }
                $ag[NOTIF_COLUNAS['lembrete_2h']] = 'dispensado';
            }
        }
    }

    // 2) Lembrete de 2h (substitui o de 24h, se este ainda não saiu)
    if ($janela2 && $pendente(NOTIF_COLUNAS['lembrete_2h'])) {

        if ($criar('lembrete_2h', 'cliente', $telefoneCliente)
            && $pendente(NOTIF_COLUNAS['lembrete_24h']) && !$dryRun
        ) {
            notificacoesDispensar($pdo, $id, 'lembrete_24h');
        }

    // 3) Lembrete de 24h (pede confirmação de presença)
    } elseif ($janela24 && $pendente(NOTIF_COLUNAS['lembrete_24h'])) {

        $criar('lembrete_24h', 'cliente', $telefoneCliente);
    }

    // 4) Aviso para a manicure, 2h antes
    if ($janela2 && $pendente(NOTIF_COLUNAS['aviso_manicure'])) {

        $telefoneManicure = notificacoesTelefoneManicure($config);

        if ($telefoneManicure !== '') {
            $criar('aviso_manicure', 'manicure', $telefoneManicure);
        }
    }

    return $criadas;
}

/**
 * Cria as notificações devidas de todos os agendamentos (ou só de $agendamentoId).
 *
 * @return array<string,int> total criado por tipo
 */
function notificacoesEnfileirarDevidas(PDO $pdo, array $config, bool $dryRun = false, ?int $agendamentoId = null, ?callable $log = null): array
{
    $sql = "SELECT a.id, a.data, a.hora_inicio,
                   a.confirmacao_enviada_em, a.lembrete_24h_enviado_em,
                   a.lembrete_2h_enviado_em, a.aviso_manicure_2h_enviado_em,
                   c.nome AS cliente_nome, c.telefone AS cliente_telefone,
                   s.nome AS servico_nome
            FROM agendamentos a
            JOIN clientes c ON c.id = a.cliente_id
            JOIN servicos s ON s.id = a.servico_id
            WHERE a.status IN ('agendado', 'confirmado')
              AND a.data >= :hoje
              AND (a.confirmacao_enviada_em IS NULL
                   OR a.lembrete_24h_enviado_em IS NULL
                   OR a.lembrete_2h_enviado_em IS NULL
                   OR a.aviso_manicure_2h_enviado_em IS NULL)";

    $params = [':hoje' => date('Y-m-d')];

    if ($agendamentoId !== null) {
        $sql .= ' AND a.id = :id';
        $params[':id'] = $agendamentoId;
    }

    $stmt = $pdo->prepare($sql . ' ORDER BY a.data ASC, a.hora_inicio ASC');
    $stmt->execute($params);

    $total = ['confirmacao' => 0, 'lembrete_24h' => 0, 'lembrete_2h' => 0, 'aviso_manicure' => 0];

    foreach ($stmt->fetchAll() as $ag) {
        foreach (notificacoesEnfileirarAgendamento($pdo, $config, $ag, $dryRun, $log) as $tipo => $n) {
            $total[$tipo] += $n;
        }
    }

    return $total;
}

// ------------------------------------------------------------------
// Fila: enviar
// ------------------------------------------------------------------

/** Segundos a esperar antes da próxima tentativa, ou null se as tentativas acabaram. */
function notificacoesProximaEspera(array $config, int $tentativasFeitas): ?int
{
    $max        = (int) ($config['notificacoes']['max_tentativas'] ?? 4);
    $intervalos = $config['notificacoes']['intervalos_retentativa'] ?? [120, 600, 1800];

    if ($tentativasFeitas >= $max || !$intervalos) {
        return null;
    }

    return (int) ($intervalos[min($tentativasFeitas - 1, count($intervalos) - 1)] ?? end($intervalos));
}

/**
 * Tenta enviar UMA notificação. Reivindica a linha (pendente → enviando) para que
 * duas execuções não mandem a mesma mensagem. Retorna o status final da linha.
 */
function notificacoesEnviarRegistro(PDO $pdo, array $config, int $id): string
{
    $stmt = $pdo->prepare(
        "UPDATE notificacoes SET status = 'enviando', atualizado_em = :agora
         WHERE id = :id AND status = 'pendente'"
    );
    $stmt->execute([':agora' => notificacoesAgora(), ':id' => $id]);

    if ($stmt->rowCount() !== 1) {
        return 'ignorada'; // outra execução pegou, ou não está mais pendente
    }

    $stmt = $pdo->prepare('SELECT * FROM notificacoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $n = $stmt->fetch();

    $tentativas = (int) $n['tentativas'] + 1;

    $finalizar = function (string $status, array $extra = []) use ($pdo, $id): string {
        notificacoesAtualizarCampos($pdo, $id, ['status' => $status] + $extra);
        return $status;
    };

    // Mensagem "velha": o atendimento foi cancelado, remarcado ou já começou
    if ($n['agendamento_id'] !== null) {

        $stmt = $pdo->prepare('SELECT status, data, hora_inicio FROM agendamentos WHERE id = :id');
        $stmt->execute([':id' => $n['agendamento_id']]);
        $ag = $stmt->fetch();

        $motivo = null;

        if (!$ag || !in_array($ag['status'], ['agendado', 'confirmado'], true)) {
            $motivo = 'Agendamento cancelado ou concluído; mensagem descartada.';
        } elseif ($n['inicio_agendado'] !== null
            && strtotime($ag['data'] . ' ' . $ag['hora_inicio']) !== strtotime($n['inicio_agendado'])) {
            $motivo = 'Agendamento remarcado; mensagem antiga descartada.';
        } elseif (strtotime($ag['data'] . ' ' . $ag['hora_inicio']) <= time()) {
            $motivo = 'O horário do atendimento já passou; mensagem descartada.';
        }

        if ($motivo !== null) {
            notificacoesLog('INFO', "Notificação #{$id} ({$n['tipo']}): {$motivo}");
            return $finalizar('cancelado', ['ultimo_erro' => $motivo]);
        }
    }

    if (!preg_match('/^55\d{11}$/', (string) $n['telefone'])) {
        return $finalizar('falhou', [
            'tentativas'  => $tentativas,
            'ultimo_erro' => 'Telefone inválido (esperado celular com DDD).',
        ]);
    }

    if (!notificacoesProvedorAtivo($config)) {
        return $finalizar('falhou', [
            'tentativas'  => $tentativas,
            'ultimo_erro' => 'Nenhum provedor de SMS configurado (veja config/integracoes.php).',
        ]);
    }

    $r = smsEnviar($config['sms'] ?? [], $n['telefone'], $n['mensagem']);

    if ($r['ok']) {

        $provedor = $config['sms']['provedor'];

        notificacoesLog(
            $provedor === 'simulado' ? 'SIMULADO' : 'ENVIADO',
            "#{$id} {$n['tipo']} → {$n['telefone']}: {$n['mensagem']}"
        );

        return $finalizar('enviado', [
            'provedor'            => $provedor,
            'provider_message_id' => $r['id'],
            'tentativas'          => $tentativas,
            'ultimo_erro'         => null,
            'proxima_tentativa'   => null,
            'enviado_em'          => notificacoesAgora(),
        ]);
    }

    $erro   = substr($r['erro'], 0, 500);
    $espera = $r['permanente'] ? null : notificacoesProximaEspera($config, $tentativas);

    if ($espera === null) {
        notificacoesLog('ERRO', "#{$id} {$n['tipo']} → {$n['telefone']}: falhou definitivamente: {$erro}");

        return $finalizar('falhou', [
            'tentativas'        => $tentativas,
            'ultimo_erro'       => $erro,
            'proxima_tentativa' => null,
        ]);
    }

    notificacoesLog('ERRO', "#{$id} {$n['tipo']} → {$n['telefone']}: {$erro} (nova tentativa em {$espera}s)");

    return $finalizar('pendente', [
        'tentativas'        => $tentativas,
        'ultimo_erro'       => $erro,
        'proxima_tentativa' => date('Y-m-d H:i:s', time() + $espera),
    ]);
}

/**
 * Envia as notificações pendentes que já estão "no ponto" (proxima_tentativa vencida).
 * Também recoloca na fila as que ficaram presas em "enviando" (processo morreu no meio).
 *
 * @return array<string,int> contagem por status final
 */
function notificacoesProcessarFila(PDO $pdo, array $config, int $limite = 50, ?int $agendamentoId = null): array
{
    $agora = notificacoesAgora();

    // Presas há mais de 10 min → volta para pendente
    $pdo->prepare(
        "UPDATE notificacoes SET status = 'pendente'
         WHERE status = 'enviando' AND atualizado_em < :limite"
    )->execute([':limite' => date('Y-m-d H:i:s', time() - 600)]);

    $sql = "SELECT id FROM notificacoes
            WHERE status = 'pendente' AND (proxima_tentativa IS NULL OR proxima_tentativa <= :agora)";
    $params = [':agora' => $agora];

    if ($agendamentoId !== null) {
        $sql .= ' AND agendamento_id = :ag';
        $params[':ag'] = $agendamentoId;
    }

    $stmt = $pdo->prepare($sql . ' ORDER BY id ASC LIMIT ' . (int) $limite);
    $stmt->execute($params);

    $resumo = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $status = notificacoesEnviarRegistro($pdo, $config, (int) $id);
        $resumo[$status] = ($resumo[$status] ?? 0) + 1;
    }

    return $resumo;
}

/** Cria (se devido) e envia agora as notificações de UM agendamento. Nunca lança exceção. */
function notificacoesDespachar(PDO $pdo, int $agendamentoId): void
{
    try {
        $config = notificacoesConfig();
        notificacoesEnfileirarDevidas($pdo, $config, false, $agendamentoId);
        notificacoesProcessarFila($pdo, $config, 10, $agendamentoId);
    } catch (Throwable $e) {
        // A notificação não pode derrubar o agendamento; o cron pega na próxima rodada.
        notificacoesLog('ERRO', "Despacho imediato do agendamento #{$agendamentoId} falhou: " . $e->getMessage());
    }
}

/**
 * Para usar DEPOIS de enviar a resposta JSON ao navegador: fecha a conexão com
 * a cliente e só então envia as mensagens (o envio pode levar alguns segundos).
 * O endpoint precisa ter chamado ob_start() antes de dar o echo da resposta.
 */
function notificacoesDespacharAposResposta(PDO $pdo, int $agendamentoId): void
{
    ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (!headers_sent() && ob_get_level() > 0) {
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    notificacoesDespachar($pdo, $agendamentoId);
}

/** Descobre o id do agendamento mais recente da cliente (para despachar após criar). */
function notificacoesUltimoAgendamentoDaCliente(PDO $pdo, int $clienteId): int
{
    $stmt = $pdo->prepare('SELECT MAX(id) FROM agendamentos WHERE cliente_id = :id');
    $stmt->execute([':id' => $clienteId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Coloca uma notificação com falha (ou cancelada) de volta na fila e tenta enviar já.
 * Retorna o status final, ou lança Exception com a razão de não ser possível.
 */
function notificacoesReenviar(PDO $pdo, array $config, int $id): string
{
    $stmt = $pdo->prepare('SELECT * FROM notificacoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $n = $stmt->fetch();

    if (!$n) {
        throw new Exception('Notificação não encontrada.');
    }

    if (!in_array($n['status'], ['falhou', 'cancelado'], true)) {
        throw new Exception('Só é possível reenviar notificações que falharam ou foram canceladas.');
    }

    if ($n['status'] === 'cancelado') {
        throw new Exception('Esta mensagem foi descartada porque o agendamento mudou. Não é mais necessária.');
    }

    $agora = notificacoesAgora();

    $pdo->prepare(
        "UPDATE notificacoes
         SET status = 'pendente', tentativas = 0, ultimo_erro = NULL, proxima_tentativa = :agora, atualizado_em = :agora2
         WHERE id = :id AND status = 'falhou'"
    )->execute([':agora' => $agora, ':agora2' => $agora, ':id' => $id]);

    // Telefone gravado inválido (nasceu "falhou"): tenta de novo, o envio acusa o erro
    return notificacoesEnviarRegistro($pdo, $config, $id);
}

// ------------------------------------------------------------------
// Webhook: status de entrega e respostas
// ------------------------------------------------------------------

/**
 * Aplica um status de entrega vindo do provedor. $status: 'enviado' | 'entregue' | 'lido' | 'falhou'.
 * Nunca regride (lido não volta para entregue). "falhou" só vale enquanto a mensagem
 * estiver apenas "enviada" (se já foi entregue, a informação de falha é ignorada).
 *
 * @return bool true se alguma notificação foi encontrada
 */
function notificacoesAtualizarEntrega(PDO $pdo, string $providerMessageId, string $status, ?string $erro = null): bool
{
    if ($providerMessageId === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT * FROM notificacoes WHERE provider_message_id = :id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':id' => $providerMessageId]);
    $n = $stmt->fetch();

    if (!$n) {
        return false;
    }

    if ($status === 'falhou') {

        if ($n['status'] === 'enviado') {
            $motivo = $erro !== null && $erro !== '' ? $erro : 'o provedor informou falha na entrega.';
            notificacoesAtualizarCampos($pdo, (int) $n['id'], [
                'status'      => 'falhou',
                'ultimo_erro' => substr('Não entregue: ' . $motivo, 0, 500),
            ]);
        }

        return true;
    }

    $novo  = NOTIF_RANK[$status] ?? 0;
    $atual = NOTIF_RANK[$n['status']] ?? 0;

    if ($novo === 0 || $novo <= $atual) {
        return true;
    }

    $campos = ['status' => $status];

    if ($status === 'entregue') {
        $campos['entregue_em'] = notificacoesAgora();
    } elseif ($status === 'lido') {
        $campos['lido_em'] = notificacoesAgora();
        if ($n['entregue_em'] === null) {
            $campos['entregue_em'] = notificacoesAgora();
        }
    }

    notificacoesAtualizarCampos($pdo, (int) $n['id'], $campos);

    return true;
}

/** Texto da resposta da cliente → 'sim' | 'nao' | null */
function notificacoesInterpretarResposta(string $texto): ?string
{
    $t = mb_strtolower(trim($texto), 'UTF-8');
    $t = smsSemAcentos($t);
    $t = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $t) ?? '');
    $primeira = explode(' ', $t)[0] ?? '';

    if (in_array($primeira, ['sim', 's', 'ok', 'confirmo', 'confirmado', 'confirmar', '1'], true)) {
        return 'sim';
    }

    if (in_array($primeira, ['nao', 'n', 'cancelar', 'cancela', 'cancelo', '2'], true)) {
        return 'nao';
    }

    return null;
}

/**
 * Trata uma mensagem recebida da cliente (via webhook).
 *  - "SIM"  → o próximo agendamento "agendado" dela vira "confirmado" e ela recebe um aviso;
 *  - "NÃO"  → recebe a orientação de como cancelar/remarcar (nada é cancelado automaticamente,
 *             porque a RN02 exige o limite de 24h e a manicure pode querer negociar).
 *
 * @return string resultado: 'confirmado' | 'orientada' | 'ignorada'
 */
function notificacoesProcessarResposta(PDO $pdo, array $config, string $telefone, string $texto): string
{
    $interpretacao = notificacoesInterpretarResposta($texto);

    if ($interpretacao === null) {
        return 'ignorada';
    }

    $formatado = telefoneFormatar($telefone);

    if ($formatado === null) {
        return 'ignorada';
    }

    $stmt = $pdo->prepare(
        "SELECT a.id, a.status, a.data, a.hora_inicio, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
                s.nome AS servico_nome
         FROM agendamentos a
         JOIN clientes c ON c.id = a.cliente_id
         JOIN servicos s ON s.id = a.servico_id
         WHERE c.telefone = :tel AND a.status IN ('agendado', 'confirmado') AND a.data >= :hoje
         ORDER BY a.data ASC, a.hora_inicio ASC"
    );
    $stmt->execute([':tel' => $formatado, ':hoje' => date('Y-m-d')]);

    $proximo = null;

    foreach ($stmt->fetchAll() as $ag) {
        if (strtotime($ag['data'] . ' ' . $ag['hora_inicio']) > time()) {
            $proximo = $ag;
            break;
        }
    }

    if ($proximo === null) {
        return 'ignorada';
    }

    $inicioSql = date('Y-m-d H:i:s', strtotime($proximo['data'] . ' ' . $proximo['hora_inicio']));

    if ($interpretacao === 'sim') {

        if ($proximo['status'] === 'agendado') {
            $pdo->prepare("UPDATE agendamentos SET status = 'confirmado' WHERE id = :id AND status = 'agendado'")
                ->execute([':id' => $proximo['id']]);
        }

        $mensagem = notificacoesMontar('confirmacao_presenca', $proximo, $config);
        $tipo = 'confirmacao_presenca';
        $resultado = 'confirmado';

    } else {

        $mensagem = notificacoesMontar('orientacao', $proximo, $config);
        $tipo = 'orientacao';
        $resultado = 'orientada';
    }

    $idNotif = notificacoesCriar(
        $pdo, (int) $proximo['id'], $inicioSql, $tipo, 'cliente', $proximo['cliente_telefone'], $mensagem
    );

    notificacoesEnviarRegistro($pdo, $config, $idNotif);

    return $resultado;
}
