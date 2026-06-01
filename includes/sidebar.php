<?php
// Garantir que a sesse3o esteja iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$includePerm = __DIR__ . '/permissions.php';
if (file_exists($includePerm)) include_once $includePerm;

$current = basename($_SERVER['PHP_SELF']);
?>

<style>
/* Sidebar visual inspired by provided image */
.app-sidebar { width:230px; min-height:100vh; background:#0b6ac1; color:#fff; padding:8px 8px; box-sizing:border-box; border-radius:0; overflow:hidden; }
.app-sidebar .sidebar-content { padding-top:4px; }
.app-sidebar .brand { display:flex; align-items:center; justify-content:center; padding:6px 0; margin:0 0 6px 0; position:sticky; top:8px; background:transparent; }
.app-sidebar .brand img.brand-logo { height:44px; width:120px; object-fit:contain; display:block; }
.app-sidebar .nav { gap:6px; }
.app-sidebar .nav-link { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.9); padding:8px 10px; border-radius:8px; margin:4px 0; text-decoration:none; font-weight:400; }
.app-sidebar .nav-link .icon { width:28px; text-align:center; color:rgba(255,255,255,0.95); }
.app-sidebar .nav-link .label { font-size:0.95rem; font-weight:400; }
.app-sidebar .nav-link:hover { background:rgba(255,255,255,0.03); color:#fff; }
.app-sidebar .nav-link.active { background:rgba(255,255,255,0.07); position:relative; padding-left:16px; }
.app-sidebar .nav-link.active::before { content:''; position:absolute; left:-6px; top:8px; bottom:8px; width:6px; border-radius:6px; background:#f5c300; }
.app-sidebar .sidebar-footer { margin-top:auto; padding-top:10px; }
.app-sidebar .logout-link { color:rgba(255,255,255,0.85); display:flex; gap:10px; align-items:center; padding:10px 12px; border-radius:8px; text-decoration:none; }
.app-sidebar .logout-link:hover { background:rgba(255,255,255,0.03); }
/* When collapsed, increase icon size and center */
.app-sidebar.collapsed .nav-link .icon i { font-size:18px; }
.app-sidebar.collapsed .nav-link { padding-left: 0.5rem; padding-right: 0.5rem; }
/* collapsed logo handling */
.brand-logo-collapsed { height:32px; width:32px; object-fit:contain; display:none; }
.app-sidebar.collapsed .brand .normal-logo,
body.sidebar-collapsed .brand .normal-logo { display: none !important; }
.app-sidebar.collapsed .brand .brand-logo-collapsed,
body.sidebar-collapsed .brand .brand-logo-collapsed { display: block !important; }
</style>

<aside class="app-sidebar d-flex flex-column">
    <div class="sidebar-top d-flex justify-content-start" style="padding:6px 6px 0 6px;">
        <button id="sidebarToggle" class="btn btn-sm" title="Alternar menu" aria-label="Alternar menu">☰</button>
    </div>
    <div class="brand">
        <?php
            // allow custom logo via storage/settings.json -> logo
            $settingsFile = __DIR__ . '/../storage/settings.json';
            $logoSrc = 'assets/img/logo150-b.png';
            if (file_exists($settingsFile)) {
                $raw = @file_get_contents($settingsFile);
                $s = $raw ? json_decode($raw, true) : null;
                if (!empty($s['logo']) && file_exists(__DIR__ . '/../' . $s['logo'])) {
                    $logoSrc = $s['logo'];
                }
            }
        ?>
        <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="WR SOLARE" class="brand-logo normal-logo">
        <?php
            // collapsed logo fallback: prefer configured collapsed logo, otherwise use assets/img/logo.png as default
            $collapsedSrc = '';
            if (file_exists($settingsFile)) {
                $raw = @file_get_contents($settingsFile);
                $s = $raw ? json_decode($raw, true) : null;
                if (!empty($s['logo_collapsed']) && file_exists(__DIR__ . '/../' . $s['logo_collapsed'])) {
                    $collapsedSrc = $s['logo_collapsed'];
                }
            }
            if (empty($collapsedSrc)) $collapsedSrc = 'assets/img/logo.png';
        ?>
        <img src="<?php echo htmlspecialchars($collapsedSrc); ?>" alt="Logo pequeno" class="brand-logo-collapsed" style="display:none;" />
    </div>
    <div class="sidebar-content" style="overflow:auto;">
        <ul class="nav nav-pills flex-column">
            <?php if (function_exists('hasPermission') ? hasPermission('dashboard') : true): ?>
            <li class="nav-item">
                <a href="index.php" class="nav-link <?php echo $current=='index.php' ? 'active':''; ?>" data-tooltip="Dashboard">
                    <span class="icon"><i class="fa-solid fa-house"></i></span>
                    <span class="label">Dashboard</span>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="meu_perfil.php" class="nav-link <?php echo $current=='meu_perfil.php' ? 'active':''; ?>" data-tooltip="Meu Perfil">
                    <span class="icon"><i class="fa-regular fa-user-circle"></i></span>
                    <span class="label">Meu Perfil</span>
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
            <?php if (function_exists('hasPermission') ? hasPermission('leads_gestao') : true): ?>
            <li class="nav-item">
                <a href="leads_gestao.php" class="nav-link <?php echo $current=='leads_gestao.php' ? 'active':''; ?>" data-tooltip="Gestão de Leads">
                    <span class="icon"><i class="fa-regular fa-rectangle-list"></i></span>
                    <span class="label">Gestão de Leads</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (function_exists('hasPermission') ? hasPermission('projetos') : true): ?>
            <li class="nav-item">
                <a href="projetos.php" class="nav-link <?php echo $current=='projetos.php' ? 'active':''; ?>" data-tooltip="Projetos">
                    <span class="icon"><i class="fa-regular fa-folder-open"></i></span>
                    <span class="label">Projetos</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (function_exists('hasPermission') ? hasPermission('integracao-equipes') : true): ?>
            <li class="nav-item">
                <a href="integracao-equipes.php" class="nav-link <?php echo $current=='integracao-equipes.php' ? 'active':''; ?>" data-tooltip="Integração de Equipes">
                    <span class="icon"><i class="fa-regular fa-address-book"></i></span>
                    <span class="label">Integração de Equipes</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (function_exists('hasPermission') ? hasPermission('pos-venda') : true): ?>
            <li class="nav-item">
                <a href="pos-venda.php" class="nav-link <?php echo $current=='pos-venda.php' ? 'active':''; ?>" data-tooltip="Pós-venda">
                    <span class="icon"><i class="fa-solid fa-tools"></i></span>
                    <span class="label">Pós-venda</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (function_exists('hasPermission') ? hasPermission('relatorios') : true): ?>
            <li class="nav-item">
                <a href="relatorios.php" class="nav-link <?php echo $current=='relatorios.php' ? 'active':''; ?>" data-tooltip="Relatórios">
                    <span class="icon"><i class="fa-regular fa-chart-bar"></i></span>
                    <span class="label">Relatórios</span>
                </a>
            </li>
            <?php endif; ?>
            <?php /* Configurações moved to footer for compact sidebar */ ?>
        </ul>
    </div>
    
    <div class="sidebar-footer mt-auto">
        <?php if (function_exists('hasPermission') ? hasPermission('configuracoes') : true): ?>
        <a href="configuracoes.php" class="nav-link logout-link mb-2" data-tooltip="Configurações">
            <span class="icon"><i class="fa-solid fa-cog"></i></span>
            <span class="label">Configurações</span>
        </a>
        <?php endif; ?>
        <a href="logout.php" class="nav-link logout-link" data-tooltip="Sair">
            <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
            <span class="label">Sair</span>
        </a>
    </div>

    <!-- <hr class="text-white-25">
    <div class="mt-auto text-white-50 small">Seu User ID: <div id="userIdDisplay"><?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']): '-'; ?></div></div> -->
</aside>