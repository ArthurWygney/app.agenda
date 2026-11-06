<?php

require_once '../../config/database.php';
require_once '../includes/verifica_admin.php';
require_once '../../lib/notificacoes.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $id = (int) ($_POST['id'] ?? 0);

    if (!$id) {
        throw new Exception('Notificação inválida.');
    }

    $status = notificacoesReenviar($pdo, notificacoesConfig(), $id);

    $mensagens = [
        'enviado'   => 'Mensagem reenviada com sucesso.',
        'pendente'  => 'O envio falhou de novo e uma nova tentativa foi agendada. Veja o motivo na lista.',
        'falhou'    => 'O envio falhou novamente. Veja o motivo na lista.',
        'cancelado' => 'A mensagem foi descartada: o agendamento mudou.',
    ];

    echo json_encode([
        'sucesso'  => in_array($status, ['enviado'], true),
        'mensagem' => $mensagens[$status] ?? 'Reenvio processado.',
        'status'   => $status,
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
