<?php

require_once '../config/database.php';
require_once 'includes/verifica_admin.php';
require_once 'includes/funcoes.php';

$busca = trim($_GET['busca'] ?? '');

$sql = "SELECT id, nome, telefone, email, blacklist, faltas_consecutivas, criado_em
        FROM clientes";
$params = [];

if ($busca) {
    // Telefone é gravado como "(11) 98765-4321": compara só os dígitos,
    // para achar tanto "98765" quanto "(11) 98765".
    $sql .= " WHERE nome LIKE :busca OR email LIKE :busca";
    $params[':busca'] = '%' . $busca . '%';

    $buscaDigitos = preg_replace('/\D+/', '', $busca);
    if ($buscaDigitos !== '') {
        $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), ' ', ''), '-', '') LIKE :busca_tel";
        $params[':busca_tel'] = '%' . $buscaDigitos . '%';
    }
}

$sql .= " ORDER BY nome ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();

$tituloPagina = 'Clientes';
$paginaAtiva  = 'clientes';
require 'includes/cabecalho.php';
?>

<form method="GET" class="filter-bar">
    <div class="field">
        <label for="busca">Buscar</label>
        <input
            type="text"
            id="busca"
            name="busca"
            class="search-input"
            placeholder="Nome, telefone ou e-mail"
            value="<?= htmlspecialchars($busca) ?>"
        >
    </div>
    <button type="submit" class="btn btn-outline">Buscar</button>
    <?php if ($busca): ?>
        <a href="clientes.php" class="btn btn-outline">Limpar</a>
    <?php endif; ?>
</form>

<div class="panel">

    <div class="panel-header">
        <div>
            <h2>Clientes cadastradas</h2>
            <p><?= count($clientes) ?> resultado(s)</p>
        </div>
    </div>

    <div class="panel-body no-pad">

        <?php if (!$clientes): ?>

            <div class="empty-state">Nenhuma cliente encontrada.</div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Contato</th>
                        <th>Faltas consecutivas</th>
                        <th>Situação</th>
                        <th>Cadastrada em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td class="cell-title"><?= htmlspecialchars($c['nome']) ?></td>
                            <td>
                                <div><?= htmlspecialchars($c['telefone']) ?></div>
                                <div class="cell-sub"><?= htmlspecialchars($c['email']) ?></div>
                            </td>
                            <td><?= (int) $c['faltas_consecutivas'] ?></td>
                            <td>
                                <?php if ($c['blacklist']): ?>
                                    <span class="badge badge-blacklist">Bloqueada</span>
                                <?php else: ?>
                                    <span class="badge badge-ativo">Ativa</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-sub"><?= formatarData(substr($c['criado_em'], 0, 10)) ?></td>
                            <td>
                                <div class="cell-actions">
                                    <a href="cliente-historico.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Histórico</a>
                                    <?php if ($c['blacklist']): ?>
                                        <button class="btn btn-outline btn-sm" onclick="alternarBlacklist(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>', false)">Desbloquear</button>
                                    <?php else: ?>
                                        <button class="btn btn-danger btn-sm" onclick="alternarBlacklist(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>', true)">Bloquear</button>
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
async function alternarBlacklist(id, nome, vaiBloquear) {
    const acao = vaiBloquear ? 'bloquear' : 'desbloquear';

    if (!confirmarAcao('Deseja ' + acao + ' ' + nome + '?')) {
        return;
    }

    try {
        const resultado = await chamarApi('api/cliente_blacklist.php', { id });
        mostrarToast(resultado.mensagem, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (erro) {
        mostrarToast(erro.message, 'error');
    }
}
</script>

<?php require 'includes/rodape.php'; ?>
