<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';
require_once '../lib/notificacoes.php';

$config = notificacoesConfig();

const NOTIF_STATUS_LABELS = [
    'pendente'  => 'Pendente',
    'enviando'  => 'Enviando',
    'enviado'   => 'Enviado',
    'entregue'  => 'Entregue',
    'lido'      => 'Lido',
    'falhou'    => 'Falhou',
    'cancelado' => 'Descartado',
];

const NOTIF_STATUS_CLASSES = [
    'pendente'  => 'status-confirmado',
    'enviando'  => 'status-agendado',
    'enviado'   => 'status-agendado',
    'entregue'  => 'status-concluido',
    'lido'      => 'status-concluido',
    'falhou'    => 'status-faltou',
    'cancelado' => 'status-cancelado',
];

const NOTIF_TIPOS = [
    'confirmacao'          => 'Confirmação',
    'lembrete_24h'         => 'Lembrete 24h',
    'lembrete_2h'          => 'Lembrete 2h',
    'aviso_manicure'       => 'Aviso à manicure',
    'confirmacao_presenca' => 'Presença confirmada (resposta)',
    'orientacao'           => 'Orientação (resposta)',
    'teste'                => 'Teste',
];

$filtro = $_GET['status'] ?? '';
if ($filtro !== '' && !array_key_exists($filtro, NOTIF_STATUS_LABELS)) {
    $filtro = '';
}

// Indicadores
$desde24h = date('Y-m-d H:i:s', time() - 86400);
$desde7d  = date('Y-m-d H:i:s', time() - 7 * 86400);

$contar = static function (string $where, array $params) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

$totalEnviadas24h = $contar("status IN ('enviado','entregue','lido') AND enviado_em >= :d", [':d' => $desde24h]);
$totalEntregues24h = $contar("status IN ('entregue','lido') AND enviado_em >= :d", [':d' => $desde24h]);
$totalPendentes   = $contar("status IN ('pendente','enviando')", []);
$totalFalhas7d    = $contar("status = 'falhou' AND criado_em >= :d", [':d' => $desde7d]);

// Lista
$sql = "SELECT n.*, c.nome AS cliente_nome
        FROM notificacoes n
        LEFT JOIN agendamentos a ON a.id = n.agendamento_id
        LEFT JOIN clientes c ON c.id = a.cliente_id";
$params = [];

if ($filtro !== '') {
    $sql .= ' WHERE n.status = :status';
    $params[':status'] = $filtro;
}

$stmt = $pdo->prepare($sql . ' ORDER BY n.id DESC LIMIT 100');
$stmt->execute($params);
$notificacoes = $stmt->fetchAll();

$simulado    = notificacoesModoSimulado($config);
$provedor    = $config['sms']['provedor'] ?? 'não configurado';
$webhookOn   = ($config['notificacoes']['webhook_token'] ?? '') !== '';
$manicureTel = notificacoesTelefoneManicure($config);

$tituloPagina = 'Notificações';
$paginaAtiva  = 'notificacoes';
require 'includes/cabecalho.php';
?>

<?php if ($simulado): ?>
    <div class="alert-inline">
        <strong>Modo simulado:</strong>
        nenhuma mensagem real está sendo enviada. Configure um provedor em
        <code>config/integracoes.php</code> (ou <code>integracoes.local.php</code>) para ativar o envio de verdade.
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Enviadas (24h)</div>
        <div class="stat-value"><?= $totalEnviadas24h ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Entregues (24h)</div>
        <div class="stat-value"><?= $totalEntregues24h ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Na fila</div>
        <div class="stat-value"><?= $totalPendentes ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Falhas (7 dias)</div>
        <div class="stat-value"><?= $totalFalhas7d ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Configuração atual</h2>
            <p>Definida em config/integracoes.php</p>
        </div>
    </div>
    <div class="panel-body">
        <p><strong>Envio por SMS:</strong> <?= htmlspecialchars($provedor) ?></p>
        <p><strong>Aviso à manicure:</strong> <?= $manicureTel !== '' ? htmlspecialchars($manicureTel) : 'desativado (defina manicure_telefone)' ?></p>
        <p><strong>Webhook (entrega e respostas "SIM"):</strong> <?= $webhookOn ? 'ativo' : 'desativado (defina notificacoes.webhook_token)' ?></p>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Enviar mensagem de teste</h2>
            <p>Confira se as credenciais do provedor estão funcionando</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="filter-bar" style="margin-bottom:0">
            <div class="field">
                <label for="telefoneTeste">Celular com DDD</label>
                <input type="text" id="telefoneTeste" placeholder="(11) 98765-4321" inputmode="numeric" maxlength="15">
            </div>
            <button class="btn btn-primary" id="btnTeste" onclick="enviarTeste()">Enviar teste</button>
        </div>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="field">
        <label for="status">Situação</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach (NOTIF_STATUS_LABELS as $valor => $rotulo): ?>
                <option value="<?= $valor ?>" <?= $filtro === $valor ? 'selected' : '' ?>><?= $rotulo ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($filtro !== ''): ?>
        <a href="notificacoes.php" class="btn btn-outline">Limpar</a>
    <?php endif; ?>
</form>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Histórico de notificações</h2>
            <p>Últimas <?= count($notificacoes) ?> (máximo 100)</p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$notificacoes): ?>

            <div class="empty-state">Nenhuma notificação registrada ainda.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Destinatário</th>
                        <th>Tipo</th>
                        <th>Provedor</th>
                        <th>Situação</th>
                        <th>Tentativas</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notificacoes as $n): ?>
                        <?php
                        $quando = strtotime($n['enviado_em'] ?? $n['criado_em']);
                        $nomeDest = $n['destinatario'] === 'manicure'
                            ? ($n['agendamento_id'] === null ? 'Teste' : 'Manicure')
                            : ($n['cliente_nome'] ?? 'Cliente');
                        $telExibir = preg_match('/^55(\d{2})(\d{5})(\d{4})$/', $n['telefone'], $m)
                            ? "({$m[1]}) {$m[2]}-{$m[3]}" : $n['telefone'];
                        ?>
                        <tr>
                            <td><?= date('d/m H:i', $quando) ?></td>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars($nomeDest) ?></div>
                                <div class="cell-sub"><?= htmlspecialchars($telExibir) ?></div>
                            </td>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars(NOTIF_TIPOS[$n['tipo']] ?? $n['tipo']) ?></div>
                                <div class="cell-sub" title="<?= htmlspecialchars($n['mensagem']) ?>">
                                    <?= htmlspecialchars(mb_strimwidth($n['mensagem'], 0, 48, '…', 'UTF-8')) ?>
                                </div>
                            </td>
                            <td><?= $n['provedor'] ? htmlspecialchars($n['provedor']) : '—' ?></td>
                            <td>
                                <span class="badge <?= NOTIF_STATUS_CLASSES[$n['status']] ?? '' ?>">
                                    <?= NOTIF_STATUS_LABELS[$n['status']] ?? htmlspecialchars($n['status']) ?>
                                </span>
                                <?php if (!empty($n['ultimo_erro'])): ?>
                                    <div class="cell-sub" title="<?= htmlspecialchars($n['ultimo_erro']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($n['ultimo_erro'], 0, 60, '…', 'UTF-8')) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($n['status'] === 'pendente' && $n['proxima_tentativa']): ?>
                                    <div class="cell-sub">próxima tentativa: <?= date('H:i', strtotime($n['proxima_tentativa'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $n['tentativas'] ?></td>
                            <td>
                                <div class="cell-actions">
                                    <?php if ($n['status'] === 'falhou'): ?>
                                        <button class="btn btn-outline btn-sm" onclick="reenviar(<?= (int) $n['id'] ?>)">Reenviar</button>
                                    <?php endif; ?>
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
async function enviarTeste() {
    const botao = document.getElementById('btnTeste');
    botao.disabled = true;

    try {
        const resultado = await chamarApi('api/notificacao_testar.php', {
            telefone: document.getElementById('telefoneTeste').value.trim(),
        });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 1200);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
        botao.disabled = false;
    }
}

async function reenviar(id) {
    try {
        const resultado = await chamarApi('api/notificacao_reenviar.php', { id });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 1200);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
        setTimeout(() => window.location.reload(), 2500);
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
