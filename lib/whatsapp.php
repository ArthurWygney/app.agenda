<?php

/**
 * Envio de mensagens de WhatsApp (documentação, RF04 e seção 12.4).
 *
 * Camada fina sobre os provedores. O restante do sistema só chama
 * whatsappEnviar(); trocar de provedor é mudar 'provedor' em
 * config/integracoes.php.
 *
 * Provedores: 'simulado', 'meta' (Cloud API oficial), 'zapi', 'evolution'.
 *
 * IMPORTANTE: os formatos de requisição seguem a documentação pública de cada
 * provedor, mas só foram testados contra um servidor simulado local — nunca
 * contra contas reais. Antes de usar em produção, faça um envio de teste pela
 * tela Admin > Notificações e confira a documentação atual do provedor.
 */

require_once __DIR__ . '/telefone.php';
require_once __DIR__ . '/http.php';

/**
 * Normaliza um telefone brasileiro para o formato internacional só com dígitos
 * (55 + DDD + número). Retorna '' se o número não for um celular válido.
 */
function whatsappNormalizarTelefone(string $telefone): string
{
    $formatado = telefoneFormatar($telefone);

    if ($formatado === null) {
        return '';
    }

    return '55' . telefoneSomenteDigitos($formatado);
}

/**
 * Envia $mensagem para $telefoneInternacional (já normalizado, só dígitos).
 *
 * $config  = $config['whatsapp'] de config/integracoes.php
 * $tipo    = 'confirmacao' | 'lembrete_24h' | 'lembrete_2h' | 'aviso_manicure' | ...
 * $params  = valores ordenados para o template da Meta (ver 'templates' na config)
 *
 * Retorno: ['ok' => bool, 'erro' => string, 'permanente' => bool, 'id' => ?string]
 * ('id' é o identificador da mensagem no provedor, usado para casar o status
 * de entrega recebido no webhook).
 */
function whatsappEnviar(array $config, string $telefoneInternacional, string $mensagem, string $tipo = '', array $params = []): array
{
    $provedor = $config['provedor'] ?? 'simulado';

    $resultado = static function (array $r, ?string $id): array {
        return [
            'ok'         => $r['ok'],
            'erro'       => $r['erro'],
            'permanente' => $r['permanente'],
            'id'         => $r['ok'] ? $id : null,
        ];
    };

    $naoConfigurado = static fn (string $nome): array => [
        'ok' => false, 'erro' => "Credenciais de {$nome} não configuradas.", 'permanente' => true, 'id' => null,
    ];

    switch ($provedor) {

        case 'simulado':
            return ['ok' => true, 'erro' => '', 'permanente' => false, 'id' => 'sim-' . bin2hex(random_bytes(6))];

        case 'meta':
            $c = $config['meta'] ?? [];
            if (empty($c['phone_number_id']) || empty($c['token'])) {
                return $naoConfigurado('Meta Cloud API');
            }

            $corpo = [
                'messaging_product' => 'whatsapp',
                'to'                => $telefoneInternacional,
            ];

            // Mensagens iniciadas pelo salão (confirmação, lembretes) só são
            // entregues fora da janela de 24h usando "message templates" aprovados
            // pela Meta. Se houver template configurado para este tipo, usa-o.
            $template = $config['templates'][$tipo] ?? '';

            if ($template !== '' && $params) {
                $corpo['type'] = 'template';
                $corpo['template'] = [
                    'name'       => $template,
                    'language'   => ['code' => $config['templates']['idioma'] ?? 'pt_BR'],
                    'components' => [[
                        'type'       => 'body',
                        'parameters' => array_map(
                            static fn ($v) => ['type' => 'text', 'text' => (string) $v],
                            array_values($params)
                        ),
                    ]],
                ];
            } else {
                $corpo['type'] = 'text';
                $corpo['text'] = ['body' => $mensagem];
            }

            $r = httpEnviar(
                rtrim($c['url_api'] ?? 'https://graph.facebook.com', '/') . '/' . ($c['versao_api'] ?? 'v20.0')
                    . '/' . $c['phone_number_id'] . '/messages',
                ['Authorization: Bearer ' . $c['token']],
                $corpo
            );

            return $resultado($r, $r['dados']['messages'][0]['id'] ?? null);

        case 'zapi':
            $c = $config['zapi'] ?? [];
            if (empty($c['instance_id']) || empty($c['token'])) {
                return $naoConfigurado('Z-API');
            }

            $headers = [];
            if (!empty($c['client_token'])) {
                $headers[] = 'Client-Token: ' . $c['client_token'];
            }

            $r = httpEnviar(
                rtrim($c['url_api'] ?? 'https://api.z-api.io', '/') . '/instances/' . $c['instance_id']
                    . '/token/' . $c['token'] . '/send-text',
                $headers,
                ['phone' => $telefoneInternacional, 'message' => $mensagem]
            );

            return $resultado($r, $r['dados']['messageId'] ?? $r['dados']['id'] ?? $r['dados']['zaapId'] ?? null);

        case 'evolution':
            $c = $config['evolution'] ?? [];
            if (empty($c['url']) || empty($c['instance']) || empty($c['apikey'])) {
                return $naoConfigurado('Evolution API');
            }

            // Evolution v2: corpo {number, text}. Versões 1.x usam {number, textMessage: {text}}.
            $r = httpEnviar(
                rtrim($c['url'], '/') . '/message/sendText/' . $c['instance'],
                ['apikey: ' . $c['apikey']],
                ['number' => $telefoneInternacional, 'text' => $mensagem]
            );

            return $resultado($r, $r['dados']['key']['id'] ?? null);
    }

    return ['ok' => false, 'erro' => 'Provedor de WhatsApp desconhecido: ' . $provedor, 'permanente' => true, 'id' => null];
}
