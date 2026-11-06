<?php

require_once '../../config/database.php';
require_once '../includes/verifica_admin.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $id = (int) ($_POST['id'] ?? 0);

    if (!$id) {
        throw new Exception('Bloqueio inválido.');
    }

    $stmt = $pdo->prepare("DELETE FROM horarios_bloqueados WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Bloqueio não encontrado.');
    }

    echo json_encode(['sucesso' => true, 'mensagem' => 'Bloqueio removido com sucesso.']);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
