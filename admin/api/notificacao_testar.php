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

    $telefone = telefoneFormatar(trim($_POST['telefone'] ?? ''));

    if ($telefone === null) {
        throw new Exception('Informe um celular válido com DDD, ex.: (11) 98765-4321.');
    }

    $config   = notificacoesConfig();
    $salao    = $config['salao_nome'] ?? 'ManiAgenda';
    $mensagem = "Teste de notificação do {$salao}. Se você recebeu esta mensagem, o envio está funcionando.";

    $id = notificacoesCriar($pdo, null, null, 'teste', 'manicure', $telefone, $mensagem);

    // Uma única tentativa, sem fila: o resultado aparece na hora
    $status = notificacoesEnviarRegistro($pdo, $config, $id);

    $stmt = $pdo->prepare('SELECT status, provedor, ultimo_erro FROM notificacoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $n = $stmt->fetch();

    if ($status !== 'enviado') {
        // Mensagem de teste não deve ficar em retentativa automática
        $pdo->prepare("UPDATE notificacoes SET status = 'falhou' WHERE id = :id AND status = 'pendente'")
            ->execute([':id' => $id]);

        throw new Exception('O envio de teste falhou: ' . ($n['ultimo_erro'] ?? 'erro desconhecido'));
    }

    $aviso = $n['provedor'] === 'simulado'
        ? ' (modo simulado: nada foi enviado de verdade, só registrado)'
        : '';

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Teste enviado por SMS' . $aviso . '.',
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
