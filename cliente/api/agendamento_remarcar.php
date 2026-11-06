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

    $clienteId  = (int) ($_SESSION['cliente_id'] ?? 0);
    $id         = (int) ($_POST['id'] ?? 0);
    $data       = trim($_POST['data'] ?? '');
    $horaInicio = trim($_POST['hora_inicio'] ?? '');

    if (!$id || !$data || !$horaInicio) {
        throw new Exception('Escolha a nova data e o novo horário.');
    }

    if (strtotime($data) === false || $data < date('Y-m-d')) {
        throw new Exception('Data inválida.');
    }

    if (strlen($horaInicio) === 5) {
        $horaInicio .= ':00';
    }

    if (strtotime($data . ' ' . $horaInicio) <= time()) {
        throw new Exception('Escolha um horário que ainda não passou.');
    }

    // Cliente bloqueada (RN03) perde o direito de agendar sozinha — inclui remarcar
    $stmt = $pdo->prepare("SELECT blacklist FROM clientes WHERE id = :id");
    $stmt->execute([':id' => $clienteId]);
    $cliente = $stmt->fetch();

    if (!$cliente || $cliente['blacklist']) {
        throw new Exception('Não é possível remarcar. Entre em contato com o salão.');
    }

    // Trava do dia de DESTINO (mesma usada em criar/reagendar): a verificação de
    // conflito e o UPDATE acontecem juntos, sem brecha para double booking.
    $resumo = executarComTravaDeAgenda($pdo, $data, function () use (
        $pdo, $id, $clienteId, $data, $horaInicio
    ) {

        // Relê o agendamento já dentro da transação, travando só a linha dele
        $stmt = $pdo->prepare(
            "SELECT servico_id, status, data, hora_inicio
             FROM agendamentos
             WHERE id = :id AND cliente_id = :cliente_id
             FOR UPDATE"
        );
        $stmt->execute([':id' => $id, ':cliente_id' => $clienteId]);
        $agendamento = $stmt->fetch();

        if (!$agendamento) {
            throw new Exception('Agendamento não encontrado.');
        }

        if (!in_array($agendamento['status'], STATUS_ATIVOS, true)) {
            throw new Exception('Este agendamento não pode mais ser remarcado.');
        }

        // RN02 — o limite de 24h vale sobre o horário ORIGINAL do atendimento
        if (!podeAlterarAgendamento($agendamento['data'], $agendamento['hora_inicio'])) {
            throw new Exception(
                'Faltam menos de ' . LIMITE_ALTERACAO_HORAS . 'h para o horário. ' .
                'Para remarcar, fale diretamente com a manicure.'
            );
        }

        $stmt = $pdo->prepare("SELECT nome, duracao FROM servicos WHERE id = :id");
        $stmt->execute([':id' => $agendamento['servico_id']]);
        $servico = $stmt->fetch();

        $horaFim = date('H:i:s', strtotime($horaInicio . ' + ' . $servico['duracao'] . ' minutes'));

        if ($agendamento['data'] === $data && $agendamento['hora_inicio'] === $horaInicio) {
            throw new Exception('O novo horário é igual ao atual. Escolha outro.');
        }

        // O próprio agendamento é ignorado na checagem (pode remarcar para um
        // horário que se sobrepõe ao seu atual)
        $erroConflito = verificarConflitoAgenda($pdo, $data, $horaInicio, $horaFim, $id);
        if ($erroConflito) {
            throw new Exception('Esse horário não está disponível: ' . $erroConflito . ' Escolha outro.');
        }

        // Volta para "agendado" (nova data precisa de nova confirmação) e zera o
        // controle de notificações, para a confirmação e os lembretes saírem
        // de novo com a data atualizada.
        $stmt = $pdo->prepare(
            "UPDATE agendamentos
             SET data = :data, hora_inicio = :hora_inicio, hora_fim = :hora_fim, status = 'agendado',
                 confirmacao_enviada_em = NULL, lembrete_24h_enviado_em = NULL,
                 lembrete_2h_enviado_em = NULL, aviso_manicure_2h_enviado_em = NULL
             WHERE id = :id AND cliente_id = :cliente_id"
        );
        $stmt->execute([
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
            ':id'          => $id,
            ':cliente_id'  => $clienteId,
        ]);

        return $servico['nome'];
    });

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Agendamento de ' . $resumo . ' remarcado para ' .
            formatarData($data) . ' às ' . formatarHora($horaInicio) . '.',
    ]);

    // Nova confirmação com a data atualizada, na hora (se falhar, o cron tenta de novo)
    notificacoesDespacharAposResposta($pdo, $id);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
