<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$dataSelecionada = trim($_GET['data'] ?? date('Y-m-d'));
if (strtotime($dataSelecionada) === false) {
    $dataSelecionada = date('Y-m-d');
}

$diaAnterior = date('Y-m-d', strtotime($dataSelecionada . ' -1 day'));
$diaSeguinte = date('Y-m-d', strtotime($dataSelecionada . ' +1 day'));

$stmt = $pdo->prepare(
    "SELECT a.id, a.hora_inicio, a.hora_fim, a.status, a.observacoes,
            c.id AS cliente_id, c.nome AS cliente_nome, c.telefone AS cliente_telefone, c.blacklist,
            s.nome AS servico_nome, s.duracao
     FROM agendamentos a
     JOIN clientes c ON c.id = a.cliente_id
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.data = :data
     ORDER BY a.hora_inicio ASC"
);
$stmt->execute([':data' => $dataSelecionada]);
$agendamentos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT id, hora_inicio, hora_fim, motivo
     FROM horarios_bloqueados
     WHERE data = :data
     ORDER BY hora_inicio ASC"
);
$stmt->execute([':data' => $dataSelecionada]);
$bloqueios = $stmt->fetchAll();

$tituloPagina = 'Agenda';
$paginaAtiva  = 'agenda';
require 'includes/cabecalho.php';
?>

<div class="filter-bar">

    <a href="?data=<?= $diaAnterior ?>" class="btn btn-outline btn-icon" title="Dia anterior">&#8592;</a>

    <form method="GET" class="field">
        <label for="data">Data</label>
        <input type="date" id="data" name="data" value="<?= htmlspecialchars($dataSelecionada) ?>" onchange="this.form.submit()">
    </form>

    <a href="?data=<?= $diaSeguinte ?>" class="btn btn-outline btn-icon" title="Próximo dia">&#8594;</a>

    <a href="?data=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm">Hoje</a>

</div>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2><?= formatarData($dataSelecionada) ?></h2>
            <p>Expediente <?= formatarHora(EXPEDIENTE_INICIO) ?> às <?= formatarHora(EXPEDIENTE_FIM) ?> · intervalo <?= formatarHora(INTERVALO_INICIO) ?> às <?= formatarHora(INTERVALO_FIM) ?></p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$agendamentos): ?>

            <div class="empty-state">Nenhum agendamento nesta data.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Horário</th>
                        <th>Cliente</th>
                        <th>Serviço</th>
                        <th>Status</th>
                        <th>Observações</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendamentos as $a): ?>
                        <tr>
                            <td><?= formatarHora($a['hora_inicio']) ?> - <?= formatarHora($a['hora_fim']) ?></td>
                            <td>
                                <div class="cell-title">
                                    <?= htmlspecialchars($a['cliente_nome']) ?>
                                    <?php if ($a['blacklist']): ?>
                                        <span class="badge badge-blacklist">Bloqueada</span>
                                    <?php endif; ?>
                                </div>
                                <div class="cell-sub"><?= htmlspecialchars($a['cliente_telefone']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($a['servico_nome']) ?></td>
                            <td><span class="badge <?= classeStatus($a['status']) ?>"><?= STATUS_LABELS[$a['status']] ?></span></td>
                            <td class="cell-sub"><?= $a['observacoes'] ? htmlspecialchars($a['observacoes']) : '—' ?></td>
                            <td>
                                <div class="status-actions">

                                    <?php if (in_array($a['status'], ['agendado', 'confirmado'], true)): ?>

                                        <?php if ($a['status'] === 'agendado'): ?>
                                            <button class="btn btn-outline btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'confirmado')">Confirmar</button>
                                        <?php endif; ?>

                                        <button class="btn btn-outline btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'concluido')">Concluir</button>
                                        <button class="btn btn-outline btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'nao_compareceu')">Não compareceu</button>
                                        <button class="btn btn-outline btn-sm" onclick="abrirReagendar(<?= $a['id'] ?>, '<?= $dataSelecionada ?>', '<?= substr($a['hora_inicio'], 0, 5) ?>', '<?= htmlspecialchars($a['cliente_nome'], ENT_QUOTES) ?>')">Remarcar</button>
                                        <button class="btn btn-danger btn-sm" onclick="mudarStatus(<?= $a['id'] ?>, 'cancelado')">Cancelar</button>

                                    <?php else: ?>
                                        <span class="cell-sub">Sem ações disponíveis</span>
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

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Horários bloqueados neste dia</h2>
            <p>Bloqueios manuais de agenda</p>
        </div>
        <a href="horarios.php?data=<?= $dataSelecionada ?>" class="btn btn-outline btn-sm">Gerenciar bloqueios</a>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$bloqueios): ?>
            <div class="empty-state">Nenhum horário bloqueado nesta data.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Horário</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bloqueios as $b): ?>
                        <tr>
                            <td><?= formatarHora($b['hora_inicio']) ?> - <?= formatarHora($b['hora_fim']) ?></td>
                            <td><?= $b['motivo'] ? htmlspecialchars($b['motivo']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</div>

<!-- Modal: Remarcar agendamento -->
<div class="modal-overlay" id="modalReagendar">
    <div class="modal">

        <div class="modal-header">
            <h3>Remarcar agendamento</h3>
            <button class="modal-close" data-fechar-modal>&times;</button>
        </div>

        <div class="modal-body">

            <div class="modal-alert" id="reagendarAlerta"></div>

            <p class="cell-sub" id="reagendarClienteNome"></p>

            <div class="field">
                <label for="reagendarData">Nova data</label>
                <input type="date" id="reagendarData">
            </div>

            <div class="field">
                <label for="reagendarHora">Novo horário de início</label>
                <input type="time" id="reagendarHora">
            </div>

        </div>

        <div class="modal-footer">
            <button class="btn btn-outline" data-fechar-modal>Cancelar</button>
            <button class="btn btn-primary" onclick="salvarReagendamento()">Salvar</button>
        </div>

    </div>
</div>

<script>
let agendamentoAtualId = null;

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

function abrirReagendar(id, dataAtual, horaAtual, clienteNome) {
    agendamentoAtualId = id;
    document.getElementById('reagendarClienteNome').textContent = clienteNome;
    document.getElementById('reagendarData').value = dataAtual;
    document.getElementById('reagendarHora').value = horaAtual;
    document.getElementById('reagendarAlerta').className = 'modal-alert';
    abrirModal('modalReagendar');
}

async function salvarReagendamento() {
    const alerta = document.getElementById('reagendarAlerta');
    const data = document.getElementById('reagendarData').value;
    const hora = document.getElementById('reagendarHora').value;

    if (!data || !hora) {
        alerta.textContent = 'Preencha a nova data e o novo horário.';
        alerta.className = 'modal-alert show';
        return;
    }

    try {
        const resultado = await chamarApi('api/agendamento_reagendar.php', {
            id: agendamentoAtualId,
            data: data,
            hora_inicio: hora,
        });
        mostrarToast(resultado.mensagem, 'success');
        fecharModal('modalReagendar');
        setTimeout(() => window.location.href = '?data=' + data, 900);
    } catch (erro) {
        alerta.textContent = erro.message;
        alerta.className = 'modal-alert show';
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
