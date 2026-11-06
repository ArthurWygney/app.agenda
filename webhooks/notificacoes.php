<?php

/**
 * Webhook de notificações (RF04): recebe dos provedores de SMS
 *   - atualização de status (enviado → entregue / falhou);
 *   - respostas das clientes ("SIM" confirma a presença).
 *
 * Configure no painel do provedor a URL:
 *   {url_base}/webhooks/notificacoes.php?token=SUA_CHAVE
 * onde SUA_CHAVE é 'webhook_token' em config/integracoes.php (obrigatório;
 * sem chave, o endpoint fica desligado). Com o Auth Token da Twilio preenchido
 * (sms.twilio.auth_token), a assinatura X-Twilio-Signature é obrigatória nas
 * requisições da Twilio.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/notificacoes.php';
require_once __DIR__ . '/../lib/notificacoes_webhook.php';

$config = notificacoesConfig();
$chave  = (string) ($config['notificacoes']['webhook_token'] ?? '');

function webhookResponder(int $codigo, string $corpo, string $tipo = 'text/plain'): void
{
    http_response_code($codigo);
    header('Content-Type: ' . $tipo . '; charset=UTF-8');
    echo $corpo;
    exit;
}

if ($chave === '') {
    webhookResponder(503, 'Webhook desativado: defina notificacoes.webhook_token em config/integracoes.php.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookResponder(405, 'Método não permitido.');
}

if (!hash_equals($chave, (string) ($_GET['token'] ?? ''))) {
    webhookResponder(403, 'Token inválido.');
}

$corpo = (string) file_get_contents('php://input', false, null, 0, 1048576);

// Assinatura da Twilio. Com o Auth Token configurado ela é OBRIGATÓRIA: requisição sem o
// cabeçalho, ou com assinatura inválida, é rejeitada. A detecção usa o mesmo critério do
// interpretador de eventos, então nenhum formato é processado sem passar pela checagem.
$tokenTwilio = (string) ($config['sms']['twilio']['auth_token'] ?? '');

if ($tokenTwilio !== '' && webhookEhTwilio($_POST)) {

    // A Twilio assina a URL exata que chamou. Atrás de proxy/HTTPS o esquema e o host
    // vistos pelo PHP podem diferir, então aceita a URL montada de url_base e a da requisição.
    $consulta = $_SERVER['QUERY_STRING'] ?? '';
    $sufixo   = '/webhooks/notificacoes.php' . ($consulta !== '' ? '?' . $consulta : '');

    $esquema = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS'])) ? 'https' : 'http';
    $urls = [
        rtrim((string) ($config['url_base'] ?? ''), '/') . $sufixo,
        $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
    ];

    if (!webhookAssinaturaTwilioValida($urls, $_POST, $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? null, $tokenTwilio)) {
        notificacoesLog('ERRO', 'Webhook Twilio rejeitado: assinatura ausente ou inválida.');
        webhookResponder(403, 'Assinatura inválida.');
    }
}

try {

    $processados = 0;

    foreach (notificacoesWebhookEventos($corpo, $_POST) as $evento) {

        if ($evento['tipo'] === 'status') {
            notificacoesAtualizarEntrega($pdo, $evento['id'], $evento['status'], $evento['erro'] ?? null);
            $processados++;
            continue;
        }

        if ($evento['tipo'] === 'mensagem') {
            $resultado = notificacoesProcessarResposta($pdo, $config, $evento['telefone'], $evento['texto']);
            notificacoesLog('INFO', "Resposta recebida de {$evento['telefone']}: {$resultado}");
            $processados++;
        }
    }

    if (webhookEhTwilio($_POST)) {
        webhookResponder(200, '<Response></Response>', 'text/xml'); // TwiML vazio: não responde nada automaticamente
    }

    webhookResponder(200, json_encode(['ok' => true, 'eventos' => $processados]), 'application/json');

} catch (Throwable $e) {

    notificacoesLog('ERRO', 'Webhook: ' . $e->getMessage());
    webhookResponder(500, 'Erro interno.');
}
