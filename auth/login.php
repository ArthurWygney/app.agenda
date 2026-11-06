<?php

require_once '../config/database.php';
require_once '../lib/telefone.php';

session_start();

$erro = '';
$sucesso = false;
$mensagemSucesso = '';

// Logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Processa o login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $identificador = trim($_POST['identificador'] ?? '');
        $senha         = $_POST['senha'] ?? '';

        if (!$identificador || !$senha) {
            throw new Exception('Preencha e-mail/telefone e senha.');
        }

        // 1) Tenta encontrar como cliente (por e-mail ou telefone)
        $sql = "SELECT id, nome, senha, blacklist
                FROM clientes
                WHERE email = :email
                   OR telefone = :telefone
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':email'    => $identificador,
            ':telefone' => telefoneOuOriginal($identificador)
        ]);

        $cliente = $stmt->fetch();

        if ($cliente && password_verify($senha, $cliente['senha'])) {

            if ($cliente['blacklist']) {
                throw new Exception('Sua conta está bloqueada. Entre em contato com o salão.');
            }

            session_regenerate_id(true);

            $_SESSION['tipo']         = 'cliente';
            $_SESSION['cliente_id']   = $cliente['id'];
            $_SESSION['cliente_nome'] = $cliente['nome'];

            header('Location: ../cliente/painel.php');
            exit;
        }

        // 2) Não bateu como cliente: tenta como administradora (só por e-mail)
        $sql = "SELECT id, nome, senha
                FROM administradores
                WHERE email = :email
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $identificador]);

        $admin = $stmt->fetch();

        if ($admin && password_verify($senha, $admin['senha'])) {

            session_regenerate_id(true);

            $_SESSION['tipo']       = 'administrador';
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_nome'] = $admin['nome'];

            header('Location: ../admin/painel.php');
            exit;
        }

        // Nenhuma das duas tabelas bateu: mensagem genérica de propósito,
        // pra não indicar se o e-mail existe nem em qual tabela
        throw new Exception('E-mail/telefone ou senha inválidos.');

    } catch (PDOException $e) {

        error_log($e->getMessage());
        $erro = 'Erro interno do servidor.';

    } catch (Exception $e) {

        $erro = $e->getMessage();

    }
}

if (isset($_GET['sucesso']) || isset($_GET['senha_alterada'])) {
    $sucesso = true;
    $mensagemSucesso = isset($_GET['senha_alterada'])
        ? 'Senha alterada com sucesso! Faça login com a nova senha.'
        : 'Login realizado com sucesso!';
}

$logado     = isset($_SESSION['cliente_id']) || isset($_SESSION['admin_id']);
$nomeLogado = $_SESSION['cliente_nome'] ?? ($_SESSION['admin_nome'] ?? '');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ManiAgenda | Entrar</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/login.css">
</head>

<body>

    <div class="decoration decoration-one"></div>
    <div class="decoration decoration-two"></div>
    <div class="decoration decoration-three"></div>

    <main class="container">

        <section class="intro">

            <div class="brand">
                <div class="brand-icon">M</div>
                <span>ManiAgenda</span>
            </div>

            <div class="intro-content">

                <span class="eyebrow">BEM-VINDA DE VOLTA</span>

                <h1>
                    Seu horário
                    <span>te espera.</span>
                </h1>

                <p>
                    Entre para ver seus agendamentos,
                    marcar novos horários e acompanhar
                    seu histórico no salão.
                </p>

            </div>

            <div class="benefits">

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Agendamentos organizados</span>
                </div>

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Controle completo dos clientes</span>
                </div>

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Mais praticidade no seu dia</span>
                </div>

            </div>

        </section>


        <section class="form-area">

            <?php if ($logado): ?>

                <div class="form-header">
                    <span class="form-eyebrow">VOCÊ ESTÁ CONECTADA</span>
                    <h2>Olá, <?= htmlspecialchars($nomeLogado) ?>!</h2>
                    <p>Seu login foi realizado com sucesso.</p>
                </div>

                <a href="login.php?logout=1" class="submit-button" style="display:flex;align-items:center;justify-content:center;text-decoration:none;">
                    Sair da conta
                </a>

            <?php else: ?>

                <div class="form-header">

                    <span class="form-eyebrow">
                        ACESSE SUA CONTA
                    </span>

                    <h2>Entrar</h2>

                    <p>
                        Informe seus dados para continuar.
                    </p>

                </div>

                <?php if ($erro): ?>
                    <div class="alert erro">
                        <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>

                <?php if ($sucesso): ?>
                    <div class="alert sucesso">
                        <?= htmlspecialchars($mensagemSucesso) ?>
                    </div>
                <?php endif; ?>

                <form id="formLogin" action="login.php" method="POST">

                    <div class="form-grid">

                        <div class="field field-full">

                            <label for="identificador">
                                E-mail ou telefone
                            </label>

                            <div class="input-wrapper">

                                <input
                                    type="text"
                                    id="identificador"
                                    name="identificador"
                                    placeholder="seu@email.com ou (00) 00000-0000"
                                    autocomplete="username"
                                    required
                                >

                            </div>

                        </div>


                        <div class="field field-full">

                            <label for="senha">
                                Senha
                            </label>

                            <div class="input-wrapper">

                                <input
                                    type="password"
                                    id="senha"
                                    name="senha"
                                    placeholder="Sua senha"
                                    autocomplete="current-password"
                                    required
                                >

                            </div>

                        </div>

                    </div>


                    <div class="forgot">
                        <a href="recuperar-senha.php">Esqueci minha senha</a>
                    </div>


                    <button
                        type="submit"
                        class="submit-button"
                    >
                        Entrar
                    </button>


                    <div class="login">

                        Ainda não tem uma conta?

                        <a href="index.php">
                            Criar conta
                        </a>

                    </div>

                </form>

            <?php endif; ?>

        </section>

    </main>

</body>
</html>