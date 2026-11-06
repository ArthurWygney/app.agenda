<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$clienteId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
$stmt->execute([':id' => $clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    header('Location: clientes.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT a.id, a.data, a.hora_inicio, a.hora_fim, a.status, a.observacoes,
            s.nome AS servico_nome, s.preco
     FROM agendamentos a
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.cliente_id = :id
     ORDER BY a.data DESC, a.hora_inicio DESC"
);
$stmt->execute([':id' => $clienteId]);
$historico = $stmt->fetchAll();

$totalConcluidos = 0;
$totalFaltas = 0;
$totalCancelados = 0;
$valorTotal = 0;

foreach ($historico as $h) {
    if ($h['status'] === 'concluido') {
        $totalConcluidos++;
        $valorTotal += (float) $h['preco'];
    } elseif ($h['status'] === 'nao_compareceu') {
        $totalFaltas++;
    } elseif ($h['status'] === 'cancelado') {
        $totalCancelados++;
    }
}

$tituloPagina = 'Histórico da cliente';
$paginaAtiva  = 'clientes';
require 'includes/cabecalho.php';
?>

<a href="clientes.php" class="btn btn-outline btn-sm" style="margin-bottom: 20px; display: inline-flex;">&#8592; Voltar para clientes</a>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2><?= htmlspecialchars($cliente['nome']) ?></h2>
            <p><?= htmlspecialchars($cliente['telefone']) ?> · <?= htmlspecialchars($cliente['email']) ?></p>
        </div>
        <?php if ($cliente['blacklist']): ?>
            <span class="badge badge-blacklist">Bloqueada</span>
        <?php else: ?>
            <span class="badge badge-ativo">Ativa</span>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <p class="cell-sub"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></p>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Atendimentos concluídos</div>
        <div class="stat-value"><?= $totalConcluidos ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Faltas registradas</div>
        <div class="stat-value"><?= $totalFaltas ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cancelamentos</div>
        <div class="stat-value"><?= $totalCancelados ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Faltas consecutivas atuais</div>
        <div class="stat-value"><?= (int) $cliente['faltas_consecutivas'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total gasto (concluídos)</div>
        <div class="stat-value"><?= formatarMoeda($valorTotal) ?></div>
    </div>
</div>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Todos os agendamentos</h2>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$historico): ?>
            <div class="empty-state">Esta cliente ainda não tem agendamentos.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Serviço</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= formatarData($h['data']) ?></td>
                            <td><?= formatarHora($h['hora_inicio']) ?> - <?= formatarHora($h['hora_fim']) ?></td>
                            <td><?= htmlspecialchars($h['servico_nome']) ?></td>
                            <td><?= formatarMoeda((float) $h['preco']) ?></td>
                            <td><span class="badge <?= classeStatus($h['status']) ?>"><?= STATUS_LABELS[$h['status']] ?></span></td>
                            <td class="cell-sub"><?= $h['observacoes'] ? htmlspecialchars($h['observacoes']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</div>

<?php require 'includes/rodape.php'; ?>
