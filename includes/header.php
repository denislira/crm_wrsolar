<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SolarCRM</title>
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <!-- Font Awesome (CDN) - integrity removed to avoid blocking if hash mismatches -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer" />
  <!-- Site theme (overrides and design system) -->
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/leads_gestao.css">
  <style>
    /* Theme derived from WR Solare logo: blues, green and yellow accents */
    :root{
        --blue-700: #0b6ac1; /* main logo blue */
        --blue-900: #073b6b; /* darker blue */
        --green: #4bbf4b;    /* leaf green */
        --yellow: #ffd24a;   /* sun yellow */
  --muted-bg: #e9eef5;
        --sidebar-w: 260px;
        --sidebar-collapsed-w: 64px;
    }
  /* Remove default browser page margin and set base font/background
    Add top padding so fixed navbar doesn't cover content. */
  body{ margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; background:var(--muted-bg); overflow-x: hidden; padding-top:56px; }
  /* Keep navbar always visible: fixed at top */
  .navbar { position: fixed; top: 0; z-index: 1040; width:100%; }
  /* Reduce the default container padding so brand sits closer to the left edge.
    Make the navbar flush with the viewport edges to maximize usable space. */
  html, body { width: 100%; }
  .navbar { padding-left: 0; padding-right: 0; width: 100%; }
  .navbar .container-fluid { padding-left: 6px; padding-right: 6px; max-width: 100%; margin: 0; }
  .app-sidebar { position: fixed; top: 56px; left: 0; height: calc(100vh - 56px); overflow-y: auto; z-index: 1030; box-shadow: 2px 0 12px rgba(7,59,107,0.08); }
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
  /* Slight negative left margin on the toggle to pull it closer to the very left edge if needed */
  #sidebarToggle { padding: .35rem .6rem; border-radius:8px; margin-left:4px; }
  </style>
</head>
<body>
  <?php if (empty($noNavbar)): ?>
  <nav class="navbar navbar-light bg-white">
    <div class="container-fluid d-flex align-items-center">
      <div class="d-flex align-items-center">
        <button id="sidebarToggle" class="btn btn-light me-2" title="Alternar menu" aria-label="Alternar menu">☰</button>
        <a class="navbar-brand d-flex align-items-center text-dark" href="index.php">
          <img src="assets/img/wrsolare-logo.png" alt="WR Solare" height="28" style="margin-right:.5rem;">
          <span class="fw-semibold" style="color:var(--blue-900);"></span>
        </a>
      </div>
      <div class="ms-auto d-flex align-items-center">
        <?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
        <?php $displayName = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Usuário'; ?>
        <div class="me-2 d-none d-md-inline text-dark">Olá, <strong><?php echo $displayName; ?></strong></div>
        <a href="logout.php" class="btn btn-outline-secondary" title="Sair" aria-label="Sair">
          <i class="fa-solid fa-right-from-bracket"></i>
        </a>
      </div>
    </div>
  </nav>
  <?php endif; ?>

  <style>
    /* Collapsible sidebar styles - single authoritative definition */
    .app-sidebar { transition: width .22s ease; box-sizing: border-box; width: 260px; min-width: 260px; max-width: 260px; flex: 0 0 260px; position: fixed; top: 56px; left: 0; height: calc(100vh - 56px); overflow-y: auto; z-index:1030; background: linear-gradient(180deg,var(--blue-900),var(--blue-700)); color: #fff; }
    .app-sidebar.collapsed { width: 64px !important; min-width: 64px; max-width: 64px; flex: 0 0 64px; }
    /* Prevent width jumping from padding/margins when active/hover */
    .app-sidebar .nav-link { display:flex; align-items:center; gap: .5rem; padding: .5rem 0.5rem; }
    .app-sidebar .nav-link.active, .app-sidebar .nav-link:hover { padding: .5rem 0.5rem; }
    .app-sidebar.collapsed .nav-link { justify-content: center; padding: .5rem 0; }
    /* Icon alignment: left-aligned when expanded, centered when collapsed */
    .app-sidebar .nav-link .icon { width:24px; text-align:left; font-size:16px; display:inline-block; margin-left:0; }
    .app-sidebar.collapsed .nav-link .icon { text-align:center; width: 100%; }
    .app-sidebar .nav-link .label { transition: all .15s ease; display: inline-block; }
    .app-sidebar.collapsed .nav-link .label { opacity:0; visibility:hidden; width:0; margin:0; padding:0; transform: translateX(-10px); }
    /* Ensure Font Awesome icons are visible in the sidebar */
    .app-sidebar .icon i { color: #fff; }

  /* Main content offset to avoid overlap with fixed sidebar */
  main.flex-grow-1, .main-content-scroll { margin-left: var(--sidebar-w); padding: 1.5rem; transition: margin-left .22s ease, width .22s ease; }
  /* Ensure container spans remaining width */
  .main-content-scroll > .container-fluid { width: 100%; padding-left: 1rem; padding-right: 1rem; }
  /* When sidebar is collapsed, reduce left margin so content resizes */
  body.sidebar-collapsed .app-sidebar { width: var(--sidebar-collapsed-w); min-width: var(--sidebar-collapsed-w); max-width: var(--sidebar-collapsed-w); }
  body.sidebar-collapsed main.flex-grow-1, body.sidebar-collapsed .main-content-scroll { margin-left: var(--sidebar-collapsed-w); width: calc(100% - var(--sidebar-collapsed-w)); }
  body.sidebar-collapsed .main-content-scroll > .container-fluid { width: 100%; }
  </style>
