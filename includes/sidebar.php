<?php
// Garantir que a sesse3o esteja iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);
?>

<aside class="app-sidebar d-flex flex-column p-3">
    <!-- sidebar brand removed per user request -->

    <ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo $current=='index.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-house"></i></span><span class="label">Dashboard</span></a>
        </li>
        <!-- App menu hidden per user request
        <li>
            <a href="crm.php" class="nav-link <?php echo $current=='crm.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-mobile-screen-button"></i></span><span class="label">App</span></a>
        </li>
        -->
        <li>
            <a href="leads.php" class="nav-link <?php echo $current=='leads.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-user"></i></span><span class="label">Leads</span></a>
        </li>
        <li>
            <a href="leads_gestao.php" class="nav-link <?php echo $current=='leads_gestao.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-list"></i></span><span class="label">Gestão de Leads</span></a>
        </li>
        <li>
            <a href="funil.php" class="nav-link <?php echo $current=='funil.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-project-diagram"></i></span><span class="label">Funil</span></a>
        </li>
        <li>
            <a href="projetos.php" class="nav-link <?php echo $current=='projetos.php' ? 'active':''; ?>" title="Organiza informações de clientes, histórico de interações e andamento de projetos. Facilita o acompanhamento de contratos, entregas e comunicação.">
                <span class="icon"><i class="fa-solid fa-folder-open"></i></span>
                <span class="label">Projetos</span>
            </a>
        </li>
        <li>
            <a href="integracao-equipes.php" class="nav-link <?php echo $current=='customers.php' ? 'active':''; ?>" title="Centraliza informações e tarefas entre equipes de marketing, vendas e atendimento. Melhora a comunicação interna e reduz falhas no processo.">
                <span class="icon"><i class="fa-solid fa-address-book"></i></span>
                <span class="label">Integração de Equipes</span>
            </a>
        </li>
        <li>
            <a href="pos-venda.php" class="nav-link <?php echo $current=='pos-venda.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-tools"></i></span><span class="label">Pós-venda</span></a>
        </li>
        <li>
            <a href="relatorios.php" class="nav-link <?php echo $current=='relatorios.php' ? 'active':''; ?>"><span class="icon"><i class="fa-solid fa-chart-line"></i></span><span class="label">Relatórios</span></a>
        </li>
        <li>
            <a href="logout.php" class="nav-link"><span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span><span class="label">Sair</span></a>
        </li>
    </ul>

    <!-- <hr class="text-white-25">
    <div class="mt-auto text-white-50 small">Seu User ID: <div id="userIdDisplay"><?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']): '-'; ?></div></div> -->
</aside>