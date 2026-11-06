<?php

require_once '../config/database.php';
require_once '../lib/telefone.php';

$erro              = '';
$solicitacaoEnviada = false;
$linkTeste          = null;

$tokenUrl    = trim($_GET['token'] ?? '');
$tokenValido = false;

const TOKEN_VALIDADE_MINUTOS = 30;

// ==========================================
// ETAPA 2 (POST): redefinir a senha
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {

    try {

        $token     = trim($_POST['token'] ?? '');
        $senha     = $_POST['senha'] ?? '';
        $confirmar = $_POST['confirmacao-senha'] ?? '';

        if (!$token || !$senha || !$confirmar) {
            throw new Exception('Preencha todos os campos.');
        }

        if ($senha !== $confirmar) {
            throw new Exception('As senhas não coincidem.');
        }

        if (strlen($senha) < 8) {
            throw new Exception('A senha deve possuir no mínimo 8 caracteres.');
        }

        // Valida o token
        $sql = "SELECT id, cliente_id
                FROM recuperacoes_senha
                WHERE token = :token
                  AND usado = 0
                  AND expira_em > NOW()
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);

        $recuperacao = $stmt->fetch();

        if (!$recuperacao) {
            throw new Exception('Link inválido ou expirado. Solicite uma nova recuperação.');
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE clientes SET senha = :senha WHERE id = :id");
        $stmt->execute([
            ':senha' => $senhaHash,
            ':id'    => $recuperacao['cliente_id']
        ]);

        $stmt = $pdo->prepare("UPDATE recuperacoes_senha SET usado = 1 WHERE id = :id");
        $stmt->execute([':id' => $recuperacao['id']]);

        $pdo->commit();

        header('Location: login.php?senha_alterada=1');
        exit;

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($e->getMessage());
        $erro = 'Erro interno do servidor.';

    } catch (Exception $e) {

        $erro = $e->getMessage();

    }

    // Se chegou aqui, deu erro na redefinição — mantém a tela de nova senha
    $tokenUrl    = $_POST['token'] ?? '';
    $tokenValido = true;

// ==========================================
// ETAPA 1 (POST): solicitar recuperação
// ==========================================
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $identificador = trim($_POST['identificador'] ?? '');

        if (!$identificador) {
            throw new Exception('Informe seu e-mail ou telefone.');
        }

        $sql = "SELECT id
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

        // Só gera o token se o cliente existir, mas a mensagem exibida
        // é sempre a mesma — evita confirmar pra quem está tentando
        // adivinhar quais e-mails/telefones estão cadastrados
        if ($cliente) {

            $token    = bin2hex(random_bytes(32));
            $expiraEm = date('Y-m-d H:i:s', strtotime('+' . TOKEN_VALIDADE_MINUTOS . ' minutes'));

            $stmt = $pdo->prepare(
                "INSERT INTO recuperacoes_senha (cliente_id, token, expira_em)
                 VALUES (:cliente_id, :token, :expira_em)"
            );

            $stmt->execute([
                ':cliente_id' => $cliente['id'],
                ':token'      => $token,
                ':expira_em'  => $expiraEm
            ]);

            // Este projeto ainda não tem envio de e-mail configurado.
            // Em produção, o link abaixo seria enviado por e-mail/SMS —
            // aqui ele só é exibido na tela para permitir testar o fluxo.
            $linkTeste = 'recuperar-senha.php?token=' . urlencode($token);
        }

        $solicitacaoEnviada = true;

    } catch (PDOException $e) {

        error_log($e->getMessage());
        $erro = 'Erro interno do servidor.';

    } catch (Exception $e) {

        $erro = $e->getMessage();

    }

// ==========================================
// GET com token: valida antes de mostrar o formulário de nova senha
// ==========================================
} elseif ($tokenUrl) {

    try {

        $sql = "SELECT id FROM recuperacoes_senha
                WHERE token = :token
                  AND usado = 0
                  AND expira_em > NOW()
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $tokenUrl]);

        if ($stmt->fetch()) {
            $tokenValido = true;
        } else {
            $erro = 'Link inválido ou expirado. Solicite uma nova recuperação.';
        }

    } catch (PDOException $e) {

        error_log($e->getMessage());
        $erro = 'Erro interno do servidor.';

    }

}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ManiAgenda | Recuperar senha</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/recuperar-senha.css">
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

                <span class="eyebrow">ACONTECE COM TODAS</span>

                <h1>
                    Vamos recuperar
                    <span>seu acesso.</span>
                </h1>

                <p>
                    Em poucos passos você define uma
                    nova senha e volta a agendar
                    seus horários.
                </p>

            </div>

            <div class="benefits">

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Processo rápido e seguro</span>
                </div>

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Link de uso único</span>
                </div>

                <div class="benefit">
                    <div class="benefit-line"></div>
                    <span>Expira em 30 minutos</span>
                </div>

            </div>

        </section>


        <section class="form-area">

            <?php if ($tokenValido): ?>

                <!-- ETAPA 2: definir nova senha -->

                <div class="form-header">
                    <span class="form-eyebrow">ÚLTIMO PASSO</span>
                    <h2>Crie uma nova senha</h2>
                    <p>Escolha uma senha com pelo menos 8 caracteres.</p>
                </div>

                <?php if ($erro): ?>
                    <div class="alert erro">
                        <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>

                <form action="recuperar-senha.php" method="POST">

                    <input type="hidden" name="token" value="<?= htmlspecialchars($tokenUrl) ?>">

                    <div class="form-grid">

                        <div class="field field-full">
                            <label for="senha">Nova senha</label>
                            <div class="input-wrapper">
                                <input
                                    type="password"
                                    id="senha"
                                    name="senha"
                                    placeholder="Crie uma senha"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>
                        </div>

                        <div class="field field-full">
                            <label for="confirmacao-senha">Confirmação de senha</label>
                            <div class="input-wrapper">
                                <input
                                    type="password"
                                    id="confirmacao-senha"
                                    name="confirmacao-senha"
                                    placeholder="Repita sua senha"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>
                        </div>

                    </div>

                    <button type="submit" class="submit-button">
                        Redefinir senha
                    </button>

                </form>

            <?php elseif ($solicitacaoEnviada): ?>

                <!-- CONFIRMAÇÃO DA ETAPA 1 -->

                <div class="form-header">
                    <span class="form-eyebrow">VERIFIQUE SEUS DADOS</span>
                    <h2>Pedido enviado</h2>
                </div>

                <div class="alert sucesso">
                    Se o e-mail ou telefone estiver cadastrado, enviaremos
                    um link de recuperação válido por <?= TOKEN_VALIDADE_MINUTOS ?> minutos.
                </div>

                <?php if ($linkTeste): ?>
                    <div class="dev-link">
                        <strong>Ambiente de testes</strong> — o envio de e-mail
                        ainda não está configurado neste projeto. Use o link
                        abaixo para seguir o fluxo:
                        <a href="<?= htmlspecialchars($linkTeste) ?>"><?= htmlspecialchars($linkTeste) ?></a>
                    </div>
                <?php endif; ?>

                <div class="login">
                    <a href="login.php">Voltar para o login</a>
                </div>

            <?php else: ?>

                <!-- ETAPA 1: solicitar recuperação -->

                <div class="form-header">
                    <span class="form-eyebrow">ESQUECEU A SENHA?</span>
                    <h2>Recuperar senha</h2>
                    <p>Informe o e-mail ou telefone usado no cadastro.</p>
                </div>

                <?php if ($erro): ?>
                    <div class="alert erro">
                        <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>

                <form action="recuperar-senha.php" method="POST">

                    <div class="form-grid">

                        <div class="field field-full">
                            <label for="identificador">E-mail ou telefone</label>
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

                    </div>

                    <button type="submit" class="submit-button">
                        Enviar link de recuperação
                    </button>

                    <div class="login">
                        Lembrou sua senha?
                        <a href="login.php">Entrar</a>
                    </div>

                </form>

            <?php endif; ?>

        </section>

    </main>

</body>
</html>
