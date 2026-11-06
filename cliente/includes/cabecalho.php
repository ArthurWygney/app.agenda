<?php
// Espera que $tituloPagina, $paginaAtiva e $_SESSION['cliente_nome'] já existam.
$paginaAtiva  = $paginaAtiva ?? '';
$tituloPagina = $tituloPagina ?? 'Painel';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ManiAgenda | <?= htmlspecialchars($tituloPagina) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/cliente.css">
</head>
<body>

    <div class="cliente-shell">

        <aside class="sidebar">

            <div class="sidebar-brand">
                <div class="brand-icon">M</div>
                <span>ManiAgenda</span>
            </div>

            <nav class="sidebar-nav">

                <a href="painel.php" class="nav-item <?= $paginaAtiva === 'painel' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9679;</span>
                    Painel
                </a>

                <a href="agendar.php" class="nav-item <?= $paginaAtiva === 'agendar' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128197;</span>
                    Agendar horário
                </a>

                <a href="meus-agendamentos.php" class="nav-item <?= $paginaAtiva === 'meus-agendamentos' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128203;</span>
                    Meus agendamentos
                </a>

            </nav>

            <a href="../auth/login.php?logout=1" class="sidebar-logout">
                &#8592; Sair da conta
            </a>

        </aside>

        <div class="cliente-content">

            <header class="topbar">

                <div>
                    <span class="topbar-eyebrow">ÁREA DA CLIENTE</span>
                    <h1><?= htmlspecialchars($tituloPagina) ?></h1>
                </div>

                <div class="topbar-user">
                    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['cliente_nome'] ?? 'C', 0, 1)) ?></div>
                    <span><?= htmlspecialchars($_SESSION['cliente_nome'] ?? '') ?></span>
                </div>

            </header>

            <main class="cliente-main">
