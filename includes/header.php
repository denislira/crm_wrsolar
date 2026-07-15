<?php 
if (session_status() === PHP_SESSION_NONE) session_start(); 
include_once 'includes/permissions.php';
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SolarCRM</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <!-- Browser also requests /favicon.ico automatically; provide explicit fallback -->
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link href="assets/css/inter.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/fontawesome.min.css" />
  <!-- Site theme (overrides and design system) -->
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/leads_gestao.css">
  <script>
    (function () {
      try {
        var darkMode = localStorage.getItem('theme.mode') === 'dark' || localStorage.getItem('darkMode') === '1';
        if (!darkMode) return;
        var style = document.createElement('style');
        style.id = 'theme-preload-dark';
        style.textContent = [
          'html,body{background:#0b1220 !important;color:#e6eef8 !important;color-scheme:dark !important;}',
          'body{background:#0b1220 !important;}',
          '.navbar{background:#0b1220 !important;border-bottom:1px solid rgba(255,255,255,0.06) !important;}',
          '.app-sidebar{background:linear-gradient(180deg,#071427 0%, #0b1220 100%) !important;color:#e6eef8 !important;}',
          '.page-loading-overlay{background:rgba(7,10,16,0.94) !important;}',
          '.btn-outline-secondary{background:transparent !important;border-color:rgba(255,255,255,0.12) !important;color:#e6eef8 !important;}'
        ].join('');
        document.head.appendChild(style);
      } catch (e) {
        // ignore storage access errors
      }
    })();
  </script>
  <?php if (empty($noNavbar) && !empty($_SESSION['user_id'])): ?>
    <link rel="stylesheet" href="assets/css/internal_chat.css">
  <?php endif; ?>
  <?php
    // Load appearance settings if available
    $settingsPath = __DIR__ . '/../storage/settings.json';
    $appearance = [];
    if (file_exists($settingsPath)) {
        $raw = @file_get_contents($settingsPath);
        $appearance = $raw ? json_decode($raw, true) : [];
    }
    $primary = isset($appearance['primary_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $appearance['primary_color']) ? $appearance['primary_color'] : '#0b6ac1';
    $primaryDark = isset($appearance['primary_dark']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $appearance['primary_dark']) ? $appearance['primary_dark'] : '#073b6b';
    $green = $appearance['green'] ?? '#4bbf4b';
    $yellow = $appearance['yellow'] ?? '#ffd24a';
  ?>
  <style>
    /* Theme derived from WR Solare logo: blues, green and yellow accents */
    :root{
        --blue-700: <?php echo $primary; ?>; /* main logo blue */
        --blue-900: <?php echo $primaryDark; ?>; /* darker blue */
        --green: <?php echo $green; ?>;    /* leaf green */
        --yellow: <?php echo $yellow; ?>;   /* sun yellow */
        --muted-bg: #e9eef5;
        --sidebar-w: 260px;
        --sidebar-collapsed-w: 64px;
        --icon-size: 16px;
    }
  /* Remove default browser page margin and set base font/background
    Add top padding so fixed navbar doesn't cover content. */
  body{ margin: 0; font-family: 'Segoe UI', 'Segoe UI Variable', system-ui, -apple-system, 'Inter', Roboto, 'Helvetica Neue', Arial; background:var(--muted-bg); overflow-x: hidden; padding-top:48px; }
  /* Keep navbar always visible: fixed at top and offset after sidebar */
  .navbar { position: fixed; top: 0; left: var(--sidebar-w); z-index: 1040; width: calc(100% - var(--sidebar-w)); }
  /* Reduce the default container padding so brand sits closer to the left edge.
    Make the navbar flush with the viewport edges to maximize usable space. */
  html, body { width: 100%; }
  .navbar { padding-left: 0; padding-right: 0; }
  .navbar .container-fluid { padding-left: 6px; padding-right: 6px; max-width: 100%; margin: 0; }
  /* Sidebar: fixed full-height left rail (contains brand at top) */
  .app-sidebar { position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; z-index: 1030; box-shadow: 2px 0 12px rgba(7,59,107,0.08); }
  /* Main uses CSS variables so it can resize based on sidebar width */
  .app-sidebar { width: var(--sidebar-w); min-width: var(--sidebar-w); max-width: var(--sidebar-w); }
  .main-content-scroll, main.flex-grow-1 { margin-left: var(--sidebar-w); padding-left: 1rem; padding-right: 1rem; width: calc(100% - var(--sidebar-w)); transition: margin-left .22s ease, width .22s ease; }
  /* Ensure the inner container spans full remaining width (no fixed max-width) */
  .main-content-scroll > .container-fluid { width: 100%; padding-left: 0.5rem; padding-right: 0.5rem; }
.dashboard-modern-card { border-radius: 1rem; box-shadow: 0 4px 24px rgba(7,59,107,0.10); border: none; background: #fff; transition: transform 0.2s; }
.dashboard-modern-card:hover { transform: translateY(-2px); }
  .main-content-scroll { min-height: 100vh; overflow-y: auto; }
  /* slightly narrower sidebar for better proportion */
  .app-sidebar{ width: var(--sidebar-w); min-width: var(--sidebar-w); max-width: var(--sidebar-w); flex: 0 0 var(--sidebar-w); box-sizing: border-box; background: linear-gradient(180deg,var(--blue-900),var(--blue-700)); color: #fff; min-height:100vh; }
    .app-sidebar .nav-link{ color: rgba(255,255,255,0.95); }
    .app-sidebar .nav-link.active, .app-sidebar .nav-link:hover{ background: rgba(255,255,255,0.06); color: #fff; }
    .app-sidebar .me-2{ background:#fff;color:var(--blue-700); }
    .card-shadow{ box-shadow: 0 10px 30px rgba(7,59,107,0.08); }
    .kanban-card{ cursor: grab; }

    /* Primary button -> logo blue */
    .btn-primary{ background-color: var(--blue-700); border-color: var(--blue-700); }
    .btn-primary:hover, .btn-primary:focus{ background-color: var(--blue-900); border-color: var(--blue-900); }

    /* Small brand helpers */
    .brand-badge{ display:inline-flex; align-items:center; gap:.5rem; }
    .brand-badge img{ height:32px; }
    .brand-sun{ width:12px;height:12px;border-radius:50%;background:var(--yellow);display:inline-block }
    .brand-leaf{ width:12px;height:8px;background:var(--green);display:inline-block;border-radius:4px }
    /* Ensure brand and toggle align neatly */
  .navbar-brand img { height: 32px; display:inline-block; vertical-align:middle; margin-right:.5rem; margin-top:0; margin-left:6px; }
  /* Sidebar toggle styling (now located inside sidebar) */
  #sidebarToggle {
    background: transparent !important;
    border: none !important;
    color: rgba(255,255,255,0.95);
    padding: .15rem .35rem;
    border-radius:6px;
    margin-left:0;
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  #sidebarToggle:hover, #sidebarToggle:focus {
    background: rgba(255,255,255,0.06) !important;
    outline: none;
  }
  /* ensure the toggle stays left-aligned in the top bar */
  .sidebar-top { padding-left: 6px; }
  /* hide duplicate brand in navbar when sidebar contains brand */
  .navbar .navbar-brand { display: none; }
  </style>
</head>
<body>
  <?php if (empty($noNavbar) && !empty($_SESSION['user_id'])): ?>
  <script>
    window.currentUserId = <?php echo (int) $_SESSION['user_id']; ?>;
  </script>
  <?php endif; ?>
  <script>
    (function(){
      try {
        var collapsed = localStorage.getItem('sidebar.collapsed');
        if (collapsed === '1') {
          document.body.classList.add('sidebar-collapsed');
        }
      } catch (e) {
        // ignore storage access errors
      }
    })();
  </script>
  <?php if (empty($noNavbar)): ?>
  <nav class="navbar navbar-light bg-white">
    <div class="container-fluid d-flex align-items-center">
      <div class="d-flex align-items-center">
        <button id="mobileSidebarToggle" class="btn btn-outline-secondary d-inline-flex d-md-none me-2" type="button" title="Abrir menu" aria-label="Abrir menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <!-- navbar brand intentionally removed (logo moved to sidebar) -->
        <?php
            $reqHost = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            $reqHost = preg_replace('/:\\d+$/', '', $reqHost);
            if ($reqHost === 'localhost' || $reqHost === '127.0.0.1') {
                echo '<div class="me-2 d-none d-md-inline"><span class="badge bg-warning text-dark">LOCAL</span></div>';
            }
        ?>
      </div>
      <div class="ms-auto d-flex align-items-center">
        <?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
        <?php $displayName = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Usuário'; ?>
        <div class="me-2 d-none d-md-inline text-dark">Olá, <strong><?php echo $displayName; ?></strong></div>
        <?php if (isset($reqHost) && ($reqHost === 'localhost' || $reqHost === '127.0.0.1')): ?>
          <div class="me-2 d-none d-md-inline"><span class="badge bg-secondary">dev</span></div>
        <?php endif; ?>
        <button id="themeToggle" class="btn btn-outline-secondary me-2" title="Alternar tema" aria-label="Alternar tema">🌓</button>
        <!-- Reminder bell -->
        <div class="dropdown me-2">
          <button id="reminderBellBtn" class="btn btn-outline-secondary position-relative dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Lembretes" aria-label="Lembretes">
            <i class="fa-regular fa-bell"></i>
            <span id="reminderBellCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem;">0</span>
          </button>
          <ul id="reminderBellMenu" class="dropdown-menu dropdown-menu-end" style="min-width:280px;">
            <li><h6 class="dropdown-header">Lembretes</h6></li>
            <li><div id="reminderBellList" class="px-2 py-1 small text-muted">Carregando...</div></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-center" href="integracao-equipes.php">Ver todos os lembretes</a></li>
          </ul>
        </div>
        <a href="logout.php" class="btn btn-outline-secondary" title="Sair" aria-label="Sair">
          <i class="fa-solid fa-right-from-bracket"></i>
        </a>
      </div>
    </div>
  </nav>
  <?php endif; ?>

  <!-- Page Loading Overlay -->
  <div id="pageLoadingOverlay" class="page-loading-overlay">
    <div class="loading-spinner">
      <div class="spinner-border text-light" role="status">
        <span class="visually-hidden">Carregando...</span>
      </div>
      <p class="mt-3 text-dark">Carregando página...</p>
    </div>
  </div>
  <div id="mobileSidebarBackdrop" class="mobile-sidebar-backdrop" aria-hidden="true"></div>

  <style>
    /* Page loading overlay - limited to main content area so it doesn't cover sidebar/navbar */
    .page-loading-overlay {
      position: fixed;
      top: 48px; /* below navbar */
      left: var(--sidebar-w, 220px); /* start after the sidebar */
      width: calc(100% - var(--sidebar-w, 220px));
      height: calc(100vh - 48px);
      background: rgba(233,238,245,0.96); /* less transparent background */
      z-index: 1025; /* above page content, below sidebar/navbar */
      display: none;
      align-items: center;
      justify-content: center;
      backdrop-filter: none; /* matte effect */
      pointer-events: auto;
    }

    .page-loading-overlay.active {
      display: flex;
    }
    /* When the sidebar is collapsed we persist a smaller left offset */
    body.sidebar-collapsed .page-loading-overlay {
      left: var(--sidebar-collapsed-w);
      width: calc(100% - var(--sidebar-collapsed-w));
    }
    /* When sidebar is collapsed move navbar accordingly */
    body.sidebar-collapsed .navbar { left: var(--sidebar-collapsed-w); width: calc(100% - var(--sidebar-collapsed-w)); }
    /* Ensure spinner and text are visible on light background */
    .page-loading-overlay .loading-spinner {
      color: var(--blue-900);
    }
    .page-loading-overlay .loading-spinner .spinner-border {
      border-width: 0.3rem;
      color: var(--blue-900);
    }
    body.theme-dark .page-loading-overlay,
    body.dark-mode .page-loading-overlay {
      background: rgba(3,7,18,0.92);
    }
    body.theme-dark .page-loading-overlay .loading-spinner,
    body.dark-mode .page-loading-overlay .loading-spinner,
    body.theme-dark .page-loading-overlay .loading-spinner .spinner-border,
    body.dark-mode .page-loading-overlay .loading-spinner .spinner-border {
      color: #e6eef8;
    }
    
    .loading-spinner {
      text-align: center;
    }
    
    .loading-spinner .spinner-border {
      width: 3rem;
      height: 3rem;
      border-width: 0.3rem;
    }
    
    /* Modern collapsible sidebar */
    .app-sidebar { 
      transition: width .3s cubic-bezier(0.4, 0, 0.2, 1); 
      box-sizing: border-box; 
      width: var(--sidebar-w); 
      min-width: var(--sidebar-w); 
      max-width: var(--sidebar-w); 
      flex: 0 0 var(--sidebar-w); 
      position: fixed; 
      top: 0; 
      left: 0; 
      height: 100vh; 
      overflow-y: auto; 
      overflow-x: hidden;
      z-index:1030; 
      background: linear-gradient(180deg, #001f3f 0%, #003d7a 100%);
      box-shadow: 2px 0 12px rgba(0, 0, 0, 0.08);
      display: flex;
      flex-direction: column;
    }
    
    .app-sidebar.collapsed { 
      width: 60px !important; 
      min-width: 60px; 
      max-width: 60px; 
      flex: 0 0 60px; 
    }

    body.sidebar-collapsed .app-sidebar {
      width: var(--sidebar-collapsed-w) !important;
      min-width: var(--sidebar-collapsed-w) !important;
      max-width: var(--sidebar-collapsed-w) !important;
      flex: 0 0 var(--sidebar-collapsed-w) !important;
    }

    body.sidebar-collapsed .app-sidebar .nav-link {
      justify-content: center;
      padding: 0.75rem 0;
      margin: 0.25rem 0;
      margin-left: -0.5rem;
      margin-right: -0.5rem;
      width: calc(100% + 1rem);
      transform: none;
      border-radius: 0;
    }

    body.sidebar-collapsed .app-sidebar .nav-link:hover,
    body.sidebar-collapsed .app-sidebar .nav-link.active {
      border-radius: 0;
    }

    body.sidebar-collapsed .app-sidebar .nav-link .icon {
      justify-content: center;
    }

    body.sidebar-collapsed .app-sidebar .nav-link .label {
      opacity: 0;
      visibility: hidden;
      width: 0;
      margin: 0;
      padding: 0;
      display: none !important;
    }

    body.sidebar-collapsed .app-sidebar .nav-link:hover::after {
      opacity: 1;
    }
    
    .sidebar-content {
      padding: 0.5rem 0.25rem;
      flex: 1;
    }
    
    .sidebar-footer {
      padding: 0.5rem 0.25rem;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    
    .app-sidebar .nav-link { 
      display: flex; 
      align-items: center; 
      gap: 0.6rem; 
      padding: 0.6rem 0.6rem; 
      margin: 0.15rem 0.25rem;
      border-radius: 8px;
      color: rgba(255, 255, 255, 0.88);
      text-decoration: none;
      transition: all 0.16s ease;
      position: relative;
      font-weight: 500;
      font-size: 0.92rem;
    }
    
    .app-sidebar .nav-link:hover { 
      background: rgba(255, 255, 255, 0.1); 
      color: #fff;
      transform: translateX(4px);
    }
    
    .app-sidebar .nav-link.active { 
      background: rgba(255, 255, 255, 0.15); 
      color: #fff;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
    }
    
    .app-sidebar.collapsed .nav-link { 
      justify-content: center; 
      padding: 0.75rem 0; 
      margin: 0.25rem 0; 
      margin-left: -0.5rem;
      margin-right: -0.5rem;
      width: calc(100% + 1rem);
      transform: none;
      border-radius: 0;
    }
    .app-sidebar.collapsed .nav-link:hover,
    .app-sidebar.collapsed .nav-link.active {
      border-radius: 0;
    }
    
    .app-sidebar.collapsed .nav-link:hover {
      transform: scale(1.05);
    }
    
    .app-sidebar .nav-link .icon { 
      width: var(--icon-size); 
      height: var(--icon-size);
      display: flex;
      align-items: center;
      justify-content: flex-start;
      font-size: var(--icon-size);
      flex-shrink: 0;
    }
    
    .app-sidebar.collapsed .nav-link .icon { 
      justify-content: center; 
      width: var(--icon-size); 
    }
    
    .app-sidebar .nav-link .label { 
      transition: all 0.16s ease; 
      display: inline-block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      font-size: 0.92rem;
    }
    
    .app-sidebar.collapsed .nav-link .label { 
      opacity: 0;
      visibility: hidden;
      width: 0;
      margin: 0;
      padding: 0;
      display: none !important;
    }
    
    .app-sidebar .icon i { 
      color: currentColor; 
    }
    
    /* Tooltip for collapsed state */
    .app-sidebar.collapsed .nav-link::after {
      content: attr(data-tooltip);
      position: absolute;
      left: 100%;
      top: 50%;
      transform: translateY(-50%);
      margin-left: 1rem;
      padding: 0.5rem 0.75rem;
      background: rgba(0, 0, 0, 0.9);
      color: #fff;
      border-radius: 6px;
      font-size: 0.875rem;
      white-space: nowrap;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s ease;
      z-index: 1050;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }
    
    .app-sidebar.collapsed .nav-link:hover::after {
      opacity: 1;
    }
    
    /* Logout link special styling */
    .logout-link {
      color: rgba(255, 100, 100, 0.9) !important;
    }
    
    .logout-link:hover {
      background: rgba(255, 100, 100, 0.1) !important;
      color: #ff6b6b !important;
    }
    
    /* Scrollbar styling for sidebar */
    /* Scrollbar styling for sidebar (thinner + transparent) */
    .app-sidebar::-webkit-scrollbar { width: 5px; }
    .app-sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.06); }
    .app-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.28); border-radius: 4px; }
    .app-sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.36); }

    /* Main content scrollbar (thinner + subtle) */
    main.flex-grow-1::-webkit-scrollbar, .main-content-scroll::-webkit-scrollbar { height: 8px; }
    main.flex-grow-1::-webkit-scrollbar-track, .main-content-scroll::-webkit-scrollbar-track { background: rgba(11,26,49,0.06); }
    main.flex-grow-1::-webkit-scrollbar-thumb, .main-content-scroll::-webkit-scrollbar-thumb { background: rgba(11,26,49,0.16); border-radius: 6px; }
    main.flex-grow-1::-webkit-scrollbar-thumb:hover, .main-content-scroll::-webkit-scrollbar-thumb:hover { background: rgba(11,26,49,0.22); }

    /* Firefox scrollbar support */
    .app-sidebar, main.flex-grow-1, .main-content-scroll { scrollbar-width: thin; }

    /* Theme-aware sidebar overrides */
    body.theme-dark .app-sidebar {
      background: linear-gradient(180deg,#071427 0%, #0b1220 100%) !important;
      color: #e6eef8 !important;
      box-shadow: 2px 0 18px rgba(0,0,0,0.6) !important;
    }
    body.theme-dark .app-sidebar .nav-link { color: rgba(230,238,248,0.95) !important; }
    body.theme-dark .app-sidebar .nav-link:hover,
    body.theme-dark .app-sidebar .nav-link.active { background: rgba(255,255,255,0.03) !important; color: #e6eef8 !important; }
    body.theme-dark .app-sidebar .me-2 { background: rgba(255,255,255,0.06) !important; color: var(--blue-700) !important; }
    body.theme-dark .sidebar-footer { border-top-color: rgba(255,255,255,0.06) !important; }

    body.theme-light .app-sidebar {
      background: linear-gradient(180deg,var(--blue-900),var(--blue-700)) !important;
      color: #fff !important;
      box-shadow: 2px 0 12px rgba(7,59,107,0.08) !important;
    }
    body.theme-light .app-sidebar .nav-link { color: rgba(255,255,255,0.95) !important; }
    body.theme-light .app-sidebar .nav-link:hover,
    body.theme-light .app-sidebar .nav-link.active { background: rgba(255,255,255,0.06) !important; color: #fff !important; }
    body.theme-light .app-sidebar .me-2 { background:#fff !important;color:var(--blue-700) !important; }

  /* Main content offset to avoid overlap with fixed sidebar */
  main.flex-grow-1, .main-content-scroll { 
    margin-left: var(--sidebar-w); 
    padding: 1.5rem; 
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
  }
  
  .main-content-scroll > .container-fluid { 
    width: 100%; 
    padding-left: 1rem; 
    padding-right: 1rem; 
  }
  
  /* When sidebar is collapsed, reduce left margin so content resizes */
  body.sidebar-collapsed main.flex-grow-1, 
  body.sidebar-collapsed .main-content-scroll { 
    margin-left: var(--sidebar-collapsed-w); 
    width: calc(100% - var(--sidebar-collapsed-w)); 
  }
  
  body.sidebar-collapsed .main-content-scroll > .container-fluid { 
    width: 100%; 
  }

  @media (max-width: 767.98px) {
    body {
      padding-top: 48px;
      overflow-x: hidden;
    }

    body.sidebar-mobile-open {
      overflow: hidden;
    }

    .navbar,
    body.sidebar-collapsed .navbar {
      left: 0;
      width: 100%;
      min-height: 48px;
    }

    .navbar .container-fluid {
      padding-left: 10px;
      padding-right: 10px;
    }

    #mobileSidebarToggle,
    .navbar .btn {
      width: 40px;
      min-width: 40px;
      height: 40px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
    }

    .navbar .dropdown .btn {
      width: 40px;
      min-width: 40px;
    }

    .navbar .dropdown-toggle::after {
      display: none;
    }

    #reminderBellMenu {
      min-width: min(92vw, 320px) !important;
      max-width: calc(100vw - 16px);
    }

    .app-sidebar,
    body.sidebar-collapsed .app-sidebar,
    .app-sidebar.collapsed,
    body.sidebar-collapsed .app-sidebar.collapsed {
      width: min(86vw, 320px) !important;
      min-width: min(86vw, 320px) !important;
      max-width: min(86vw, 320px) !important;
      top: 48px;
      height: calc(100dvh - 48px);
      min-height: 0;
      transform: translateX(-104%);
      transition: transform .24s ease;
      z-index: 3000 !important;
      box-shadow: 18px 0 38px rgba(15, 23, 42, 0.28);
      border-radius: 0 14px 14px 0;
    }

    main.flex-grow-1,
    .main-content-scroll,
    body.sidebar-collapsed main.flex-grow-1,
    body.sidebar-collapsed .main-content-scroll {
      margin-left: 0;
      width: 100%;
      padding: 0.85rem !important;
    }

    .main-content-scroll > .container-fluid {
      padding-left: 0;
      padding-right: 0;
      max-width: 100%;
    }

    .page-loading-overlay,
    body.sidebar-collapsed .page-loading-overlay {
      left: 0;
      width: 100%;
    }

    .app-sidebar .nav-link,
    .app-sidebar.collapsed .nav-link {
      justify-content: flex-start;
      width: auto;
      margin: 0.15rem 0.25rem;
      padding: 0.7rem 0.75rem;
      border-radius: 8px;
    }

    .app-sidebar .nav-link .label,
    .app-sidebar.collapsed .nav-link .label,
    body.sidebar-collapsed .app-sidebar .nav-link .label {
      display: inline-block !important;
      opacity: 1;
      visibility: visible;
      width: auto;
      max-width: calc(86vw - 82px);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    body.sidebar-mobile-open .app-sidebar {
      transform: translateX(0);
    }

    body.sidebar-mobile-open .mobile-sidebar-backdrop {
      opacity: 1;
      pointer-events: auto;
    }

    .mobile-sidebar-backdrop {
      position: fixed;
      inset: 48px 0 0 0;
      z-index: 2990;
      background: rgba(15, 23, 42, 0.48);
      opacity: 0;
      pointer-events: none;
      transition: opacity .2s ease;
    }

    .app-sidebar .brand .normal-logo,
    body.sidebar-collapsed .app-sidebar .brand .normal-logo {
      display: block !important;
    }

    .app-sidebar .brand .brand-logo-collapsed,
    body.sidebar-collapsed .app-sidebar .brand .brand-logo-collapsed {
      display: none !important;
    }

    .app-sidebar .sidebar-top {
      justify-content: flex-end !important;
    }

    #sidebarToggle {
      width: 36px;
      height: 36px;
      color: #fff;
    }

    .app-sidebar .nav-link::after,
    .app-sidebar.collapsed .nav-link::after {
      display: none;
    }

    .table-responsive {
      border-radius: 10px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .card .card-body {
      padding: 0.85rem;
    }

    .btn-group,
    .btn-toolbar {
      flex-wrap: wrap;
      gap: 0.35rem;
    }

    .modal-dialog {
      margin: 0.5rem;
    }
  }
  </style>

  <style>
    /* Theme specific scrollbar tweaks */
    body.theme-dark .app-sidebar::-webkit-scrollbar-track { background: rgba(230,238,248,0.04); }
    body.theme-dark .app-sidebar::-webkit-scrollbar-thumb { background: rgba(230,238,248,0.20); }
    body.theme-dark main.flex-grow-1::-webkit-scrollbar-track, body.theme-dark .main-content-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.04); }
    body.theme-dark main.flex-grow-1::-webkit-scrollbar-thumb, body.theme-dark .main-content-scroll::-webkit-scrollbar-thumb { background: rgba(230,238,248,0.16); }

    body.theme-light .app-sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.08); }
    body.theme-light .app-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.24); }
    body.theme-light main.flex-grow-1::-webkit-scrollbar-track, body.theme-light .main-content-scroll::-webkit-scrollbar-track { background: rgba(11,26,49,0.06); }
    body.theme-light main.flex-grow-1::-webkit-scrollbar-thumb, body.theme-light .main-content-scroll::-webkit-scrollbar-thumb { background: rgba(11,26,49,0.16); }
  </style>

  <style>
    /* Global scrollbars for the whole app (webkit browsers) */
    html::-webkit-scrollbar, body::-webkit-scrollbar, .modal::-webkit-scrollbar, .dropdown-menu::-webkit-scrollbar { height: 8px; width: 8px; }
    html::-webkit-scrollbar-track, body::-webkit-scrollbar-track, .modal::-webkit-scrollbar-track, .dropdown-menu::-webkit-scrollbar-track { background: transparent; }
    html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb, .modal::-webkit-scrollbar-thumb, .dropdown-menu::-webkit-scrollbar-thumb { background: rgba(11,26,49,0.16); border-radius: 6px; }
    html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover, .modal::-webkit-scrollbar-thumb:hover, .dropdown-menu::-webkit-scrollbar-thumb:hover { background: rgba(11,26,49,0.22); }

    /* Firefox global scrollbar color shorthand */
    html, body, .modal, .dropdown-menu { scrollbar-width: thin; scrollbar-color: rgba(11,26,49,0.08) transparent; }

    /* Theme variants for global scrollbars */
    body.theme-dark html::-webkit-scrollbar-thumb, body.theme-dark body::-webkit-scrollbar-thumb, body.theme-dark .modal::-webkit-scrollbar-thumb { background: rgba(230,238,248,0.16); }
    body.theme-dark html::-webkit-scrollbar-track, body.theme-dark body::-webkit-scrollbar-track, body.theme-dark .modal::-webkit-scrollbar-track { background: rgba(230,238,248,0.04); }
    body.theme-dark html, body.theme-dark body, body.theme-dark .modal { scrollbar-color: rgba(230,238,248,0.08) rgba(230,238,248,0.02); }

    body.theme-light html::-webkit-scrollbar-thumb, body.theme-light body::-webkit-scrollbar-thumb, body.theme-light .modal::-webkit-scrollbar-thumb { background: rgba(11,26,49,0.16); }
    body.theme-light html::-webkit-scrollbar-track, body.theme-light body::-webkit-scrollbar-track, body.theme-light .modal::-webkit-scrollbar-track { background: rgba(255,255,255,0.04); }
    body.theme-light html, body.theme-light body, body.theme-light .modal { scrollbar-color: rgba(11,26,49,0.08) rgba(255,255,255,0.02); }

    /* Make dropdowns and other small scrollables slightly thinner */
    .dropdown-menu::-webkit-scrollbar, .modal-body::-webkit-scrollbar { height:6px; width:6px; }
  </style>
