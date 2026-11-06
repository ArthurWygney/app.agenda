<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ManiAgenda | Criar conta</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/style.css">
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

                <span class="eyebrow">GESTÃO PARA PROFISSIONAIS</span>

                <h1>
                    Seu negócio de
                    <span>beleza.</span>
                </h1>

                <p>
                    Organize seus horários, clientes e
                    agendamentos em um só lugar.
                    Simples, elegante e feito para facilitar
                    sua rotina.
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

            <div class="form-header">

                <span class="form-eyebrow">
                    COMEÇAR AGORA
                </span>

                <h2>Crie sua conta</h2>

                <p>
                    Preencha seus dados para começar.
                </p>

            </div>


            <form id="formCliente" action="salvar.php" method="POST">

                <div id="formMensagem"></div>

                <div class="form-grid">

                    <div class="field field-full">

                        <label for="nome">
                            Nome completo
                        </label>

                        <div class="input-wrapper">

                            <input
                                type="text"
                                id="nome"
                                name="nome"
                                placeholder="Como podemos te chamar?"
                                autocomplete="name"
                                required
                            >

                        </div>

                    </div>


                    <div class="field">

                        <label for="telefone">
                            Telefone
                        </label>

                        <div class="input-wrapper">

                            <input
                                type="tel"
                                id="telefone"
                                name="telefone"
                                placeholder="(00) 00000-0000"
                                autocomplete="tel-national"
                                inputmode="numeric"
                                maxlength="15"
                                pattern="\(\d{2}\) \d{5}-\d{4}"
                                title="Use o formato (00) 00000-0000"
                                required
                            >

                        </div>

                    </div>


                    <div class="field">

                        <label for="email">
                            E-mail
                        </label>

                        <div class="input-wrapper">

                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="seu@email.com"
                                autocomplete="email"
                                required
                            >

                        </div>

                    </div>


                    <div class="field field-full">

                        <label for="endereco">
                            Endereço
                        </label>

                        <div class="input-wrapper">

                            <input
                                type="text"
                                id="endereco"
                                name="endereco"
                                placeholder="Rua, número, bairro e cidade"
                                autocomplete="street-address"
                                required
                            >

                        </div>

                    </div>


                    <div class="field">

                        <label for="senha">
                            Senha
                        </label>

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


                    <div class="field">

                        <label for="confirmacao-senha">
                            Confirmação de senha
                        </label>

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


                <label class="terms">

                    <input
                        type="checkbox"
                        name="termos"
                        required
                    >

                    <span>
                        Concordo com os
                        <a href="#">Termos de Uso</a>
                        e a
                        <a href="#">Política de Privacidade</a>.
                    </span>

                </label>


                <button
                    type="submit"
                    class="submit-button"
                >
                    Criar minha conta
                </button>


                <div class="login">

                    Já possui uma conta?

                    <a href="login.php">
                        Entrar
                    </a>

                </div>

            </form>

        </section>

    </main>

    <script src="../assets/js/script.js"></script>
</body>
</html>