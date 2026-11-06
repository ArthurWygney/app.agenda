<?php

require_once '../../config/database.php';
require_once '../includes/verifica_cliente.php';
require_once '../includes/funcoes.php';
require_once '../../lib/notificacoes.php';

ob_start(); // permite responder à cliente antes de enviar as notificações
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $clienteId = (int) ($_SESSION['cliente_id'] ?? 0);

    $servicoId   = (int) ($_POST['servico_id'] ?? 0);
    $data        = trim($_POST['data'] ?? '');
    $horaInicio  = trim($_POST['hora_inicio'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');

    if (!$servicoId || !$data || !$horaInicio) {
        throw new Exception('Selecione o serviço, a data e o horário.');
    }

    if (strtotime($data) === false || $data < date('Y-m-d')) {
        throw new Exception('Data inválida.');
    }

    if (strlen($horaInicio) === 5) {
        $horaInicio .= ':00';
    }

    // Revalida se a cliente não foi bloqueada durante a sessão
    $stmt = $pdo->prepare("SELECT blacklist FROM clientes WHERE id = :id");
    $stmt->execute([':id' => $clienteId]);
    $cliente = $stmt->fetch();

    if (!$cliente || $cliente['blacklist']) {
        throw new Exception('Não é possível agendar. Entre em contato com o salão.');
    }

    $stmt = $pdo->prepare("SELECT id, nome, duracao FROM servicos WHERE id = :id AND ativo = 1");
    $stmt->execute([':id' => $servicoId]);
    $servico = $stmt->fetch();

    if (!$servico) {
        throw new Exception('Serviço não encontrado ou indisponível.');
    }

    $horaFim = date('H:i:s', strtotime($horaInicio . ' + ' . $servico['duracao'] . ' minutes'));

    // Verificação + INSERT dentro da mesma trava do dia: sem isso, duas
    // clientes simultâneas passam na verificação e as duas gravam (double booking).
    executarComTravaDeAgenda($pdo, $data, function () use (
        $pdo, $data, $horaInicio, $horaFim, $clienteId, $servicoId, $observacoes
    ) {

        $erroConflito = verificarConflitoAgenda($pdo, $data, $horaInicio, $horaFim);
        if ($erroConflito) {
            throw new Exception('Esse horário acabou de ficar indisponível: ' . $erroConflito . ' Escolha outro.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO agendamentos (cliente_id, servico_id, data, hora_inicio, hora_fim, status, observacoes)
             VALUES (:cliente_id, :servico_id, :data, :hora_inicio, :hora_fim, 'agendado', :observacoes)"
        );
        $stmt->execute([
            ':cliente_id'  => $clienteId,
            ':servico_id'  => $servicoId,
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
            ':observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);
    });

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Agendamento de ' . $servico['nome'] . ' confirmado para ' .
            formatarData($data) . ' às ' . formatarHora($horaInicio) . '.',
    ]);

    // Confirmação por SMS na hora (se falhar, o cron tenta de novo)
    notificacoesDespacharAposResposta($pdo, notificacoesUltimoAgendamentoDaCliente($pdo, $clienteId));

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
