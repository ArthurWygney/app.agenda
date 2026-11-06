<?php
// Espera que $tituloPagina, $paginaAtiva e $_SESSION['admin_nome'] já existam.
$paginaAtiva = $paginaAtiva ?? '';
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

    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

    <div class="admin-shell">

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

                <a href="agenda.php" class="nav-item <?= $paginaAtiva === 'agenda' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128197;</span>
                    Agenda
                </a>

                <a href="clientes.php" class="nav-item <?= $paginaAtiva === 'clientes' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128100;</span>
                    Clientes
                </a>

                <a href="servicos.php" class="nav-item <?= $paginaAtiva === 'servicos' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128142;</span>
                    Serviços
                </a>

                <a href="horarios.php" class="nav-item <?= $paginaAtiva === 'horarios' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128274;</span>
                    Horários bloqueados
                </a>

                <a href="notificacoes.php" class="nav-item <?= $paginaAtiva === 'notificacoes' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128276;</span>
                    Notificações
                </a>

            </nav>

            <a href="../auth/login.php?logout=1" class="sidebar-logout">
                &#8592; Sair da conta
            </a>

        </aside>

        <div class="admin-content">

            <header class="topbar">

                <div>
                    <span class="topbar-eyebrow">ÁREA ADMINISTRATIVA</span>
                    <h1><?= htmlspecialchars($tituloPagina) ?></h1>
                </div>

                <div class="topbar-user">
                    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['admin_nome'] ?? 'A', 0, 1)) ?></div>
                    <span><?= htmlspecialchars($_SESSION['admin_nome'] ?? '') ?></span>
                </div>

            </header>

            <main class="admin-main">
