<?php

require_once '../config/database.php';
require_once '../lib/telefone.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]));
}

try {

    // Dados
    $nome      = trim($_POST['nome'] ?? '');
    $telefone  = trim($_POST['telefone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $endereco  = trim($_POST['endereco'] ?? '');
    $senha     = $_POST['senha'] ?? '';
    $confirmar = $_POST['confirmacao-senha'] ?? '';

    // Validação
    if (
        !$nome || !$telefone || !$email ||
        !$endereco || !$senha || !$confirmar
    ) {
        throw new Exception('Preencha todos os campos.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('E-mail inválido.');
    }

    if ($senha !== $confirmar) {
        throw new Exception('As senhas não coincidem.');
    }

    if (strlen($senha) < 8) {
        throw new Exception('A senha deve possuir no mínimo 8 caracteres.');
    }

    // Telefone: valida e padroniza para (00) 00000-0000
    $telefone = telefoneFormatar($telefone);

    if ($telefone === null) {
        throw new Exception('Telefone inválido. Use o formato (00) 00000-0000.');
    }

    // Verifica e-mail
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE email = :email");
    $stmt->execute([':email' => $email]);

    if ($stmt->fetch()) {
        throw new Exception('Este e-mail já está cadastrado.');
    }

    // Verifica telefone
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefone = :telefone");
    $stmt->execute([':telefone' => $telefone]);

    if ($stmt->fetch()) {
        throw new Exception('Este telefone já está cadastrado.');
    }

    // Hash da senha
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    // Transação
    $pdo->beginTransaction();

    $sql = "INSERT INTO clientes
            (nome, telefone, email, endereco, senha)
            VALUES
            (:nome, :telefone, :email, :endereco, :senha)";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':nome'      => $nome,
        ':telefone'  => $telefone,
        ':email'     => $email,
        ':endereco'  => $endereco,
        ':senha'     => $senhaHash
    ]);

    $idCliente = $pdo->lastInsertId();

    $pdo->commit();

    http_response_code(201);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Conta criada com sucesso!',
        'cliente_id' => $idCliente
    ]);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno do servidor.'
    ]);

    error_log($e->getMessage());

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);

}