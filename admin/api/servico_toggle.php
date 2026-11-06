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
        throw new Exception('Serviço inválido.');
    }

    $stmt = $pdo->prepare("SELECT ativo, nome FROM servicos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $servico = $stmt->fetch();

    if (!$servico) {
        throw new Exception('Serviço não encontrado.');
    }

    $novoStatus = $servico['ativo'] ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE servicos SET ativo = :ativo WHERE id = :id");
    $stmt->execute([':ativo' => $novoStatus, ':id' => $id]);

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => $novoStatus
            ? htmlspecialchars($servico['nome']) . ' foi reativado.'
            : htmlspecialchars($servico['nome']) . ' foi desativado.',
        'ativo'    => $novoStatus,
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
