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
        throw new Exception('Cliente inválida.');
    }

    $stmt = $pdo->prepare("SELECT blacklist, nome FROM clientes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        throw new Exception('Cliente não encontrada.');
    }

    $novoStatus = $cliente['blacklist'] ? 0 : 1;

    // Ao desbloquear, zera as faltas consecutivas para dar um novo começo
    if ($novoStatus === 0) {
        $stmt = $pdo->prepare("UPDATE clientes SET blacklist = 0, faltas_consecutivas = 0 WHERE id = :id");
    } else {
        $stmt = $pdo->prepare("UPDATE clientes SET blacklist = 1 WHERE id = :id");
    }
    $stmt->execute([':id' => $id]);

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => $novoStatus
            ? htmlspecialchars($cliente['nome']) . ' foi bloqueada.'
            : htmlspecialchars($cliente['nome']) . ' foi desbloqueada.',
        'blacklist' => $novoStatus,
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
