<?php

require_once '../config/database.php';
require_once 'includes/verifica_cliente.php';
require_once 'includes/funcoes.php';

$clienteId = (int) $_SESSION['cliente_id'];

$stmt = $pdo->prepare("SELECT blacklist FROM clientes WHERE id = :id");
$stmt->execute([':id' => $clienteId]);
$cliente = $stmt->fetch();
$bloqueada = $cliente && (bool) $cliente['blacklist'];

$stmt = $pdo->query("SELECT id, nome, descricao, preco, duracao FROM servicos WHERE ativo = 1 ORDER BY nome ASC");
$servicos = $stmt->fetchAll();

$tituloPagina = 'Agendar horário';
$paginaAtiva  = 'agendar';
require 'includes/cabecalho.php';
?>

<?php if ($bloqueada): ?>

    <div class="alert-inline">
        &#9888;
        <span>Sua conta está temporariamente bloqueada para novos agendamentos. Entre em contato com o salão para regularizar.</span>
    </div>

<?php elseif (!$servicos): ?>

    <div class="panel">
        <div class="panel-body">
            <div class="empty-state">Nenhum serviço disponível para agendamento no momento.</div>
        </div>
    </div>

<?php else: ?>

    <div class="steps">
        <div class="step active" id="stepServico"><span class="step-number">1</span> Serviço</div>
        <div class="step" id="stepData"><span class="step-number">2</span> Data e horário</div>
        <div class="step" id="stepConfirmar"><span class="step-number">3</span> Confirmar</div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Escolha o serviço</h2>
                <p>Selecione o que você quer agendar</p>
            </div>
        </div>
        <div class="panel-body">

            <div class="service-grid">
                <?php foreach ($servicos as $s): ?>
                    <button
                        type="button"
                        class="service-card"
                        data-id="<?= $s['id'] ?>"
                        data-nome="<?= htmlspecialchars($s['nome'], ENT_QUOTES) ?>"
                        data-duracao="<?= (int) $s['duracao'] ?>"
                        onclick="selecionarServico(this)"
                    >
                        <div class="service-name"><?= htmlspecialchars($s['nome']) ?></div>
                        <?php if ($s['descricao']): ?>
                            <div class="cell-sub"><?= htmlspecialchars($s['descricao']) ?></div>
                        <?php endif; ?>
                        <div class="service-meta">
                            <span class="service-duration"><?= (int) $s['duracao'] ?> min</span>
                            <span class="service-price"><?= formatarMoeda((float) $s['preco']) ?></span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Escolha a data e o horário</h2>
                <p>Horários livres, considerando o expediente e a agenda atual</p>
            </div>
        </div>
        <div class="panel-body">

            <div class="field" style="max-width:220px;margin-bottom:18px;">
                <label for="dataAgendamento">Data</label>
                <input type="date" id="dataAgendamento" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="slots-grid" id="gradeHorarios">
                <div class="empty-state">Escolha um serviço para ver os horários livres.</div>
            </div>

        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Confirmar agendamento</h2>
                <p id="resumoAgendamento">Selecione o serviço, a data e o horário acima.</p>
            </div>
        </div>
        <div class="panel-body">

            <div class="modal-alert" id="confirmarAlerta"></div>

            <div class="field" style="margin-bottom:16px;">
                <label for="observacoes">Observações (opcional)</label>
                <textarea id="observacoes" rows="3" placeholder="Alguma preferência ou informação para a manicure?"></textarea>
            </div>

            <button type="button" class="btn btn-primary" id="btnConfirmar" onclick="confirmarAgendamento()" disabled>
                Confirmar agendamento
            </button>

        </div>
    </div>

    <script>
        let servicoSelecionado = null;
        let horarioSelecionado = null;

        function selecionarServico(el) {
            document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');

            servicoSelecionado = {
                id: el.dataset.id,
                nome: el.dataset.nome,
                duracao: el.dataset.duracao,
            };
            horarioSelecionado = null;

            document.getElementById('stepServico').classList.add('done');
            document.getElementById('stepData').classList.add('active');

            atualizarResumo();
            buscarHorarios();
        }

        document.getElementById('dataAgendamento').addEventListener('change', () => {
            horarioSelecionado = null;
            buscarHorarios();
        });

        async function buscarHorarios() {
            const grade = document.getElementById('gradeHorarios');
            const data = document.getElementById('dataAgendamento').value;

            atualizarResumo();
            atualizarBotaoConfirmar();

            if (!servicoSelecionado || !data) {
                grade.innerHTML = '<div class="empty-state">Escolha um serviço para ver os horários livres.</div>';
                return;
            }

            grade.innerHTML = '<div class="empty-state">Buscando horários livres...</div>';

            try {
                const resultado = await chamarApi('api/horarios_disponiveis.php', {
                    servico_id: servicoSelecionado.id,
                    data: data,
                });

                if (!resultado.horarios.length) {
                    grade.innerHTML = '<div class="empty-state">Nenhum horário livre nesta data. Tente outro dia.</div>';
                    return;
                }

                grade.innerHTML = '';
                resultado.horarios.forEach(hora => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'slot-btn';
                    btn.textContent = hora;
                    btn.onclick = () => selecionarHorario(btn, hora);
                    grade.appendChild(btn);
                });

            } catch (erro) {
                grade.innerHTML = '<div class="empty-state">' + erro.message + '</div>';
            }
        }

        function selecionarHorario(el, hora) {
            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            el.classList.add('selected');
            horarioSelecionado = hora;

            document.getElementById('stepData').classList.add('done');
            document.getElementById('stepConfirmar').classList.add('active');

            atualizarResumo();
            atualizarBotaoConfirmar();
        }

        function atualizarResumo() {
            const data = document.getElementById('dataAgendamento').value;
            const resumo = document.getElementById('resumoAgendamento');

            if (servicoSelecionado && data && horarioSelecionado) {
                const [ano, mes, dia] = data.split('-');
                resumo.textContent = servicoSelecionado.nome + ' — ' + dia + '/' + mes + '/' + ano + ' às ' + horarioSelecionado + '.';
            } else if (servicoSelecionado) {
                resumo.textContent = servicoSelecionado.nome + ' — escolha a data e o horário acima.';
            } else {
                resumo.textContent = 'Selecione o serviço, a data e o horário acima.';
            }
        }

        function atualizarBotaoConfirmar() {
            document.getElementById('btnConfirmar').disabled =
                !(servicoSelecionado && horarioSelecionado && document.getElementById('dataAgendamento').value);
        }

        async function confirmarAgendamento() {
            const alerta = document.getElementById('confirmarAlerta');
            alerta.className = 'modal-alert';

            try {
                const resultado = await chamarApi('api/agendamento_criar.php', {
                    servico_id: servicoSelecionado.id,
                    data: document.getElementById('dataAgendamento').value,
                    hora_inicio: horarioSelecionado,
                    observacoes: document.getElementById('observacoes').value,
                });

                mostrarToast(resultado.mensagem, 'success');
                document.getElementById('btnConfirmar').disabled = true;
                setTimeout(() => window.location.href = 'painel.php', 1200);

            } catch (erro) {
                alerta.textContent = erro.message;
                alerta.className = 'modal-alert show';
            }
        }
    </script>

<?php endif; ?>

<?php require 'includes/rodape.php'; ?>
