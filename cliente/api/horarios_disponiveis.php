<?php

require_once '../../config/database.php';
require_once '../includes/verifica_cliente.php';
require_once '../includes/funcoes.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $servicoId = (int) ($_POST['servico_id'] ?? 0);
    $data      = trim($_POST['data'] ?? '');

    if (!$servicoId || !$data) {
        throw new Exception('Selecione um serviço e uma data.');
    }

    if (strtotime($data) === false) {
        throw new Exception('Data inválida.');
    }

    if ($data < date('Y-m-d')) {
        throw new Exception('Escolha uma data a partir de hoje.');
    }

    $stmt = $pdo->prepare("SELECT id, duracao FROM servicos WHERE id = :id AND ativo = 1");
    $stmt->execute([':id' => $servicoId]);
    $servico = $stmt->fetch();

    if (!$servico) {
        throw new Exception('Serviço não encontrado ou indisponível.');
    }

    // Na remarcação, o próprio agendamento não deve bloquear o horário que ele ocupa hoje
    $agendamentoId = (int) ($_POST['agendamento_id'] ?? 0);
    $excluirId     = null;

    if ($agendamentoId) {
        $stmt = $pdo->prepare(
            "SELECT id FROM agendamentos
             WHERE id = :id AND cliente_id = :cliente_id AND status IN ('agendado', 'confirmado')"
        );
        $stmt->execute([
            ':id'         => $agendamentoId,
            ':cliente_id' => (int) $_SESSION['cliente_id'],
        ]);

        if ($stmt->fetch()) {
            $excluirId = $agendamentoId;
        }
    }

    $horarios = gerarHorariosDisponiveis($pdo, $data, (int) $servico['duracao'], 30, $excluirId);

    echo json_encode([
        'sucesso'  => true,
        'horarios' => $horarios,
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
