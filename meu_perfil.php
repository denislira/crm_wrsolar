<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
}
$user_id = (int) $_SESSION['user_id'];
// fetch user info (best-effort)
$user = null;
try {
        $stmt = $pdo->prepare('SELECT id, username, email, nome_completo, biografia, avatar FROM users WHERE id = ? LIMIT 1');
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

// Server-side fetch of profile-related data as a reliable fallback
$profile_leads = [];
$profile_projects = [];
$profile_movements = [];
$profile_reminders = [];
$profile_tasks = [];
try {
    $tstmt = $pdo->prepare('SELECT * FROM team_tasks WHERE user_id = ? OR responsavel_id = ? OR responsavel = ? ORDER BY data_vencimento ASC, criado_em DESC LIMIT 500');
    $tstmt->execute([$user_id, $user_id, $user['username'] ?? '']);
    $profile_tasks = $tstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }
try {
    $lstmt = $pdo->prepare('SELECT id, user_id, name, email, phone, status, source, created_at FROM leads WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
    $lstmt->execute([$user_id]);
    $profile_leads = $lstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }
try {
    $pjstmt = $pdo->prepare('SELECT id, user_id, client_name, proposal_value, status, created_at FROM projetos WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
    $pjstmt->execute([$user_id]);
    $profile_projects = $pjstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* projetos may not exist */ }
try {
    $mstmt = $pdo->prepare('SELECT id, lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE user_id = ? OR changed_by = ? ORDER BY created_at DESC LIMIT 1000');
    $mstmt->execute([$user_id, $user['username'] ?? '']);
    $profile_movements = $mstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }
try {
    $rstmt = $pdo->prepare('SELECT r.id, r.lead_id, r.message, r.remind_at, r.status, r.created_at, l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id WHERE r.created_by = ? ORDER BY r.created_at DESC LIMIT 500');
    $rstmt->execute([$user_id]);
    $profile_reminders = $rstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

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
$primary_color = '#667eea';
$storageDir = __DIR__ . '/storage';
$settingsPath = $storageDir . '/settings.json';
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
    if (!empty($settings['primary_color'])) {
        $primary_color = $settings['primary_color'];
    }
}

include __DIR__ . '/includes/header.php';
// sidebar contains navigation
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .profile-header-gradient {
        background: <?php echo htmlspecialchars($primary_color); ?>;
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
        max-height: 360px;
        overflow-y: auto;
        padding-right: 0;
    }
    .scrollable-list::-webkit-scrollbar {
        width: 6px;
    }
    .scrollable-list::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    .scrollable-list::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }
    .scrollable-list::-webkit-scrollbar-thumb:hover {
        background: #a0aec0;
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
    /* Modal edit task styles */
    .task-avatar { width:56px; height:56px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:1.25rem; color:#fff; background:#6c757d; }
    .modal-header.colorful { background:#0b5ed7; color:#fff; }
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
        background: rgba(37,99,235,0.9) !important;
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
        box-shadow: 0 0 0 0.2rem rgba(59,130,246,0.2) !important;
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
</style>

<main class="flex-grow-1 p-4 main-content-scroll">
    <div class="container-fluid">
        <!-- Header com gradiente -->
        <div class="profile-header-gradient">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-2" style="font-weight: 700;">Meu Perfil</h2>
                    <p class="mb-0 opacity-75">Gerencie suas informações e acompanhe suas atividades</p>
                    <div class="mt-2">
                        <div id="profile_name_display" style="font-weight:700"><?php echo htmlspecialchars($profileNomeCompleto ?: ($user['username'] ?? '')); ?></div>
                        <div id="profile_email_display" class="small text-white-50"><?php echo htmlspecialchars($profileEmail); ?></div>
                        <div id="profile_bio_display" class="small text-white-50 mt-1"><?php echo nl2br(htmlspecialchars($profileBiografia)); ?></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="logout.php" class="btn btn-outline-light btn-modern">
                        <i class="fas fa-sign-out-alt me-2"></i>Sair
                    </a>
                </div>
            </div>
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

        <div class="tab-content" id="perfilTabContent">
        <!-- TAB: Meu Perfil -->
        <div class="tab-pane fade show active" id="pane-perfil" role="tabpanel">

        <div class="row g-4">
            <!-- Coluna Esquerda - Foto e Informações -->
            <div class="col-lg-4">
                <!-- Card Foto de Perfil -->
                <div class="profile-card">
                    <h5 class="profile-section-title">Foto de Perfil</h5>
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
                    <div class="d-flex gap-2 mb-4">
                        <button id="btnUploadAvatar" class="btn btn-primary btn-modern flex-grow-1">
                            <i class="fas fa-upload me-2"></i>Salvar
                        </button>
                        <button id="btnRemoveAvatar" class="btn btn-outline-danger btn-modern">
                            <i class="fas fa-trash me-2"></i>Remover
                        </button>
                    </div>
                    <div id="avatarMsg" class="mt-3 mb-4"></div>

                    <!-- Informações Pessoais -->
                    <hr class="my-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <h6 class="fw-bold mb-3 text-secondary">Informações Pessoais</h6>
                        <button id="btnInlineEditProfile" type="button" class="btn btn-sm btn-success" title="Editar informações"><i class="fas fa-pen"></i></button>
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
                    </div>
                </div>
            </div>

            <!-- Coluna Direita - Tarefas -->
            <div class="col-lg-8">
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
                    <div id="profileTasksList" class="scrollable-list" data-page-size="5" style="min-height:350px;">
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
                    <?php if (count($profile_tasks) > 5): ?>
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
            </div>
        </div>

        <!-- Seções de Leads, Projetos, Movimentações e Lembretes -->
        <div class="row g-4 mt-2">
            <div class="col-lg-6">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="fas fa-user-tie"></i></span>Meus Leads</h5>
                        <span class="profile-panel-count"><?php echo count($profile_leads); ?> registros</span>
                    </div>
                    <div id="profileLeadsList" class="scrollable-list" data-page-size="5" style="min-height:200px;">
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
                    <?php if (count($profile_leads) > 5): ?>
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
                    <div id="profileProjectsList" class="scrollable-list" data-page-size="5" style="min-height:200px;">
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
                    <?php if (count($profile_projects) > 5): ?>
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
                    <div id="profileMovementsList" class="scrollable-list" data-page-size="5" style="min-height:200px;">
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
                    <?php if (count($profile_movements) > 5): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileMovementsList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-12">
                <div class="profile-card profile-modern-panel">
                    <div class="profile-panel-header">
                        <h5 class="profile-panel-title"><span class="profile-panel-icon"><i class="far fa-bell"></i></span>Lembretes Criados</h5>
                        <span class="profile-panel-count"><?php echo count($profile_reminders); ?> registros</span>
                    </div>
                    <div id="profileRemindersList" class="scrollable-list" data-page-size="5" style="min-height:200px;">
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
                    <?php if (count($profile_reminders) > 5): ?>
                        <div class="profile-list-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm profile-load-more" data-load-more="#profileRemindersList">
                                <i class="fas fa-plus"></i> Carregar mais
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div><!-- /pane-perfil -->

        <!-- TAB: Fluxo de Atendimento -->
        <div class="tab-pane fade" id="pane-fluxo" role="tabpanel">

            <!-- Fluxo de Venda Progressivo -->
            <h5 class="fw-bold mb-4" style="font-size:1.25rem;">
                <i class="fas fa-chart-line me-2 text-primary"></i>Fluxo de Venda Progressivo
            </h5>

            <div class="row g-4 mb-5">
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

        </div><!-- /pane-fluxo -->
        </div><!-- /tab-content -->

    </div>
</main>

<script type="module">
    const PROFILE_USER_ID = <?php echo json_encode($user_id); ?>;
    const usersMap = <?php echo json_encode($usersMap ?? []); ?>;
    const profileTasks = <?php echo json_encode($profile_tasks); ?>;

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

        document.querySelectorAll('.profile-info-item[data-field]').forEach(it => {
            const field = it.getAttribute('data-field');
            const view = it.querySelector('.view-mode');
            const input = it.querySelector('.edit-mode input, .edit-mode textarea');
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
            const input = it.querySelector('.edit-mode input, .edit-mode textarea');
            if (!field || !view || !input) return;
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
            const input = it.querySelector('.edit-mode input, .edit-mode textarea');
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
        btnInline.innerHTML = '<i class="fas fa-pen"></i>';
        btnInline.disabled = false;
        const cancelBtn = document.getElementById('btnInlineCancel');
        if (cancelBtn) cancelBtn.remove();
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

    document.addEventListener('DOMContentLoaded', ()=>{
        bindLoadMoreLists();
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
        if (!btnInline) return;
        let editing = false;
        btnInline.addEventListener('click', async ()=>{
            const items = document.querySelectorAll('.profile-info-item');
            if (!editing) {
                // enter edit mode
                syncInlineProfileInputsFromView();
                items.forEach(it=>{ it.querySelector('.view-mode')?.classList.add('d-none'); it.querySelector('.edit-mode')?.classList.remove('d-none'); });
                btnInline.innerHTML = '<i class="fas fa-check"></i>';
                const cancelBtn = document.createElement('button'); cancelBtn.type='button'; cancelBtn.className='btn btn-sm btn-outline-secondary ms-2'; cancelBtn.id='btnInlineCancel'; cancelBtn.innerHTML='<i class="fas fa-times"></i>';
                btnInline.insertAdjacentElement('afterend', cancelBtn);
                cancelBtn.addEventListener('click', ()=>{ location.reload(); });
                editing = true;
            } else {
                const fd = new FormData();
                document.querySelectorAll('.profile-info-item[data-field]').forEach(it=>{
                    const name = it.getAttribute('data-field');
                    const input = it.querySelector('.edit-mode input, .edit-mode textarea');
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
                    applyInlineProfileData(json.profile || {});
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

