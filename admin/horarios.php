<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$dataSelecionada = trim($_GET['data'] ?? '');
$somenteFuturos = !$dataSelecionada;

if ($dataSelecionada && strtotime($dataSelecionada) === false) {
    $dataSelecionada = '';
    $somenteFuturos = true;
}

if ($dataSelecionada) {
    $stmt = $pdo->prepare(
        "SELECT * FROM horarios_bloqueados WHERE data = :data ORDER BY hora_inicio ASC"
    );
    $stmt->execute([':data' => $dataSelecionada]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM horarios_bloqueados WHERE data >= :hoje ORDER BY data ASC, hora_inicio ASC"
    );
    $stmt->execute([':hoje' => date('Y-m-d')]);
}

$bloqueios = $stmt->fetchAll();

$tituloPagina = 'Horários bloqueados';
$paginaAtiva  = 'horarios';
require 'includes/cabecalho.php';
?>

<div class="filter-bar">

    <button class="btn btn-primary" onclick="abrirNovoBloqueio()">+ Bloquear horário</button>

    <form method="GET" class="field">
        <label for="data">Filtrar por data</label>
        <input type="date" id="data" name="data" value="<?= htmlspecialchars($dataSelecionada) ?>" onchange="this.form.submit()">
    </form>

    <?php if ($dataSelecionada): ?>
        <a href="horarios.php" class="btn btn-outline">Ver todos (a partir de hoje)</a>
    <?php endif; ?>

</div>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2><?= $dataSelecionada ? formatarData($dataSelecionada) : 'Próximos bloqueios' ?></h2>
            <p><?= count($bloqueios) ?> bloqueio(s)</p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$bloqueios): ?>

            <div class="empty-state">Nenhum horário bloqueado.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <?php if (!$dataSelecionada): ?><th>Data</th><?php endif; ?>
                        <th>Horário</th>
                        <th>Motivo</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bloqueios as $b): ?>
                        <tr>
                            <?php if (!$dataSelecionada): ?><td><?= formatarData($b['data']) ?></td><?php endif; ?>
                            <td><?= formatarHora($b['hora_inicio']) ?> - <?= formatarHora($b['hora_fim']) ?></td>
                            <td><?= $b['motivo'] ? htmlspecialchars($b['motivo']) : '—' ?></td>
                            <td>
                                <button class="btn btn-danger btn-sm" onclick="removerBloqueio(<?= $b['id'] ?>)">Remover</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<!-- Modal: Novo bloqueio -->
<div class="modal-overlay" id="modalBloqueio">
    <div class="modal">

        <div class="modal-header">
            <h3>Bloquear horário</h3>
            <button class="modal-close" data-fechar-modal>&times;</button>
        </div>

        <div class="modal-body">

            <div class="modal-alert" id="bloqueioAlerta"></div>

            <div class="field">
                <label for="bloqueioData">Data</label>
                <input type="date" id="bloqueioData">
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="bloqueioInicio">Início</label>
                    <input type="time" id="bloqueioInicio">
                </div>
                <div class="field">
                    <label for="bloqueioFim">Fim</label>
                    <input type="time" id="bloqueioFim">
                </div>
            </div>

            <div class="field">
                <label for="bloqueioMotivo">Motivo (opcional)</label>
                <input type="text" id="bloqueioMotivo" placeholder="Ex: Almoço estendido, compromisso pessoal...">
            </div>

        </div>

        <div class="modal-footer">
            <button class="btn btn-outline" data-fechar-modal>Cancelar</button>
            <button class="btn btn-primary" onclick="salvarBloqueio()">Bloquear</button>
        </div>

    </div>
</div>

<script>
function abrirNovoBloqueio() {
    document.getElementById('bloqueioData').value = '<?= $dataSelecionada ?: date('Y-m-d') ?>';
    document.getElementById('bloqueioInicio').value = '';
    document.getElementById('bloqueioFim').value = '';
    document.getElementById('bloqueioMotivo').value = '';
    document.getElementById('bloqueioAlerta').className = 'modal-alert';
    abrirModal('modalBloqueio');
}

async function salvarBloqueio() {
    const alerta = document.getElementById('bloqueioAlerta');

    const dados = {
        data: document.getElementById('bloqueioData').value,
        hora_inicio: document.getElementById('bloqueioInicio').value,
        hora_fim: document.getElementById('bloqueioFim').value,
        motivo: document.getElementById('bloqueioMotivo').value.trim(),
    };

    try {
        const resultado = await chamarApi('api/horario_bloqueado_salvar.php', dados);
        mostrarToast(resultado.mensagem, 'success');
        fecharModal('modalBloqueio');
        setTimeout(() => window.location.reload(), 1100);
    } catch (erro) {
        alerta.textContent = erro.message;
        alerta.className = 'modal-alert show';
    }
}

async function removerBloqueio(id) {
    if (!confirmarAcao('Deseja remover este bloqueio?')) {
        return;
    }

    try {
        const resultado = await chamarApi('api/horario_bloqueado_remover.php', { id });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
