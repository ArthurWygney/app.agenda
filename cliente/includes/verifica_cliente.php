<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['cliente_id']) || ($_SESSION['tipo'] ?? '') !== 'cliente') {

    $ehAjax = (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );

    if ($ehAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Sessão expirada. Faça login novamente.',
        ]);
        exit;
    }

    header('Location: ../auth/login.php');
    exit;
}
