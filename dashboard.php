<?php
// Ensure session and DB
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

checkAccessOrRedirect('dashboard');

// ── Defensive schema detection ────────────────────────────────────────────────
try {
    $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $leadCols = []; }
$hasDeleted       = in_array('deleted', $leadCols, true);
$hasCreatedAt     = in_array('created_at', $leadCols, true);
$hasDataInicio    = in_array('data_inicio', $leadCols, true);
$hasSource        = in_array('source', $leadCols, true);
$hasStageId       = in_array('stage_id', $leadCols, true);
$hasOrcamento     = in_array('orcamento_value', $leadCols, true);
$hasFirstContact  = in_array('first_contact_at', $leadCols, true);
$hasUltimoCtato   = in_array('ultimo_contato', $leadCols, true);
$hasDisqual       = in_array('disqualification_reason', $leadCols, true);
$hasIsSQL         = in_array('is_sql', $leadCols, true);
$hasKwp           = in_array('kwp', $leadCols, true) ? 'kwp' : (in_array('estimativa_projeto_kwh', $leadCols, true) ? 'estimativa_projeto_kwh' : null);
$hasPayType       = in_array('payment_type', $leadCols, true) ? 'payment_type' : (in_array('forma_pagamento', $leadCols, true) ? 'forma_pagamento' : null);

$delWhere  = $hasDeleted ? "deleted = 0" : "1=1";
$dateCol   = $hasDataInicio ? 'data_inicio' : ($hasCreatedAt ? 'created_at' : 'data_inicio');

// By default the lead owner is the user who last edited it (user_id_update). If missing, fall back to the creator (user_id).
$leadOwnerJoinExpr = in_array('user_id_update', $leadCols, true) ? 'COALESCE(l.user_id_update, l.user_id)' : 'l.user_id';

// ── Helper: safe fetchColumn ──────────────────────────────────────────────────
function safeQuery($pdo, $sql, $params = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    } catch (Exception $e) { return 0; }
}
function safeQueryAll($pdo, $sql, $params = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── Core KPIs ─────────────────────────────────────────────────────────────────
$totalLeadsAll    = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere}");
$totalLeadsActive = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND status NOT LIKE '%perdido%' AND status NOT LIKE '%descartado%' AND status NOT LIKE '%convertido%'");
$newLeads30       = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$newLeadsToday    = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND DATE({$dateCol}) = CURDATE()");
$newLeadsYesterday= (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND DATE({$dateCol}) = DATE_SUB(CURDATE(),INTERVAL 1 DAY)");

// Projetos
$totalProjetos    = (int)safeQuery($pdo, "SELECT COUNT(*) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')");
$projetosFinalizados = (int)safeQuery($pdo, "SELECT COUNT(*) FROM projetos WHERE status='Finalizado'");
$valorNegociacao  = (float)safeQuery($pdo, "SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')");
$valorContratado  = (float)safeQuery($pdo, "SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE status='Finalizado'");
$conversionRate   = $totalLeadsAll > 0 ? round(($projetosFinalizados / $totalLeadsAll) * 100, 1) : 0;
$ticketMedio     = $projetosFinalizados > 0 ? ($valorContratado / $projetosFinalizados) : 0;

// ── SLA / Speed-to-Lead KPIs ──────────────────────────────────────────────────
$threshold24h = (new DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
$slaNoContact = 0;
if ($hasFirstContact) {
    $slaNoContact = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND first_contact_at IS NULL AND {$dateCol} < ?", [$threshold24h]);
} elseif ($hasUltimoCtato) {
    $slaNoContact = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND (ultimo_contato IS NULL OR ultimo_contato < ?) AND {$dateCol} < ?", [$threshold24h, $threshold24h]);
}

$speedToLeadAvg = null;
if ($hasFirstContact) {
    $speedToLeadAvg = safeQuery($pdo, "SELECT AVG(TIMESTAMPDIFF(HOUR, {$dateCol}, first_contact_at)) FROM leads WHERE {$delWhere} AND first_contact_at IS NOT NULL AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if ($speedToLeadAvg !== null && $speedToLeadAvg !== false) $speedToLeadAvg = round((float)$speedToLeadAvg, 1);
}

$staleLeads7 = 0;
try {
    $staleThreshold = (new DateTime())->modify('-7 days')->format('Y-m-d H:i:s');
    $staleStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT l.id) FROM leads l
        LEFT JOIN lead_movements lm ON lm.lead_id = l.id
        WHERE l.{$delWhere} AND l.{$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          AND l.status NOT LIKE '%fechado%' AND l.status NOT LIKE '%ganho%' AND l.status NOT LIKE '%perdido%'
        GROUP BY l.id
        HAVING (MAX(lm.created_at) IS NULL OR MAX(lm.created_at) < ?)
    ");
    $staleStmt->execute([$staleThreshold]);
    $staleLeads7 = count($staleStmt->fetchAll());
} catch (Exception $e) { $staleLeads7 = 0; }

// ── SQL / Qualification KPI ───────────────────────────────────────────────────
$totalSql = 0;
$sqlRate  = null;
if ($hasIsSQL) {
    $totalSql = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND is_sql = 1 AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
} elseif ($hasOrcamento) {
    $totalSql = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND orcamento_value > 0 AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
}
if ($newLeads30 > 0) $sqlRate = round(($totalSql / $newLeads30) * 100, 1);

// ── Funil por etapas ──────────────────────────────────────────────────────────
$funnelStages = [];
try {
    $fsColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $fsCols = $fsColsStmt->fetchAll(PDO::FETCH_COLUMN);
    $fsNameCol  = in_array('stage_name', $fsCols, true) ? 'stage_name' : (in_array('name', $fsCols, true) ? 'name' : 'stage_name');
    $fsOrdCol   = in_array('stage_order', $fsCols, true) ? 'stage_order' : (in_array('position', $fsCols, true) ? 'position' : 'id');
    $fsColorCol = in_array('stage_color', $fsCols, true) ? 'stage_color' : (in_array('color', $fsCols, true) ? 'color' : null);
    $fsSel = "id, {$fsNameCol} AS name" . ($fsColorCol ? ", {$fsColorCol} AS color" : "");
    $fsRows = $pdo->query("SELECT {$fsSel} FROM funil_stages ORDER BY COALESCE({$fsOrdCol}, id) ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fsRows as $fs) {
        $cnt = (int)safeQuery($pdo, "SELECT COUNT(*) FROM leads WHERE {$delWhere} AND (stage_id = ? OR status = ?)", [$fs['id'], $fs['name']]);
        $funnelStages[] = ['name' => $fs['name'], 'color' => $fs['color'] ?? null, 'count' => $cnt];
    }
} catch (Exception $e) { $funnelStages = []; }

// If no funil_stages, fall back to status counts
$leadsStatusData = [];
if (empty($funnelStages)) {
    $leadsStatusData = safeQueryAll($pdo, "SELECT COALESCE(NULLIF(status,''),'Sem status') AS status, COUNT(*) as count FROM leads WHERE {$delWhere} GROUP BY status ORDER BY count DESC LIMIT 12");
}

// ── Charts data ───────────────────────────────────────────────────────────────

// Leads by source + qualification rate (last 30d)
$sourceQualData = [];
if ($hasSource) {
    $sqlCond = $hasIsSQL ? "is_sql = 1" : ($hasOrcamento ? "orcamento_value > 0" : "status LIKE '%qualif%' OR status LIKE '%proposta%'");
    $sourceQualData = safeQueryAll($pdo, "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN {$sqlCond} THEN 1 ELSE 0 END) AS qualified FROM leads WHERE {$delWhere} AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY source ORDER BY total DESC LIMIT 8");
}

// Top consultores (last 30 days)
$topConsultores = [];
try {
    $conversionStageIds = [];
    if (!empty($funnelStages)) {
        // Use explicit conversion stages only (won / explicit conversion flag). Exclude generic is_final (which can represent both won/lost).
        $conversionStageIds = $pdo->query("SELECT id FROM funil_stages WHERE is_conversion = 1 OR final_type = 'won'")->fetchAll(PDO::FETCH_COLUMN);
    }

    $conversionStatusCondition = "LOWER(l.status) LIKE '%ganho%' OR LOWER(l.status) LIKE '%convertido%'";
    $stageCondition = '';

    if (!empty($conversionStageIds)) {
        $stagePlaceholders = implode(',', array_fill(0, count($conversionStageIds), '?'));
        $stageCondition = "l.stage_id IN ($stagePlaceholders) OR ";
    }

    $conversionParams = [];
    foreach ($conversionStageIds as $sid) {
        $conversionParams[] = (int)$sid;
    }

    $conversionCondition = "({$stageCondition}{$conversionStatusCondition})";

    $topConsultores = safeQueryAll($pdo, "
        SELECT u.username,
               COUNT(DISTINCT l.id) AS total,
               SUM(CASE WHEN {$conversionCondition} THEN 1 ELSE 0 END) AS conversoes
        FROM users u
        LEFT JOIN (
            SELECT id, stage_id, status, user_id, user_id_update
            FROM leads
            WHERE {$delWhere} AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ) l ON {$leadOwnerJoinExpr} = u.id
        GROUP BY u.id, u.username
        HAVING total > 0
        ORDER BY conversoes DESC, total DESC
        LIMIT 5
    ", $conversionParams);
} catch (Exception $e) { $topConsultores = []; }

// Leads sem contato (alert list, up to 5 for dashboard)
$slaAlertPreview = [];
if ($hasFirstContact) {
    $slaAlertPreview = safeQueryAll($pdo, "SELECT id, name, COALESCE(NULLIF(source,''),'—') AS source, {$dateCol} AS created_at FROM leads WHERE {$delWhere} AND first_contact_at IS NULL AND {$dateCol} < ? ORDER BY {$dateCol} ASC LIMIT 5", [$threshold24h]);
} elseif ($hasUltimoCtato) {
    $slaAlertPreview = safeQueryAll($pdo, "SELECT id, name, COALESCE(NULLIF(source,''),'—') AS source, {$dateCol} AS created_at FROM leads WHERE {$delWhere} AND (ultimo_contato IS NULL OR ultimo_contato < ?) AND {$dateCol} < ? ORDER BY {$dateCol} ASC LIMIT 5", [$threshold24h, $threshold24h]);
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<style>
#dashboard { max-width: 1680px; padding-bottom: 2rem; }
.dash-hero { position:relative; overflow:hidden; padding:1.65rem 1.75rem; border-radius:22px; color:#fff; background:linear-gradient(118deg,var(--blue-900) 0%,var(--blue-700) 58%,var(--green) 100%); box-shadow:0 18px 35px rgba(var(--bs-primary-rgb),.22); }
.dash-hero:after { content:''; position:absolute; width:360px; height:360px; border-radius:50%; top:-230px; right:-70px; background:rgba(255,255,255,.11); box-shadow:-105px 175px 0 -24px rgba(255,210,74,.17); }
.dash-hero-content { position:relative; z-index:1; }
.dash-eyebrow { font-size:.72rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; opacity:.75; }
.dash-hero h1 { font-size:1.55rem; letter-spacing:-.03em; color:#fff !important; }
.dash-hero .hero-subtitle { color:rgba(255,255,255,.78); }
.hero-action { border:1px solid rgba(255,255,255,.3); background:rgba(255,255,255,.12); color:#fff; backdrop-filter:blur(5px); }
.hero-action:hover { background:#fff; border-color:#fff; color:var(--blue-900); }
.hero-stat { border-left:1px solid rgba(255,255,255,.25); padding-left:1rem; }
.hero-stat strong { display:block; font-size:1.05rem; line-height:1.15; }
.hero-stat span { font-size:.73rem; color:rgba(255,255,255,.72); }
.dash-kpi { position:relative; min-height:132px; overflow:hidden; background:#fff; border:1px solid #e8eef5; border-radius:18px; box-shadow:0 8px 20px rgba(15,23,42,.045); padding:1.1rem 1.15rem; transition:transform .2s,box-shadow .2s; }
.dash-kpi:hover { transform:translateY(-3px); box-shadow:0 14px 26px rgba(15,23,42,.1); }
.dash-kpi:before { content:''; position:absolute; height:4px; left:0; right:0; top:0; background:var(--kpi-color,var(--blue-700)); }
.dash-kpi .kpi-icon { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border-radius:11px; background:color-mix(in srgb,var(--kpi-color,var(--blue-700)) 12%,white); color:var(--kpi-color,var(--blue-700)); }
.dash-kpi .kpi-value { font-size:1.75rem; font-weight:750; letter-spacing:-.045em; line-height:1.05; margin-top:.72rem; }
.dash-kpi .kpi-label { font-size:.74rem; font-weight:650; color:#64748b; margin-top:2px; }
.dash-kpi .kpi-delta { font-size:.75rem; margin-top:.38rem; }
.dash-kpi.accent-blue { --kpi-color:var(--blue-700); } .dash-kpi.accent-cyan { --kpi-color:#0891b2; } .dash-kpi.accent-green { --kpi-color:#059669; } .dash-kpi.accent-amber { --kpi-color:#d97706; } .dash-kpi.accent-red { --kpi-color:#dc2626; } .dash-kpi.accent-violet { --kpi-color:#7c3aed; }
.dash-card { background:#fff; border:1px solid #e8eef5; border-radius:18px; box-shadow:0 8px 20px rgba(15,23,42,.045); padding:1.3rem; }
.dash-card-title { font-size:.95rem; font-weight:750; letter-spacing:-.015em; color:#1e293b; margin-bottom:1.15rem; display:flex; align-items:center; gap:.55rem; }
.dash-card-title i { color:var(--blue-700); width:28px; height:28px; border-radius:9px; background:rgba(var(--bs-primary-rgb),.1); display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; }
.chart-legend { display:flex; align-items:center; gap:1rem; font-size:.7rem; color:#64748b; margin:-.65rem 0 1rem 2.35rem; }
.chart-legend span { display:inline-flex; align-items:center; gap:.35rem; }
.chart-legend i { width:8px; height:8px; border-radius:50%; display:inline-block; }
.funnel-mini { display:flex; flex-direction:column; gap:11px; }
.funnel-mini-row { display:grid; grid-template-columns:minmax(105px,145px) 1fr 48px; align-items:center; gap:12px; }
.funnel-mini-label { min-width:0; font-size:.79rem; font-weight:650; color:#475569; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.funnel-mini-bar { position:relative; height:28px; background:repeating-linear-gradient(90deg,#f1f5f9 0,#f1f5f9 calc(25% - 1px),#e5eaf0 calc(25% - 1px),#e5eaf0 25%); border-radius:9px; overflow:hidden; }
.funnel-mini-fill { position:relative; height:100%; min-width:9px; border-radius:9px; display:flex; align-items:center; justify-content:flex-end; padding-right:8px; font-size:.7rem; font-weight:750; color:#fff; box-shadow:inset 0 -1px 0 rgba(0,0,0,.1); transition:width .65s cubic-bezier(.2,.8,.2,1); }
.funnel-mini-fill:after { content:''; position:absolute; inset:0; background:linear-gradient(90deg,rgba(255,255,255,.04),rgba(255,255,255,.22)); }
.funnel-mini-fill span { position:relative; z-index:1; }
.funnel-mini-count { min-width:36px; text-align:right; font-weight:750; font-size:.88rem; color:#1e293b; }
.funnel-mini-count small { display:block; font-size:.62rem; font-weight:600; color:#94a3b8; }
.channel-chart { display:flex; flex-direction:column; gap:14px; }
.channel-row { display:grid; grid-template-columns:minmax(92px,125px) 1fr 58px; align-items:center; gap:11px; }
.channel-name { font-size:.78rem; font-weight:650; color:#475569; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.channel-track { position:relative; height:22px; border-radius:7px; background:#f1f5f9; overflow:hidden; }
.channel-total { position:absolute; inset:0 auto 0 0; border-radius:7px; background:rgba(var(--bs-primary-rgb),.14); min-width:5px; }
.channel-qualified { position:absolute; inset:4px auto 4px 0; border-radius:5px; min-width:3px; box-shadow:0 2px 7px rgba(15,23,42,.12); }
.channel-numbers { text-align:right; }
.channel-numbers strong { display:block; font-size:.78rem; color:#1e293b; }
.channel-numbers small { display:block; font-size:.64rem; color:#94a3b8; }
.alert-sla-item { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:.85rem; }
.alert-sla-item:last-child { border-bottom:none; }
.badge-src { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.73rem; font-weight:600; background:rgba(var(--bs-primary-rgb),.1); color:var(--blue-700); }
.trend-up   { color:#10b981; } .trend-down { color:#ef4444; } .trend-flat { color:#94a3b8; }
.consultant-row { display:grid; grid-template-columns:34px minmax(100px,1fr) minmax(110px,1.4fr) 48px; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; }
.consultant-row:last-child { border-bottom:none; }
.consultant-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,var(--blue-700),var(--blue-900)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.8rem; flex-shrink:0; }
.consultant-track { height:8px; border-radius:99px; background:#eef2f7; overflow:hidden; }
.consultant-fill { height:100%; min-width:3px; border-radius:99px; background:linear-gradient(90deg,var(--blue-700),var(--green)); }
.chart-summary { display:flex; align-items:center; gap:.45rem; margin-left:auto; font-size:.7rem; font-weight:650; color:#64748b; background:#f8fafc; border:1px solid #e8eef5; border-radius:99px; padding:.32rem .65rem; }
.finance-card { background:linear-gradient(135deg,var(--blue-700),var(--blue-900)); color:#fff; border:none; }
.finance-card .dash-card-title,.finance-card .text-muted { color:#fff !important; }.finance-card .dash-card-title i { color:#fff; background:rgba(255,255,255,.16); }.finance-value { font-size:1.6rem; font-weight:750; letter-spacing:-.04em; }.finance-label { color:rgba(255,255,255,.72); font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

body.theme-dark .dash-kpi,
body.theme-dark .dash-card {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}
body.theme-dark .dash-hero { box-shadow:none; }
body.theme-dark .dash-kpi .kpi-icon { background:rgba(255,255,255,.08); }
body.theme-dark .dash-kpi .kpi-label,
body.theme-dark .dash-kpi .kpi-delta,
body.theme-dark .dash-card-title,
body.theme-dark .funnel-mini-label,
body.theme-dark .funnel-mini-count,
body.theme-dark .alert-sla-item,
body.theme-dark .consultant-row,
body.theme-dark .badge-src,
body.theme-dark .text-muted {
    color: #c3d5ea !important;
}
body.theme-dark .dash-card-title i,
body.theme-dark .badge-src {
    color: #93c5fd !important;
}
body.theme-dark .funnel-mini-bar {
    background: rgba(255,255,255,0.08) !important;
}
body.theme-dark .channel-track,
body.theme-dark .consultant-track { background:rgba(255,255,255,.08); }
body.theme-dark .channel-total { background:rgba(var(--bs-primary-rgb),.24); }
body.theme-dark .channel-name,
body.theme-dark .channel-numbers strong,
body.theme-dark .chart-legend,
body.theme-dark .chart-summary { color:#c3d5ea; }
body.theme-dark .chart-summary { background:rgba(255,255,255,.04); border-color:rgba(255,255,255,.08); }
@media (max-width:575.98px) {
    .funnel-mini-row { grid-template-columns:92px 1fr 38px; gap:8px; }
    .channel-row { grid-template-columns:82px 1fr 50px; gap:8px; }
    .consultant-row { grid-template-columns:34px minmax(90px,1fr) 48px; }
    .consultant-track { display:none; }
}
body.theme-dark .alert-sla-item {
    border-bottom-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .consultant-row {
    border-bottom-color: rgba(255,255,255,0.08) !important;
}
</style>

<main class="flex-grow-1 p-4 main-content-scroll">
<div class="container-fluid" id="dashboard">

    <section class="dash-hero mb-4">
        <div class="dash-hero-content d-lg-flex align-items-end justify-content-between gap-4">
            <div>
                <div class="dash-eyebrow">Central de desempenho</div>
                <h1 class="mb-1">Olá, <?= htmlspecialchars($_SESSION['username'] ?? 'Usuário') ?>.</h1>
                <p class="hero-subtitle mb-0">Acompanhe o que precisa da sua atenção hoje · <?= (new DateTime())->format('d/m/Y H:i') ?></p>
            </div>
            <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                <div class="hero-stat d-none d-sm-block"><strong><?= (int)$totalLeadsActive ?></strong><span>leads em andamento</span></div>
                <div class="hero-stat d-none d-md-block"><strong><?= (int)$totalProjetos ?></strong><span>projetos abertos</span></div>
                <a href="relatorios.php" class="btn btn-sm hero-action px-3"><i class="fa fa-chart-line me-1"></i> Relatórios</a>
            </div>
        </div>
    </section>

    <?php if ($slaNoContact > 0): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4 py-2" role="alert">
        <i class="fa fa-exclamation-circle fa-lg"></i>
        <div>
            <strong><?= (int)$slaNoContact ?> lead<?= $slaNoContact > 1 ? 's' : '' ?> sem contato há mais de 24h.</strong>
            Atenda agora — cada hora reduz a chance de conversão.
            <a href="relatorios.php?tab=sla" class="alert-link ms-2">Ver lista →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 1: KPIs principais -->
    <div class="row g-3 mb-4">
        <!-- Leads hoje -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi accent-blue">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label">Leads Hoje</div><span class="kpi-icon"><i class="fa fa-user-plus"></i></span></div>
                <div class="kpi-value" style="color:var(--blue-700);"><?= (int)$newLeadsToday ?></div>
                <?php $deltaDay = $newLeadsToday - $newLeadsYesterday; ?>
                <div class="kpi-delta <?= $deltaDay > 0 ? 'trend-up' : ($deltaDay < 0 ? 'trend-down' : 'trend-flat') ?>">
                    <?= $deltaDay > 0 ? '▲' : ($deltaDay < 0 ? '▼' : '—') ?> <?= abs($deltaDay) ?> vs ontem
                </div>
            </div>
        </div>
        <!-- Leads 30d -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi accent-cyan">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label">Novos (30d)</div><span class="kpi-icon"><i class="fa fa-calendar-plus"></i></span></div>
                <div class="kpi-value" style="color:#06b6d4;"><?= (int)$newLeads30 ?></div>
                <div class="kpi-delta text-muted">Total geral: <?= (int)$totalLeadsAll ?></div>
            </div>
        </div>
        <!-- Taxa SQL -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= ($sqlRate !== null && $sqlRate < 30) ? 'accent-red' : 'accent-green' ?>">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label d-flex align-items-center">Taxa SQL (30d)<button type="button" class="metric-info-button" title="SQL significa Sales Qualified Lead. O CRM marca is_sql = 1 automaticamente quando o lead atinge uma etapa configurada como qualificação e mantém essa marca ao avançar ou voltar no funil. Fórmula: SQL dos últimos 30 dias ÷ novos leads dos últimos 30 dias × 100." aria-label="Explicação da Taxa SQL"><i class="fa-solid fa-circle-info"></i></button></div><span class="kpi-icon"><i class="fa fa-bullseye"></i></span></div>
                <div class="kpi-value <?= ($sqlRate !== null && $sqlRate < 30) ? 'text-danger' : 'text-success' ?>"><?= $sqlRate !== null ? $sqlRate . '%' : '—' ?></div>
                <div class="kpi-delta text-muted"><?= (int)$totalSql ?> qualificados</div>
            </div>
        </div>
        <!-- Speed-to-Lead -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? 'accent-red' : 'accent-amber' ?>">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label d-flex align-items-center">Speed-to-Lead<button type="button" class="metric-info-button" title="Tempo médio entre a entrada do lead e o primeiro contato. Fórmula: média de first_contact_at − data_inicio (ou created_at), em horas, para leads dos últimos 30 dias. O resultado depende de first_contact_at estar preenchido; sem esse registro, o lead não entra na média." aria-label="Explicação do Speed-to-Lead"><i class="fa-solid fa-circle-info"></i></button></div><span class="kpi-icon"><i class="fa fa-bolt"></i></span></div>
                <div class="kpi-value <?= ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? 'text-danger' : 'text-warning' ?>"><?= $speedToLeadAvg !== null ? $speedToLeadAvg . 'h' : '—' ?></div>
                <div class="kpi-delta <?= ($slaNoContact > 0) ? 'trend-down' : 'trend-flat' ?>"><?= (int)$slaNoContact ?> sem contato >24h</div>
            </div>
        </div>
        <!-- Leads parados -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= $staleLeads7 > 0 ? 'accent-violet' : 'accent-green' ?>">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label">Parados &gt;7 dias</div><span class="kpi-icon"><i class="fa fa-hourglass-half"></i></span></div>
                <div class="kpi-value" style="color:<?= $staleLeads7 > 0 ? '#8b5cf6' : '#10b981' ?>;"><?= (int)$staleLeads7 ?></div>
                <div class="kpi-delta text-muted">Risco de esfriamento</div>
            </div>
        </div>
        <!-- Taxa de conversão -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi accent-green">
                <div class="d-flex align-items-center justify-content-between"><div class="kpi-label">Taxa de Conversão</div><span class="kpi-icon"><i class="fa fa-arrow-trend-up"></i></span></div>
                <div class="kpi-value text-success"><?= $conversionRate ?>%</div>
                <div class="kpi-delta text-muted"><?= (int)$projetosFinalizados ?> proj. fechados</div>
            </div>
        </div>
    </div>

    <!-- Commercial overview: data already registered in projects -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="dash-card finance-card h-100">
                <div class="dash-card-title"><i class="fa fa-wallet"></i> Visão comercial</div>
                <div class="row g-3">
                    <div class="col-sm-4"><div class="finance-value">R$ <?= number_format($valorNegociacao, 0, ',', '.') ?></div><div class="finance-label">em negociação</div></div>
                    <div class="col-sm-4"><div class="finance-value">R$ <?= number_format($valorContratado, 0, ',', '.') ?></div><div class="finance-label">contratado</div></div>
                    <div class="col-sm-4"><div class="finance-value">R$ <?= number_format($ticketMedio, 0, ',', '.') ?></div><div class="finance-label">ticket médio fechado</div></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-card h-100">
                <div class="dash-card-title"><i class="fa fa-compass"></i> Próximos passos</div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="crm.php" class="btn btn-sm btn-primary"><i class="fa fa-users me-1"></i> Gerenciar leads</a>
                    <a href="projetos.php" class="btn btn-sm btn-outline-primary"><i class="fa fa-diagram-project me-1"></i> Ver projetos</a>
                    <a href="relatorios.php?tab=sla" class="btn btn-sm btn-outline-secondary"><i class="fa fa-clock me-1"></i> Acompanhar SLA</a>
                </div>
                <p class="text-muted small mb-0 mt-3">Priorize leads sem contato e oportunidades paradas para proteger a conversão.</p>
            </div>
        </div>
    </div>

    <!-- Row 2: Funil + Alertas SLA -->
    <div class="row g-3 mb-4">
        <!-- Funil de etapas -->
        <div class="col-lg-6">
            <div class="dash-card h-100">
                <?php
                    $funnelDataForSummary = !empty($funnelStages) ? $funnelStages : $leadsStatusData;
                    $funnelTotalVisible = array_sum(array_map(static fn($item) => (int)($item['count'] ?? 0), $funnelDataForSummary));
                    $colors = ['var(--blue-700)','#0891b2','#10b981','#f59e0b','#f97316','#ef4444','#8b5cf6','#ec4899'];
                ?>
                <div class="dash-card-title">
                    <i class="fa fa-filter"></i> Funil por Etapa
                    <span class="chart-summary"><?= number_format($funnelTotalVisible, 0, ',', '.') ?> leads</span>
                </div>
                <?php if (!empty($funnelStages)): ?>
                    <div class="funnel-mini">
                    <?php
                        $funnelMax = max(array_column($funnelStages, 'count') ?: [1]);
                        $funnelMax = max($funnelMax, 1);
                        foreach ($funnelStages as $i => $fs):
                            $w = max(8, round(($fs['count'] / $funnelMax) * 100));
                            $share = $funnelTotalVisible > 0 ? round(((int)$fs['count'] / $funnelTotalVisible) * 100) : 0;
                            $color = ($fs['color'] && $fs['color'] !== '') ? $fs['color'] : $colors[$i % count($colors)];
                    ?>
                    <div class="funnel-mini-row">
                        <div class="funnel-mini-label" title="<?= htmlspecialchars($fs['name']) ?>"><?= htmlspecialchars($fs['name']) ?></div>
                        <div class="funnel-mini-bar">
                            <div class="funnel-mini-fill" style="width:<?= $w ?>%; background:<?= htmlspecialchars($color) ?>;"><span><?= $w > 19 ? (int)$fs['count'] : '' ?></span></div>
                        </div>
                        <div class="funnel-mini-count"><?= (int)$fs['count'] ?><small><?= $share ?>%</small></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php elseif (!empty($leadsStatusData)): ?>
                    <div class="funnel-mini">
                    <?php
                        $statusMax = max(array_column($leadsStatusData, 'count') ?: [1]);
                        $statusMax = max($statusMax, 1);
                        foreach ($leadsStatusData as $i => $sd):
                            $w = max(8, round(($sd['count'] / $statusMax) * 100));
                            $share = $funnelTotalVisible > 0 ? round(((int)$sd['count'] / $funnelTotalVisible) * 100) : 0;
                    ?>
                    <div class="funnel-mini-row">
                        <div class="funnel-mini-label"><?= htmlspecialchars($sd['status']) ?></div>
                        <div class="funnel-mini-bar">
                            <div class="funnel-mini-fill" style="width:<?= $w ?>%; background:<?= $colors[$i % count($colors)] ?>;"><span><?= $w > 19 ? (int)$sd['count'] : '' ?></span></div>
                        </div>
                        <div class="funnel-mini-count"><?= (int)$sd['count'] ?><small><?= $share ?>%</small></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4 small">Configure as etapas do funil para ver a distribuição.</p>
                <?php endif; ?>
                <div class="mt-3 text-end"><a href="relatorios.php?tab=funnel" class="btn btn-sm btn-outline-secondary">Ver funil completo →</a></div>
            </div>
        </div>

        <!-- Alertas SLA -->
        <div class="col-lg-6">
            <div class="dash-card h-100">
                <div class="dash-card-title">
                    <i class="fa fa-exclamation-triangle" style="color:#ef4444;"></i> Leads sem contato >24h
                    <?php if ($slaNoContact > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= (int)$slaNoContact ?></span>
                    <?php else: ?>
                    <span class="badge bg-success ms-auto">OK</span>
                    <?php endif; ?>
                </div>
                <?php
    function formatTimeAgoInBusiness($dt) {
        if (!$dt) return '—';
        $diff = (new DateTime())->getTimestamp() - $dt->getTimestamp();
        if ($diff < 3600) return '<1h';
        $hours = (int) round($diff / 3600);
        if ($hours < 48) return $hours . 'h';
        $days = (int) round($diff / 86400);
        if ($days < 30) return $days . 'd';
        $months = (int) floor($days / 30);
        if ($months < 12) {
            $remDays = $days - ($months * 30);
            return $months . 'm' . ($remDays > 0 ? ' ' . $remDays . 'd' : '');
        }
        $years = (int) floor($months / 12);
        $remMonths = $months % 12;
        return $years . 'y' . ($remMonths > 0 ? ' ' . $remMonths . 'm' : '');
    }
    ?>
    <?php if (!empty($slaAlertPreview)): ?>
        <?php foreach ($slaAlertPreview as $al):
            $createdDt = null;
            try { if ($al['created_at']) $createdDt = new DateTime((string)$al['created_at']); } catch (Exception $e) {}
            $hoursWaiting = $createdDt ? round((new DateTime())->getTimestamp() - $createdDt->getTimestamp()) / 3600 : null;
        ?>
        <div class="alert-sla-item">
            <div>
                <strong class="d-block" style="font-size:.85rem;"><?= htmlspecialchars($al['name']) ?></strong>
                <span class="badge-src"><?= htmlspecialchars($al['source']) ?></span>
            </div>
            <div class="text-end">
                <span style="color:<?= ($hoursWaiting !== null && $hoursWaiting > 48) ? '#ef4444' : '#f59e0b' ?>; font-weight:700; font-size:.85rem;">
                    <?= $createdDt ? formatTimeAgoInBusiness($createdDt) : '—' ?>
                </span>
                <div class="small text-muted"><?= $createdDt ? $createdDt->format('d/m H:i') : '' ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($slaNoContact > count($slaAlertPreview)): ?>
            <div class="mt-2 text-center"><a href="relatorios.php?tab=sla" class="btn btn-sm btn-danger">+<?= $slaNoContact - count($slaAlertPreview) ?> mais → Ver todos</a></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="py-4 text-center text-muted small"><i class="fa fa-check-circle text-success fa-2x d-block mb-2"></i>Nenhum lead sem contato.<br>Ótimo atendimento!</div>
    <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Row 3: Qualificação por fonte + Consultores -->
    <div class="row g-3 mb-4">
        <!-- Qualificação por canal -->
        <div class="col-lg-6">
            <div class="dash-card h-100">
                <?php
                    $sourceTotalVisible = array_sum(array_map(static fn($item) => (int)($item['total'] ?? 0), $sourceQualData));
                    $sourceQualifiedVisible = array_sum(array_map(static fn($item) => (int)($item['qualified'] ?? 0), $sourceQualData));
                    $sourceMax = max(array_map(static fn($item) => (int)($item['total'] ?? 0), $sourceQualData) ?: [1]);
                    $sourceMax = max($sourceMax, 1);
                ?>
                <div class="dash-card-title">
                    <i class="fa fa-bullseye"></i> Qualificação por Canal
                    <span class="chart-summary"><?= (int)$sourceQualifiedVisible ?> de <?= (int)$sourceTotalVisible ?></span>
                </div>
                <?php if (!empty($sourceQualData)): ?>
                <div class="chart-legend">
                    <span><i style="background:#dbeafe;"></i>Total de leads</span>
                    <span><i style="background:#10b981;"></i>Qualificados</span>
                    <span class="ms-auto">últimos 30 dias</span>
                </div>
                <div class="channel-chart">
                    <?php foreach ($sourceQualData as $sq):
                            $total = (int)$sq['total'];
                            $qual = (int)$sq['qualified'];
                            $taxa = $total > 0 ? round(($qual / $total) * 100) : 0;
                            $barColor = $taxa >= 30 ? '#10b981' : ($taxa >= 15 ? '#f59e0b' : '#ef4444');
                            $totalWidth = round(($total / $sourceMax) * 100);
                            $qualifiedWidth = $total > 0 ? ($totalWidth * $taxa / 100) : 0;
                        ?>
                        <div class="channel-row">
                            <div class="channel-name" title="<?= htmlspecialchars($sq['source']) ?>"><?= htmlspecialchars($sq['source']) ?></div>
                            <div class="channel-track" title="<?= $qual ?> qualificados de <?= $total ?> leads">
                                <div class="channel-total" style="width:<?= $totalWidth ?>%;"></div>
                                <div class="channel-qualified" style="width:<?= $qualifiedWidth ?>%;background:<?= $barColor ?>;"></div>
                            </div>
                            <div class="channel-numbers"><strong style="color:<?= $barColor ?>;"><?= $taxa ?>%</strong><small><?= $qual ?>/<?= $total ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small text-center py-4">Sem dados de origem no período.</p>
                <?php endif; ?>
                <div class="mt-2 text-end"><a href="relatorios.php?tab=qualificacao" class="btn btn-sm btn-outline-secondary">Ver qualificação →</a></div>
            </div>
        </div>

        <!-- Top Consultores (30d) -->
        <div class="col-lg-6">
            <div class="dash-card h-100">
                <?php
                    $teamConversions = array_sum(array_map(static fn($item) => (int)($item['conversoes'] ?? 0), $topConsultores));
                ?>
                <div class="dash-card-title">
                    <i class="fa fa-trophy"></i> Desempenho dos Consultores
                    <span class="chart-summary"><?= (int)$teamConversions ?> conversões</span>
                </div>
                <?php if (!empty($topConsultores)): ?>
                    <div class="chart-legend"><span><i style="background:var(--blue-700);"></i>Taxa de conversão</span><span class="ms-auto">últimos 30 dias</span></div>
                    <?php foreach ($topConsultores as $i => $tc):
                        $taxa = (int)$tc['total'] > 0 ? round(((int)$tc['conversoes'] / (int)$tc['total']) * 100) : 0;
                        $initials = mb_strtoupper(mb_substr($tc['username'], 0, 2));
                        $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ($i+1).'.'));
                    ?>
                    <div class="consultant-row">
                        <div class="consultant-avatar"><?= $initials ?></div>
                        <div style="min-width:0;">
                            <div class="fw-semibold text-truncate" style="font-size:.85rem;"><?= htmlspecialchars($tc['username']) ?> <?= $medal ?></div>
                            <div style="font-size:.78rem;color:#64748b;"><?= (int)$tc['conversoes'] ?> conv. / <?= (int)$tc['total'] ?> leads</div>
                        </div>
                        <div class="consultant-track" title="Taxa de conversão: <?= $taxa ?>%">
                            <div class="consultant-fill" style="width:<?= min(100, $taxa) ?>%;"></div>
                        </div>
                        <div style="font-weight:700;font-size:.85rem;color:<?= $taxa >= 10 ? '#10b981' : '#f59e0b' ?>;"><?= $taxa ?>%</div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted small text-center py-4">Sem dados de consultores.</p>
                <?php endif; ?>
                <div class="mt-2 text-end"><a href="relatorios.php?tab=consultores" class="btn btn-sm btn-outline-secondary">Ver ranking →</a></div>
            </div>
        </div>

    </div>

</div><!-- /.container-fluid -->
</main>

<?php include 'includes/footer.php'; ?>
