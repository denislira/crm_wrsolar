<?php
// Garantir que a sesse3o esteja iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);
?>

<aside class="app-sidebar d-flex flex-column">
    <div class="sidebar-content">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item">
                <a href="index.php" class="nav-link <?php echo $current=='index.php' ? 'active':''; ?>" data-tooltip="Dashboard">
                    <span class="icon"><i class="fa-solid fa-house"></i></span>
                    <span class="label">Dashboard</span>
                </a>
            </li>
            <!-- Leads menu hidden
            <li class="nav-item">
                <a href="leads.php" class="nav-link <?php echo $current=='leads.php' ? 'active':''; ?>" data-tooltip="Leads">
                    <span class="icon"><i class="fa-regular fa-user"></i></span>
                    <span class="label">Leads</span>
                </a>
            </li>
            -->
            <li class="nav-item">
                <a href="leads_gestao.php" class="nav-link <?php echo $current=='leads_gestao.php' ? 'active':''; ?>" data-tooltip="Gestão de Leads">
                    <span class="icon"><i class="fa-regular fa-rectangle-list"></i></span>
                    <span class="label">Gestão de Leads</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="projetos.php" class="nav-link <?php echo $current=='projetos.php' ? 'active':''; ?>" data-tooltip="Projetos">
                    <span class="icon"><i class="fa-regular fa-folder-open"></i></span>
                    <span class="label">Projetos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="integracao-equipes.php" class="nav-link <?php echo $current=='integracao-equipes.php' ? 'active':''; ?>" data-tooltip="Integração de Equipes">
                    <span class="icon"><i class="fa-regular fa-address-book"></i></span>
                    <span class="label">Integração de Equipes</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="pos-venda.php" class="nav-link <?php echo $current=='pos-venda.php' ? 'active':''; ?>" data-tooltip="Pós-venda">
                    <span class="icon"><i class="fa-solid fa-tools"></i></span>
                    <span class="label">Pós-venda</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="relatorios.php" class="nav-link <?php echo $current=='relatorios.php' ? 'active':''; ?>" data-tooltip="Relatórios">
                    <span class="icon"><i class="fa-regular fa-chart-bar"></i></span>
                    <span class="label">Relatórios</span>
                </a>
            </li>
            <?php if (hasPermission('configuracoes')): ?>
            <li class="nav-item">
                <a href="configuracoes.php" class="nav-link <?php echo $current=='configuracoes.php' ? 'active':''; ?>" data-tooltip="Configurações">
                    <span class="icon"><i class="fa-solid fa-cog"></i></span>
                    <span class="label">Configurações</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    
    <div class="sidebar-footer mt-auto">
        <a href="logout.php" class="nav-link logout-link" data-tooltip="Sair">
            <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
            <span class="label">Sair</span>
        </a>
    </div>

    <!-- <hr class="text-white-25">
    <div class="mt-auto text-white-50 small">Seu User ID: <div id="userIdDisplay"><?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']): '-'; ?></div></div> -->
</aside>