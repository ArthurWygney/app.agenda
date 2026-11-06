<?php

require_once '../../config/database.php';
require_once '../includes/verifica_admin.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $id        = (int) ($_POST['id'] ?? 0);
    $nome      = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $preco     = str_replace(',', '.', trim($_POST['preco'] ?? ''));
    $duracao   = (int) ($_POST['duracao'] ?? 0);
    $ativo     = isset($_POST['ativo']) ? 1 : 0;

    if (!$nome) {
        throw new Exception('Informe o nome do serviço.');
    }

    if (!is_numeric($preco) || (float) $preco <= 0) {
        throw new Exception('Informe um preço válido.');
    }

    if ($duracao <= 0) {
        throw new Exception('Informe a duração em minutos (maior que zero).');
    }

    if ($id) {

        $stmt = $pdo->prepare(
            "UPDATE servicos
             SET nome = :nome, descricao = :descricao, preco = :preco, duracao = :duracao, ativo = :ativo
             WHERE id = :id"
        );
        $stmt->execute([
            ':nome'      => $nome,
            ':descricao' => $descricao,
            ':preco'     => $preco,
            ':duracao'   => $duracao,
            ':ativo'     => $ativo,
            ':id'        => $id,
        ]);

        $mensagem = 'Serviço atualizado com sucesso.';

    } else {

        $stmt = $pdo->prepare(
            "INSERT INTO servicos (nome, descricao, preco, duracao, ativo)
             VALUES (:nome, :descricao, :preco, :duracao, :ativo)"
        );
        $stmt->execute([
            ':nome'      => $nome,
            ':descricao' => $descricao,
            ':preco'     => $preco,
            ':duracao'   => $duracao,
            ':ativo'     => $ativo,
        ]);

        $mensagem = 'Serviço cadastrado com sucesso.';

    }

    echo json_encode(['sucesso' => true, 'mensagem' => $mensagem]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
