<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
}
$user_id = (int) $_SESSION['user_id'];
if (empty($_SESSION['profile_password_csrf'])) $_SESSION['profile_password_csrf'] = bin2hex(random_bytes(32));
// Schema migrations are handled manually, outside the page request.
// fetch user info (best-effort)
$user = null;
try {
        $stmt = $pdo->prepare('SELECT id, username, email, nome_completo, biografia, avatar, ai_bot_mode FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user['name'] = $user['nome_completo'] ?? '';
        }
} catch (Exception $e) {
        // ignore - we'll show basic info from session
}

$profileNomeCompleto = trim((string)($user['nome_completo'] ?? ''));
if ($profileNomeCompleto === '') $profileNomeCompleto = trim((string)($_SESSION['name'] ?? ''));
$profileEmail = trim((string)($user['email'] ?? ''));
if ($profileEmail === '') $profileEmail = trim((string)($_SESSION['email'] ?? ''));
$profileBiografia = (string)($user['biografia'] ?? '');
$profileAiBotMode = (string)($user['ai_bot_mode'] ?? 'chatbot3');
if ($profileAiBotMode === 'speech') {
    $profileAiBotMode = 'body';
}
if (!in_array($profileAiBotMode, ['none', 'head', 'body', 'chatbot1', 'chatbot3'], true)) {
    $profileAiBotMode = 'chatbot3';
}

// Lists are loaded lazily via api/profile_data.php. Keeping the initial request
// limited to the profile shell prevents hundreds of rows from blocking the page.
$profile_leads = [];
$profile_projects = [];
$profile_movements = [];
$profile_reminders = [];
$profile_tasks = [];

// Etapas reais do funil, usadas na aba Fluxo de Atendimento.
$profile_funnel_stages = [];
try {
    $stageCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'")->fetchAll(PDO::FETCH_COLUMN);
    $stageNameCol = in_array('name', $stageCols, true) ? 'name' : (in_array('stage_name', $stageCols, true) ? 'stage_name' : 'name');
    $stageOrderCol = in_array('position', $stageCols, true) ? 'position' : (in_array('stage_order', $stageCols, true) ? 'stage_order' : 'id');
    $stageColorCol = in_array('color', $stageCols, true) ? 'color' : (in_array('stage_color', $stageCols, true) ? 'stage_color' : null);
    $stageSelect = "id, {$stageNameCol} AS name, {$stageOrderCol} AS position";
    $stageSelect .= $stageColorCol ? ", {$stageColorCol} AS color" : ", NULL AS color";
    $stageSelect .= in_array('final_type', $stageCols, true) ? ", final_type" : ", 'none' AS final_type";
    $profile_funnel_stages = $pdo->query("SELECT {$stageSelect} FROM funil_stages ORDER BY {$stageOrderCol} ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $profile_funnel_stages = [];
}

// Build usersMap for avatar display
$usersMap = [];
try {
    $usersStmt = $pdo->query('SELECT id, username, nome_completo AS name, nome_completo, biografia, email, avatar FROM users ORDER BY id');
    $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allUsers as $u) {
        $usersMap[$u['id']] = $u;
    }
} catch (Exception $e) { /* ignore */ }

// User-scoped metrics (cards similar to dashboard but only for this user)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0 AND status NOT IN ('Convertido','Perdido')");
    $stmt->execute([$user_id]);
    $totalLeadsUser = $stmt->fetchColumn();
} catch (Exception $e) { $totalLeadsUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $totalProjetosUser = $stmt->fetchColumn();
} catch (Exception $e) { $totalProjetosUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $valorNegociacaoUser = $stmt->fetchColumn();
} catch (Exception $e) { $valorNegociacaoUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE user_id = ? AND status = 'Finalizado'");
    $stmt->execute([$user_id]);
    $projetosFinalizadosUser = $stmt->fetchColumn();
} catch (Exception $e) { $projetosFinalizadosUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status = 'Finalizado'");
    $stmt->execute([$user_id]);
    $valorContratadoUser = $stmt->fetchColumn();
} catch (Exception $e) { $valorContratadoUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0");
    $stmt->execute([$user_id]);
    $totalLeadsAllUser = $stmt->fetchColumn();
    $conversionRateUser = $totalLeadsAllUser > 0 ? round(($projetosFinalizadosUser / $totalLeadsAllUser) * 100, 1) : 0;
} catch (Exception $e) { $totalLeadsAllUser = 0; $conversionRateUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute([$user_id]);
    $newLeads30User = $stmt->fetchColumn();
} catch (Exception $e) { $newLeads30User = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(AVG(NULLIF(proposal_value,0)),0) FROM projetos WHERE user_id = ? AND proposal_value > 0");
    $stmt->execute([$user_id]);
    $avgProposalUser = $stmt->fetchColumn();
} catch (Exception $e) { $avgProposalUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $openProposalsSumUser = $stmt->fetchColumn();
    $revenueForecastUser = round(($openProposalsSumUser * ($conversionRateUser / 100)), 2);
} catch (Exception $e) { $openProposalsSumUser = 0; $revenueForecastUser = 0; }


// Carregar cor primária das configurações
$primary_color = '#0b6ac1';
$primary_dark = '#073b6b';
$storageDir = __DIR__ . '/storage';
$settingsPath = $storageDir . '/settings.json';
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
    if (!empty($settings['primary_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $settings['primary_color'])) {
        $primary_color = $settings['primary_color'];
    }
    if (!empty($settings['primary_dark']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $settings['primary_dark'])) {
        $primary_dark = $settings['primary_dark'];
    }
}

include __DIR__ . '/includes/header.php';
// sidebar contains navigation
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .profile-header-gradient {
        background: linear-gradient(135deg, <?php echo htmlspecialchars($primary_dark); ?> 0%, <?php echo htmlspecialchars($primary_color); ?> 100%);
        border-radius: 16px;
        padding: 2rem;
        color: white !important;
        margin-bottom: 2rem;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }
    .profile-header-gradient h2,
    .profile-header-gradient p,
    .profile-header-gradient a,
    .profile-header-gradient * {
        color: white !important;
    }
    .profile-card {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        border: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        height: 100%;
    }
    .profile-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    .profile-card.profile-modern-panel {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 18px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        padding: 0;
    }
    .profile-card.profile-modern-panel:hover {
        transform: none;
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.09);
    }
    .profile-panel-header {
        align-items: center;
        border-bottom: 1px solid #eef2f7;
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.85rem 1rem;
    }
    .profile-panel-title {
        align-items: center;
        color: #0f172a;
        display: flex;
        font-size: 0.95rem;
        font-weight: 800;
        gap: 0.6rem;
        margin: 0;
    }
    .profile-panel-icon {
        align-items: center;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        color: <?php echo htmlspecialchars($primary_color); ?>;
        display: inline-flex;
        height: 32px;
        justify-content: center;
        width: 32px;
    }
    .profile-panel-count {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.35rem 0.7rem;
        white-space: nowrap;
    }
    .min-width-0 {
        min-width: 0;
    }
    .profile-avatar-large {
        width: 140px;
        height: 140px;
        object-fit: cover;
        border-radius: 50%;
        border: 5px solid white;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .profile-info-item {
        padding: 0.75rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .profile-overview-grid { display:grid; grid-template-columns:minmax(230px,.72fr) minmax(0,2fr); gap:1.5rem; align-items:stretch; }
    .profile-avatar-column { padding:1.25rem; border:1px solid #e8eef5; border-radius:16px; background:linear-gradient(145deg,#f8fbff,#f3f7fc); }
    .profile-avatar-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; margin-bottom:1rem; }
    .profile-avatar-actions .btn { width:100%; min-height:42px; display:inline-flex; align-items:center; justify-content:center; white-space:nowrap; }
    .profile-details-column { min-width:0; padding:.25rem .35rem; }
    .profile-details-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.5rem; padding-bottom:.8rem; border-bottom:1px solid #e8eef5; }
    .profile-details-header h6 { margin:0 !important; color:#172033; font-size:1rem; font-weight:750; }
    .profile-details-actions { display:flex; align-items:center; justify-content:flex-end; gap:.5rem; flex-wrap:wrap; }
    .profile-details-actions .btn { min-height:38px; display:inline-flex; align-items:center; justify-content:center; gap:.45rem; padding:.45rem .85rem; border-radius:11px; font-weight:700; white-space:nowrap; }
    .profile-edit-button { box-shadow:0 4px 12px rgba(5,150,105,.16); }
    .profile-edit-button:hover { transform:translateY(-1px); box-shadow:0 6px 15px rgba(5,150,105,.23); }
    .profile-info-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); column-gap:1.5rem; }
    .profile-info-grid .profile-info-item { min-width:0; }
    .profile-details-column > hr { display:none; }
    .profile-details-column > div:last-child { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.9rem 1rem; }
    .profile-info-item:last-child {
        border-bottom: none;
    }
    .profile-info-label {
        font-weight: 600;
        color: #6c757d;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .profile-info-value {
        color: #2d3748;
        font-size: 1rem;
        margin-top: 0.25rem;
    }
    .profile-section-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: #2d3748;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .profile-section-title::before {
        content: '';
        width: 4px;
        height: 24px;
        background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        border-radius: 4px;
    }
    .task-item {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
    }
    .task-item:hover {
        border-color: #667eea;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
    }
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.875rem;
    }
    .badge-custom {
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .badge-pendente { background: #fef3c7; color: #92400e; }
    .badge-andamento { background: #dbeafe; color: #1e40af; }
    .badge-concluida { background: #d1fae5; color: #065f46; }
    body.theme-dark .badge-custom.badge-pendente { background: rgba(254,243,199,0.18) !important; color: #f59e0b !important; border: 1px solid rgba(255,255,255,0.12) !important; }
    body.theme-dark .badge-custom.badge-andamento { background: rgba(219,234,254,0.18) !important; color: #93c5fd !important; border: 1px solid rgba(255,255,255,0.12) !important; }
    body.theme-dark .badge-custom.badge-concluida { background: rgba(209,250,229,0.18) !important; color: #34d399 !important; border: 1px solid rgba(255,255,255,0.12) !important; }
    .list-item {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    .list-item:hover {
        background: #f8f9fa;
    }
    .list-item:last-child {
        border-bottom: none;
    }
    .profile-list-item {
        background: #fff;
    }
    .profile-modern-panel .profile-list-item {
        border-bottom-color: #eef2f7;
    }
    .profile-modern-panel .profile-list-item h6 {
        color: #0f172a;
        font-size: 0.9rem;
        line-height: 1.2;
        margin-bottom: 0.25rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .profile-modern-panel .profile-list-item p,
    .profile-modern-panel .profile-list-item .small {
        font-size: 0.78rem;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }
    .profile-modern-panel .profile-list-item .badge {
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.34rem 0.55rem;
        white-space: nowrap;
    }
    .profile-modern-panel .avatar-circle {
        height: 28px !important;
        width: 28px !important;
    }
    .movement-item {
        padding: 0.75rem 0.95rem;
    }
    .movement-row {
        align-items: center;
        display: grid;
        gap: 0.85rem;
        grid-template-columns: minmax(170px, 0.9fr) minmax(260px, 1.4fr) minmax(140px, 0.7fr);
    }
    .movement-person {
        align-items: center;
        display: flex;
        gap: 0.65rem;
        min-width: 0;
    }
    .movement-avatar {
        align-items: center;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        color: #334155;
        display: inline-flex;
        flex-shrink: 0;
        font-size: 0.72rem;
        font-weight: 800;
        height: 34px;
        justify-content: center;
        width: 34px;
    }
    .movement-user {
        color: #0f172a;
        font-size: 0.88rem;
        font-weight: 800;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .movement-time {
        color: #64748b;
        font-size: 0.74rem;
        line-height: 1.2;
        margin-top: 0.12rem;
    }
    .movement-flow {
        min-width: 0;
    }
    .movement-row > .flex-grow-1 {
        align-items: center;
        display: grid;
        gap: 0.85rem;
        grid-column: span 2;
        grid-template-columns: minmax(230px, 1fr) minmax(140px, 0.7fr);
        min-width: 0;
    }
    .movement-row > .flex-grow-1 > .small:first-child {
        display: none;
    }
    .movement-row > .flex-grow-1 > .small.text-muted {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 0.42rem;
        margin: 0 !important;
        min-width: 0;
    }
    .movement-row > .flex-grow-1 > .small.text-muted::before {
        color: #64748b;
        content: 'Lead';
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .movement-row > .flex-grow-1 > .small.text-muted .mx-2 {
        display: none;
    }
    .movement-row > .flex-grow-1 > .small.text-muted .badge {
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 800;
        max-width: 170px;
        overflow: hidden;
        padding: 0.34rem 0.62rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .movement-row > .flex-grow-1 > .small.text-muted .fa-arrow-right {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        color: #64748b;
        font-size: 0.68rem;
        height: 24px;
        line-height: 22px;
        margin: 0 !important;
        text-align: center;
        width: 24px;
    }
    .movement-row > .flex-grow-1 > p {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        color: #475569;
        font-size: 0.78rem;
        line-height: 1.35;
        margin: 0 !important;
        max-height: 2.8em;
        overflow: hidden;
        padding: 0.45rem 0.6rem;
    }
    .movement-row > .flex-grow-1:not(:has(> p))::after {
        color: #94a3b8;
        content: 'Sem observação';
        font-size: 0.78rem;
        font-style: italic;
    }
    .movement-lead {
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 700;
        margin-bottom: 0.32rem;
        text-transform: uppercase;
    }
    .movement-statuses {
        align-items: center;
        display: flex;
        gap: 0.45rem;
        min-width: 0;
    }
    .movement-status {
        border-radius: 999px;
        display: inline-block;
        font-size: 0.72rem;
        font-weight: 800;
        max-width: 180px;
        overflow: hidden;
        padding: 0.34rem 0.62rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .movement-status.from {
        background: #f1f5f9;
        color: #475569;
    }
    .movement-status.to {
        background: #dbeafe;
        color: #1d4ed8;
    }
    .movement-arrow {
        align-items: center;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        color: #64748b;
        display: inline-flex;
        flex-shrink: 0;
        height: 24px;
        justify-content: center;
        width: 24px;
    }
    .movement-note {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        color: #475569;
        font-size: 0.78rem;
        line-height: 1.35;
        margin: 0;
        max-height: 2.8em;
        overflow: hidden;
        padding: 0.45rem 0.6rem;
    }
    .movement-note.empty {
        background: transparent;
        border-color: transparent;
        color: #94a3b8;
        font-style: italic;
        padding-left: 0;
    }
    .profile-list-item.is-hidden {
        display: none !important;
    }
    .profile-list-footer {
        border-top: 1px solid #eef2f7;
        padding: 0.65rem 1rem 0.8rem;
        text-align: center;
    }
    .profile-load-more {
        align-items: center;
        border-radius: 8px;
        display: inline-flex;
        font-weight: 700;
        gap: 0.45rem;
        justify-content: center;
        min-width: 145px;
        padding: 0.35rem 0.8rem;
    }
    .profile-load-more.is-hidden {
        display: none !important;
    }
    .btn-modern {
        border-radius: 8px;
        font-weight: 600;
        padding: 0.5rem 1.25rem;
        transition: all 0.2s ease;
    }
    .btn-modern:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .scrollable-list {
        overflow: visible;
    }
    .tasks-panel {
        background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(248,250,252,0.96) 100%);
        border: 1px solid rgba(148,163,184,0.18);
        border-radius: 18px;
        padding: 1rem;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
    }
    .tasks-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
    }
    .tasks-panel-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
    }
    .tasks-panel-subtitle {
        margin: 0.25rem 0 0;
        color: #64748b;
        font-size: 0.82rem;
    }
    .tasks-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .task-summary-chip {
        border-radius: 999px;
        padding: 0.35rem 0.65rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.74rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        box-shadow: none;
    }
    .task-summary-chip .chip-value {
        color: #0f172a;
        font-size: 0.84rem;
    }
    .task-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }
    .task-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        padding: 0.7rem 0.75rem;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .task-card:hover {
        transform: translateY(-1px);
        border-color: rgba(102, 126, 234, 0.35);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
    }
    .task-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 3px;
        background: linear-gradient(180deg, #cbd5e1 0%, #94a3b8 100%);
    }
    .task-card.is-pendente::before { background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%); }
    .task-card.is-andamento::before { background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%); }
    .task-card.is-concluida::before { background: linear-gradient(180deg, #10b981 0%, #059669 100%); }
    .task-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.6rem;
        margin-bottom: 0.45rem;
        padding-left: 0.25rem;
    }
    .task-card-title {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
    }
    .task-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-bottom: 0.45rem;
        padding-left: 0.25rem;
    }
    .task-meta-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        border-radius: 999px;
        padding: 0.24rem 0.5rem;
        font-size: 0.68rem;
        font-weight: 700;
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    .task-meta-pill.overdue {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }
    .task-meta-pill.due-soon {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fed7aa;
    }
    .task-description {
        margin: 0;
        color: #475569;
        display: -webkit-box;
        font-size: 0.8rem;
        line-height: 1.32;
        overflow: hidden;
        padding-left: 0.25rem;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }
    .task-card-actions {
        display: flex;
        gap: 0.35rem;
        justify-content: flex-end;
        margin-top: 0.55rem;
        padding-left: 0.25rem;
    }
    .task-card-actions .btn {
        border-radius: 8px;
        min-width: 34px;
        padding: 0.28rem 0.55rem;
    }
    .task-card .badge-custom {
        border-radius: 999px;
        font-size: 0.66rem;
        letter-spacing: 0;
        padding: 0.28rem 0.52rem;
        white-space: nowrap;
    }
    /* Fluxo de Atendimento cards */
    .fluxo-card {
        background: #fff;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        border-left: 5px solid #e2e8f0;
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .fluxo-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }
    .fluxo-card-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }
    .fluxo-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #475569;
        font-weight: 700;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .fluxo-number-red {
        background: #fee2e2;
        color: #dc2626;
    }
    .fluxo-badge {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
    }
    .fluxo-badge-blue { background: #dbeafe; color: #1e40af; }
    .fluxo-badge-purple { background: #ede9fe; color: #6d28d9; }
    .fluxo-badge-success { background: rgba(255,255,255,0.3); color: #fff; }
    .fluxo-badge-red { background: #fee2e2; color: #dc2626; }
    .fluxo-border-green { border-left-color: #22c55e; }
    .fluxo-border-purple { border-left-color: #8b5cf6; }
    .fluxo-border-yellow { border-left-color: #f59e0b; }
    .fluxo-border-blue { border-left-color: #3b82f6; }
    .fluxo-border-red { border-left-color: #ef4444; }
    .fluxo-border-orange { border-left-color: #f97316; }
    .fluxo-card-success {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        color: #fff;
        border-left-color: #15803d;
    }
    .fluxo-card-success h6,
    .fluxo-card-success p { color: #fff !important; }
    .fluxo-check {
        font-size: 1.5rem;
        color: #fff;
    }
    #perfilTabs .nav-link {
        border-radius: 10px;
        font-weight: 600;
        padding: 0.6rem 1.25rem;
        color: #64748b;
        transition: all 0.2s ease;
    }
    #perfilTabs .nav-link.active {
        background: <?php echo htmlspecialchars($primary_color); ?>;
        color: #fff;
    }
    #perfilTabs .nav-link:not(.active):hover {
        background: #f1f5f9;
        color: #334155;
    }
    #perfilTabContent > .tab-pane[hidden],
    #perfilTabContent[data-active-pane="fluxo"] #pane-perfil,
    #perfilTabContent[data-active-pane="perfil"] #pane-fluxo { display: none !important; }
    /* Modal edit task styles */
    .task-avatar { width:56px; height:56px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:1.25rem; color:#fff; background:#6c757d; }
    .modal-header.colorful { background:linear-gradient(120deg, var(--blue-900) 0%, var(--blue-700) 100%); color:#fff; }
    body.theme-dark .profile-header-gradient,
    body.theme-dark .profile-card,
    body.theme-dark .tasks-panel,
    body.theme-dark .task-card,
    body.theme-dark .task-summary-chip,
    body.theme-dark .task-meta-pill,
    body.theme-dark .task-item,
    body.theme-dark .list-item,
    body.theme-dark .fluxo-card,
    body.theme-dark .modal-content,
    body.theme-dark .modal-header.colorful,
    body.theme-dark .modal-body,
    body.theme-dark .modal-footer {
        background: rgba(255,255,255,0.04) !important;
        color: #e6eef8 !important;
        border-color: rgba(255,255,255,0.08) !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
    }
    body.theme-dark #modalEditarTarefa .modal-header.colorful {
        background: linear-gradient(120deg, var(--blue-900) 0%, var(--blue-700) 100%) !important;
        border-bottom: 1px solid rgba(255,255,255,0.12) !important;
    }
    body.theme-dark .modal-body .form-label,
    body.theme-dark .modal-body .text-muted,
    body.theme-dark .modal-body .small {
        color: #cbd5e1 !important;
    }
    body.theme-dark .modal-body .form-control,
    body.theme-dark .modal-body .form-select {
        background: rgba(255,255,255,0.05) !important;
        color: #e6eef8 !important;
        border-color: rgba(255,255,255,0.12) !important;
    }
    body.theme-dark .modal-body .form-control:focus,
    body.theme-dark .modal-body .form-select:focus {
        background: rgba(255,255,255,0.07) !important;
        box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb),0.2) !important;
    }
    body.theme-dark .profile-header-gradient h2,
    body.theme-dark .profile-header-gradient p,
    body.theme-dark .profile-header-gradient a,
    body.theme-dark .profile-section-title,
    body.theme-dark .tasks-panel-title,
    body.theme-dark .tasks-panel-subtitle,
    body.theme-dark .profile-info-label,
    body.theme-dark .profile-info-value,
    body.theme-dark .task-card,
    body.theme-dark .task-card *,
    body.theme-dark .task-item,
    body.theme-dark .task-item *,
    body.theme-dark .list-item,
    body.theme-dark .list-item *,
    body.theme-dark .fluxo-card,
    body.theme-dark .fluxo-card *,
    body.theme-dark .text-muted,
    body.theme-dark .small {
        color: #e6eef8 !important;
    }
    body.theme-dark .profile-info-item,
    body.theme-dark .task-item,
    body.theme-dark .list-item {
        border-bottom-color: rgba(255,255,255,0.08) !important;
    }
    body.theme-dark .profile-avatar-large {
        border-color: rgba(255,255,255,0.18) !important;
    }
    body.theme-dark #perfilTabs .nav-link:not(.active):hover {
        background: rgba(255,255,255,0.05) !important;
        color: #fff !important;
    }
    body.theme-dark .fluxo-number {
        background: rgba(255,255,255,0.08) !important;
        color: #e6eef8 !important;
    }
    body.theme-dark .fluxo-badge-blue,
    body.theme-dark .fluxo-badge-purple,
    body.theme-dark .fluxo-badge-red {
        background: rgba(255,255,255,0.08) !important;
        color: #e6eef8 !important;
    }
    body.theme-dark .btn-modern.btn-outline-primary,
    body.theme-dark .btn-modern.btn-outline-danger,
    body.theme-dark .btn-modern.btn-outline-secondary,
    body.theme-dark .btn-modern.btn-primary {
        color: #e6eef8 !important;
    }
    body.theme-dark .btn-modern.btn-outline-primary,
    body.theme-dark .btn-modern.btn-outline-danger,
    body.theme-dark .btn-modern.btn-outline-secondary {
        border-color: rgba(255,255,255,0.12) !important;
        background: transparent !important;
    }
    body.theme-dark .task-summary-chip,
    body.theme-dark .task-meta-pill {
        background: rgba(255,255,255,0.05) !important;
        border-color: rgba(255,255,255,0.12) !important;
    }
    body.theme-dark .profile-panel-header,
    body.theme-dark .profile-list-footer {
        border-color: rgba(255,255,255,0.08) !important;
    }
    body.theme-dark .profile-panel-title,
    body.theme-dark .profile-panel-count {
        color: #e6eef8 !important;
    }
    body.theme-dark .profile-panel-icon,
    body.theme-dark .profile-panel-count {
        background: rgba(255,255,255,0.05) !important;
        border-color: rgba(255,255,255,0.12) !important;
    }
    body.theme-dark .movement-avatar,
    body.theme-dark .movement-arrow,
    body.theme-dark .movement-row > .flex-grow-1 > .small.text-muted .fa-arrow-right,
    body.theme-dark .movement-row > .flex-grow-1 > p {
        background: rgba(255,255,255,0.05) !important;
        border-color: rgba(255,255,255,0.12) !important;
    }
    body.theme-dark .movement-user,
    body.theme-dark .movement-avatar {
        color: #e6eef8 !important;
    }
    body.theme-dark .movement-time,
    body.theme-dark .movement-lead,
    body.theme-dark .movement-row > .flex-grow-1 > .small.text-muted::before {
        color: #cbd5e1 !important;
    }
    body.theme-dark .movement-row > .flex-grow-1 > p {
        color: #dbeafe !important;
    }
    body.theme-dark .movement-row > .flex-grow-1:not(:has(> p))::after {
        color: #94a3b8 !important;
    }
    body.theme-dark .task-meta-pill.overdue {
        background: rgba(254, 226, 226, 0.14) !important;
        color: #fca5a5 !important;
        border-color: rgba(248, 113, 113, 0.28) !important;
    }
    body.theme-dark .task-meta-pill.due-soon {
        background: rgba(255, 237, 213, 0.14) !important;
        color: #fdba74 !important;
        border-color: rgba(251, 146, 60, 0.28) !important;
    }
    @media (max-width: 768px) {
        .profile-panel-header,
        .tasks-panel-header {
            align-items: stretch;
            flex-direction: column;
        }
        .tasks-summary {
            display: grid;
            grid-template-columns: 1fr;
        }
        .profile-panel-count,
        .task-summary-chip {
            justify-content: center;
            width: 100%;
        }
        .task-card-header {
            flex-direction: column;
        }
        .task-card-actions {
            justify-content: stretch;
        }
        .task-card-actions .btn {
            flex: 1;
        }
        .movement-row,
        .movement-row > .flex-grow-1 {
            grid-template-columns: 1fr;
        }
        .movement-row > .flex-grow-1 {
            grid-column: auto;
        }
        .movement-row > .flex-grow-1 > .small.text-muted .badge {
            max-width: 100%;
        }
    }

    /* Visual exclusivo da página Meu Perfil */
    .profile-page {
        --profile-accent: <?php echo htmlspecialchars($primary_color); ?>;
        --profile-accent-dark: <?php echo htmlspecialchars($primary_dark); ?>;
        --profile-border: #e7ecf3;
        --profile-muted: #64748b;
        background: #f6f8fb;
        padding: 1.5rem !important;
    }
    .profile-page > .container-fluid { max-width: 1480px; margin: 0 auto; }
    .profile-page .profile-header-gradient {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(255,255,255,.18);
        border-radius: 22px;
        padding: 1.05rem 1.4rem;
        margin-bottom: 1rem;
        box-shadow: 0 14px 36px rgba(15,23,42,.12);
    }
    .profile-page .profile-header-gradient::after {
        content: '';
        position: absolute;
        width: 280px; height: 280px;
        right: -70px; top: -145px;
        border-radius: 50%;
        background: rgba(255,255,255,.09);
        pointer-events: none;
    }
    .profile-page .profile-header-gradient h2 { font-size: 1.35rem; letter-spacing: -.025em; }
    .profile-page #perfilTabs {
        display: inline-flex;
        gap: .35rem;
        padding: .35rem;
        margin-bottom: 1.25rem !important;
        border: 1px solid var(--profile-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 4px 16px rgba(15,23,42,.04);
    }
    .profile-page #perfilTabs .nav-link { border-radius: 10px; padding: .58rem 1rem; font-size: .88rem; }
    .profile-page .profile-card,
    .profile-page .tasks-panel {
        border: 1px solid var(--profile-border);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 7px 24px rgba(15,23,42,.055);
    }
    .profile-page .profile-card:hover,
    .profile-page .tasks-panel:hover { transform: none; box-shadow: 0 10px 30px rgba(15,23,42,.075); }
    .profile-page .profile-overview-layout > .col-12 > .profile-card {
        position: relative;
        overflow: hidden;
        padding: 1.35rem;
        background: linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
    }
    .profile-page .profile-overview-layout > .col-12 > .profile-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--profile-accent), #69aef5);
    }
    .profile-page .profile-overview-layout .profile-section-title {
        margin: 0 0 1.15rem;
        padding: 0 .25rem .9rem;
        border-bottom: 1px solid var(--profile-border);
        letter-spacing: -.01em;
    }
    .profile-page .profile-avatar-column {
        border: 1px solid #e2ebf5;
        background: linear-gradient(155deg, #f4f9ff 0%, #edf4fb 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    .profile-page .profile-details-column {
        padding: .35rem .55rem;
    }
    .profile-page .profile-info-item {
        margin: 0;
        padding: 1.05rem 1.2rem;
        border: 1px solid #e2eaf3;
        border-radius: 14px;
        background: #fff;
        transition: background .2s ease, border-color .2s ease;
    }
    .profile-page .profile-info-item:hover {
        border-color: #bfd8f0;
        background: #f8fbff;
    }
    .profile-page .profile-info-label i {
        width: 16px;
        color: var(--profile-accent);
        text-align: center;
    }
    .profile-page .profile-info-value {
        overflow-wrap: anywhere;
    }
    .profile-page .profile-info-item .edit-mode input,
    .profile-page .profile-info-item .edit-mode textarea,
    .profile-page .profile-info-item .edit-mode select {
        margin-top: .45rem;
        border: 1px solid #9bc5ee;
        border-radius: 10px;
        background: #f2f8ff;
        color: #173a63;
        font-weight: 600;
        box-shadow: inset 0 1px 2px rgba(23,58,99,.04);
        transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
    }
    .profile-page .profile-info-item .edit-mode input:focus,
    .profile-page .profile-info-item .edit-mode textarea:focus,
    .profile-page .profile-info-item .edit-mode select:focus {
        border-color: var(--profile-accent);
        background: #fff;
        box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--profile-accent) 16%, transparent);
        outline: 0;
    }
    .profile-page .profile-section-title::before { display: none; }
    .profile-page .profile-section-title { font-size: 1rem; color: #172033; }
    .profile-page .profile-avatar-large {
        width: 116px; height: 116px;
        border: 4px solid #fff;
        outline: 1px solid var(--profile-border);
        box-shadow: 0 8px 25px rgba(15,23,42,.13);
    }
    .profile-page .profile-info-item:last-child { border-bottom: 1px solid #e2eaf3; }
    .profile-page .profile-info-label { font-size: .69rem; color: #8491a5; letter-spacing: .08em; }
    .profile-page .profile-info-value { color: #172033; font-size: .92rem; font-weight: 600; }
    .profile-page .tasks-panel { padding: 1.15rem; }
    .profile-page .profile-tasks-reminders-row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1.5rem; margin:1.5rem 0 0; }
    .profile-page .profile-tasks-reminders-row > .col-lg-6 { display:flex; width:auto; max-width:none; min-width:0; padding:0; }
    .profile-page .profile-tasks-reminders-row > .col-lg-6 > .tasks-panel,
    .profile-page .profile-tasks-reminders-row > .col-lg-6 > .profile-modern-panel { display:flex; flex-direction:column; width:100%; height:100%; }
    .profile-page .profile-tasks-reminders-row .profile-list-footer { margin-top:auto; }
    .profile-insights { margin-top:1.5rem; }
    .profile-insights-header { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; margin-bottom:1rem; }
    .profile-insights-header h3 { margin:0; color:#172033; font-size:1.12rem; font-weight:800; }
    .profile-insights-header p { margin:.25rem 0 0; color:#64748b; font-size:.84rem; }
    .profile-insights-period { padding:.42rem .75rem; border:1px solid #dce8f5; border-radius:999px; color:#315579; background:#fff; font-size:.76rem; font-weight:700; white-space:nowrap; }
    .profile-insights-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; }
    .profile-insight-card, .profile-security-card { min-width:0; padding:1.2rem; border:1px solid #e3ebf4; border-radius:18px; background:#fff; box-shadow:0 7px 24px rgba(15,23,42,.055); }
    .profile-insight-card h4, .profile-security-card h4 { margin:0; color:#172033; font-size:.95rem; font-weight:800; }
    .profile-insight-card p, .profile-security-card p { margin:.3rem 0 .9rem; color:#64748b; font-size:.78rem; }
    body:not(.theme-dark) .profile-page .profile-insight-card h4 {
        color: #172033 !important;
    }
    body:not(.theme-dark) .profile-page .profile-insight-card p,
    body:not(.theme-dark) .profile-page .profile-insight-metric span {
        color: #475569 !important;
    }
    .profile-chart-frame { position:relative; height:220px; }
    .profile-chart-frame canvas { width:100% !important; height:100% !important; }
    .profile-insight-metric { display:flex; align-items:baseline; gap:.5rem; margin-bottom:.8rem; }
    .profile-insight-metric strong { color:#0b6ac1; font-size:1.55rem; line-height:1; }
    .profile-insight-metric span { color:#64748b; font-size:.78rem; }
    .profile-insight-empty { min-height:220px; display:grid; place-items:center; color:#64748b; font-size:.82rem; text-align:center; }
    .profile-advice-list { display:grid; align-content:start; gap:.65rem; min-height:220px; }
    .profile-advice-item { padding:.75rem .85rem; border:1px solid #e4edf7; border-radius:12px; background:#f7faff; color:#31516d; font-size:.83rem; line-height:1.45; }
    .profile-security-card { margin-top:1rem; }
    .profile-security-form { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.8rem; align-items:end; }
    .profile-security-form label { display:block; margin-bottom:.35rem; color:#31516d; font-size:.78rem; font-weight:700; }
    .profile-security-form .form-control { min-height:42px; border-radius:10px; }
    .profile-security-actions { display:flex; align-items:center; gap:.75rem; margin-top:1rem; flex-wrap:wrap; }
    .profile-security-feedback { font-size:.82rem; }
    .profile-page .tasks-panel-header { padding: .15rem .15rem .9rem; border-bottom: 1px solid var(--profile-border); }
    .profile-page .profile-panel-header { padding: 1rem 1.1rem; background: linear-gradient(180deg,#fff,#fbfcfe); }
    .profile-page .profile-panel-icon { border: 0; background: color-mix(in srgb, var(--profile-accent) 11%, white); }
    .profile-page .profile-panel-count { border: 0; background: #f1f5f9; color: #526074; }
    .profile-page .profile-list-item { padding: .85rem 1.1rem; }
    .profile-page .profile-list-item:hover { background: #f8fafc; }
    body.theme-dark .profile-page .profile-panel-header {
        background: #142638 !important;
        border-bottom-color: rgba(255,255,255,.10) !important;
    }
    body.theme-dark .profile-page .profile-panel-title {
        color: #f3f7fb !important;
    }
    body.theme-dark .profile-page .profile-panel-icon {
        background: rgba(255,255,255,.08) !important;
        color: #b9d7ff !important;
    }
    body.theme-dark .profile-page .profile-panel-count {
        background: rgba(255,255,255,.08) !important;
        color: #cbd5e1 !important;
    }
    body.theme-dark .profile-page .profile-list-item:hover {
        background: rgba(255,255,255,.05) !important;
    }
    .profile-page .profile-load-more { border: 0; background: #eef5ff; color: var(--profile-accent); }
    .profile-page .profile-load-more:hover { background: var(--profile-accent); color: #fff; }
    .profile-page .profile-loading-state,
    .profile-page .profile-empty-state {
        min-height: 150px; display: grid; place-items: center; text-align: center; color: #8491a5;
    }
    .profile-page .profile-loading-state i { color: var(--profile-accent); font-size: 1.25rem; }
    .profile-page .profile-item-icon {
        width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center;
        border-radius: 11px; background: #f1f5f9; color: var(--profile-accent);
    }
    body.theme-dark .profile-page .profile-item-icon {
        background: rgba(255,255,255,.08) !important;
        border: 1px solid rgba(255,255,255,.12) !important;
        color: #b9d7ff !important;
    }
    body.theme-dark .profile-page { background: #0b1220; }
    body.theme-dark .profile-avatar-column { background:rgba(255,255,255,.035); border-color:rgba(255,255,255,.09); }
    body.theme-dark .profile-details-header { border-bottom-color:rgba(255,255,255,.09); }
    body.theme-dark .profile-details-header h6 { color:#e6eef8; }
    body.theme-dark .profile-page .profile-info-item,
    body.theme-dark .profile-page .profile-info-item:last-child { background:rgba(255,255,255,.035); border:1px solid rgba(255,255,255,.12) !important; }
    body.theme-dark .profile-page .profile-info-item:hover { background:rgba(255,255,255,.07); border-color:rgba(255,255,255,.2) !important; }
    body.theme-dark .profile-insight-card, body.theme-dark .profile-security-card { background:#111d2d; border-color:rgba(255,255,255,.11); }
    body.theme-dark .profile-insights-header h3, body.theme-dark .profile-insight-card h4, body.theme-dark .profile-security-card h4, body.theme-dark .profile-security-form label { color:#e6eef8; }
    body.theme-dark .profile-insights-header p, body.theme-dark .profile-insight-card p, body.theme-dark .profile-security-card p, body.theme-dark .profile-insight-metric span { color:#b8c7dc; }
    body.theme-dark .profile-insights-period { background:#17283d; color:#c7dcf4; border-color:rgba(255,255,255,.12); }
    body.theme-dark .profile-security-form .form-control { background:#0c1726; color:#e6eef8; border-color:#30445d; }
    body.theme-dark .profile-advice-item { background:#17283d; border-color:rgba(255,255,255,.1); color:#d9e9fa; }
    body.theme-dark .profile-page #perfilTabs { background: #111b2d; border-color: rgba(255,255,255,.08); }
    @media (max-width: 768px) {
        .profile-page { padding: .85rem !important; }
        .profile-details-header { align-items:flex-start; flex-wrap:wrap; }
        .profile-details-actions { width:100%; justify-content:flex-start; }
        .profile-page .profile-header-gradient { padding: 1rem 1.15rem; border-radius: 17px; }
        .profile-page .profile-header-gradient > .d-flex { align-items: flex-start !important; gap: 1rem; }
        .profile-page .profile-overview-grid { grid-template-columns:1fr; gap:1rem; }
        .profile-page .profile-info-grid,
        .profile-page .profile-details-column > div:last-child { grid-template-columns:1fr; }
        .profile-insights-grid, .profile-security-form { grid-template-columns:1fr; }
        .profile-page .profile-tasks-reminders-row { grid-template-columns:1fr; gap:1rem; margin-top:1rem; }
        .profile-insights-header { align-items:flex-start; flex-direction:column; }
        .profile-page #perfilTabs { display: flex; width: 100%; }
        .profile-page #perfilTabs .nav-item { flex: 1; }
        .profile-page #perfilTabs .nav-link { width: 100%; padding: .55rem .4rem; }
    }
</style>

<main class="flex-grow-1 p-4 main-content-scroll profile-page">
    <div class="container-fluid">
        <!-- Header com gradiente -->
        <div class="profile-header-gradient">
            <h2 class="mb-1" style="font-weight:700;">Meu Perfil</h2>
            <p class="mb-0 opacity-75">Gerencie suas informações e acompanhe suas atividades</p>
        </div>

        <div id="profileDebug" class="mb-3 d-none">
            <small class="text-muted">Debug (user data):</small>
            <pre style="font-size:0.85rem; background:#f8f9fa; padding:8px; border-radius:6px;"><?php echo htmlspecialchars(print_r($user, true)); ?></pre>
        </div>

        <!-- Abas: Meu Perfil / Fluxo de Atendimento -->
        <ul class="nav nav-pills mb-4" id="perfilTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-perfil" data-bs-toggle="pill" data-bs-target="#pane-perfil" type="button" role="tab">
                    <i class="fas fa-user me-2"></i>Meu Perfil
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-fluxo" data-bs-toggle="pill" data-bs-target="#pane-fluxo" type="button" role="tab">
                    <i class="fas fa-route me-2"></i>Fluxo de Atendimento
                </button>
            </li>
        </ul>

        <div class="tab-content" id="perfilTabContent" data-active-pane="perfil">
        <!-- TAB: Meu Perfil -->
        <div class="tab-pane fade show active" id="pane-perfil" role="tabpanel">

        <div class="row g-4 profile-overview-layout">
            <!-- Coluna Esquerda - Foto e Informações -->
            <div class="col-12">
                <!-- Card Foto de Perfil -->
                <div class="profile-card">
                    <h5 class="profile-section-title">Foto de Perfil</h5>
                    <div class="profile-overview-grid">
                    <div class="profile-avatar-column">
                    <div class="text-center mb-4">
                        <?php
                            // load avatar if present
                            $avatarPath = null;
                            try {
                                $ast = $pdo->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
                                $ast->execute([$user_id]);
                                $arow = $ast->fetch(PDO::FETCH_ASSOC);
                                if (!empty($arow['avatar']) && file_exists(__DIR__ . '/' . $arow['avatar'])) {
                                    $avatarPath = $arow['avatar'];
                                }
                            } catch (Exception $e) { }
                            $avatarSrc = $avatarPath ?: 'assets/img/avatar-placeholder.png';
                        ?>
                        <img id="profileAvatar" src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="Avatar" class="profile-avatar-large" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Escolher nova foto</label>
                        <input id="avatarInput" type="file" accept="image/*" class="form-control" />
                    </div>
                    <div class="profile-avatar-actions">
                        <button id="btnUploadAvatar" class="btn btn-primary btn-modern">
                            <i class="fas fa-upload me-2"></i>Salvar
                        </button>
                        <button id="btnRemoveAvatar" class="btn btn-outline-danger btn-modern">
                            <i class="fas fa-trash me-2"></i>Remover
                        </button>
                    </div>
                    <div id="avatarMsg" class="mt-3 mb-4"></div>
                    </div>
                    <section class="profile-details-column">

                    <!-- Informações Pessoais -->
                    <hr class="my-4">
                    <div class="profile-details-header">
                        <h6 class="fw-bold mb-3 text-secondary">Informações Pessoais</h6>
                        <div class="profile-details-actions">
                            <button id="btnInlineCancel" type="button" class="btn btn-outline-secondary d-none"><i class="fas fa-times" aria-hidden="true"></i>Cancelar</button>
                            <button id="btnInlineEditProfile" type="button" class="btn btn-success profile-edit-button" title="Editar informações pessoais"><i class="fas fa-pen" aria-hidden="true"></i><span>Editar</span></button>
                        </div>
                    </div>
                    <div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-hashtag me-2"></i>ID do Usuário
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['id'] ?? $user_id); ?></div>
                        </div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-user me-2"></i>Usuário
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['username'] ?? ($_SESSION['username'] ?? '')); ?></div>
                        </div>
                        <div class="profile-info-item" data-field="nome_completo">
                            <div class="profile-info-label">
                                <i class="fas fa-id-card me-2"></i>Nome Completo
                            </div>
                            <div class="profile-info-value view-mode"><?php echo htmlspecialchars($profileNomeCompleto); ?></div>
                            <div class="edit-mode d-none">
                                <input type="text" class="form-control form-control-sm" name="nome_completo" value="<?php echo htmlspecialchars($profileNomeCompleto); ?>" />
                            </div>
                        </div>
                        <div class="profile-info-item" data-field="email">
                            <div class="profile-info-label">
                                <i class="fas fa-envelope me-2"></i>E-mail
                            </div>
                            <div class="profile-info-value view-mode"><?php echo htmlspecialchars($profileEmail); ?></div>
                            <div class="edit-mode d-none">
                                <input type="email" class="form-control form-control-sm" name="email" value="<?php echo htmlspecialchars($profileEmail); ?>" />
                            </div>
                        </div>
                        <div class="profile-info-item" data-field="biografia">
                            <div class="profile-info-label">
                                <i class="fas fa-user-edit me-2"></i>Biografia
                            </div>
                            <div class="profile-info-value view-mode small"><?php echo nl2br(htmlspecialchars($profileBiografia)); ?></div>
                            <div class="edit-mode d-none">
                                <textarea class="form-control form-control-sm" name="biografia" rows="3"><?php echo htmlspecialchars($profileBiografia); ?></textarea>
                            </div>
                        </div>
                        <div class="profile-info-item" data-field="ai_bot_mode">
                            <div class="profile-info-label">
                                <i class="fas fa-robot me-2"></i>Modo do bot
                            </div>
                            <div class="profile-info-value view-mode small">
                                <?php
                                    $modeLabels = [
                                        'none' => 'Nenhum',
                                        'head' => 'Avatar compacto',
                                        'body' => 'Avatar completo',
                                        'chatbot1' => 'Chat IA',
                                        'chatbot3' => 'Copiloto',
                                    ];
                                    echo htmlspecialchars($modeLabels[$profileAiBotMode] ?? 'Avatar compacto');
                                ?>
                            </div>
                            <div class="edit-mode d-none">
                                <select class="form-select form-select-sm ai-bot-mode-select" name="ai_bot_mode" data-current-mode="<?php echo htmlspecialchars($profileAiBotMode, ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="chatbot3" <?php echo $profileAiBotMode === 'chatbot3' ? 'selected' : ''; ?>>Copiloto</option>
                                    <option value="chatbot1" <?php echo $profileAiBotMode === 'chatbot1' ? 'selected' : ''; ?>>Chat IA</option>
                                    <option value="none" <?php echo $profileAiBotMode === 'none' ? 'selected' : ''; ?>>Nenhum</option>
                                    <option value="head" <?php echo $profileAiBotMode === 'head' ? 'selected' : ''; ?>>Avatar compacto</option>
                                    <option value="body" <?php echo $profileAiBotMode === 'body' ? 'selected' : ''; ?>>Avatar completo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    </section>
                    </div>
                </div>
            </div>

        </div>

        <section class="profile-insights" aria-labelledby="profileInsightsTitle">
            <div class="profile-insights-header">
                <div>
                    <h3 id="profileInsightsTitle"><i class="fas fa-chart-line me-2 text-primary" aria-hidden="true"></i>Sua atividade em números</h3>
                    <p>Indicadores da sua carteira e das tarefas sob sua responsabilidade.</p>
                </div>
                <span class="profile-insights-period">Últimos 30 dias para novos leads</span>
            </div>
            <div id="profileInsightsStatus" class="small text-muted mb-2" role="status">Carregando indicadores...</div>
            <div class="profile-insights-grid">
                <article class="profile-insight-card">
                    <h4>Novos leads</h4>
                    <p>Evolução diária da sua carteira nos últimos 30 dias</p>
                    <div class="profile-insight-metric"><strong id="profileLeads30Total">—</strong><span>leads no período</span></div>
                    <div class="profile-chart-frame"><canvas id="profileLeadsTrendChart" role="img" aria-label="Leads criados por dia nos últimos 30 dias"></canvas></div>
                </article>
                <article class="profile-insight-card">
                    <h4>Etapas dos leads</h4>
                    <p>Distribuição atual dos seus leads por status</p>
                    <div class="profile-insight-metric"><strong id="profileStagesTotal">—</strong><span>leads na carteira</span></div>
                    <div class="profile-chart-frame"><canvas id="profileStagesChart" role="img" aria-label="Quantidade de leads por etapa"></canvas></div>
                </article>
                <article class="profile-insight-card">
                    <h4>Situação das tarefas</h4>
                    <p>Tarefas criadas por você ou atribuídas a você</p>
                    <div class="profile-insight-metric"><strong id="profileTasksOverdue">—</strong><span>vencidas e não concluídas</span></div>
                    <div class="profile-chart-frame"><canvas id="profileTasksChart" role="img" aria-label="Tarefas pendentes, em andamento e concluídas"></canvas></div>
                </article>
                <article class="profile-insight-card">
                    <h4>O que observar</h4>
                    <p>Leitura rápida dos indicadores para orientar o próximo passo</p>
                    <div id="profileInsightsAdvice" class="profile-insight-empty">Carregando recomendações...</div>
                </article>
            </div>
        </section>

        <div class="profile-tasks-reminders-row">
            <!-- Tarefas e lembretes compartilham a mesma linha no desktop. -->
            <div class="col-lg-6">
                <!-- Edit profile form -->
                <!-- inline edit handled inside Informações Pessoais -->

                <div class="tasks-panel">
                    <div class="tasks-panel-header">
                        <div>
                            <h5 class="tasks-panel-title">Minhas Tarefas</h5>
                            <p class="tasks-panel-subtitle">Visão rápida das suas pendências, prazos e itens concluídos.</p>
                        </div>
                        <div class="tasks-summary">
                            <?php
                                $taskCounts = ['Pendente' => 0, 'Em andamento' => 0, 'Concluída' => 0];
                                foreach ($profile_tasks as $taskCountItem) {
                                    $taskStatusKey = trim((string)($taskCountItem['status'] ?? ''));
                                    if (isset($taskCounts[$taskStatusKey])) {
                                        $taskCounts[$taskStatusKey]++;
                                    }
                                }
                            ?>
                            <span class="task-summary-chip"><i class="far fa-circle"></i> Pendentes <span class="chip-value"><?php echo (int) $taskCounts['Pendente']; ?></span></span>
                            <span class="task-summary-chip"><i class="fas fa-spinner"></i> Em andamento <span class="chip-value"><?php echo (int) $taskCounts['Em andamento']; ?></span></span>
                            <span class="task-summary-chip"><i class="fas fa-check"></i> Concluídas <span class="chip-value"><?php echo (int) $taskCounts['Concluída']; ?></span></span>
                        </div>
                    </div>
                    <div id="profileTasksList" class="scrollable-list" data-page-size="5">
                        <?php if (!empty($profile_tasks)): ?>
                            <?php foreach ($profile_tasks as $taskIndex => $t): ?>
                                <?php
                                    $taskStatus = trim((string)($t['status'] ?? 'Pendente'));
                                    $taskStatusSlug = 'is-pendente';
                                    if ($taskStatus === 'Em andamento') {
                                        $taskStatusSlug = 'is-andamento';
                                    } elseif ($taskStatus === 'Concluída') {
                                        $taskStatusSlug = 'is-concluida';
                                    }
                                    $dueRaw = trim((string)($t['data_vencimento'] ?? ''));
                                    $dueLabel = 'Sem data';
                                    $dueClass = '';
                                    if ($dueRaw !== '') {
                                        $dueDateObj = date_create($dueRaw);
                                        if ($dueDateObj) {
                                            $dueLabel = $dueDateObj->format('d/m/Y');
                                            $today = new DateTime('today');
                                            $diffDays = (int)$today->diff($dueDateObj)->format('%r%a');
                                            if ($diffDays < 0 && $taskStatus !== 'Concluída') {
                                                $dueClass = 'overdue';
                                            } elseif ($diffDays <= 2 && $diffDays >= 0 && $taskStatus !== 'Concluída') {
                                                $dueClass = 'due-soon';
                                            }
                                        } else {
                                            $dueLabel = $dueRaw;
                                        }
                                    }
                                ?>
                                <div class="task-card profile-list-item <?php echo $taskIndex >= 5 ? 'is-hidden' : ''; ?> <?php echo htmlspecialchars($taskStatusSlug); ?>">
                                    <div class="task-card-header">
                                        <div class="flex-grow-1">
                                            <h6 class="task-card-title"><?php echo htmlspecialchars($t['titulo'] ?? ''); ?></h6>
                                        </div>
                                        <span class="badge-custom <?php echo $taskStatus === 'Concluída' ? 'badge-concluida' : ($taskStatus === 'Em andamento' ? 'badge-andamento' : 'badge-pendente'); ?>">
                                            <?php echo htmlspecialchars($taskStatus); ?>
                                        </span>
                                    </div>
                                    <div class="task-card-meta">
                                        <span class="task-meta-pill <?php echo $dueClass; ?>">
                                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($dueLabel); ?>
                                        </span>
                                        <?php if ($taskStatus === 'Concluída'): ?>
                                            <span class="task-meta-pill"><i class="fas fa-check-circle"></i> Finalizada</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="task-description"><?php echo htmlspecialchars($t['descricao'] ?? ''); ?></p>
                                    <div class="task-card-actions">
                                        <button class="btn btn-sm btn-outline-primary btn-modern" title="Editar tarefa" data-task-id="<?php echo $t['id']; ?>" onclick="openEditTaskModal(<?php echo $t['id']; ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger btn-modern" title="Excluir tarefa" data-task-id="<?php echo $t['id']; ?>" onclick="deleteTaskConfirm(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-tasks fa-3x mb-3 opacity-25"></i>
                                <p>Nenhuma tarefa encontrada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (true): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileTasksList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modal Editar Tarefa -->
                <div class="modal fade" id="modalEditarTarefa" tabindex="-1" aria-labelledby="modalEditarTarefaLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header colorful d-flex align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <div id="modal-header-avatar" class="task-avatar bg-dark"><i class="fa fa-edit"></i></div>
                                    <div>
                                        <h5 class="modal-title mb-0" id="modalEditarTarefaLabel">Editar Tarefa</h5>
                                        <div class="text-muted small">Altere dados e atribua a outro responsável</div>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <form id="formEditarTarefa">
                                    <input type="hidden" name="id" id="edit-id">
                                    <div class="row">
                                        <div class="col-md-8 modal-edit-left pe-4">
                                            <div class="mb-3">
                                                <label class="form-label">Título</label>
                                                <input type="text" name="titulo" id="edit-titulo" class="form-control form-control-lg" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Descrição</label>
                                                <textarea name="descricao" id="edit-descricao" class="form-control" rows="4" placeholder="Notas, passos a seguir..."></textarea>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Data de vencimento</label>
                                                    <input type="date" name="data_vencimento" id="edit-data-vencimento" class="form-control">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Status</label>
                                                    <select name="status" id="edit-status" class="form-select">
                                                        <option value="Pendente">Pendente</option>
                                                        <option value="Em andamento">Em andamento</option>
                                                        <option value="Concluída">Concluída</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <input type="hidden" name="equipe" id="edit-equipe" value="">
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3 text-center">
                                                <div id="edit-avatar" class="task-avatar mb-2">?</div>
                                                <div class="fw-semibold" id="edit-responsavel_name">&nbsp;</div>
                                                <div class="small text-muted">ID: <span id="edit-responsavel-id" class="text-muted">-</span></div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Atribuir responsável</label>
                                                <select name="responsavel" id="edit-responsavel" class="form-select" onchange="document.getElementById('edit-responsavel-id-hidden').value = this.options[this.selectedIndex].dataset.userId || '';">
                                                    <?php foreach ($usersMap as $uid => $info): ?>
                                                        <option value="<?php echo htmlspecialchars($info['username']); ?>" data-user-id="<?php echo htmlspecialchars($uid); ?>"><?php echo htmlspecialchars($info['username']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="hidden" name="responsavel_id" id="edit-responsavel-id-hidden" value="">
                                                <div class="small text-muted mt-1">Escolha outro usuário para transferir a tarefa.</div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                                <div id="editarTarefaMsg" class="mt-2"></div>
                            </div>
                            <div class="modal-footer d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button id="btnSalvarEdicao" type="button" class="btn btn-primary"><i class="fa fa-save me-1"></i> Salvar alterações</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="col-lg-6">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="far fa-bell"></i></span>Lembretes Criados</h5>
                        <span class="profile-panel-count"><?php echo count($profile_reminders); ?> registros</span>
                    </div>
                    <div id="profileRemindersList" class="scrollable-list" data-page-size="5">
                        <?php if (!empty($profile_reminders)): ?>
                            <?php foreach ($profile_reminders as $reminderIndex => $r): ?>
                                <div class="list-item profile-list-item <?php echo $reminderIndex >= 5 ? 'is-hidden' : ''; ?>">
                                    <div class="d-flex align-items-start gap-3">
                                        <div style="color:#667eea;font-size:1.5rem;">
                                            <i class="far fa-bell"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($r['message']); ?></h6>
                                            <p class="mb-0 small text-muted">
                                                <i class="far fa-calendar-alt me-1"></i><?php echo htmlspecialchars($r['remind_at']); ?>
                                                <span class="mx-2">•</span>
                                                Lead: <strong><?php echo htmlspecialchars($r['lead_name'] ?? $r['lead_id']); ?></strong>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="far fa-bell fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum lembrete encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-list-footer">
                        <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileRemindersList">
                            <i class="fas fa-plus"></i> Carregar mais
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <section class="profile-security-card" aria-labelledby="profileSecurityTitle">
            <h4 id="profileSecurityTitle"><i class="fas fa-shield-alt me-2 text-primary" aria-hidden="true"></i>Segurança da conta</h4>
            <p>Altere sua senha informando a senha atual. A nova senha deve ter pelo menos 12 caracteres.</p>
            <form id="profilePasswordForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['profile_password_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="profile-security-form">
                    <div><label for="profileCurrentPassword">Senha atual</label><input id="profileCurrentPassword" class="form-control" name="current_password" type="password" autocomplete="current-password" required></div>
                    <div><label for="profileNewPassword">Nova senha</label><input id="profileNewPassword" class="form-control" name="new_password" type="password" autocomplete="new-password" minlength="12" required></div>
                    <div><label for="profileConfirmPassword">Confirmar nova senha</label><input id="profileConfirmPassword" class="form-control" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required></div>
                </div>
                <div class="profile-security-actions">
                    <button id="profilePasswordSave" class="btn btn-primary" type="submit"><i class="fas fa-key me-2" aria-hidden="true"></i>Alterar minha senha</button>
                    <span id="profilePasswordFeedback" class="profile-security-feedback" role="status" aria-live="polite"></span>
                </div>
            </form>
        </section>

        <!-- Seções de Leads, Projetos e Movimentações -->
        <div class="row g-4 mt-2" id="profileOnlySections">
            <div class="col-lg-6">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="fas fa-user-tie"></i></span>Meus Leads</h5>
                        <span class="profile-panel-count"><?php echo count($profile_leads); ?> registros</span>
                    </div>
                    <div id="profileLeadsList" class="scrollable-list" data-page-size="5">
                        <?php if (!empty($profile_leads)): ?>
                            <?php foreach ($profile_leads as $leadIndex => $l): ?>
                                <div class="list-item profile-list-item <?php echo $leadIndex >= 5 ? 'is-hidden' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div class="min-width-0">
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($l['name']); ?></h6>
                                            <p class="mb-0 small text-muted">
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($l['email'] ?? ''); ?>
                                                <span class="mx-2">•</span>
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($l['phone'] ?? ''); ?>
                                            </p>
                                        </div>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($l['status'] ?? ''); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-user-tie fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum lead encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (true): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileLeadsList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="fas fa-briefcase"></i></span>Meus Projetos</h5>
                        <span class="profile-panel-count"><?php echo count($profile_projects); ?> registros</span>
                    </div>
                    <div id="profileProjectsList" class="scrollable-list" data-page-size="5">
                        <?php if (!empty($profile_projects)): ?>
                            <?php foreach ($profile_projects as $projectIndex => $p): ?>
                                <div class="list-item profile-list-item <?php echo $projectIndex >= 5 ? 'is-hidden' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div class="min-width-0">
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($p['client_name'] ?? 'Projeto'); ?></h6>
                                            <p class="mb-0 small">
                                                <span class="text-success fw-bold">
                                                    <i class="fas fa-dollar-sign me-1"></i>R$ <?php echo number_format($p['proposal_value'] ?? 0,2,',','.'); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <span class="badge bg-success flex-shrink-0"><?php echo htmlspecialchars($p['status'] ?? ''); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-briefcase fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum projeto encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (true): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileProjectsList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-12">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="fas fa-chart-line"></i></span>Histórico de Movimentações</h5>
                        <span class="profile-panel-count"><?php echo count($profile_movements); ?> registros</span>
                    </div>
                    <div id="profileMovementsList" class="scrollable-list" data-page-size="5">
                        <?php if (!empty($profile_movements)): ?>
                            <?php foreach ($profile_movements as $movementIndex => $m): ?>
                                <div class="list-item profile-list-item movement-item <?php echo $movementIndex >= 5 ? 'is-hidden' : ''; ?>">
                                    <div class="movement-row">
                                        <div class="movement-person">
                                            <div class="movement-avatar">
                                            <?php echo strtoupper(substr(trim($m['changed_by'] ?: $m['user_id']),0,2)); ?>
                                            </div>
                                            <div class="min-width-0">
                                                <div class="movement-user"><?php echo htmlspecialchars($m['changed_by'] ?: $m['user_id']); ?></div>
                                                <div class="movement-time"><i class="far fa-clock me-1"></i><?php echo htmlspecialchars($m['created_at']); ?></div>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="small mb-1">
                                                <strong><?php echo htmlspecialchars($m['changed_by'] ?: $m['user_id']); ?></strong>
                                                <span class="text-muted mx-2">•</span>
                                                <span class="text-muted">
                                                    <i class="far fa-clock me-1"></i><?php echo htmlspecialchars($m['created_at']); ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted mb-1">
                                                #<?php echo htmlspecialchars($m['lead_id']); ?>
                                                <span class="mx-2">→</span>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($m['from_status'] ?? ''); ?></span>
                                                <i class="fas fa-arrow-right mx-1"></i>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($m['to_status'] ?? ''); ?></span>
                                            </div>
                                            <?php if(!empty($m['note'])): ?>
                                                <p class="mb-0 small"><?php echo htmlspecialchars($m['note']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i>
                                <p>Nenhuma movimentação encontrada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (true): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileMovementsList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        </div><!-- /pane-perfil -->

        <!-- TAB: Fluxo de Atendimento -->
        <div class="tab-pane fade" id="pane-fluxo" role="tabpanel" hidden>

            <?php
                $profileProgressiveStages = array_values(array_filter($profile_funnel_stages, static fn($stage) => ($stage['final_type'] ?? 'none') !== 'lost'));
                $profileLostStages = array_values(array_filter($profile_funnel_stages, static fn($stage) => ($stage['final_type'] ?? 'none') === 'lost'));
                $profileStageDescriptions = [
                    'Em Atendimento' => 'Realize o primeiro contato, entenda a necessidade do cliente, confirme os dados essenciais e combine o próximo passo do atendimento.',
                    'Qualificado' => 'Valide o perfil, a necessidade, o prazo e as condições do cliente. Com essas informações confirmadas, prepare a solução e a proposta adequadas.',
                    'Proposta Enviada' => 'Apresente a proposta e confirme que o cliente a recebeu e compreendeu. Registre dúvidas, objeções e a data combinada para retorno.',
                    'Em Negociação' => 'Conduza a negociação, esclareça objeções e alinhe escopo, valores e condições. Registre os acordos e mantenha uma próxima ação definida.',
                    'Financiamento' => 'Reúna e confira os dados e documentos necessários, encaminhe a análise financeira e acompanhe a aprovação. Mantenha o cliente informado sobre pendências e prazos.',
                    'Consórcio' => 'Apresente a modalidade e simule opções compatíveis com o perfil do cliente. Registre crédito, prazo e parcela de interesse para orientar a decisão.',
                    'Assinatura' => 'Confirme o aceite das condições, revise os dados e conduza a documentação até a assinatura. Acompanhe eventuais pendências para concluir o fechamento.',
                    'Aguardará' => 'Registre o motivo da espera e o prazo indicado pelo cliente. Agende o retorno para a data combinada e retome o contato nesse momento.',
                ];
                $normalizeProfileStageName = static function ($name) {
                    $name = trim((string)$name);
                    if (function_exists('mb_strtolower')) $name = mb_strtolower($name, 'UTF-8');
                    else $name = strtolower($name);
                    if (function_exists('iconv')) {
                        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                        if ($asciiName !== false) $name = $asciiName;
                    }
                    return strtolower($name);
                };
                $profileStageDescriptionsNormalized = [];
                foreach ($profileStageDescriptions as $descriptionStageName => $descriptionText) {
                    $descriptionKey = $normalizeProfileStageName($descriptionStageName);
                    $profileStageDescriptionsNormalized[$descriptionKey] = $descriptionText;
                }
                $describeUnmappedProfileStage = static function ($stageName, $finalType = 'none') use ($normalizeProfileStageName) {
                    $name = $normalizeProfileStageName($stageName);
                    if ($finalType === 'won') {
                        return 'Etapa de fechamento concluído. Confirme o aceite e a documentação, registre o resultado e encaminhe as próximas providências ao cliente e à equipe responsável.';
                    }
                    if ($finalType === 'lost') {
                        if (strpos($name, 'sem interesse') !== false) return 'Encerre o acompanhamento comercial quando o cliente confirmar que não deseja prosseguir. Registre as tentativas realizadas e o motivo informado.';
                        if (strpos($name, 'desist') !== false) return 'Registre a decisão e o motivo da desistência, incluindo eventuais pendências ou objeções. Encerre as ações comerciais abertas e mantenha o histórico atualizado.';
                        if (strpos($name, 'outra empresa') !== false || strpos($name, 'concorr') !== false) return 'Registre que o cliente optou por outro fornecedor e, se possível, o motivo da escolha. Essa informação ajuda a identificar oportunidades de melhoria.';
                        return 'Etapa de encerramento sem venda. Registre o motivo e o contexto da decisão, conclua as ações pendentes e mantenha o histórico do lead para consulta.';
                    }
                    if (strpos($name, 'qualific') !== false) return 'Confirme necessidade, perfil, prazo e condições do cliente. Registre os critérios atendidos e as informações necessárias para avançar com uma proposta adequada.';
                    if (strpos($name, 'proposta') !== false || strpos($name, 'orcamento') !== false) return 'Prepare ou apresente a proposta ao cliente, confirme o recebimento e registre dúvidas, ajustes solicitados e a próxima data de acompanhamento.';
                    if (strpos($name, 'negoci') !== false) return 'Alinhe escopo, valores e condições, esclareça objeções e registre os acordos e pontos pendentes. Defina uma próxima ação com prazo.';
                    if (strpos($name, 'financ') !== false || strpos($name, 'credito') !== false) return 'Confira os dados e documentos necessários, acompanhe a análise financeira e informe o cliente sobre o andamento, os prazos e eventuais pendências.';
                    if (strpos($name, 'consor') !== false) return 'Apresente e simule opções compatíveis com o perfil do cliente. Registre crédito, prazo e parcela de interesse para orientar a decisão.';
                    if (strpos($name, 'assin') !== false || strpos($name, 'contrato') !== false) return 'Revise as condições e os dados, conduza a documentação e acompanhe a assinatura. Registre pendências até a conclusão do fechamento.';
                    if (strpos($name, 'aguard') !== false || strpos($name, 'retorno') !== false) return 'Registre o motivo da espera e a data combinada para retomar o contato. Agende a próxima ação e cumpra o prazo acordado com o cliente.';
                    if (strpos($name, 'atendimento') !== false || strpos($name, 'novo') !== false || strpos($name, 'entrada') !== false || strpos($name, 'contato') !== false) return 'Faça contato com o cliente, compreenda sua necessidade, confirme os dados essenciais e registre a próxima ação do atendimento.';
                    return 'Conduza o lead conforme o objetivo desta etapa, registre as interações e informações relevantes e defina a próxima ação necessária para avançar ou concluir o atendimento.';
                };
            ?>

            <!-- Fluxo de Venda Progressivo -->
            <h5 class="fw-bold mb-4" style="font-size:1.25rem;">
                <i class="fas fa-chart-line me-2 text-primary"></i>Fluxo de Venda Progressivo
            </h5>

            <div class="row g-4 mb-5">
                <?php if (!empty($profileProgressiveStages)): ?>
                    <?php foreach ($profileProgressiveStages as $stageIndex => $stage): ?>
                        <?php
                            $stageName = trim((string)($stage['name'] ?? ''));
                            $stageDescriptionKey = $normalizeProfileStageName($stageName);
                            $stageDescription = $profileStageDescriptionsNormalized[$stageDescriptionKey] ?? $describeUnmappedProfileStage($stageName, $stage['final_type'] ?? 'none');
                        ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="fluxo-card" style="border-left-color:<?= htmlspecialchars($stage['color'] ?: '#3b82f6') ?>;">
                                <div class="fluxo-card-header">
                                    <span class="fluxo-number" style="background:<?= htmlspecialchars($stage['color'] ?: '#e2e8f0') ?>22;color:<?= htmlspecialchars($stage['color'] ?: '#475569') ?>;"><?= $stageIndex + 1 ?></span>
                                    <?php if (($stage['final_type'] ?? 'none') === 'won'): ?><span class="fluxo-badge fluxo-badge-success" style="background:#dcfce7;color:#15803d;">SUCESSO</span><?php endif; ?>
                                </div>
                                <h6 class="fw-bold mb-2"><?= htmlspecialchars($stageName) ?></h6>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($stageDescription) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12"><p class="text-muted">Nenhuma etapa de venda cadastrada no funil.</p></div>
                <?php endif; ?>
            </div>

            <?php if (false): // Conteúdo fixo legado; o fluxo visível vem exclusivamente do funil configurado. ?>
            <div class="legacy-fixed-flow">
                <!-- 1 - Em Atendimento -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-green">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">1</span>
                            <span class="fluxo-badge fluxo-badge-blue">INICIAL</span>
                        </div>
                        <h6 class="fw-bold mb-2">Em Atendimento</h6>
                        <p class="text-muted small mb-0">Ao adicionar um novo cliente no CRM, ele entra automaticamente neste estágio inicial.</p>
                    </div>
                </div>

                <!-- 2 - Qualificado -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-green">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">2</span>
                            <span class="fluxo-badge fluxo-badge-purple">QUALIFICAÇÃO</span>
                        </div>
                        <h6 class="fw-bold mb-2">Qualificado</h6>
                        <p class="text-muted small mb-0">Cliente passou as informações e está aguardando o orçamento ser finalizado.</p>
                    </div>
                </div>

                <!-- 3 - Proposta Enviada -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-green">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">3</span>
                        </div>
                        <h6 class="fw-bold mb-2">Proposta Enviada</h6>
                        <p class="text-muted small mb-0">Informações e orçamento já foram entregues ao cliente.</p>
                    </div>
                </div>

                <!-- 4 - Em Negociação -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-purple">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">4</span>
                        </div>
                        <h6 class="fw-bold mb-2">Em Negociação</h6>
                        <p class="text-muted small mb-0">Cliente com alto índice de fechamento e em fase de negociação ativa, independente da forma de pagamento.</p>
                    </div>
                </div>

                <!-- 5 - Financiamento -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-yellow">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">5</span>
                        </div>
                        <h6 class="fw-bold mb-2">Financiamento</h6>
                        <p class="text-muted small mb-2">Obrigatório detalhar financeira e dados do cliente.</p>
                        <p class="small mb-0" style="color:#b45309;">Simulação feita mas sem prioridade alta de fechamento imediato.</p>
                    </div>
                </div>

                <!-- 6 - Consórcio -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-blue">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">6</span>
                        </div>
                        <h6 class="fw-bold mb-2">Consórcio</h6>
                        <p class="text-muted small mb-0">Perfil identificado, dados coletados e simulação de consórcio realizada.</p>
                    </div>
                </div>

                <!-- 7 - Assinatura -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-blue">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">7</span>
                        </div>
                        <h6 class="fw-bold mb-2">Assinatura</h6>
                        <p class="text-muted small mb-0">Cliente passou os dados e concordou em analisar o contrato final.</p>
                    </div>
                </div>

                <!-- 11 - Aguardará -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-yellow">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number">11</span>
                        </div>
                        <h6 class="fw-bold mb-2">Aguardará</h6>
                        <p class="text-muted small mb-0">Cliente decidiu aguardar e informou o prazo de retorno.</p>
                    </div>
                </div>

                <!-- Venda Concluída -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-card-success">
                        <div class="fluxo-card-header">
                            <span class="fluxo-check"><i class="fas fa-check-circle"></i></span>
                            <span class="fluxo-badge fluxo-badge-success">SUCESSO</span>
                        </div>
                        <h6 class="fw-bold mb-2">Venda Concluída</h6>
                        <p class="small mb-0" style="opacity:0.9;">Contrato assinado com a empresa. Parabéns!</p>
                    </div>
                </div>
            </div>

            <!-- Status de Perda ou Desistência -->
            <h5 class="fw-bold mb-4" style="font-size:1.25rem;">
                <i class="fas fa-times-circle me-2 text-danger"></i>Status de Perda ou Desistência
            </h5>

            <div class="row g-4">
                <!-- 8 - Sem Interesse -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-red">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number fluxo-number-red">8</span>
                            <span class="fluxo-badge fluxo-badge-red">REGRA: 8 TENTATIVAS</span>
                        </div>
                        <h6 class="fw-bold mb-2">Sem Interesse</h6>
                        <p class="text-muted small mb-0">Utilizar após mais de 8 tentativas de contato sem sucesso.</p>
                    </div>
                </div>

                <!-- 9 - Desistiu -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-orange">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number fluxo-number-red">9</span>
                        </div>
                        <h6 class="fw-bold mb-2">Desistiu</h6>
                        <p class="text-muted small mb-2">Obrigatório informar o motivo em notas.</p>
                        <p class="small mb-0" style="color:#b45309;">Para clientes que desistiram mas não fecharam com outra empresa.</p>
                    </div>
                </div>

                <!-- 10 - Fez com outra Empresa -->
                <div class="col-lg-4 col-md-6">
                    <div class="fluxo-card fluxo-border-orange">
                        <div class="fluxo-card-header">
                            <span class="fluxo-number fluxo-number-red">10</span>
                        </div>
                        <h6 class="fw-bold mb-2">Fez com outra Empresa</h6>
                        <p class="text-muted small mb-0">Indispensável perguntar o motivo da escolha da concorrência.</p>
                    </div>
                </div>
            </div>

            </div>
            <?php endif; ?>

            <h5 class="fw-bold mb-4" style="font-size:1.25rem;">
                <i class="fas fa-times-circle me-2 text-danger"></i>Status de Perda ou Desistência
            </h5>
            <div class="row g-4">
                <?php if (!empty($profileLostStages)): ?>
                    <?php foreach ($profileLostStages as $stageIndex => $stage): ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="fluxo-card fluxo-border-red">
                                <div class="fluxo-card-header">
                                    <span class="fluxo-number fluxo-number-red"><?= $stageIndex + 1 ?></span>
                                    <span class="fluxo-badge fluxo-badge-red">PERDA</span>
                                </div>
                                <?php
                                    $stageName = trim((string)($stage['name'] ?? ''));
                                    $stageDescriptionKey = $normalizeProfileStageName($stageName);
                                    $stageDescription = $profileStageDescriptionsNormalized[$stageDescriptionKey] ?? $describeUnmappedProfileStage($stageName, 'lost');
                                ?>
                                <h6 class="fw-bold mb-2"><?= htmlspecialchars($stageName) ?></h6>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($stageDescription) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12"><p class="text-muted">Nenhum status de perda ou desistência cadastrado no funil.</p></div>
                <?php endif; ?>
            </div>

        </div><!-- /pane-fluxo -->
        </div><!-- /tab-content -->

        <script>
        (function () {
            const tabs = document.getElementById('perfilTabs');
            const panes = {
                'tab-perfil': document.getElementById('pane-perfil'),
                'tab-fluxo': document.getElementById('pane-fluxo')
            };
            if (!tabs || !panes['tab-perfil'] || !panes['tab-fluxo']) return;

            const profileOnlySections = document.getElementById('profileOnlySections');
            if (profileOnlySections && !panes['tab-perfil'].contains(profileOnlySections)) {
                panes['tab-perfil'].appendChild(profileOnlySections);
            }

            tabs.addEventListener('click', function (event) {
                const button = event.target.closest('[data-bs-target]');
                if (!button || !panes[button.id]) return;

                Object.entries(panes).forEach(function ([buttonId, pane]) {
                    const isActive = buttonId === button.id;
                    pane.hidden = !isActive;
                    pane.style.setProperty('display', isActive ? 'block' : 'none', 'important');
                    pane.classList.toggle('show', isActive);
                    pane.classList.toggle('active', isActive);
                    const tabButton = document.getElementById(buttonId);
                    tabButton.classList.toggle('active', isActive);
                    tabButton.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                document.getElementById('perfilTabContent').dataset.activePane = button.id === 'tab-fluxo' ? 'fluxo' : 'perfil';
            });
        })();
        </script>

    </div>
</main>

<script src="assets/js/chart.min.js"></script>
<script src="assets/js/profile_insights.js?v=20261015-4" defer></script>
<script type="module">
    const PROFILE_USER_ID = <?php echo json_encode($user_id); ?>;
    const usersMap = <?php echo json_encode($usersMap ?? []); ?>;
    let profileTasks = <?php echo json_encode($profile_tasks); ?>;

    // helper methods to call team_tasks API
    async function fetchTasks(filters = {}) {
        const params = new URLSearchParams({action:'list', ...filters});
        const res = await fetch('includes/team_tasks_api.php?' + params);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }
    async function updateTask(id, data) {
        const res = await fetch('includes/team_tasks_api.php?action=update&id=' + encodeURIComponent(id), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }
    async function deleteTask(id) {
        const res = await fetch('includes/team_tasks_api.php?action=delete&id=' + encodeURIComponent(id), {method:'POST'});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }

    function escapeHtml(str){ if(!str) return ''; return String(str).replace(/[&<>'"]/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":"&#39;",'"':'&quot;'})[s]); }
    function applyInlineProfileData(profile = {}) {
        const fullName = Object.prototype.hasOwnProperty.call(profile, 'nome_completo') ? (profile.nome_completo || '') : null;
        const email = Object.prototype.hasOwnProperty.call(profile, 'email') ? (profile.email || '') : null;
        const bio = Object.prototype.hasOwnProperty.call(profile, 'biografia') ? (profile.biografia || '') : null;
        const botMode = Object.prototype.hasOwnProperty.call(profile, 'ai_bot_mode') ? (profile.ai_bot_mode || 'chatbot3') : null;
        const botModeLabels = {
            none: 'Nenhum',
            head: 'Avatar compacto',
            body: 'Avatar completo',
            chatbot1: 'Chat IA',
            chatbot3: 'Copiloto'
        };

        document.querySelectorAll('.profile-info-item[data-field]').forEach(it => {
            const field = it.getAttribute('data-field');
            const view = it.querySelector('.view-mode');
            const input = it.querySelector('.edit-mode input, .edit-mode textarea, .edit-mode select');
            if (input && Object.prototype.hasOwnProperty.call(profile, field)) {
                input.value = profile[field] || '';
            }
            if (!view) return;
            if (field === 'biografia' && bio !== null) {
                view.innerHTML = escapeHtml(bio).replace(/\n/g, '<br>');
            } else if (field === 'nome_completo' && fullName !== null) {
                view.textContent = fullName;
            } else if (field === 'email' && email !== null) {
                view.textContent = email;
            } else if (field === 'ai_bot_mode' && botMode !== null) {
                view.textContent = botModeLabels[botMode] || botModeLabels.chatbot3;
            }
        });

        if (fullName !== null && document.getElementById('profile_name_display')) {
            document.getElementById('profile_name_display').textContent = fullName || profile.username || '<?php echo htmlspecialchars($user['username'] ?? ''); ?>';
        }
        if (email !== null && document.getElementById('profile_email_display')) {
            document.getElementById('profile_email_display').textContent = email;
        }
        if (bio !== null && document.getElementById('profile_bio_display')) {
            document.getElementById('profile_bio_display').innerHTML = escapeHtml(bio).replace(/\n/g, '<br>');
        }
    }
    function syncInlineProfileInputsFromView() {
        document.querySelectorAll('.profile-info-item[data-field]').forEach(it => {
            const field = it.getAttribute('data-field');
            const view = it.querySelector('.view-mode');
            const input = it.querySelector('.edit-mode input, .edit-mode textarea, .edit-mode select');
            if (!field || !view || !input) return;
            // O select ja vem com o valor canonico selecionado pelo PHP. O texto exibido
            // no modo leitura e um rotulo (ex.: "Copiloto"), nao o value da opcao.
            if (field === 'ai_bot_mode') return;
            if (field === 'biografia') {
                input.value = (view.innerHTML || '').replace(/<br\s*\/?>/gi, '\n').replace(/&nbsp;/g, ' ').trim();
            } else {
                input.value = (view.textContent || '').trim();
            }
        });
    }
    function hydrateInlineProfileViewFromHeader() {
        const headerName = (document.getElementById('profile_name_display')?.textContent || '').trim();
        const headerEmail = (document.getElementById('profile_email_display')?.textContent || '').trim();
        const headerBio = (document.getElementById('profile_bio_display')?.innerHTML || '').trim();

        document.querySelectorAll('.profile-info-item[data-field]').forEach(it => {
            const field = it.getAttribute('data-field');
            const view = it.querySelector('.view-mode');
            const input = it.querySelector('.edit-mode input, .edit-mode textarea, .edit-mode select');
            if (!field || !view) return;

            const currentText = (view.textContent || '').trim();
            if (currentText !== '') return;

            if (field === 'nome_completo' && headerName) {
                view.textContent = headerName;
                if (input) input.value = headerName;
            }
            if (field === 'email' && headerEmail) {
                view.textContent = headerEmail;
                if (input) input.value = headerEmail;
            }
            if (field === 'biografia' && headerBio) {
                view.innerHTML = headerBio;
                if (input) input.value = headerBio.replace(/<br\s*\/?>/gi, '\n').replace(/&nbsp;/g, ' ').trim();
            }
        });
    }
    function exitInlineProfileEdit(btnInline) {
        document.querySelectorAll('.profile-info-item').forEach(it => {
            it.querySelector('.view-mode')?.classList.remove('d-none');
            it.querySelector('.edit-mode')?.classList.add('d-none');
        });
        btnInline.innerHTML = '<i class="fas fa-pen" aria-hidden="true"></i><span>Editar</span>';
        btnInline.title = 'Editar informações pessoais';
        btnInline.disabled = false;
        const cancelBtn = document.getElementById('btnInlineCancel');
        cancelBtn?.classList.add('d-none');
    }
    function bindLoadMoreLists(scope = document) {
        scope.querySelectorAll('[data-load-more]').forEach(button => {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', () => {
                const list = document.querySelector(button.dataset.loadMore);
                if (!list) return;
                const pageSize = Number(list.dataset.pageSize || 5);
                const hiddenItems = Array.from(list.querySelectorAll('.profile-list-item.is-hidden'));
                hiddenItems.slice(0, pageSize).forEach(item => item.classList.remove('is-hidden'));
                if (list.querySelectorAll('.profile-list-item.is-hidden').length === 0) {
                    button.classList.add('is-hidden');
                }
            });
        });
    }

    function renderProfileItem(section, item) {
        const el = document.createElement('div');
        el.className = section === 'tasks' ? 'task-card profile-list-item' : 'list-item profile-list-item';
        if (section === 'leads') el.innerHTML = `<div class="d-flex align-items-center gap-3"><span class="profile-item-icon"><i class="fas fa-user"></i></span><div class="min-width-0 flex-grow-1"><h6 class="mb-1">${escapeHtml(item.name||'Lead')}</h6><div class="small text-muted text-truncate">${escapeHtml(item.email||'Sem e-mail')} · ${escapeHtml(item.phone||'Sem telefone')}</div></div><span class="badge bg-primary">${escapeHtml(item.status||'Novo')}</span></div>`;
        else if (section === 'projects') el.innerHTML = `<div class="d-flex align-items-center gap-3"><span class="profile-item-icon"><i class="fas fa-briefcase"></i></span><div class="min-width-0 flex-grow-1"><h6 class="mb-1">${escapeHtml(item.client_name||'Projeto')}</h6><div class="small text-success fw-semibold">R$ ${Number(item.proposal_value||0).toLocaleString('pt-BR',{minimumFractionDigits:2})}</div></div><span class="badge bg-success">${escapeHtml(item.status||'')}</span></div>`;
        else if (section === 'reminders') el.innerHTML = `<div class="d-flex align-items-center gap-3"><span class="profile-item-icon"><i class="far fa-bell"></i></span><div class="min-width-0 flex-grow-1"><h6 class="mb-1">${escapeHtml(item.message||'Lembrete')}</h6><div class="small text-muted">${escapeHtml(item.remind_at||'Sem data')} · ${escapeHtml(item.lead_name||('Lead #'+(item.lead_id||'')))}</div></div></div>`;
        else if (section === 'movements') el.innerHTML = `<div class="d-flex align-items-center gap-3"><span class="profile-item-icon"><i class="fas fa-exchange-alt"></i></span><div class="min-width-0 flex-grow-1"><h6 class="mb-1">Lead #${escapeHtml(item.lead_id||'')} · ${escapeHtml(item.changed_by||'Usuário')}</h6><div class="small text-muted">${escapeHtml(item.from_status||'Início')} → ${escapeHtml(item.to_status||'Atualizado')} · ${escapeHtml(item.created_at||'')}</div></div></div>`;
        else {
            const status = item.status || 'Pendente';
            const statusClass = status === 'Concluída' ? 'badge-concluida' : (status === 'Em andamento' ? 'badge-andamento' : 'badge-pendente');
            el.innerHTML = `<div class="task-card-header"><h6 class="task-card-title">${escapeHtml(item.titulo||'Tarefa')}</h6><span class="badge-custom ${statusClass}">${escapeHtml(status)}</span></div><div class="task-card-meta"><span class="task-meta-pill"><i class="far fa-calendar"></i>${escapeHtml(item.data_vencimento||'Sem data')}</span></div><p class="task-description">${escapeHtml(item.descricao||'Sem descrição')}</p><div class="task-card-actions"><button class="btn btn-sm btn-outline-primary" onclick="openEditTaskModal(${Number(item.id)})"><i class="fas fa-pen"></i></button><button class="btn btn-sm btn-outline-danger" data-task-id="${Number(item.id)}" onclick="deleteTaskConfirm(this)"><i class="fas fa-trash"></i></button></div>`;
        }
        return el;
    }

    async function loadProfileSection(section, page = 1) {
        const ids = {tasks:'profileTasksList', leads:'profileLeadsList', projects:'profileProjectsList', movements:'profileMovementsList', reminders:'profileRemindersList'};
        const wrap = document.getElementById(ids[section]);
        if (!wrap) return;
        if (page === 1) wrap.innerHTML = '<div class="profile-loading-state"><div><i class="fas fa-circle-notch fa-spin mb-2"></i><div class="small">Carregando dados</div></div></div>';
        try {
            const res = await fetch('api/profile_data.php?section=' + encodeURIComponent(section) + '&page=' + page + '&limit=5');
            const data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.message || 'Falha ao carregar');
            if (page === 1) wrap.innerHTML = '';
            if (section === 'tasks') profileTasks = page === 1 ? data.items : profileTasks.concat(data.items);
            data.items.forEach(item => {
                wrap.appendChild(renderProfileItem(section, item));
                return;
                const el = document.createElement('div'); el.className = 'list-item profile-list-item';
                if (section === 'leads') el.innerHTML = `<div class="d-flex justify-content-between"><div><h6 class="mb-1 fw-semibold">${escapeHtml(item.name||'Lead')}</h6><small class="text-muted">${escapeHtml(item.email||'')} ${escapeHtml(item.phone||'')}</small></div><span class="badge bg-primary">${escapeHtml(item.status||'')}</span></div>`;
                else if (section === 'projects') el.innerHTML = `<div class="d-flex justify-content-between"><strong>${escapeHtml(item.client_name||'Projeto')}</strong><span class="badge bg-success">${escapeHtml(item.status||'')}</span></div>`;
                else if (section === 'reminders') el.innerHTML = `<strong>${escapeHtml(item.message||'')}</strong><div class="small text-muted">${escapeHtml(item.remind_at||'')} · ${escapeHtml(item.lead_name||item.lead_id||'')}</div>`;
                else if (section === 'movements') el.innerHTML = `<div class="small"><strong>${escapeHtml(item.changed_by||item.user_id||'')}</strong> · ${escapeHtml(item.created_at||'')}</div><div class="small text-muted">Lead #${escapeHtml(item.lead_id||'')} · ${escapeHtml(item.from_status||'')} → ${escapeHtml(item.to_status||'')}</div>`;
                else el.innerHTML = `<div class="d-flex justify-content-between"><strong>${escapeHtml(item.titulo||item.title||item.nome||'Tarefa')}</strong><span class="small text-muted">${escapeHtml(item.data_vencimento||'')}</span></div>`;
                wrap.appendChild(el);
            });
            if (!data.items.length && page === 1) wrap.innerHTML = '<div class="profile-empty-state"><div><i class="far fa-folder-open fa-2x mb-2 opacity-50"></i><div>Nenhum registro encontrado</div></div></div>';
            const countBadge = wrap.closest('.profile-modern-panel')?.querySelector('.profile-panel-count');
            if (countBadge) countBadge.textContent = `${wrap.querySelectorAll('.profile-list-item').length}${data.has_more ? '+' : ''} registros`;
            const more = wrap.parentElement?.querySelector('.profile-load-more');
            if (more) { more.classList.toggle('is-hidden', !data.has_more); more.dataset.page = String(page + 1); more.onclick = () => loadProfileSection(section, page + 1); }
        } catch (e) { wrap.innerHTML = '<div class="text-center text-danger py-4">Erro ao carregar dados.</div>'; console.error(e); }
    }

    document.addEventListener('DOMContentLoaded', ()=>{
        ['tasks','leads','projects','movements','reminders'].forEach(section => loadProfileSection(section));
        // Avatar upload handlers
        const avatarInput = document.getElementById('avatarInput');
        const btnUpload = document.getElementById('btnUploadAvatar');
        const btnRemove = document.getElementById('btnRemoveAvatar');
        const avatarMsg = document.getElementById('avatarMsg');
        const profileAvatar = document.getElementById('profileAvatar');

        // editing helpers
        window.openEditTaskModal = function(id) {
            // find task in profileTasks
            const task = profileTasks.find(t => String(t.id) === String(id));
            if (!task) {
                console.warn('task not found for edit:', id);
                alert('Tarefa não encontrada para edição.');
                return;
            }
            // populate form fields similar to integracao
            document.getElementById('edit-id').value = task.id;
            document.getElementById('edit-equipe').value = task.equipe || '';
            const sel = document.getElementById('edit-responsavel');
            if (sel) {
                let selectedName = task.responsavel || '';
                if (task.responsavel_id && usersMap && usersMap[task.responsavel_id]) {
                    selectedName = usersMap[task.responsavel_id].username || selectedName;
                }
                sel.value = selectedName;
            }
            const editRespIdHidden = document.getElementById('edit-responsavel-id-hidden');
            if (editRespIdHidden) {
                if (task.responsavel_id) {
                    editRespIdHidden.value = task.responsavel_id;
                } else if (sel && sel.options[sel.selectedIndex]) {
                    editRespIdHidden.value = sel.options[sel.selectedIndex].dataset.userId || '';
                } else {
                    editRespIdHidden.value = '';
                }
            }
            document.getElementById('edit-titulo').value = task.titulo || '';
            document.getElementById('edit-status').value = task.status || '';
            document.getElementById('edit-data-vencimento').value = task.data_vencimento || '';
            document.getElementById('edit-descricao').value = task.descricao || '';
            // avatar/header
            try {
                const nameEl = document.getElementById('edit-responsavel_name');
                const avatarEl = document.getElementById('edit-avatar');
                const headerAvatar = document.getElementById('modal-header-avatar');
                const idEl = document.getElementById('edit-responsavel-id');
                const respIdForHeader = task.responsavel_id || null;
                const respInfo = respIdForHeader && usersMap && usersMap[respIdForHeader] ? usersMap[respIdForHeader] : null;
                if (respInfo && respInfo.avatar) {
                    if (headerAvatar) headerAvatar.innerHTML = `<img src="${respInfo.avatar}?v=${Date.now()}" class="rounded-circle" style="width:56px;height:56px;object-fit:cover;">`;
                } else if (headerAvatar) {
                    const initialsH = (respInfo && respInfo.username ? respInfo.username : (task.responsavel||'')).split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase()||'?';
                    headerAvatar.textContent = initialsH; headerAvatar.style.background = '#0d6efd'; headerAvatar.style.color = '#fff';
                }
                const creatorId = task.user_id || null;
                const creatorInfo = creatorId && usersMap && usersMap[creatorId] ? usersMap[creatorId] : null;
                const creatorName = creatorInfo && creatorInfo.username ? creatorInfo.username : (task.username||'');
                if (nameEl) nameEl.textContent = 'Criador por ' + (creatorName||'');
                if (idEl) idEl.textContent = creatorId || '-';
                if (creatorInfo && creatorInfo.avatar) {
                    if (avatarEl) avatarEl.innerHTML = `<img src="${creatorInfo.avatar}?v=${Date.now()}" class="rounded-circle" style="width:56px;height:56px;object-fit:cover;">`;
                } else {
                    const initialsC = (creatorName||'').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase()||'?';
                    if (avatarEl) { avatarEl.textContent = initialsC; avatarEl.style.background = '#6c757d'; avatarEl.style.color = '#fff'; }
                }
                const modalHeader = document.querySelector('#modalEditarTarefa .modal-header');
                if (modalHeader) {
                    modalHeader.style.background = '#0b5ed7';
                    modalHeader.style.borderBottom = '1px solid rgba(0,0,0,0.06)';
                }
            } catch(e){ console.warn('avatar fill error',e); }
            const modal = new bootstrap.Modal(document.getElementById('modalEditarTarefa'));
            modal.show();
        };

        window.deleteTaskConfirm = async function(el) {
            const taskId = el.getAttribute('data-task-id');
            if (!confirm('Tem certeza que deseja deletar essa tarefa?')) return;
            try {
                const resp = await deleteTask(taskId);
                if (resp.success) {
                    alert('Tarefa deletada com sucesso!');
                    location.reload();
                } else {
                    alert('Erro ao deletar: ' + (resp.error || ''));
                }
            } catch(err) {
                console.error(err);
                alert('Erro ao deletar tarefa');
            }
        };

        // salvar edição de tarefa
        const btnSalvarEdicao = document.getElementById('btnSalvarEdicao');
        if (btnSalvarEdicao) {
            btnSalvarEdicao.addEventListener('click', async function() {
                const form = document.getElementById('formEditarTarefa');
                const formData = new FormData(form);
                const data = Object.fromEntries(formData);
                if (!data.responsavel_id) {
                    const sel = document.getElementById('edit-responsavel');
                    if (sel && sel.options[sel.selectedIndex]) {
                        data.responsavel_id = sel.options[sel.selectedIndex].dataset.userId || '';
                    }
                }
                const id = data.id;
                delete data.id;
                const resposta = await updateTask(id, data);
                if (resposta.success) {
                    document.getElementById('editarTarefaMsg').innerHTML = '<div class="alert alert-success">Tarefa atualizada com sucesso!</div>';
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarTarefa'));
                        modal.hide();
                        document.getElementById('editarTarefaMsg').innerHTML = '';
                        // reload page to show changes
                        location.reload();
                    }, 1000);
                } else {
                    const msg = resposta && resposta.error ? resposta.error : 'Erro ao atualizar tarefa';
                    document.getElementById('editarTarefaMsg').innerHTML = `<div class="alert alert-danger">${msg}</div>`;
                }
            });
        }

        if (btnUpload) btnUpload.addEventListener('click', async ()=>{
            if (!avatarInput.files || !avatarInput.files[0]) { avatarMsg.innerHTML = '<div class="text-danger small">Selecione um arquivo.</div>'; return; }
            btnUpload.disabled = true;
            const fd = new FormData(); fd.append('avatar', avatarInput.files[0]);
            try {
                const res = await fetch('api/upload_avatar.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch (e){ avatarMsg.innerHTML = '<div class="text-danger small">Resposta inválida do servidor</div>'; console.error('upload raw:', text); btnUpload.disabled = false; return; }
                if (data.success) {
                    avatarMsg.innerHTML = '<div class="text-success small">Foto salva.</div>';
                    if (data.avatar) profileAvatar.src = data.avatar + '?v=' + Date.now();
                } else {
                    avatarMsg.innerHTML = '<div class="text-danger small">' + (data.message||'Erro') + '</div>';
                }
            }catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Erro ao enviar</div>'; console.error(e); }
            btnUpload.disabled = false;
        });

        if (btnRemove) btnRemove.addEventListener('click', async ()=>{
            if (!confirm('Remover sua foto de perfil?')) return;
            try{
                btnRemove.disabled = true;
                const fd = new FormData(); fd.append('remove','1');
                const res = await fetch('api/upload_avatar.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Resposta inválida do servidor</div>'; btnRemove.disabled = false; return; }
                if (data.success) {
                    avatarMsg.innerHTML = '<div class="text-success small">Foto removida.</div>';
                    profileAvatar.src = 'assets/img/avatar-placeholder.png';
                } else {
                    avatarMsg.innerHTML = '<div class="text-danger small">' + (data.message||'Erro') + '</div>';
                }
            }catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Erro ao remover</div>'; console.error(e); }
            btnRemove.disabled = false;
        });

        // profile update form submission
        const updateForm = document.getElementById('updateProfileForm');
        if (updateForm) updateForm.addEventListener('submit', async function(e){
            e.preventDefault();
            const btn = document.getElementById('btnSaveProfile');
            if (btn) btn.disabled = true;
            const fd = new FormData(this);
            const avatarEl = document.getElementById('pf_avatar');
            if (avatarEl && avatarEl.files && avatarEl.files[0]) fd.append('avatar', avatarEl.files[0]);
            try {
                const res = await fetch('api/update_profile.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) {
                    const savedBotMode = fd.get('ai_bot_mode');
                    if (savedBotMode && typeof window.__cacheAiAssistantAvatarMode === 'function') {
                        window.__cacheAiAssistantAvatarMode(String(savedBotMode));
                    }
                    const name = document.getElementById('pf_nome_completo') ? document.getElementById('pf_nome_completo').value : '';
                    if (document.getElementById('profile_name_display')) document.getElementById('profile_name_display').textContent = name || '<?php echo htmlspecialchars($user['username'] ?? ''); ?>';
                    if (document.getElementById('profile_email_display')) document.getElementById('profile_email_display').textContent = document.getElementById('pf_email').value;
                    if (document.getElementById('profile_bio_display')) document.getElementById('profile_bio_display').innerHTML = (document.getElementById('pf_biografia').value || '').replace(/\n/g,'<br>');
                    if (data.avatar) {
                        document.getElementById('profileAvatar').src = data.avatar + '?v=' + Date.now();
                    }
                    alert('Perfil atualizado com sucesso');
                    const collapseEl = document.getElementById('editProfilePane');
                    if (collapseEl) { const bs = bootstrap.Collapse.getInstance(collapseEl); if (bs) bs.hide(); }
                } else {
                    alert('Erro: ' + (data.message || ''));
                }
            } catch(err) { console.error(err); alert('Erro ao atualizar perfil'); }
            if (btn) btn.disabled = false;
        });

    });


    // Inline edit handlers for Informações Pessoais
    document.addEventListener('DOMContentLoaded', ()=>{
        hydrateInlineProfileViewFromHeader();
        const btnInline = document.getElementById('btnInlineEditProfile');
        const cancelBtn = document.getElementById('btnInlineCancel');
        if (!btnInline) return;
        let editing = false;
        cancelBtn?.addEventListener('click', () => {
            if (!editing) return;
            exitInlineProfileEdit(btnInline);
            editing = false;
        });
        btnInline.addEventListener('click', async ()=>{
            const items = document.querySelectorAll('.profile-info-item');
            if (!editing) {
                // enter edit mode
                syncInlineProfileInputsFromView();
                const modeSelect = document.querySelector('.ai-bot-mode-select');
                if (modeSelect) {
                    let currentMode = modeSelect.dataset.currentMode || modeSelect.value || 'chatbot3';
                    try {
                        const response = await fetch('api/get_my_profile.php', { credentials: 'same-origin' });
                        const data = await response.json();
                        if (response.ok && data.success && data.user?.ai_bot_mode) currentMode = data.user.ai_bot_mode;
                    } catch (error) { /* Mantem o valor carregado com a pagina. */ }
                    if (currentMode === 'speech') currentMode = 'body';
                    modeSelect.value = Array.from(modeSelect.options).some(option => option.value === currentMode) ? currentMode : 'chatbot3';
                    modeSelect.dataset.currentMode = modeSelect.value;
                }
                items.forEach(it=>{ it.querySelector('.view-mode')?.classList.add('d-none'); it.querySelector('.edit-mode')?.classList.remove('d-none'); });
                btnInline.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i><span>Salvar</span>';
                btnInline.title = 'Salvar informações pessoais';
                cancelBtn?.classList.remove('d-none');
                editing = true;
            } else {
                const fd = new FormData();
                document.querySelectorAll('.profile-info-item[data-field]').forEach(it=>{
                    const name = it.getAttribute('data-field');
                    const input = it.querySelector('.edit-mode input, .edit-mode textarea, .edit-mode select');
                    if (name && input) fd.append(name, input.value);
                });

                btnInline.disabled = true;
                try {
                    const res = await fetch('api/update_profile.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const text = await res.text();
                    let json = null;
                    try {
                        json = JSON.parse(text);
                    } catch(parseErr) {
                        throw new Error('Resposta inválida do servidor: ' + text.slice(0, 180));
                    }
                    if (!res.ok || !json.success) {
                        throw new Error(json.message || 'Não foi possível salvar o perfil.');
                    }
                    const savedBotMode = fd.get('ai_bot_mode');
                    if (savedBotMode && typeof window.__cacheAiAssistantAvatarMode === 'function') {
                        window.__cacheAiAssistantAvatarMode(String(savedBotMode));
                    }
                    applyInlineProfileData(json.profile || {});
                    const modeSelect = document.querySelector('.ai-bot-mode-select');
                    if (modeSelect && json.profile?.ai_bot_mode) modeSelect.dataset.currentMode = json.profile.ai_bot_mode;
                    exitInlineProfileEdit(btnInline);
                    editing = false;
                } catch(e){
                    console.error(e);
                    alert('Erro ao salvar perfil: ' + e.message);
                    btnInline.disabled = false;
                }
            }
        });
    });
    async function loadProfileData(){
        const base = 'api/get_user_activity.php?id=' + encodeURIComponent(PROFILE_USER_ID);
        try{
            const res = await fetch(base);
            const text = await res.text();
            // clear debug element (do not display raw JSON)
            const debugEl = document.getElementById('profileDebug');
            if (debugEl) debugEl.innerHTML = '';
            if(!res.ok) throw new Error('HTTP ' + res.status + ' — ' + text);
            let data = null;
            try { data = JSON.parse(text); } catch(parseErr){ throw new Error('Invalid JSON response: ' + parseErr.message); }
            if(!data.success){ console.warn('get_user_activity:', data); }

            // Leads
            const leadsWrap = document.getElementById('profileLeadsList');
            if(Array.isArray(data.leads) && data.leads.length){
                leadsWrap.innerHTML = '';
                data.leads.slice(0,50).forEach(l=>{
                    const el = document.createElement('div'); 
                    el.className='list-item'; 
                    el.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold">${escapeHtml(l.name)}</h6>
                                <p class="mb-0 small text-muted">
                                    <i class="fas fa-envelope me-1"></i>${escapeHtml(l.email||'')}
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-phone me-1"></i>${escapeHtml(l.phone||'')}
                                </p>
                            </div>
                            <span class="badge bg-primary">${escapeHtml(l.status||'')}</span>
                        </div>
                    `;
                    leadsWrap.appendChild(el);
                });
            } else { 
                leadsWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-user-tie fa-3x mb-3 opacity-25"></i><p>Nenhum lead encontrado.</p></div>'; 
            }

            // Projects
            const projWrap = document.getElementById('profileProjectsList');
            if(Array.isArray(data.projects) && data.projects.length){
                projWrap.innerHTML = '';
                data.projects.slice(0,50).forEach(p=>{
                    const el = document.createElement('div'); 
                    el.className='list-item'; 
                    el.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold">${escapeHtml(p.client_name||'Projeto')}</h6>
                                <p class="mb-0 small">
                                    <span class="text-success fw-bold">
                                        <i class="fas fa-dollar-sign me-1"></i>R$ ${Number(p.proposal_value||0).toLocaleString('pt-BR')}
                                    </span>
                                </p>
                            </div>
                            <span class="badge bg-success">${escapeHtml(p.status||'')}</span>
                        </div>
                    `;
                    projWrap.appendChild(el);
                });
            } else { 
                projWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-briefcase fa-3x mb-3 opacity-25"></i><p>Nenhum projeto encontrado.</p></div>'; 
            }

            // Movements
            const movWrap = document.getElementById('profileMovementsList');
            if(Array.isArray(data.movements) && data.movements.length){
                movWrap.innerHTML = '';
                data.movements.slice(0,200).forEach(m=>{
                    const el = document.createElement('div'); 
                    el.className='list-item profile-list-item movement-item';
                    const initials = (m.changed_by||m.user_id||'?').split(' ').map(p=>p[0]).join('').slice(0,2).toUpperCase();
                    const note = m.note ? `<p class="mb-0 small">${escapeHtml(m.note)}</p>` : '';
                    el.innerHTML = `
                        <div class="movement-row">
                            <div class="movement-person">
                                <div class="movement-avatar">${initials}</div>
                                <div class="min-width-0">
                                    <div class="movement-user">${escapeHtml(m.changed_by||m.user_id||'')}</div>
                                    <div class="movement-time"><i class="far fa-clock me-1"></i>${escapeHtml(m.created_at||'')}</div>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="small mb-1">
                                    <strong>${escapeHtml(m.changed_by||m.user_id||'')}</strong>
                                    <span class="text-muted mx-2">•</span>
                                    <span class="text-muted">
                                        <i class="far fa-clock me-1"></i>${escapeHtml(m.created_at||'')}
                                    </span>
                                </div>
                                <div class="small text-muted mb-1">
                                    #${escapeHtml(m.lead_id||'')}
                                    <span class="mx-2">→</span>
                                    <span class="badge bg-secondary">${escapeHtml(m.from_status||'')}</span>
                                    <i class="fas fa-arrow-right mx-1"></i>
                                    <span class="badge bg-primary">${escapeHtml(m.to_status||'')}</span>
                                </div>
                                ${note}
                            </div>
                        </div>
                    `;
                    movWrap.appendChild(el);
                });
            } else { 
                movWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i><p>Nenhuma movimentação encontrada.</p></div>'; 
            }

            // Reminders
            const remWrap = document.getElementById('profileRemindersList');
            if(Array.isArray(data.reminders) && data.reminders.length){
                remWrap.innerHTML = '';
                data.reminders.slice(0,200).forEach(r=>{
                    const el = document.createElement('div'); 
                    el.className='list-item';
                    el.innerHTML = `
                        <div class="d-flex align-items-start gap-3">
                            <div style="color:#667eea;font-size:1.5rem;">
                                <i class="far fa-bell"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="mb-1 fw-semibold">${escapeHtml(r.message||'')}</h6>
                                <p class="mb-0 small text-muted">
                                    <i class="far fa-calendar-alt me-1"></i>${escapeHtml(r.remind_at||'')}
                                    <span class="mx-2">•</span>
                                    Lead: <strong>${escapeHtml(r.lead_name||r.lead_id||'')}</strong>
                                </p>
                            </div>
                        </div>
                    `;
                    remWrap.appendChild(el);
                });
            } else { 
                remWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="far fa-bell fa-3x mb-3 opacity-25"></i><p>Nenhum lembrete encontrado.</p></div>'; 
            }

        }catch(e){ 
            console.error('loadProfileData', e); 
            const wrap = document.getElementById('profileLeadsList'); 
            if(wrap) wrap.innerHTML = '<div class="text-center text-danger py-5"><i class="fas fa-exclamation-triangle fa-2x mb-3"></i><p>Erro ao carregar dados</p></div>'; 
        }
    }
</script>

<?php include __DIR__ . '/includes/footer.php';

