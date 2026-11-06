<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$stmt = $pdo->query("SELECT * FROM servicos ORDER BY ativo DESC, nome ASC");
$servicos = $stmt->fetchAll();

$tituloPagina = 'Serviços';
$paginaAtiva  = 'servicos';
require 'includes/cabecalho.php';
?>

<div class="filter-bar">
    <button class="btn btn-primary" onclick="abrirNovoServico()">+ Novo serviço</button>
</div>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Serviços cadastrados</h2>
            <p><?= count($servicos) ?> serviço(s)</p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$servicos): ?>

            <div class="empty-state">Nenhum serviço cadastrado ainda.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Serviço</th>
                        <th>Preço</th>
                        <th>Duração</th>
                        <th>Situação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servicos as $s): ?>
                        <tr>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars($s['nome']) ?></div>
                                <div class="cell-sub"><?= htmlspecialchars($s['descricao'] ?? '') ?></div>
                            </td>
                            <td><?= formatarMoeda((float) $s['preco']) ?></td>
                            <td><?= (int) $s['duracao'] ?> min</td>
                            <td>
                                <?php if ($s['ativo']): ?>
                                    <span class="badge badge-ativo">Ativo</span>
                                <?php else: ?>
                                    <span class="badge badge-inativo">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="cell-actions">
                                    <button
                                        class="btn btn-outline btn-sm"
                                        onclick='abrirEditarServico(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'
                                    >Editar</button>
                                    <button
                                        class="btn <?= $s['ativo'] ? 'btn-danger' : 'btn-outline' ?> btn-sm"
                                        onclick="alternarServico(<?= $s['id'] ?>, '<?= htmlspecialchars($s['nome'], ENT_QUOTES) ?>')"
                                    ><?= $s['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<!-- Modal: Novo / Editar serviço -->
<div class="modal-overlay" id="modalServico">
    <div class="modal">

        <div class="modal-header">
            <h3 id="modalServicoTitulo">Novo serviço</h3>
            <button class="modal-close" data-fechar-modal>&times;</button>
        </div>

        <div class="modal-body">

            <div class="modal-alert" id="servicoAlerta"></div>

            <input type="hidden" id="servicoId">

            <div class="field">
                <label for="servicoNome">Nome do serviço</label>
                <input type="text" id="servicoNome" placeholder="Ex: Manicure Simples">
            </div>

            <div class="field">
                <label for="servicoDescricao">Descrição</label>
                <textarea id="servicoDescricao" rows="2" placeholder="Ex: Cuticulagem e esmaltação"></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="servicoPreco">Preço (R$)</label>
                    <input type="text" id="servicoPreco" placeholder="Ex: 40.00">
                </div>
                <div class="field">
                    <label for="servicoDuracao">Duração (minutos)</label>
                    <input type="number" id="servicoDuracao" min="1" placeholder="Ex: 60">
                </div>
            </div>

            <label class="checkbox-field">
                <input type="checkbox" id="servicoAtivo" checked>
                Serviço ativo (visível para as clientes)
            </label>

        </div>

        <div class="modal-footer">
            <button class="btn btn-outline" data-fechar-modal>Cancelar</button>
            <button class="btn btn-primary" onclick="salvarServico()">Salvar</button>
        </div>

    </div>
</div>

<script>
function abrirNovoServico() {
    document.getElementById('modalServicoTitulo').textContent = 'Novo serviço';
    document.getElementById('servicoId').value = '';
    document.getElementById('servicoNome').value = '';
    document.getElementById('servicoDescricao').value = '';
    document.getElementById('servicoPreco').value = '';
    document.getElementById('servicoDuracao').value = '';
    document.getElementById('servicoAtivo').checked = true;
    document.getElementById('servicoAlerta').className = 'modal-alert';
    abrirModal('modalServico');
}

function abrirEditarServico(servico) {
    document.getElementById('modalServicoTitulo').textContent = 'Editar serviço';
    document.getElementById('servicoId').value = servico.id;
    document.getElementById('servicoNome').value = servico.nome;
    document.getElementById('servicoDescricao').value = servico.descricao || '';
    document.getElementById('servicoPreco').value = servico.preco;
    document.getElementById('servicoDuracao').value = servico.duracao;
    document.getElementById('servicoAtivo').checked = Number(servico.ativo) === 1;
    document.getElementById('servicoAlerta').className = 'modal-alert';
    abrirModal('modalServico');
}

async function salvarServico() {
    const alerta = document.getElementById('servicoAlerta');

    const dados = {
        id: document.getElementById('servicoId').value,
        nome: document.getElementById('servicoNome').value.trim(),
        descricao: document.getElementById('servicoDescricao').value.trim(),
        preco: document.getElementById('servicoPreco').value.trim(),
        duracao: document.getElementById('servicoDuracao').value,
        ativo: document.getElementById('servicoAtivo').checked ? 1 : '',
    };

    try {
        const resultado = await chamarApi('api/servico_salvar.php', dados);
        mostrarToast(resultado.mensagem, 'success');
        fecharModal('modalServico');
        setTimeout(() => window.location.reload(), 900);
    } catch (erro) {
        alerta.textContent = erro.message;
        alerta.className = 'modal-alert show';
    }
}

async function alternarServico(id, nome) {
    if (!confirmarAcao('Deseja alterar a situação de "' + nome + '"?')) {
        return;
    }

    try {
        const resultado = await chamarApi('api/servico_toggle.php', { id });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
