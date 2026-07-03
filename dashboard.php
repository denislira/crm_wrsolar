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
.dash-kpi { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.07); padding:1.25rem 1.5rem; transition:box-shadow .2s; }
.dash-kpi:hover { box-shadow:0 4px 18px rgba(0,0,0,0.12); }
.dash-kpi .kpi-value { font-size:2rem; font-weight:700; line-height:1.1; }
.dash-kpi .kpi-label { font-size:0.8rem; color:#64748b; margin-top:2px; }
.dash-kpi .kpi-delta { font-size:0.78rem; margin-top:4px; }
.dash-kpi.accent-blue  { border-top:4px solid #3b82f6; }
.dash-kpi.accent-green { border-top:4px solid #10b981; }
.dash-kpi.accent-amber { border-top:4px solid #f59e0b; }
.dash-kpi.accent-red   { border-top:4px solid #ef4444; }
.dash-kpi.accent-violet{ border-top:4px solid #8b5cf6; }
.dash-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.07); padding:1.25rem 1.5rem; }
.dash-card-title { font-size:1rem; font-weight:600; color:#1f2937; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.dash-card-title i { color:#3b82f6; }
.funnel-mini { display:flex; flex-direction:column; gap:6px; }
.funnel-mini-row { display:flex; align-items:center; gap:10px; }
.funnel-mini-label { min-width:120px; font-size:.82rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.funnel-mini-bar { flex:1; height:20px; background:#f1f5f9; border-radius:5px; overflow:hidden; }
.funnel-mini-fill { height:100%; border-radius:5px; display:flex; align-items:center; padding-left:6px; font-size:.72rem; font-weight:700; color:#fff; transition:width .5s; }
.funnel-mini-count { min-width:36px; text-align:right; font-weight:700; font-size:.82rem; color:#374151; }
.alert-sla-item { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:.85rem; }
.alert-sla-item:last-child { border-bottom:none; }
.badge-src { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.73rem; font-weight:600; background:#eff6ff; color:#1d4ed8; }
.trend-up   { color:#10b981; } .trend-down { color:#ef4444; } .trend-flat { color:#94a3b8; }
.consultant-row { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f8fafc; }
.consultant-row:last-child { border-bottom:none; }
.consultant-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.8rem; flex-shrink:0; }

body.theme-dark .dash-kpi,
body.theme-dark .dash-card {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}
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
body.theme-dark .alert-sla-item {
    border-bottom-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .consultant-row {
    border-bottom-color: rgba(255,255,255,0.08) !important;
}
</style>

<main class="flex-grow-1 p-4 main-content-scroll">
<div class="container-fluid" id="dashboard">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 mb-1 fw-bold text-dark">Bem-vindo, <?= htmlspecialchars($_SESSION['username'] ?? 'Usuário') ?>!</h1>
            <p class="text-muted mb-0 small">Visão geral em tempo real — <?= (new DateTime())->format('d/m/Y H:i') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="relatorios.php" class="btn btn-sm btn-outline-primary"><i class="fa fa-chart-bar me-1"></i>Relatórios</a>
        </div>
    </div>

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
                <div class="kpi-label">Leads Hoje</div>
                <div class="kpi-value text-primary"><?= (int)$newLeadsToday ?></div>
                <?php $deltaDay = $newLeadsToday - $newLeadsYesterday; ?>
                <div class="kpi-delta <?= $deltaDay > 0 ? 'trend-up' : ($deltaDay < 0 ? 'trend-down' : 'trend-flat') ?>">
                    <?= $deltaDay > 0 ? '▲' : ($deltaDay < 0 ? '▼' : '—') ?> <?= abs($deltaDay) ?> vs ontem
                </div>
            </div>
        </div>
        <!-- Leads 30d -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi accent-cyan">
                <div class="kpi-label">Novos (30d)</div>
                <div class="kpi-value" style="color:#06b6d4;"><?= (int)$newLeads30 ?></div>
                <div class="kpi-delta text-muted">Total geral: <?= (int)$totalLeadsAll ?></div>
            </div>
        </div>
        <!-- Taxa SQL -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= ($sqlRate !== null && $sqlRate < 30) ? 'accent-red' : 'accent-green' ?>">
                <div class="kpi-label">Taxa SQL (30d)</div>
                <div class="kpi-value <?= ($sqlRate !== null && $sqlRate < 30) ? 'text-danger' : 'text-success' ?>"><?= $sqlRate !== null ? $sqlRate . '%' : '—' ?></div>
                <div class="kpi-delta text-muted"><?= (int)$totalSql ?> qualificados</div>
            </div>
        </div>
        <!-- Speed-to-Lead -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? 'accent-red' : 'accent-amber' ?>">
                <div class="kpi-label">Speed-to-Lead</div>
                <div class="kpi-value <?= ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? 'text-danger' : 'text-warning' ?>"><?= $speedToLeadAvg !== null ? $speedToLeadAvg . 'h' : '—' ?></div>
                <div class="kpi-delta <?= ($slaNoContact > 0) ? 'trend-down' : 'trend-flat' ?>"><?= (int)$slaNoContact ?> sem contato >24h</div>
            </div>
        </div>
        <!-- Leads parados -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi <?= $staleLeads7 > 0 ? 'accent-violet' : 'accent-green' ?>">
                <div class="kpi-label">Parados >7 dias</div>
                <div class="kpi-value" style="color:<?= $staleLeads7 > 0 ? '#8b5cf6' : '#10b981' ?>;"><?= (int)$staleLeads7 ?></div>
                <div class="kpi-delta text-muted">Risco de esfriamento</div>
            </div>
        </div>
        <!-- Taxa de conversão -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="dash-kpi accent-green">
                <div class="kpi-label">Taxa de Conversão</div>
                <div class="kpi-value text-success"><?= $conversionRate ?>%</div>
                <div class="kpi-delta text-muted"><?= (int)$projetosFinalizados ?> proj. fechados</div>
            </div>
        </div>
    </div>

    <!-- Row 2: Funil + Alertas SLA -->
    <div class="row g-3 mb-4">
        <!-- Funil de etapas -->
        <div class="col-lg-6">
            <div class="dash-card h-100">
                <div class="dash-card-title"><i class="fa fa-filter"></i> Funil por Etapa</div>
                <?php if (!empty($funnelStages)): ?>
                    <div class="funnel-mini">
                    <?php
                        $funnelMax = max(array_column($funnelStages, 'count') ?: [1]);
                        $funnelMax = max($funnelMax, 1);
                        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6'];
                        foreach ($funnelStages as $i => $fs):
                            $w = max(8, round(($fs['count'] / $funnelMax) * 100));
                            $color = ($fs['color'] && $fs['color'] !== '') ? $fs['color'] : $colors[$i % count($colors)];
                    ?>
                    <div class="funnel-mini-row">
                        <div class="funnel-mini-label" title="<?= htmlspecialchars($fs['name']) ?>"><?= htmlspecialchars($fs['name']) ?></div>
                        <div class="funnel-mini-bar">
                            <div class="funnel-mini-fill" style="width:<?= $w ?>%; background:<?= htmlspecialchars($color) ?>;"><?= $w > 15 ? $fs['count'] : '' ?></div>
                        </div>
                        <div class="funnel-mini-count"><?= (int)$fs['count'] ?></div>
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
                    ?>
                    <div class="funnel-mini-row">
                        <div class="funnel-mini-label"><?= htmlspecialchars($sd['status']) ?></div>
                        <div class="funnel-mini-bar">
                            <div class="funnel-mini-fill" style="width:<?= $w ?>%; background:<?= $colors[$i % count($colors)] ?>;"><?= $w > 15 ? $sd['count'] : '' ?></div>
                        </div>
                        <div class="funnel-mini-count"><?= (int)$sd['count'] ?></div>
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
            <div class="dash-card">
                <div class="dash-card-title"><i class="fa fa-bullseye"></i> Qualificação por Canal (30d)</div>
                <?php if (!empty($sourceQualData)): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Canal</th><th>Total</th><th>Qualif.</th><th>Taxa</th></tr></thead>
                        <tbody>
                        <?php foreach ($sourceQualData as $sq):
                            $total = (int)$sq['total'];
                            $qual = (int)$sq['qualified'];
                            $taxa = $total > 0 ? round(($qual / $total) * 100) : 0;
                            $barColor = $taxa >= 30 ? '#10b981' : ($taxa >= 15 ? '#f59e0b' : '#ef4444');
                        ?>
                        <tr>
                            <td class="fw-semibold" style="max-width:110px;" title="<?= htmlspecialchars($sq['source']) ?>"><?= htmlspecialchars($sq['source']) ?></td>
                            <td><?= $total ?></td>
                            <td><?= $qual ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;min-width:40px;"><div style="width:<?= $taxa ?>%;height:100%;background:<?= $barColor ?>;border-radius:4px;"></div></div>
                                    <span style="font-size:.78rem;font-weight:700;color:<?= $barColor ?>;"><?= $taxa ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted small text-center py-4">Sem dados de origem no período.</p>
                <?php endif; ?>
                <div class="mt-2 text-end"><a href="relatorios.php?tab=qualificacao" class="btn btn-sm btn-outline-secondary">Ver qualificação →</a></div>
            </div>
        </div>

        <!-- Top Consultores (30d) -->
        <div class="col-lg-6">
            <div class="dash-card">
                <div class="dash-card-title"><i class="fa fa-trophy"></i> Top Consultores (30d)</div>
                <?php if (!empty($topConsultores)): ?>
                    <?php foreach ($topConsultores as $i => $tc):
                        $taxa = (int)$tc['total'] > 0 ? round(((int)$tc['conversoes'] / (int)$tc['total']) * 100) : 0;
                        $initials = mb_strtoupper(mb_substr($tc['username'], 0, 2));
                        $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ($i+1).'.'));
                    ?>
                    <div class="consultant-row">
                        <div class="consultant-avatar"><?= $initials ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="fw-semibold text-truncate" style="font-size:.85rem;"><?= htmlspecialchars($tc['username']) ?> <?= $medal ?></div>
                            <div style="font-size:.78rem;color:#64748b;"><?= (int)$tc['conversoes'] ?> conv. / <?= (int)$tc['total'] ?> leads</div>
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
