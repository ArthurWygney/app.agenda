<?php

/**
 * Interpretação dos webhooks dos provedores de SMS (status de entrega e mensagens recebidas).
 *
 * notificacoesWebhookEventos() converte o formato de cada provedor num formato
 * único, que webhooks/notificacoes.php aplica no banco:
 *
 *   ['tipo' => 'status',   'id' => '<id da mensagem>', 'status' => 'enviado|entregue|falhou', 'erro' => '...']
 *   ['tipo' => 'mensagem', 'telefone' => '5511...', 'texto' => '...']
 *
 * Os formatos seguem a documentação pública de cada provedor e foram testados
 * apenas com cargas de exemplo; confirme com um envio real após configurar.
 */

/** Só dígitos de um identificador como "+5511987654321". */
function webhookSomenteTelefone(string $valor): string
{
    return preg_replace('/\D+/', '', $valor) ?? '';
}

/** A requisição tem o formato de um webhook da Twilio? (mesmo critério do interpretador abaixo) */
function webhookEhTwilio(array $post): bool
{
    return isset($post['MessageSid']);
}

/**
 * @param string $corpoBruto corpo cru da requisição (JSON — Zenvia)
 * @param array  $post       $_POST (a Twilio envia formulário)
 * @return array<int,array<string,string>>
 */
function notificacoesWebhookEventos(string $corpoBruto, array $post): array
{
    $eventos = [];

    // ---------------- Twilio (formulário) ----------------
    if (webhookEhTwilio($post)) {

        if (isset($post['MessageStatus'])) {

            $mapa = [
                'queued' => 'enviado', 'sending' => 'enviado', 'sent' => 'enviado',
                'delivered' => 'entregue',
                'failed' => 'falhou', 'undelivered' => 'falhou',
            ];
            $status = $mapa[strtolower((string) $post['MessageStatus'])] ?? null;

            if ($status !== null) {
                $eventos[] = [
                    'tipo' => 'status', 'id' => (string) $post['MessageSid'], 'status' => $status,
                    'erro' => isset($post['ErrorCode']) ? 'código Twilio ' . $post['ErrorCode'] : '',
                ];
            }

        } elseif (isset($post['Body'], $post['From'])) {

            $eventos[] = [
                'tipo'     => 'mensagem',
                'telefone' => webhookSomenteTelefone((string) $post['From']),
                'texto'    => (string) $post['Body'],
            ];
        }

        return $eventos;
    }

    // ---------------- Zenvia (JSON) ----------------
    $json = json_decode($corpoBruto, true);
    $json = is_array($json) ? $json : [];

    if (($json['type'] ?? '') === 'MESSAGE_STATUS' && isset($json['messageStatus'])) {

        $mapa = ['SENT' => 'enviado', 'DELIVERED' => 'entregue', 'REJECTED' => 'falhou', 'NOT_DELIVERED' => 'falhou'];
        $status = $mapa[strtoupper((string) ($json['messageStatus']['code'] ?? ''))] ?? null;

        if ($status !== null && !empty($json['message']['id'])) {
            $eventos[] = [
                'tipo' => 'status', 'id' => (string) $json['message']['id'], 'status' => $status,
                'erro' => (string) ($json['messageStatus']['description'] ?? ''),
            ];
        }

    } elseif (($json['type'] ?? '') === 'MESSAGE' && isset($json['message']['from'])) {

        $texto = $json['message']['contents'][0]['text'] ?? null;

        if ($texto !== null) {
            $eventos[] = [
                'tipo'     => 'mensagem',
                'telefone' => webhookSomenteTelefone((string) $json['message']['from']),
                'texto'    => (string) $texto,
            ];
        }
    }

    return $eventos;
}

// ------------------------------------------------------------------
// Assinatura do webhook da Twilio
// ------------------------------------------------------------------

/**
 * Twilio: X-Twilio-Signature = base64(HMAC-SHA1(URL completa + parâmetros do POST
 * ordenados por nome, cada um como nome+valor, Auth Token)).
 * $urlsPossiveis: a URL exata que a Twilio chamou (com a query string); passe mais de
 * uma quando houver proxy/HTTPS na frente que altere esquema, host ou porta.
 * Cabeçalho ausente ou diferente → false.
 *
 * @param string[] $urlsPossiveis
 */
function webhookAssinaturaTwilioValida(array $urlsPossiveis, array $post, ?string $cabecalho, string $authToken): bool
{
    if ($authToken === '' || $cabecalho === null || $cabecalho === '') {
        return false;
    }

    ksort($post);
    $parametros = '';

    foreach ($post as $nome => $valor) {
        foreach ((array) $valor as $v) {
            $parametros .= $nome . $v;
        }
    }

    foreach ($urlsPossiveis as $url) {
        $esperada = base64_encode(hash_hmac('sha1', $url . $parametros, $authToken, true));
        if (hash_equals($esperada, $cabecalho)) {
            return true;
        }
    }

    return false;
}
