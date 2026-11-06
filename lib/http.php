<?php

/**
 * Cliente HTTP mínimo (cURL) usado pelos provedores de SMS.
 *
 * Retorno padronizado de httpEnviar():
 *   [
 *     'ok'         => bool,    // HTTP 2xx
 *     'codigo'     => int,     // código HTTP (0 = falha de conexão)
 *     'dados'      => array,   // corpo decodificado (JSON); [] se não for JSON
 *     'erro'       => string,  // mensagem legível quando !ok
 *     'permanente' => bool,    // true = repetir não adianta (ex.: 400/401/403/404/422)
 *   ]
 *
 * Erros "temporários" (queda de rede, timeout, HTTP 408/429/5xx) têm
 * permanente = false e são tentados de novo pela fila de notificações.
 */

/**
 * @param string                 $url
 * @param array<int,string>      $headers   ex.: ['Authorization: Bearer xxx']
 * @param array|string           $corpo     array → JSON ou form (conforme $formato)
 * @param string                 $formato   'json' | 'form'
 * @param string|null            $basicAuth 'usuario:senha' (HTTP Basic), opcional
 */
function httpEnviar(string $url, array $headers, $corpo, string $formato = 'json', ?string $basicAuth = null): array
{
    $falha = static fn (string $erro, bool $permanente, int $codigo = 0): array => [
        'ok' => false, 'codigo' => $codigo, 'dados' => [], 'erro' => $erro, 'permanente' => $permanente,
    ];

    if (!function_exists('curl_init')) {
        return $falha('A extensão cURL do PHP não está habilitada.', true);
    }

    if ($formato === 'json') {
        $payload = is_string($corpo) ? $corpo : json_encode($corpo, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    } else {
        $payload = is_string($corpo) ? $corpo : http_build_query($corpo);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $ch = curl_init($url);

    $opcoes = [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
    ];

    if ($basicAuth !== null) {
        $opcoes[CURLOPT_USERPWD] = $basicAuth;
    }

    curl_setopt_array($ch, $opcoes);

    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $codigo   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false) {
        return $falha('Falha de conexão: ' . $erroCurl, false);
    }

    $dados = json_decode((string) $resposta, true);
    $dados = is_array($dados) ? $dados : [];

    if ($codigo >= 200 && $codigo < 300) {
        return ['ok' => true, 'codigo' => $codigo, 'dados' => $dados, 'erro' => '', 'permanente' => false];
    }

    $temporario = in_array($codigo, [408, 425, 429], true) || $codigo >= 500;

    return [
        'ok'         => false,
        'codigo'     => $codigo,
        'dados'      => $dados,
        'erro'       => 'HTTP ' . $codigo . ': ' . substr((string) $resposta, 0, 300),
        'permanente' => !$temporario,
    ];
}
