<?php

require_once '../config/database.php';
require_once 'includes/verifica_cliente.php';
require_once 'includes/funcoes.php';

$clienteId = (int) $_SESSION['cliente_id'];
$hoje      = date('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT a.id, a.data, a.hora_inicio, a.hora_fim, a.status, a.observacoes,
            s.nome AS servico_nome, s.preco
     FROM agendamentos a
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.cliente_id = :cliente_id
       AND a.status IN ('agendado', 'confirmado')
       AND a.data >= :hoje
     ORDER BY a.data ASC, a.hora_inicio ASC"
);
$stmt->execute([':cliente_id' => $clienteId, ':hoje' => $hoje]);
$proximosAgendamentos = $stmt->fetchAll();

$tituloPagina = 'Painel';
$paginaAtiva  = 'painel';
require 'includes/cabecalho.php';
?>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Olá, <?= htmlspecialchars(explode(' ', $_SESSION['cliente_nome'])[0]) ?>!</h2>
            <p>Aqui você acompanha seus próximos horários e marca novos atendimentos.</p>
        </div>
        <div class="cell-actions">
            <a href="meus-agendamentos.php" class="btn btn-outline">Meus agendamentos</a>
            <a href="agendar.php" class="btn btn-primary">+ Agendar horário</a>
        </div>
    </div>

</div>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Seus próximos agendamentos</h2>
            <p><?= count($proximosAgendamentos) ?> agendamento<?= count($proximosAgendamentos) === 1 ? '' : 's' ?> confirmado<?= count($proximosAgendamentos) === 1 ? '' : 's' ?> ou marcado<?= count($proximosAgendamentos) === 1 ? '' : 's' ?></p>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$proximosAgendamentos): ?>

            <div class="empty-state">
                Você ainda não tem nenhum agendamento marcado.<br>
                <a href="agendar.php" class="btn btn-primary btn-sm" style="margin-top:14px;display:inline-flex;">Agendar agora</a>
            </div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Serviço</th>
                        <th>Valor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proximosAgendamentos as $a): ?>
                        <tr>
                            <td><?= $a['data'] === $hoje ? 'Hoje' : formatarData($a['data']) ?></td>
                            <td><?= formatarHora($a['hora_inicio']) ?> - <?= formatarHora($a['hora_fim']) ?></td>
                            <td><?= htmlspecialchars($a['servico_nome']) ?></td>
                            <td><?= formatarMoeda((float) $a['preco']) ?></td>
                            <td><span class="badge <?= classeStatus($a['status']) ?>"><?= STATUS_LABELS[$a['status']] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<?php require 'includes/rodape.php'; ?>
