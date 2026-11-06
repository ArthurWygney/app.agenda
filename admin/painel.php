<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$hoje    = date('Y-m-d');
$amanha  = date('Y-m-d', strtotime('+1 day'));

// --- Estatísticas do dia ---

$stmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS total
     FROM agendamentos
     WHERE data = :hoje
     GROUP BY status"
);
$stmt->execute([':hoje' => $hoje]);
$contagemHoje = array_fill_keys(array_keys(STATUS_LABELS), 0);
foreach ($stmt->fetchAll() as $linha) {
    $contagemHoje[$linha['status']] = (int) $linha['total'];
}
$totalHoje = array_sum($contagemHoje);

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM clientes WHERE blacklist = 0");
$clientesAtivas = (int) $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM clientes WHERE blacklist = 1");
$clientesBloqueadas = (int) $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM servicos WHERE ativo = 1");
$servicosAtivos = (int) $stmt->fetch()['total'];

// --- Próximos agendamentos (hoje e amanhã) ---

$stmt = $pdo->prepare(
    "SELECT a.id, a.data, a.hora_inicio, a.hora_fim, a.status, a.observacoes,
            c.nome AS cliente_nome, c.telefone AS cliente_telefone,
            s.nome AS servico_nome
     FROM agendamentos a
     JOIN clientes c ON c.id = a.cliente_id
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.data IN (:hoje, :amanha)
       AND a.status IN ('agendado', 'confirmado')
     ORDER BY a.data ASC, a.hora_inicio ASC"
);
$stmt->execute([':hoje' => $hoje, ':amanha' => $amanha]);
$proximosAgendamentos = $stmt->fetchAll();

// --- Clientes perto do limite de faltas (alerta) ---

$stmt = $pdo->prepare(
    "SELECT nome, faltas_consecutivas
     FROM clientes
     WHERE blacklist = 0
       AND faltas_consecutivas >= :quase_limite
     ORDER BY faltas_consecutivas DESC"
);
$stmt->execute([':quase_limite' => max(1, LIMITE_FALTAS_BLACKLIST - 1)]);
$clientesEmAlerta = $stmt->fetchAll();

$tituloPagina = 'Painel';
$paginaAtiva  = 'painel';
require 'includes/cabecalho.php';
?>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-label">Agendamentos hoje</div>
        <div class="stat-value"><?= $totalHoje ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Confirmados hoje</div>
        <div class="stat-value"><?= $contagemHoje['confirmado'] ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Serviços ativos</div>
        <div class="stat-value"><?= $servicosAtivos ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Clientes ativas</div>
        <div class="stat-value"><?= $clientesAtivas ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Clientes bloqueadas</div>
        <div class="stat-value"><?= $clientesBloqueadas ?></div>
    </div>

</div>

<?php if ($clientesEmAlerta): ?>
    <div class="alert-inline">
        &#9888;
        <span>
            <?php foreach ($clientesEmAlerta as $c): ?>
                <strong><?= htmlspecialchars($c['nome']) ?></strong>
                (<?= (int) $c['faltas_consecutivas'] ?> faltas consecutivas)<?= $c !== end($clientesEmAlerta) ? ', ' : '' ?>
            <?php endforeach; ?>
            está<?= count($clientesEmAlerta) > 1 ? 'ão' : '' ?> perto do limite de bloqueio automático (<?= LIMITE_FALTAS_BLACKLIST ?> faltas).
        </span>
    </div>
<?php endif; ?>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Próximos agendamentos</h2>
            <p>Hoje e amanhã</p>
        </div>
        <a href="agenda.php" class="btn btn-outline btn-sm">Ver agenda completa</a>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$proximosAgendamentos): ?>

            <div class="empty-state">Nenhum agendamento para hoje ou amanhã.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Cliente</th>
                        <th>Serviço</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proximosAgendamentos as $a): ?>
                        <tr>
                            <td><?= $a['data'] === $hoje ? 'Hoje' : 'Amanhã' ?></td>
                            <td><?= formatarHora($a['hora_inicio']) ?> - <?= formatarHora($a['hora_fim']) ?></td>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars($a['cliente_nome']) ?></div>
                                <div class="cell-sub"><?= htmlspecialchars($a['cliente_telefone']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($a['servico_nome']) ?></td>
                            <td><span class="badge <?= classeStatus($a['status']) ?>"><?= STATUS_LABELS[$a['status']] ?></span></td>
                            <td>
                                <div class="status-actions">
                                    <?php if ($a['status'] === 'agendado'): ?>
                                        <button class="btn btn-outline btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'confirmado')">Confirmar</button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'concluido')">Concluir</button>
                                    <button class="btn btn-danger btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'cancelado')">Cancelar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<script>
async function mudarStatus(id, status) {
    const rotulos = {
        confirmado: 'confirmar',
        concluido: 'marcar como concluído',
        nao_compareceu: 'marcar como não compareceu',
        cancelado: 'cancelar',
    };

    if (!confirmarAcao('Deseja ' + (rotulos[status] || 'alterar') + ' este agendamento?')) {
        return;
    }

    try {
        const resultado = await chamarApi('api/agendamento_status.php', { id, status });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
