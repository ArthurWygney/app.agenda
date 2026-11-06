<?php

/**
 * Configuração das integrações externas (documentação, seção 12.4 / RF04).
 *
 * As notificações (confirmação, lembretes e aviso à manicure) são enviadas por SMS.
 *
 * SEGREDOS: não coloque credenciais reais neste arquivo se ele for para um
 * repositório. Crie o arquivo config/integracoes.local.php (já ignorado pelo
 * .gitignore) retornando apenas os valores que quiser sobrescrever, por exemplo:
 *
 *   <?php
 *   return [
 *       'url_base'          => 'https://meusalao.com.br/app.agenda',
 *       'manicure_telefone' => '(45) 99999-8888',
 *       'notificacoes'      => ['webhook_token' => 'valor-longo-e-aleatorio'],
 *       'sms' => ['provedor' => 'twilio', 'twilio' => ['account_sid' => '...', 'auth_token' => '...', 'from' => '+1...']],
 *   ];
 *
 * Os valores do .local.php são mesclados por cima destes (campo a campo).
 *
 * Provedores de SMS ('sms' → 'provedor'):
 *   'simulado' → não envia nada, só registra (padrão; bom para testes e para a apresentação);
 *   'twilio'   → Twilio;
 *   'zenvia'   → Zenvia.
 */
$config = [

    // Endereço público do sistema (sem barra no final) — usado nos links das mensagens
    // e na validação da assinatura da Twilio (precisa ser o endereço que a Twilio chama).
    'url_base'   => 'http://localhost/app.agenda',
    'salao_nome' => 'ManiAgenda',

    // Telefone da manicure, para o aviso de 2h antes de cada atendimento.
    // Com DDD, em qualquer formato. Vazio = o aviso à manicure não é enviado.
    'manicure_telefone' => '',

    'notificacoes' => [

        // Quantas vezes tentar antes de marcar como "falhou" (o admin pode reenviar depois)
        'max_tentativas' => 4,

        // Espera (em segundos) antes da 2ª, 3ª, 4ª... tentativa: 2 min, 10 min, 30 min
        'intervalos_retentativa' => [120, 600, 1800],

        // Chave secreta do webhook (status de entrega e respostas "SIM").
        // Gere um valor longo e aleatório (ex.: php -r "echo bin2hex(random_bytes(24));").
        // Vazio = webhook desativado. URL: {url_base}/webhooks/notificacoes.php?token=CHAVE
        // Com sms.twilio.auth_token preenchido, a assinatura X-Twilio-Signature
        // também é obrigatória nas requisições da Twilio.
        'webhook_token' => '',
    ],

    'sms' => [

        'provedor' => 'simulado',

        'twilio' => [
            'account_sid'           => '',
            'auth_token'            => '',
            'from'                  => '', // número Twilio, ex.: +15005550006 (ou use messaging_service_sid)
            'messaging_service_sid' => '',
            'status_callback'       => '', // opcional: {url_base}/webhooks/notificacoes.php?token=CHAVE
        ],

        'zenvia' => [
            'token' => '',
            'from'  => '', // remetente cadastrado na Zenvia
        ],
    ],

];

// Sobrescritas locais (credenciais reais), fora do controle de versão
$local = __DIR__ . '/integracoes.local.php';

if (is_file($local)) {

    $sobrescrita = (array) require $local;
    $config      = array_replace_recursive($config, $sobrescrita);

    // A lista de intervalos substitui por inteiro (array_replace_recursive mesclaria por posição)
    if (isset($sobrescrita['notificacoes']['intervalos_retentativa'])) {
        $config['notificacoes']['intervalos_retentativa'] = $sobrescrita['notificacoes']['intervalos_retentativa'];
    }
}

return $config;
