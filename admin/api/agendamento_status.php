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

    $id     = (int) ($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    if (!$id || !array_key_exists($status, STATUS_LABELS)) {
        throw new Exception('Dados inválidos.');
    }

    $stmt = $pdo->prepare(
        "SELECT a.status, a.cliente_id, a.data, a.hora_inicio, a.hora_fim, c.faltas_consecutivas
         FROM agendamentos a
         JOIN clientes c ON c.id = a.cliente_id
         WHERE a.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new Exception('Agendamento não encontrado.');
    }

    if (in_array($agendamento['status'], ['cancelado', 'concluido', 'nao_compareceu'], true)
        && $agendamento['status'] === $status
    ) {
        throw new Exception('Este agendamento já está com este status.');
    }

    $pdo->beginTransaction();

    // Reativar um agendamento cancelado/finalizado (voltar para agendado/confirmado)
    // ocupa o horário de novo: precisa da mesma trava e da mesma verificação de
    // conflito, senão vira uma porta dos fundos para o double booking.
    $reativando = in_array($status, STATUS_ATIVOS, true)
        && !in_array($agendamento['status'], STATUS_ATIVOS, true);

    if ($reativando) {

        $stmt = $pdo->prepare('INSERT IGNORE INTO agenda_locks (data) VALUES (:data)');
        $stmt->execute([':data' => $agendamento['data']]);

        $stmt = $pdo->prepare('SELECT data FROM agenda_locks WHERE data = :data FOR UPDATE');
        $stmt->execute([':data' => $agendamento['data']]);

        $erroConflito = verificarConflitoAgenda(
            $pdo, $agendamento['data'], $agendamento['hora_inicio'], $agendamento['hora_fim'], $id
        );
        if ($erroConflito) {
            throw new Exception('Não foi possível reativar: ' . $erroConflito);
        }
    }

    $stmt = $pdo->prepare("UPDATE agendamentos SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);

    $mensagemExtra = '';

    // Regra de faltas / blacklist (RN03 — limite provisório, ver includes/funcoes.php)
    if ($status === 'nao_compareceu') {

        $novasFaltas = (int) $agendamento['faltas_consecutivas'] + 1;
        $vaiBloquear = $novasFaltas >= LIMITE_FALTAS_BLACKLIST;

        $stmt = $pdo->prepare(
            "UPDATE clientes
             SET faltas_consecutivas = :faltas, blacklist = :blacklist
             WHERE id = :id"
        );
        $stmt->execute([
            ':faltas'    => $novasFaltas,
            ':blacklist' => $vaiBloquear ? 1 : 0,
            ':id'        => $agendamento['cliente_id'],
        ]);

        if ($vaiBloquear) {
            $mensagemExtra = ' A cliente atingiu ' . $novasFaltas . ' faltas consecutivas e foi bloqueada automaticamente.';
        }

    } elseif ($status === 'concluido') {

        // Comparecimento zera a contagem de faltas consecutivas
        $stmt = $pdo->prepare("UPDATE clientes SET faltas_consecutivas = 0 WHERE id = :id");
        $stmt->execute([':id' => $agendamento['cliente_id']]);

    }

    $pdo->commit();

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Status atualizado para "' . STATUS_LABELS[$status] . '".' . $mensagemExtra,
    ]);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor.']);

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);

}
