<?php

require_once '../config/database.php';
require_once 'includes/verifica_cliente.php';
require_once 'includes/funcoes.php';

$clienteId = (int) $_SESSION['cliente_id'];
$hoje      = date('Y-m-d');
$agora     = time();

$stmt = $pdo->prepare("SELECT blacklist FROM clientes WHERE id = :id");
$stmt->execute([':id' => $clienteId]);
$cliente   = $stmt->fetch();
$bloqueada = $cliente && (bool) $cliente['blacklist'];

// Próximos atendimentos (ativos, a partir de hoje)
$stmt = $pdo->prepare(
    "SELECT a.id, a.servico_id, a.data, a.hora_inicio, a.hora_fim, a.status,
            s.nome AS servico_nome, s.preco
     FROM agendamentos a
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.cliente_id = :cliente_id
       AND a.status IN ('agendado', 'confirmado')
       AND a.data >= :hoje
     ORDER BY a.data ASC, a.hora_inicio ASC"
);
$stmt->execute([':cliente_id' => $clienteId, ':hoje' => $hoje]);
$proximos = $stmt->fetchAll();

// Histórico: concluídos, cancelados, faltas e qualquer coisa que não esteja nos próximos
$stmt = $pdo->prepare(
    "SELECT a.id, a.data, a.hora_inicio, a.hora_fim, a.status,
            s.nome AS servico_nome, s.preco
     FROM agendamentos a
     JOIN servicos s ON s.id = a.servico_id
     WHERE a.cliente_id = :cliente_id
       AND NOT (a.status IN ('agendado', 'confirmado') AND a.data >= :hoje)
     ORDER BY a.data DESC, a.hora_inicio DESC
     LIMIT 30"
);
$stmt->execute([':cliente_id' => $clienteId, ':hoje' => $hoje]);
$historico = $stmt->fetchAll();

$tituloPagina = 'Meus agendamentos';
$paginaAtiva  = 'meus-agendamentos';
require 'includes/cabecalho.php';
?>

<?php if ($bloqueada): ?>

    <div class="alert-inline">
        &#9888;
        <span>Sua conta está bloqueada para novos agendamentos e remarcações. Você ainda pode cancelar. Para regularizar, entre em contato com o salão.</span>
    </div>

<?php endif; ?>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Próximos atendimentos</h2>
            <p>Você pode cancelar ou remarcar com até <?= LIMITE_ALTERACAO_HORAS ?>h de antecedência. Depois disso, fale diretamente com a manicure.</p>
        </div>
        <a href="agendar.php" class="btn btn-primary">+ Agendar horário</a>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$proximos): ?>

            <div class="empty-state">
                Você não tem nenhum atendimento marcado.<br>
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
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proximos as $a):
                        $inicio  = strtotime($a['data'] . ' ' . $a['hora_inicio']);
                        $iniciou = $inicio <= $agora;
                        $pode    = podeAlterarAgendamento($a['data'], $a['hora_inicio']);
                    ?>
                        <tr>
                            <td><?= $a['data'] === $hoje ? 'Hoje' : formatarData($a['data']) ?></td>
                            <td><?= formatarHora($a['hora_inicio']) ?> - <?= formatarHora($a['hora_fim']) ?></td>
                            <td><?= htmlspecialchars($a['servico_nome']) ?></td>
                            <td><?= formatarMoeda((float) $a['preco']) ?></td>
                            <td><span class="badge <?= classeStatus($a['status']) ?>"><?= STATUS_LABELS[$a['status']] ?></span></td>
                            <td>
                                <?php if ($pode): ?>

                                    <div class="cell-actions">
                                        <button
                                            type="button"
                                            class="btn btn-outline btn-sm"
                                            data-id="<?= (int) $a['id'] ?>"
                                            data-servico-id="<?= (int) $a['servico_id'] ?>"
                                            data-servico="<?= htmlspecialchars($a['servico_nome'], ENT_QUOTES) ?>"
                                            data-data="<?= $a['data'] ?>"
                                            data-hora="<?= formatarHora($a['hora_inicio']) ?>"
                                            onclick="abrirRemarcar(this)"
                                            <?= $bloqueada ? 'disabled title="Conta bloqueada para remarcações"' : '' ?>
                                        >Remarcar</button>

                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-id="<?= (int) $a['id'] ?>"
                                            data-resumo="<?= htmlspecialchars($a['servico_nome'] . ' — ' . formatarData($a['data']) . ' às ' . formatarHora($a['hora_inicio']), ENT_QUOTES) ?>"
                                            onclick="cancelarAgendamento(this)"
                                        >Cancelar</button>
                                    </div>

                                <?php elseif ($iniciou): ?>

                                    <span class="cell-sub">Atendimento de hoje</span>

                                <?php else: ?>

                                    <span class="cell-sub">Menos de <?= LIMITE_ALTERACAO_HORAS ?>h para o horário.<br>Fale diretamente com a manicure.</span>

                                <?php endif; ?>
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
            <h2>Histórico</h2>
            <p>Seus últimos atendimentos</p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$historico): ?>

            <div class="empty-state">Você ainda não tem atendimentos no histórico.</div>

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
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= formatarData($h['data']) ?></td>
                            <td><?= formatarHora($h['hora_inicio']) ?> - <?= formatarHora($h['hora_fim']) ?></td>
                            <td><?= htmlspecialchars($h['servico_nome']) ?></td>
                            <td><?= formatarMoeda((float) $h['preco']) ?></td>
                            <td><span class="badge <?= classeStatus($h['status']) ?>"><?= STATUS_LABELS[$h['status']] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<!-- MODAL: REMARCAR -->
<div class="modal-overlay" id="modalRemarcar">
    <div class="modal">

        <div class="modal-header">
            <h3>Remarcar atendimento</h3>
            <button type="button" class="modal-close" data-fechar-modal>&times;</button>
        </div>

        <div class="modal-body">

            <div class="modal-alert" id="remarcarAlerta"></div>

            <p id="remarcarResumo" class="cell-sub"></p>

            <div class="field">
                <label for="remarcarData">Nova data</label>
                <input type="date" id="remarcarData" min="<?= $hoje ?>">
            </div>

            <div>
                <label class="cell-sub" style="display:block;margin-bottom:8px;">Horários livres</label>
                <div class="slots-grid" id="remarcarHorarios">
                    <div class="empty-state" style="grid-column:1/-1;padding:20px 0;">Escolha uma data.</div>
                </div>
            </div>

        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-outline" data-fechar-modal>Voltar</button>
            <button type="button" class="btn btn-primary" id="btnConfirmarRemarcacao" onclick="confirmarRemarcacao()" disabled>
                Confirmar remarcação
            </button>
        </div>

    </div>
</div>

<script>
    let remarcacao = null;
    let novoHorario = null;

    function abrirRemarcar(el) {
        remarcacao = {
            id: el.dataset.id,
            servicoId: el.dataset.servicoId,
            servico: el.dataset.servico,
        };
        novoHorario = null;

        const [ano, mes, dia] = el.dataset.data.split('-');
        document.getElementById('remarcarResumo').textContent =
            remarcacao.servico + ' — atualmente em ' + dia + '/' + mes + '/' + ano + ' às ' + el.dataset.hora + '.';

        document.getElementById('remarcarAlerta').className = 'modal-alert';
        document.getElementById('remarcarData').value = el.dataset.data;
        document.getElementById('btnConfirmarRemarcacao').disabled = true;

        abrirModal('modalRemarcar');
        buscarHorariosRemarcar();
    }

    document.getElementById('remarcarData').addEventListener('change', () => {
        novoHorario = null;
        buscarHorariosRemarcar();
    });

    async function buscarHorariosRemarcar() {
        const grade = document.getElementById('remarcarHorarios');
        const data = document.getElementById('remarcarData').value;

        document.getElementById('btnConfirmarRemarcacao').disabled = true;

        if (!remarcacao || !data) {
            grade.innerHTML = '<div class="empty-state" style="grid-column:1/-1;padding:20px 0;">Escolha uma data.</div>';
            return;
        }

        grade.innerHTML = '<div class="empty-state" style="grid-column:1/-1;padding:20px 0;">Buscando horários livres...</div>';

        try {
            const resultado = await chamarApi('api/horarios_disponiveis.php', {
                servico_id: remarcacao.servicoId,
                data: data,
                agendamento_id: remarcacao.id,
            });

            if (!resultado.horarios.length) {
                grade.innerHTML = '<div class="empty-state" style="grid-column:1/-1;padding:20px 0;">Nenhum horário livre nesta data. Tente outro dia.</div>';
                return;
            }

            grade.innerHTML = '';
            resultado.horarios.forEach(hora => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'slot-btn';
                btn.textContent = hora;
                btn.onclick = () => {
                    document.querySelectorAll('#remarcarHorarios .slot-btn').forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    novoHorario = hora;
                    document.getElementById('btnConfirmarRemarcacao').disabled = false;
                };
                grade.appendChild(btn);
            });

        } catch (erro) {
            grade.innerHTML = '<div class="empty-state" style="grid-column:1/-1;padding:20px 0;"></div>';
            grade.firstChild.textContent = erro.message;
        }
    }

    async function confirmarRemarcacao() {
        const alerta = document.getElementById('remarcarAlerta');
        alerta.className = 'modal-alert';

        try {
            const resultado = await chamarApi('api/agendamento_remarcar.php', {
                id: remarcacao.id,
                data: document.getElementById('remarcarData').value,
                hora_inicio: novoHorario,
            });

            fecharModal('modalRemarcar');
            mostrarToast(resultado.mensagem, 'success');
            setTimeout(() => window.location.reload(), 1200);

        } catch (erro) {
            alerta.textContent = erro.message;
            alerta.className = 'modal-alert show';
        }
    }

    async function cancelarAgendamento(el) {
        if (!confirmarAcao('Cancelar ' + el.dataset.resumo + '?')) {
            return;
        }

        try {
            const resultado = await chamarApi('api/agendamento_cancelar.php', { id: el.dataset.id });

            mostrarToast(resultado.mensagem, 'success');
            setTimeout(() => window.location.reload(), 1200);

        } catch (erro) {
            mostrarToast(erro.message, 'error');
        }
    }
</script>

<?php require 'includes/rodape.php'; ?>
