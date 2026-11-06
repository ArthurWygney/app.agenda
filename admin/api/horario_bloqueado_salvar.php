<?php

require_once '../../config/database.php';
require_once '../includes/verifica_admin.php';
require_once '../includes/funcoes.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']));
}

try {

    $data       = trim($_POST['data'] ?? '');
    $horaInicio = trim($_POST['hora_inicio'] ?? '');
    $horaFim    = trim($_POST['hora_fim'] ?? '');
    $motivo     = trim($_POST['motivo'] ?? '');

    if (!$data || !$horaInicio || !$horaFim) {
        throw new Exception('Preencha data, horário de início e horário de fim.');
    }

    if (strtotime($data) === false) {
        throw new Exception('Data inválida.');
    }

    if (strlen($horaInicio) === 5) $horaInicio .= ':00';
    if (strlen($horaFim) === 5) $horaFim .= ':00';

    if ($horaFim <= $horaInicio) {
        throw new Exception('O horário de fim deve ser depois do horário de início.');
    }

    // O bloqueio entra na mesma fila (trava do dia) das marcações: assim, uma cliente
    // agendando no mesmo instante não consegue entrar "dentro" do bloqueio sem ser vista.
    // Mantém o comportamento de AVISAR (sem impedir) se já houver agendamento ativo no
    // intervalo, pois o bloqueio pode ser criado justamente para reorganizar a agenda.
    $conflito = executarComTravaDeAgenda($pdo, $data, function () use (
        $pdo, $data, $horaInicio, $horaFim, $motivo
    ) {

        $stmt = $pdo->prepare(
            "SELECT a.id, c.nome AS cliente_nome, a.hora_inicio, a.hora_fim
             FROM agendamentos a
             JOIN clientes c ON c.id = a.cliente_id
             WHERE a.data = :data
               AND a.status IN ('agendado', 'confirmado')
               AND a.hora_inicio < :hora_fim
               AND a.hora_fim > :hora_inicio
             LIMIT 1"
        );
        $stmt->execute([
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
        ]);
        $conflito = $stmt->fetch();

        $stmt = $pdo->prepare(
            "INSERT INTO horarios_bloqueados (data, hora_inicio, hora_fim, motivo)
             VALUES (:data, :hora_inicio, :hora_fim, :motivo)"
        );
        $stmt->execute([
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
            ':motivo'      => $motivo ?: null,
        ]);

        return $conflito;
    });

    $mensagem = 'Horário bloqueado com sucesso.';
    if ($conflito) {
        $mensagem .= ' Atenção: há um agendamento de ' . htmlspecialchars($conflito['cliente_nome']) .
            ' (' . formatarHora($conflito['hora_inicio']) . ' às ' . formatarHora($conflito['hora_fim']) .
            ') dentro desse intervalo — considere remarcar ou cancelar.';
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
