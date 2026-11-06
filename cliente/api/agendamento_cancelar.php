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

    $clienteId = (int) ($_SESSION['cliente_id'] ?? 0);
    $id        = (int) ($_POST['id'] ?? 0);

    if (!$id) {
        throw new Exception('Agendamento inválido.');
    }

    // O agendamento precisa pertencer à cliente logada
    $stmt = $pdo->prepare(
        "SELECT a.status, a.data, a.hora_inicio, s.nome AS servico_nome
         FROM agendamentos a
         JOIN servicos s ON s.id = a.servico_id
         WHERE a.id = :id AND a.cliente_id = :cliente_id"
    );
    $stmt->execute([':id' => $id, ':cliente_id' => $clienteId]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new Exception('Agendamento não encontrado.');
    }

    if (!in_array($agendamento['status'], STATUS_ATIVOS, true)) {
        throw new Exception('Este agendamento não pode mais ser cancelado.');
    }

    // RN02 — limite de 24h de antecedência
    if (!podeAlterarAgendamento($agendamento['data'], $agendamento['hora_inicio'])) {
        throw new Exception(
            'Faltam menos de ' . LIMITE_ALTERACAO_HORAS . 'h para o horário. ' .
            'Para cancelar, fale diretamente com a manicure.'
        );
    }

    // O WHERE com status garante que um clique duplo (ou uma alteração feita
    // pela manicure no mesmo instante) não cancele duas vezes.
    $stmt = $pdo->prepare(
        "UPDATE agendamentos
         SET status = 'cancelado'
         WHERE id = :id
           AND cliente_id = :cliente_id
           AND status IN ('agendado', 'confirmado')"
    );
    $stmt->execute([':id' => $id, ':cliente_id' => $clienteId]);

    if ($stmt->rowCount() !== 1) {
        throw new Exception('Este agendamento já foi alterado. Atualize a página.');
    }

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Agendamento de ' . $agendamento['servico_nome'] . ' em ' .
            formatarData($agendamento['data']) . ' às ' . formatarHora($agendamento['hora_inicio']) .
            ' cancelado.',
    ]);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
