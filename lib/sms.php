<?php

/**
 * Envio de SMS (RF04) — único canal de notificação do sistema.
 *
 * Provedores: 'simulado', 'twilio', 'zenvia'.
 *
 * IMPORTANTE: os formatos seguem a documentação pública dos provedores e foram
 * testados apenas contra um servidor simulado local, nunca contra contas reais.
 */

require_once __DIR__ . '/telefone.php';
require_once __DIR__ . '/http.php';

/**
 * Remove acentos para o texto caber no alfabeto GSM-7 (160 caracteres por SMS;
 * com acentos o SMS vira UCS-2 e cai para 70 caracteres por segmento).
 */
function smsSemAcentos(string $texto): string
{
    $mapa = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
        '—' => '-', '–' => '-', '“' => '"', '”' => '"', '‘' => "'", '’' => "'",
    ];

    return strtr($texto, $mapa);
}

/**
 * Retorno: ['ok' => bool, 'erro' => string, 'permanente' => bool, 'id' => ?string]
 *
 * @param array  $config                $config['sms'] de config/integracoes.php
 * @param string $telefoneInternacional só dígitos, com 55 (ex.: 5511987654321)
 */
function smsEnviar(array $config, string $telefoneInternacional, string $mensagem): array
{
    $provedor = $config['provedor'] ?? 'simulado';
    $mensagem = smsSemAcentos($mensagem);

    $naoConfigurado = static fn (string $nome): array => [
        'ok' => false, 'erro' => "Credenciais de {$nome} não configuradas.", 'permanente' => true, 'id' => null,
    ];

    switch ($provedor) {

        case 'simulado':
            return ['ok' => true, 'erro' => '', 'permanente' => false, 'id' => 'sim-sms-' . bin2hex(random_bytes(6))];

        case 'twilio':
            $c = $config['twilio'] ?? [];
            if (empty($c['account_sid']) || empty($c['auth_token'])
                || (empty($c['from']) && empty($c['messaging_service_sid']))) {
                return $naoConfigurado('Twilio');
            }

            $corpo = ['To' => '+' . $telefoneInternacional, 'Body' => $mensagem];

            if (!empty($c['messaging_service_sid'])) {
                $corpo['MessagingServiceSid'] = $c['messaging_service_sid'];
            } else {
                $corpo['From'] = $c['from'];
            }

            // URL pública do webhook, para o Twilio avisar "entregue"/"não entregue"
            if (!empty($c['status_callback'])) {
                $corpo['StatusCallback'] = $c['status_callback'];
            }

            $r = httpEnviar(
                rtrim($c['url_api'] ?? 'https://api.twilio.com', '/')
                    . '/2010-04-01/Accounts/' . $c['account_sid'] . '/Messages.json',
                [],
                $corpo,
                'form',
                $c['account_sid'] . ':' . $c['auth_token']
            );

            return [
                'ok' => $r['ok'], 'erro' => $r['erro'], 'permanente' => $r['permanente'],
                'id' => $r['ok'] ? ($r['dados']['sid'] ?? null) : null,
            ];

        case 'zenvia':
            $c = $config['zenvia'] ?? [];
            if (empty($c['token']) || empty($c['from'])) {
                return $naoConfigurado('Zenvia');
            }

            $r = httpEnviar(
                rtrim($c['url_api'] ?? 'https://api.zenvia.com', '/') . '/v2/channels/sms/messages',
                ['X-API-TOKEN: ' . $c['token']],
                [
                    'from'     => $c['from'],
                    'to'       => $telefoneInternacional,
                    'contents' => [['type' => 'text', 'text' => $mensagem]],
                ]
            );

            return [
                'ok' => $r['ok'], 'erro' => $r['erro'], 'permanente' => $r['permanente'],
                'id' => $r['ok'] ? ($r['dados']['id'] ?? null) : null,
            ];
    }

    return ['ok' => false, 'erro' => 'Provedor de SMS desconhecido: ' . $provedor, 'permanente' => true, 'id' => null];
}
