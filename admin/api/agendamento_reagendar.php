<?php

require_once '../../config/database.php';
require_once '../includes/verifica_admin.php';
require_once '../includes/funcoes.php';
require_once '../../lib/notificacoes.php';

ob_start(); // permite responder antes de enviar as notificações
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $id         = (int) ($_POST['id'] ?? 0);
    $data       = trim($_POST['data'] ?? '');
    $horaInicio = trim($_POST['hora_inicio'] ?? '');

    if (!$id || !$data || !$horaInicio) {
        throw new Exception('Preencha a nova data e o novo horário.');
    }

    if (strtotime($data) === false) {
        throw new Exception('Data inválida.');
    }

    if (strlen($horaInicio) === 5) {
        $horaInicio .= ':00';
    }

    $stmt = $pdo->prepare(
        "SELECT a.status, s.duracao, s.nome AS servico_nome
         FROM agendamentos a
         JOIN servicos s ON s.id = a.servico_id
         WHERE a.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new Exception('Agendamento não encontrado.');
    }

    if (in_array($agendamento['status'], ['cancelado', 'concluido', 'nao_compareceu'], true)) {
        throw new Exception('Não é possível reagendar um atendimento já finalizado ou cancelado.');
    }

    $horaFim = date('H:i:s', strtotime($horaInicio . ' + ' . $agendamento['duracao'] . ' minutes'));

    // Trava o dia de DESTINO; a verificação e o UPDATE acontecem juntos.
    executarComTravaDeAgenda($pdo, $data, function () use (
        $pdo, $id, $data, $horaInicio, $horaFim
    ) {

        $erroConflito = verificarConflitoAgenda($pdo, $data, $horaInicio, $horaFim, $id);
        if ($erroConflito) {
            throw new Exception($erroConflito);
        }

        $stmt = $pdo->prepare(
            "UPDATE agendamentos
             SET data = :data, hora_inicio = :hora_inicio, hora_fim = :hora_fim, status = 'agendado',
                 confirmacao_enviada_em = NULL, lembrete_24h_enviado_em = NULL,
                 lembrete_2h_enviado_em = NULL, aviso_manicure_2h_enviado_em = NULL
             WHERE id = :id"
        );
        $stmt->execute([
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
            ':id'          => $id,
        ]);
    });

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Agendamento remarcado para ' . formatarData($data) . ' às ' . formatarHora($horaInicio) . '.',
    ]);

    // Nova confirmação para a cliente com a data atualizada (se falhar, o cron tenta de novo)
    notificacoesDespacharAposResposta($pdo, $id);

} catch (PDOException $e) {

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
