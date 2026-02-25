<?php
// relatorios.php - Extended reports with multiple charts and a funnel
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit(); }
require_once 'includes/config.php';
require_once 'includes/permissions.php';

checkAccessOrRedirect('relatorios');

$pageTitle = 'Relatórios';
include 'includes/header.php';

// Defensive server-side data collection
$userId = $_SESSION['user_id'];

// Active tab (keep user on the chosen report after submit)
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'overview';
$allowedTabs = ['overview','funnel','temporal','consultores','sources','daily'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'overview';

// Daily report (leads created on a specific day)
$dailyDate = isset($_GET['daily_date']) ? (string)$_GET['daily_date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) $dailyDate = date('Y-m-d');
$dailyCount = 0;
$dailyLeads = [];
$dailyBySource = [];
$dailyByStatus = [];
$dailyByStage = [];
$dailyByHour = array_fill(0, 24, 0);
// Filters: period and sources (multi-select)
$filterPeriod = isset($_GET['period']) ? (int)$_GET['period'] : 365;
$filterStartDate = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$filterEndDate = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';
$filterSources = isset($_GET['sources']) ? (array)$_GET['sources'] : [];

// Prepare a default filtered range (used for filtered status counts)
try {
    if ($filterStartDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterStartDate)) {
        $fStart = new DateTime($filterStartDate . ' 00:00:00');
    } else {
        $fStart = new DateTime(); $fStart->modify('-' . max(1, $filterPeriod) . ' days');
    }
    if ($filterEndDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterEndDate)) {
        $fEnd = new DateTime($filterEndDate . ' 23:59:59');
    } else {
        $fEnd = new DateTime();
    }
} catch (Exception $e) { $fEnd = new DateTime(); $fStart = (clone $fEnd)->modify('-365 days'); }

// Variables to be filled for filtered view
$filteredStatusCounts = [];
$last24Leads = [];
$last24Total = 0;
$last24Proposta = 0;
$last24Atendimento = 0;
try {
    // best-effort schema detection
    $leadColsStmtDaily = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadColsDaily = $leadColsStmtDaily->fetchAll(PDO::FETCH_COLUMN);
    $hasDeleted = in_array('deleted', $leadColsDaily, true);
    $hasCreatedAt = in_array('created_at', $leadColsDaily, true);
    $dateCol = $hasCreatedAt ? 'created_at' : (in_array('data_inicio', $leadColsDaily, true) ? 'data_inicio' : 'created_at');

    $start = new DateTime($dailyDate . ' 00:00:00');
    $end = (clone $start)->modify('+1 day');

    $where = "{$dateCol} >= ? AND {$dateCol} < ?";
    if ($hasDeleted) $where .= " AND deleted = 0";

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$where}");
    $cntStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    $dailyCount = (int)$cntStmt->fetchColumn();

    $listCols = ['id'];
    foreach (['name','source','status','created_at'] as $c) if (in_array($c, $leadColsDaily, true)) $listCols[] = $c;
    $selectCols = implode(', ', array_unique($listCols));
    $listStmt = $pdo->prepare("SELECT {$selectCols} FROM leads WHERE {$where} ORDER BY {$dateCol} DESC LIMIT 500");
    $listStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    $dailyLeads = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregations (advanced): by source / status / stage / hour
    if (in_array('source', $leadColsDaily, true)) {
        $srcStmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS label, COUNT(*) AS cnt
             FROM leads WHERE {$where}
             GROUP BY label ORDER BY cnt DESC LIMIT 10"
        );
        $srcStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $dailyBySource = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (in_array('status', $leadColsDaily, true)) {
        $stStmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(status,''),'Sem status') AS label, COUNT(*) AS cnt
             FROM leads WHERE {$where}
             GROUP BY label ORDER BY cnt DESC LIMIT 12"
        );
        $stStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $dailyByStatus = $stStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // by hour (0-23)
    try {
        $hrStmt = $pdo->prepare(
            "SELECT HOUR({$dateCol}) AS h, COUNT(*) AS cnt
             FROM leads WHERE {$where}
             GROUP BY h ORDER BY h ASC"
        );
        $hrStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $rows = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $h = isset($r['h']) ? (int)$r['h'] : null;
            if ($h !== null && $h >= 0 && $h <= 23) $dailyByHour[$h] = (int)($r['cnt'] ?? 0);
        }
    } catch (Exception $e) { /* ignore */ }

    // by stage (if stage_id exists)
    if (in_array('stage_id', $leadColsDaily, true)) {
        $whereL = "l.{$dateCol} >= ? AND l.{$dateCol} < ?";
        if ($hasDeleted) $whereL .= " AND l.deleted = 0";

        $stageNameCol = 'name';
        try {
            $fsColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
            $fsCols = $fsColsStmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('stage_name', $fsCols, true)) $stageNameCol = 'stage_name';
            else if (!in_array('name', $fsCols, true) && in_array('stage', $fsCols, true)) $stageNameCol = 'stage';
        } catch (Exception $e) { /* ignore */ }

        $sgStmt = $pdo->prepare(
            "SELECT l.stage_id AS stage_id,
                COALESCE(NULLIF(fs.{$stageNameCol},''),'Sem Status') AS label,
                COUNT(*) AS cnt
             FROM leads l
             LEFT JOIN funil_stages fs ON fs.id = l.stage_id
             WHERE {$whereL}
             GROUP BY l.stage_id, label
             ORDER BY cnt DESC LIMIT 20"
        );
        $sgStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $dailyByStage = $sgStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { $dailyCount = 0; $dailyLeads = []; }

$leadsTotal = 0;
$stages = [];
$stageCounts = [];
$monthsRows = [];
$monthsClosedRows = [];
$timeline = [];
$timelineUsers = [];
$timelineTypes = [];
$avgDaysToClose = null;
$avgTicket = null;
$sources = [];

try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM leads');
        $leadsTotal = (int)$stmt->fetchColumn();
} catch (Exception $e) { $leadsTotal = 0; }

// funil_stages (name, color, position) - defensive column detection
try {
        $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $nameCol = in_array('name', $cols) ? 'name' : (in_array('stage_name', $cols) ? 'stage_name' : 'name');
        $positionCol = in_array('position', $cols) ? 'position' : (in_array('stage_order', $cols) ? 'stage_order' : 'id');
        $colorCol = in_array('color', $cols) ? 'color' : (in_array('stage_color', $cols) ? 'stage_color' : null);
        $selectCols = "id, {$nameCol} AS name";
        if ($colorCol) $selectCols .= ", {$colorCol} AS color";
        $q = $pdo->query("SELECT {$selectCols} FROM funil_stages ORDER BY COALESCE({$positionCol}, id) ASC");
        $stages = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE (stage_id = ? OR status = ?)');
                $c->execute([$s['id'], $s['name']]);
                $stageCounts[] = (int)$c->fetchColumn();
        }
} catch (Exception $e) { $stages = []; $stageCounts = []; }

// Last 12 months created
try {
        $m = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
        $monthsRows = $m->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $monthsRows = []; }

// Last 12 months closed (if closed_at exists)
try {
        $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
        $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasClosedAt = in_array('closed_at', $leadCols);
        $valueCol = null;
        foreach (['value','amount','budget','estimated_value'] as $vc) if (in_array($vc, $leadCols)) { $valueCol = $vc; break; }
        $sourceCol = null;
        foreach (['source','origem','lead_source'] as $sc) if (in_array($sc, $leadCols)) { $sourceCol = $sc; break; }

        if ($hasClosedAt) {
                $mc = $pdo->query("SELECT DATE_FORMAT(closed_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE closed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        }

        // avg days to close
        if ($hasClosedAt) {
                $ad = $pdo->query("SELECT AVG(DATEDIFF(closed_at, created_at)) as avgd FROM leads WHERE closed_at IS NOT NULL");
                $avgDaysToClose = round((float)$ad->fetchColumn(),2);
        }

        // avg ticket
        if ($valueCol) {
                $at = $pdo->query("SELECT AVG(CASE WHEN {$valueCol} IS NULL OR {$valueCol} = '' THEN NULL ELSE {$valueCol} END) FROM leads");
                $avgTicket = $at->fetchColumn();
                if ($avgTicket !== null) $avgTicket = round((float)$avgTicket,2);
        }

        // sources
        if ($sourceCol) {
                $sstmt = $pdo->query("SELECT {$sourceCol} AS source, COUNT(*) AS cnt FROM leads GROUP BY {$sourceCol} ORDER BY cnt DESC LIMIT 10");
                $sources = $sstmt->fetchAll(PDO::FETCH_ASSOC);
        }

} catch (Exception $e) { /* ignore and continue */ }

// Timeline
try {
        $actColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'");
        $actCols = $actColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $select = ['a.message','a.created_at'];
        $joins = '';
        if (in_array('user_id', $actCols)) { $select[] = 'a.user_id'; $select[] = 'COALESCE(u.username, "(desconhecido)") AS username'; $joins .= ' LEFT JOIN users u ON u.id = a.user_id '; }
        if (in_array('event_type', $actCols)) { $select[] = 'a.event_type'; }
        $selectSql = implode(', ', $select);
        $timelineSql = "SELECT {$selectSql} FROM activity_log a {$joins} ORDER BY a.created_at DESC LIMIT 500";
        $tStmt = $pdo->query($timelineSql);
        $timeline = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        // derive users and types
        $usersMap = [];
        $typesMap = [];
        foreach ($timeline as $r) {
                if (isset($r['user_id']) && isset($r['username'])) $usersMap[$r['user_id']] = ['id'=>$r['user_id'],'username'=>$r['username']];
                if (isset($r['event_type']) && $r['event_type'] !== '') $typesMap[$r['event_type']] = true;
        }
        $timelineUsers = array_values($usersMap);
        $timelineTypes = array_keys($typesMap);
} catch (Exception $e) { $timeline = []; $timelineUsers = []; $timelineTypes = []; }

// Final stage and conversion
$finalStageCount = 0; $conversionRate = 0.0;
if (count($stages) > 0) {
        $final = end($stages);
        try {
                $fstmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE (stage_id = ? OR status = ?)');
                $fstmt->execute([$final['id'], $final['name']]);
                $finalStageCount = (int)$fstmt->fetchColumn();
                $conversionRate = $leadsTotal > 0 ? round(($finalStageCount / $leadsTotal) * 100, 2) : 0;
        } catch (Exception $e) { }
}

// Consultores/Usuários ranking
$usersRanking = [];
try {
        // Get all users with their lead counts, conversions, and total value
        $usersQuery = "
                SELECT 
                        u.id,
                        u.username,
                        u.email,
                        COUNT(DISTINCT l.id) as total_leads,
                        SUM(CASE WHEN l.stage_id = ? OR l.status LIKE '%fechado%' OR l.status LIKE '%ganho%' THEN 1 ELSE 0 END) as conversoes,
                        SUM(CASE WHEN l.orcamento_value IS NOT NULL THEN l.orcamento_value ELSE 0 END) as valor_total
                FROM users u
                LEFT JOIN leads l ON l.user_id = u.id
                GROUP BY u.id, u.username, u.email
                HAVING total_leads > 0
                ORDER BY conversoes DESC, total_leads DESC
                LIMIT 20
        ";
        $finalStageId = count($stages) > 0 ? end($stages)['id'] : 0;
        $usersStmt = $pdo->prepare($usersQuery);
        $usersStmt->execute([$finalStageId]);
        $usersRanking = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
        $usersRanking = []; 
}

// Tasks per user (if team_tasks table exists)
$usersTasks = [];
try {
        $tasksQuery = "
                SELECT 
                        u.id,
                        u.username,
                        COUNT(DISTINCT t.id) as total_tarefas,
                        SUM(CASE WHEN t.status = 'concluida' OR t.status = 'completed' THEN 1 ELSE 0 END) as tarefas_concluidas
                FROM users u
                LEFT JOIN team_tasks t ON t.responsavel_id = u.id
                GROUP BY u.id, u.username
                HAVING total_tarefas > 0
                ORDER BY tarefas_concluidas DESC, total_tarefas DESC
                LIMIT 10
        ";
        $tasksStmt = $pdo->prepare($tasksQuery);
        $tasksStmt->execute();
        $usersTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
        $usersTasks = []; 
}

// --- Filtered status counts and last-24h summary ---
try {
    $leadColsStmt2 = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadCols2 = $leadColsStmt2->fetchAll(PDO::FETCH_COLUMN);
    $dateCol2 = in_array('created_at', $leadCols2, true) ? 'created_at' : (in_array('data_inicio', $leadCols2, true) ? 'data_inicio' : 'created_at');
    $hasDeleted2 = in_array('deleted', $leadCols2, true);

    $where = "{$dateCol2} >= ? AND {$dateCol2} <= ?";
    if ($hasDeleted2) $where .= " AND deleted = 0";

    $params = [$fStart->format('Y-m-d H:i:s'), $fEnd->format('Y-m-d H:i:s')];
    if (!empty($filterSources)) {
        $placeholders = implode(',', array_fill(0, count($filterSources), '?'));
        $where .= " AND COALESCE(NULLIF(source,''),'') IN ($placeholders)";
        foreach ($filterSources as $s) $params[] = (string)$s;
    }

    $fsStmt = $pdo->prepare("SELECT COALESCE(NULLIF(status,''),'Sem status') AS label, COUNT(*) AS cnt FROM leads WHERE {$where} GROUP BY label ORDER BY cnt DESC");
    $fsStmt->execute($params);
    $filteredStatusCounts = $fsStmt->fetchAll(PDO::FETCH_ASSOC);

    // last 24 hours
    $lStart = (new DateTime())->modify('-24 hours');
    $lEnd = new DateTime();
    $lWhere = "{$dateCol2} >= ? AND {$dateCol2} <= ?";
    if ($hasDeleted2) $lWhere .= " AND deleted = 0";
    $lStmt = $pdo->prepare("SELECT id, name, COALESCE(NULLIF(source,''),'Sem origem') AS source, COALESCE(NULLIF(status,''),'Sem status') AS status, {$dateCol2} AS created_at FROM leads WHERE {$lWhere} ORDER BY {$dateCol2} DESC LIMIT 200");
    $lStmt->execute([$lStart->format('Y-m-d H:i:s'), $lEnd->format('Y-m-d H:i:s')]);
    $last24Leads = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    $last24Total = count($last24Leads);
    foreach ($last24Leads as $r) {
        $st = mb_strtolower((string)$r['status']);
        if (strpos($st, 'proposta') !== false) $last24Proposta++;
        if (strpos($st, 'atendimento') !== false || strpos($st, 'atendim') !== false) $last24Atendimento++;
    }
} catch (Exception $e) {
    // ignore and continue
}

?>

<style>
.report-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; transition: all 0.3s ease; }
.report-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
.report-card-title { font-size: 1.1rem; font-weight: 600; color: #1f2937; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
.report-card-title i { color: #3b82f6; }
.kpi-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-radius: 12px; padding: 1.5rem; height: 100%; position: relative; overflow: hidden; }
.kpi-card::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); }
.kpi-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.kpi-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.kpi-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.kpi-value { font-size: 2.5rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.kpi-label { font-size: 0.875rem; opacity: 0.9; position: relative; z-index: 1; }
.kpi-icon { position: absolute; bottom: 1rem; right: 1rem; font-size: 3rem; opacity: 0.2; z-index: 0; }
.chart-container { position: relative; height: 280px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead { background: #f8fafc; }
.data-table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.875rem; border-bottom: 2px solid #e2e8f0; }
.data-table td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
.data-table tr:hover { background: #f8fafc; }
.badge-stage { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
.pyramid-svg { max-width: 100%; height: auto; display: block; margin: 2rem auto; }
.filter-bar { background: #f8fafc; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
.filter-bar select, .filter-bar input { border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.875rem; }
.export-btn { background: #3b82f6; color: #fff; border: none; padding: 0.5rem 1.5rem; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s; }
.export-btn:hover { background: #2563eb; transform: translateY(-1px); }
.stat-change { font-size: 0.75rem; margin-top: 0.25rem; }
.stat-change.positive { color: #10b981; }
.stat-change.negative { color: #ef4444; }
.funnel-stage { background: #fff; border-left: 4px solid; padding: 1rem 1.5rem; margin-bottom: 0.5rem; border-radius: 0 8px 8px 0; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s; }
.funnel-stage:hover { transform: translateX(4px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
.funnel-value { font-size: 1.5rem; font-weight: 700; }
.funnel-percent { font-size: 0.875rem; color: #64748b; }
.funnel-container { padding: 1rem 0; }
.funnel-stage-wrapper { animation: slideInLeft 0.5s ease-out forwards; opacity: 0; }
.funnel-stage-wrapper:nth-child(1) { animation-delay: 0.1s; }
.funnel-stage-wrapper:nth-child(2) { animation-delay: 0.2s; }
.funnel-stage-wrapper:nth-child(3) { animation-delay: 0.3s; }
.funnel-stage-wrapper:nth-child(4) { animation-delay: 0.4s; }
.funnel-stage-wrapper:nth-child(5) { animation-delay: 0.5s; }
.funnel-stage-wrapper:nth-child(6) { animation-delay: 0.6s; }
@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-20px); }
    to { opacity: 1; transform: translateX(0); }
}
.user-rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; font-weight: 700; font-size: 0.875rem; }
.user-rank-badge.gold { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.user-rank-badge.silver { background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%); }
.user-rank-badge.bronze { background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%); }
#reportTabs.nav-pills .nav-link { border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; color: #64748b; transition: all 0.3s; margin-right: 0.5rem; background: #fff; border: 2px solid #e2e8f0; }
#reportTabs.nav-pills .nav-link:hover { background: #f8fafc; color: #3b82f6; border-color: #3b82f6; transform: translateY(-2px); }
#reportTabs.nav-pills .nav-link.active { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; border-color: #3b82f6; box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
#reportTabs.nav-pills .nav-link i { margin-right: 0.5rem; }
#reportTabsContent .tab-content { animation: fadeIn 0.4s ease-in; }
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 main-content-scroll p-4">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">📊 Relatórios e Análises</h1>
                    <p class="text-muted small mb-0">Visão completa do desempenho comercial</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="export-btn" onclick="exportReport('pdf')"><i class="fa fa-file-pdf"></i> Exportar PDF</button>
                    <button class="export-btn" onclick="exportReport('excel')"><i class="fa fa-file-excel"></i> Exportar Excel</button>
                </div>
            </div>

            <!-- Filters -->
            <form class="filter-bar" method="get" action="relatorios.php">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>" />
                <label class="mb-0 fw-semibold">Período:</label>
                <select id="filterPeriod" name="period">
                    <option value="30" <?php echo $filterPeriod===30?'selected':''; ?>>Últimos 30 dias</option>
                    <option value="90" <?php echo $filterPeriod===90?'selected':''; ?>>Últimos 90 dias</option>
                    <option value="180" <?php echo $filterPeriod===180?'selected':''; ?>>Últimos 6 meses</option>
                    <option value="365" <?php echo $filterPeriod===365?'selected':''; ?>>Último ano</option>
                </select>
                <input type="date" id="filterStartDate" name="start_date" value="<?php echo htmlspecialchars($filterStartDate, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="date" id="filterEndDate" name="end_date" value="<?php echo htmlspecialchars($filterEndDate, ENT_QUOTES, 'UTF-8'); ?>" />
                <label class="mb-0 fw-semibold">Fontes:</label>
                <select name="sources[]" multiple size="1" style="min-width:160px;">
                    <?php foreach ($sources as $s): ?>
                        <option value="<?php echo htmlspecialchars((string)($s['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array(($s['source'] ?? ''), $filterSources, true)?'selected':''; ?>><?php echo htmlspecialchars((string)($s['source'] ?? 'Sem origem'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-left:auto; display:flex; gap:0.5rem;">
                    <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
                    <a class="btn btn-sm btn-outline-secondary" href="relatorios.php">Limpar</a>
                </div>
            </form>

            <!-- Last 24h summary -->
            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <div class="report-card">
                        <div class="report-card-title"><i class="fa fa-clock"></i> Últimas 24 horas</div>
                        <div class="d-flex align-items-center gap-3">
                            <div>
                                <div class="small text-muted">Total</div>
                                <div class="h4 mb-0"><?php echo (int)$last24Total; ?></div>
                            </div>
                            <div>
                                <div class="small text-muted">Proposta enviada</div>
                                <div class="h5 mb-0 text-success"><?php echo (int)$last24Proposta; ?></div>
                            </div>
                            <div>
                                <div class="small text-muted">Em atendimento</div>
                                <div class="h5 mb-0 text-primary"><?php echo (int)$last24Atendimento; ?></div>
                            </div>
                        </div>
                        <div style="margin-top:12px; height:180px;" class="chart-container">
                            <canvas id="chartFilteredByStatus"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="report-card">
                        <div class="report-card-title"><i class="fa fa-list"></i> Leads (últimas 24h)</div>
                        <div class="table-responsive" style="max-height:220px; overflow:auto;">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">ID</th>
                                        <th>Nome</th>
                                        <th style="width:160px;">Fonte</th>
                                        <th style="width:160px;">Criado em</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($last24Leads)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum lead nas últimas 24 horas.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($last24Leads as $l): ?>
                                            <tr>
                                                <td><?php echo (int)($l['id'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars((string)($l['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($l['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php
                                                        $raw = $l['created_at'] ?? null;
                                                        $out = '';
                                                        if ($raw) {
                                                            try { $dt = new DateTime((string)$raw); $out = $dt->format('d/m/Y H:i'); } catch (Exception $e) { $out = (string)$raw; }
                                                        }
                                                        echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-pills mb-4" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='overview'?'active':''; ?>" id="overview-tab" data-bs-toggle="pill" data-bs-target="#overview" type="button" role="tab">
                        <i class="fa fa-home"></i> Visão Geral
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='funnel'?'active':''; ?>" id="funnel-tab" data-bs-toggle="pill" data-bs-target="#funnel" type="button" role="tab">
                        <i class="fa fa-filter"></i> Funil de Vendas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='temporal'?'active':''; ?>" id="temporal-tab" data-bs-toggle="pill" data-bs-target="#temporal" type="button" role="tab">
                        <i class="fa fa-chart-line"></i> Análise Temporal
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='consultores'?'active':''; ?>" id="consultores-tab" data-bs-toggle="pill" data-bs-target="#consultores" type="button" role="tab">
                        <i class="fa fa-users"></i> Consultores
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='sources'?'active':''; ?>" id="sources-tab" data-bs-toggle="pill" data-bs-target="#sources" type="button" role="tab">
                        <i class="fa fa-bullseye"></i> Fontes e Origem
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='daily'?'active':''; ?>" id="daily-tab" data-bs-toggle="pill" data-bs-target="#daily" type="button" role="tab">
                        <i class="fa fa-calendar-day"></i> Por Dia
                    </button>
                </li>
            </ul>

            <!-- Tabs Content -->
            <div class="tab-content" id="reportTabsContent">
                
                <!-- Overview Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='overview'?'show active':''; ?>" id="overview" role="tabpanel">
                    <!-- KPIs -->
                    <div id="reportKpis" class="row g-3 mb-4"></div>

                    <!-- Main Charts Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-8">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-chart-line"></i> Evolução de Leads (12 meses)</div>
                                <div class="chart-container">
                                    <canvas id="chartLeadsMonthly"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-chart-pie"></i> Taxa de Conversão</div>
                                <div class="chart-container" style="height: 240px;">
                                    <canvas id="chartConversionDonut"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-tasks"></i> Leads por Estágio</div>
                                <div class="chart-container">
                                    <canvas id="chartLeadsByStage"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-trophy"></i> Estágios Detalhados</div>
                                <div id="tableStagesDetail"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Funnel Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='funnel'?'show active':''; ?>" id="funnel" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-filter"></i> Funil de Vendas Completo</div>
                                <div id="chartFunnel"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Temporal Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='temporal'?'show active':''; ?>" id="temporal" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-lg-4">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-chart-bar"></i> Criados vs Fechados</div>
                                <div class="chart-container" style="height: 240px;">
                                    <canvas id="chartCreatedClosed"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-clock"></i> Performance Temporal</div>
                                <div class="chart-container" style="height: 240px;">
                                    <canvas id="chartTimeDistribution"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-chart-area"></i> Tendências</div>
                                <div class="chart-container" style="height: 240px;">
                                    <canvas id="chartTrends"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Consultores Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='consultores'?'show active':''; ?>" id="consultores" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title">
                                    <i class="fa fa-users"></i> 🏆 Ranking de Consultores
                                </div>
                                <div class="row g-3">
                                    <div class="col-lg-8">
                                        <div class="chart-container" style="height: 350px;">
                                            <canvas id="chartTopSellers"></canvas>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="chart-container" style="height: 350px;">
                                            <canvas id="chartUserTasks"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-medal"></i> Top Vendedores</div>
                                <div id="tableTopSellers"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-chart-line"></i> Performance por Consultor</div>
                                <div id="tableUsersTasks"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sources Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='sources'?'show active':''; ?>" id="sources" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-bullseye"></i> Origem dos Leads</div>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartSourcesPie"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-table"></i> Top 10 Fontes de Leads</div>
                                <div id="tableTopSources"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Tab -->
                <div class="tab-pane fade <?php echo $activeTab==='daily'?'show active':''; ?>" id="daily" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-calendar-day"></i> Leads criados no dia</div>
                                <form class="d-flex gap-2 align-items-end flex-wrap" method="get" action="relatorios.php">
                                    <input type="hidden" name="tab" value="daily" />
                                    <div>
                                        <label class="small text-muted">Data</label>
                                        <input type="date" name="daily_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dailyDate, ENT_QUOTES, 'UTF-8'); ?>" />
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-sm btn-primary">Ver</button>
                                    </div>
                                    <div class="ms-auto">
                                        <div class="small text-muted">Total no dia</div>
                                        <div class="h4 mb-0"><?php echo (int)$dailyCount; ?></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-list"></i> Lista de leads do dia</div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width:90px;">ID</th>
                                                <th>Nome</th>
                                                <th>Fonte</th>
                                                <th>Status</th>
                                                <th style="width:170px;">Criado em</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($dailyLeads)): ?>
                                                <tr><td colspan="5" class="text-center text-muted py-4">Nenhum lead criado neste dia.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($dailyLeads as $l): ?>
                                                    <tr>
                                                        <td><?php echo (int)($l['id'] ?? 0); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($l['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($l['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($l['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td>
                                                            <?php
                                                                $raw = $l['created_at'] ?? null;
                                                                $out = '';
                                                                if ($raw) {
                                                                    try { $dt = new DateTime((string)$raw); $out = $dt->format('d/m/Y H:i'); } catch (Exception $e) { $out = (string)$raw; }
                                                                }
                                                                echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-bullseye"></i> Leads do dia por origem</div>
                                <div class="chart-container" style="height: 320px;">
                                    <canvas id="chartDailyBySource"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-tags"></i> Leads do dia por status</div>
                                <div class="chart-container" style="height: 320px;">
                                    <canvas id="chartDailyByStatus"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-filter"></i> Leads do dia por etapa</div>
                                <div class="chart-container" style="height: 320px;">
                                    <canvas id="chartDailyByStage"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-clock"></i> Leads do dia por hora</div>
                                <div class="chart-container" style="height: 320px;">
                                    <canvas id="chartDailyByHour"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- html2pdf (bundles html2canvas + jsPDF) for accurate PDF export of the on-screen report -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
<script>
// Server data
const REPORT_STAGES = <?php echo json_encode($stages, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_STAGE_COUNTS = <?php echo json_encode($stageCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_MONTHS = <?php echo json_encode($monthsRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_MONTHS_CLOSED = <?php echo json_encode($monthsClosedRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LEADS_TOTAL = <?php echo json_encode($leadsTotal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_FINAL_STAGE_COUNT = <?php echo json_encode($finalStageCount, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_CONVERSION_RATE = <?php echo json_encode($conversionRate, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_DAYS_TO_CLOSE = <?php echo json_encode($avgDaysToClose, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_TICKET = <?php echo json_encode($avgTicket, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_SOURCES = <?php echo json_encode($sources, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_USERS_RANKING = <?php echo json_encode($usersRanking, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_USERS_TASKS = <?php echo json_encode($usersTasks, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

// Daily report data
const REPORT_DAILY_DATE = <?php echo json_encode($dailyDate, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DAILY_COUNT = <?php echo json_encode($dailyCount, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DAILY_BY_SOURCE = <?php echo json_encode($dailyBySource, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DAILY_BY_STATUS = <?php echo json_encode($dailyByStatus, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DAILY_BY_STAGE = <?php echo json_encode($dailyByStage, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DAILY_BY_HOUR = <?php echo json_encode($dailyByHour, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

// Filtered status counts (server-side) and last-24h data
const REPORT_FILTERED_STATUS = <?php echo json_encode($filteredStatusCounts ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_FILTER_START = <?php echo json_encode(isset($fStart) ? $fStart->format('Y-m-d H:i:s') : null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_FILTER_END = <?php echo json_encode(isset($fEnd) ? $fEnd->format('Y-m-d H:i:s') : null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LAST24_LEADS = <?php echo json_encode($last24Leads ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LAST24_TOTAL = <?php echo json_encode($last24Total ?? 0, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LAST24_PROPOSTA = <?php echo json_encode($last24Proposta ?? 0, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LAST24_ATENDIMENTO = <?php echo json_encode($last24Atendimento ?? 0, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

let chartInstances = {};

function defaultPalette(i) { 
    const pal = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6']; 
    return pal[i%pal.length]; 
}

function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);
}

function formatNumber(value) {
    return new Intl.NumberFormat('pt-BR').format(value || 0);
}

function buildLast12Months() {
    const res = []; 
    const now = new Date(); 
    for (let i=11;i>=0;i--){ 
        const d=new Date(now.getFullYear(), now.getMonth()-i,1); 
        res.push(d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')); 
    } 
    return res;
}

function getMonthName(ym) {
    const months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    const parts = ym.split('-');
    return months[parseInt(parts[1]) - 1] + '/' + parts[0].substr(2);
}

function escapeHtml(s){ 
    if(!s) return ''; 
    return String(s).replace(/[&<>"']/g, function(t){ 
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[t]||t; 
    }); 
}

function destroyChart(id) {
    if (chartInstances[id]) {
        chartInstances[id].destroy();
        delete chartInstances[id];
    }
}

function renderDailyCharts(){
    // Source pie
    const srcEl = document.getElementById('chartDailyBySource');
    if (srcEl) {
        destroyChart('chartDailyBySource');
        const rows = Array.isArray(REPORT_DAILY_BY_SOURCE) ? REPORT_DAILY_BY_SOURCE : [];
        const labels = rows.map(r => r.label || 'Sem origem');
        const data = rows.map(r => Number(r.cnt) || 0);
        chartInstances['chartDailyBySource'] = new Chart(srcEl, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: labels.map((_,i)=>defaultPalette(i)), borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    // Status bar
    const stEl = document.getElementById('chartDailyByStatus');
    if (stEl) {
        destroyChart('chartDailyByStatus');
        const rows = Array.isArray(REPORT_DAILY_BY_STATUS) ? REPORT_DAILY_BY_STATUS : [];
        const labels = rows.map(r => r.label || 'Sem status');
        const data = rows.map(r => Number(r.cnt) || 0);
        chartInstances['chartDailyByStatus'] = new Chart(stEl, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Leads',
                    data,
                    backgroundColor: labels.map((_,i)=>defaultPalette(i)),
                    borderRadius: 6,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    // Stage bar
    const sgEl = document.getElementById('chartDailyByStage');
    if (sgEl) {
        destroyChart('chartDailyByStage');
        const rows = Array.isArray(REPORT_DAILY_BY_STAGE) ? REPORT_DAILY_BY_STAGE : [];
        const labels = rows.map(r => r.label || 'Sem Status');
        const data = rows.map(r => Number(r.cnt) || 0);
        chartInstances['chartDailyByStage'] = new Chart(sgEl, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Leads',
                    data,
                    backgroundColor: labels.map((_,i)=>defaultPalette(i)),
                    borderRadius: 6,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    // Hour line
    const hrEl = document.getElementById('chartDailyByHour');
    if (hrEl) {
        destroyChart('chartDailyByHour');
        const hours = Array.from({length:24}, (_,i)=>String(i).padStart(2,'0') + ':00');
        const data = Array.isArray(REPORT_DAILY_BY_HOUR) ? REPORT_DAILY_BY_HOUR.map(v=>Number(v)||0) : new Array(24).fill(0);
        chartInstances['chartDailyByHour'] = new Chart(hrEl, {
            type: 'line',
            data: {
                labels: hours,
                datasets: [{
                    label: 'Leads',
                    data,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.12)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
}

function renderFilteredStatusChart() {
    const el = document.getElementById('chartFilteredByStatus');
    if (!el) return;
    destroyChart('chartFilteredByStatus');
    const rows = Array.isArray(REPORT_FILTERED_STATUS) ? REPORT_FILTERED_STATUS : [];
    const labels = rows.map(r => r.label || 'Sem status');
    const data = rows.map(r => Number(r.cnt) || 0);
    chartInstances['chartFilteredByStatus'] = new Chart(el, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: labels.map((_,i)=>defaultPalette(i)), borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}

function renderKPIs() {
    const kpiWrap = document.getElementById('reportKpis');
    if(!kpiWrap) return;
    
    const avgTicketFormatted = REPORT_AVG_TICKET !== null ? formatCurrency(REPORT_AVG_TICKET) : '—';
    const avgDaysFormatted = REPORT_AVG_DAYS_TO_CLOSE !== null ? REPORT_AVG_DAYS_TO_CLOSE : '—';
    
    kpiWrap.innerHTML = `
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value">${formatNumber(REPORT_LEADS_TOTAL)}</div>
                <div class="kpi-label">Total de Leads</div>
                <i class="fa fa-users kpi-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card green">
                <div class="kpi-value">${formatNumber(REPORT_FINAL_STAGE_COUNT)}</div>
                <div class="kpi-label">Conversões (${REPORT_CONVERSION_RATE}%)</div>
                <i class="fa fa-check-circle kpi-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card orange">
                <div class="kpi-value">${avgDaysFormatted}</div>
                <div class="kpi-label">Dias p/ Fechar</div>
                <i class="fa fa-clock kpi-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card blue">
                <div class="kpi-value">${avgTicketFormatted}</div>
                <div class="kpi-label">Ticket Médio</div>
                <i class="fa fa-dollar-sign kpi-icon"></i>
            </div>
        </div>
    `;
}

function renderLeadsMonthlyChart() {
    const ctx = document.getElementById('chartLeadsMonthly');
    if (!ctx) return;
    
    destroyChart('chartLeadsMonthly');
    
    const monthsMap = {}; 
    REPORT_MONTHS.forEach(r=>monthsMap[r.ym]=Number(r.cnt));
    const monthsClosedMap = {}; 
    REPORT_MONTHS_CLOSED.forEach(r=>monthsClosedMap[r.ym]=Number(r.cnt));
    const last12 = buildLast12Months();
    const createdData = last12.map(m=>monthsMap[m]||0);
    const closedData = last12.map(m=>monthsClosedMap[m]||0);
    const labels = last12.map(getMonthName);
    
    chartInstances['chartLeadsMonthly'] = new Chart(ctx, { 
        type:'line', 
        data:{ 
            labels, 
            datasets:[
                { 
                    label:'Leads Criados', 
                    data:createdData, 
                    borderColor:'#3b82f6', 
                    backgroundColor:'rgba(59,130,246,0.1)', 
                    fill:true,
                    tension: 0.4,
                    borderWidth: 2
                }, 
                { 
                    label:'Leads Fechados', 
                    data:closedData, 
                    borderColor:'#10b981', 
                    backgroundColor:'rgba(16,185,129,0.1)', 
                    fill:true,
                    tension: 0.4,
                    borderWidth: 2
                }
            ]
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'top' },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                }
            }, 
            scales:{ 
                y:{ beginAtZero:true, ticks: { precision: 0 } }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        } 
    });
}

function renderLeadsByStageChart() {
    const ctx = document.getElementById('chartLeadsByStage');
    if (!ctx) return;
    
    destroyChart('chartLeadsByStage');
    
    const labels = REPORT_STAGES.map(s=>s.name||'Sem nome');
    const colors = REPORT_STAGES.map((s,i)=>(s.color && s.color!=='')?s.color:defaultPalette(i));
    const counts = REPORT_STAGE_COUNTS.map(c=>Number(c)||0);
    
    chartInstances['chartLeadsByStage'] = new Chart(ctx, { 
        type:'bar', 
        data:{ 
            labels, 
            datasets:[{ 
                label:'Quantidade de Leads', 
                data:counts, 
                backgroundColor:colors,
                borderRadius: 6,
                borderWidth: 0
            }]
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ display:false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' leads';
                        }
                    }
                }
            }, 
            scales:{ 
                y:{ beginAtZero:true, ticks: { precision: 0 } }
            } 
        } 
    });
}

function renderConversionDonutChart() {
    const ctx = document.getElementById('chartConversionDonut');
    if (!ctx) return;
    
    destroyChart('chartConversionDonut');
    
    chartInstances['chartConversionDonut'] = new Chart(ctx, { 
        type:'doughnut', 
        data:{ 
            labels:['Convertidos','Em Andamento'], 
            datasets:[{ 
                data:[REPORT_FINAL_STAGE_COUNT, Math.max(0, REPORT_LEADS_TOTAL - REPORT_FINAL_STAGE_COUNT)], 
                backgroundColor:['#10b981', '#e5e7eb'],
                borderWidth: 0
            }] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                        }
                    }
                }
            } 
        } 
    });
}

function renderSourcesPieChart() {
    const ctx = document.getElementById('chartSourcesPie');
    if (!ctx) return;
    
    destroyChart('chartSourcesPie');
    
    const srcLabels = REPORT_SOURCES.map(s=>s.source||'Sem origem');
    const srcData = REPORT_SOURCES.map(s=>Number(s.cnt)||0);
    
    chartInstances['chartSourcesPie'] = new Chart(ctx,{ 
        type:'pie', 
        data:{ 
            labels:srcLabels, 
            datasets:[{ 
                data:srcData, 
                backgroundColor: srcLabels.map((_,i)=>defaultPalette(i)),
                borderWidth: 0
            }] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'bottom' }
            } 
        } 
    });
}

function renderCreatedClosedChart() {
    const ctx = document.getElementById('chartCreatedClosed');
    if (!ctx) return;
    
    destroyChart('chartCreatedClosed');
    
    const monthsMap = {}; 
    REPORT_MONTHS.forEach(r=>monthsMap[r.ym]=Number(r.cnt));
    const monthsClosedMap = {}; 
    REPORT_MONTHS_CLOSED.forEach(r=>monthsClosedMap[r.ym]=Number(r.cnt));
    const last12 = buildLast12Months();
    const createdData = last12.map(m=>monthsMap[m]||0);
    const closedData = last12.map(m=>monthsClosedMap[m]||0);
    const labels = last12.map(getMonthName);
    
    chartInstances['chartCreatedClosed'] = new Chart(ctx, { 
        type:'bar', 
        data:{ 
            labels, 
            datasets:[
                { 
                    label:'Criados', 
                    data:createdData, 
                    backgroundColor:'rgba(59,130,246,0.7)',
                    borderRadius: 4
                }, 
                { 
                    label:'Fechados', 
                    data:closedData, 
                    backgroundColor:'rgba(16,185,129,0.7)',
                    borderRadius: 4
                }
            ] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'top' } 
            }, 
            scales:{ 
                y:{ beginAtZero:true, ticks: { precision: 0 } }
            } 
        } 
    });
}

function renderTimeDistributionChart() {
    const ctx = document.getElementById('chartTimeDistribution');
    if (!ctx) return;
    
    destroyChart('chartTimeDistribution');
    
    // Simulate weekly distribution
    const weeks = ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4'];
    const weekData = weeks.map(() => Math.floor(Math.random() * 50) + 10);
    
    chartInstances['chartTimeDistribution'] = new Chart(ctx, { 
        type:'line', 
        data:{ 
            labels: weeks, 
            datasets:[{ 
                label:'Atividade Semanal', 
                data:weekData, 
                borderColor:'#8b5cf6', 
                backgroundColor:'rgba(139,92,246,0.1)', 
                fill:true,
                tension: 0.4,
                borderWidth: 2
            }] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'top' } 
            }, 
            scales:{ 
                y:{ beginAtZero:true, ticks: { precision: 0 } }
            } 
        } 
    });
}

function renderTrendsChart() {
    const ctx = document.getElementById('chartTrends');
    if (!ctx) return;
    
    destroyChart('chartTrends');
    
    const last12 = buildLast12Months();
    const labels = last12.map(getMonthName);
    
    // Simulate trend data
    const trendData = last12.map((_, i) => 20 + (i * 5) + Math.random() * 10);
    
    chartInstances['chartTrends'] = new Chart(ctx, { 
        type:'line', 
        data:{ 
            labels, 
            datasets:[{ 
                label:'Tendência de Crescimento', 
                data:trendData, 
                borderColor:'#f59e0b', 
                backgroundColor:'rgba(245,158,11,0.1)', 
                fill:true,
                tension: 0.4,
                borderWidth: 3
            }] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'top' } 
            }, 
            scales:{ 
                y:{ beginAtZero:true }
            } 
        } 
    });
}

function renderFunnel() {
    const container = document.getElementById('chartFunnel');
    if(!container) return;
    
    const counts = REPORT_STAGE_COUNTS.map(c=>Number(c)||0);
    const pairs = REPORT_STAGES.map((s,i)=>({ 
        label:s.name||'Sem nome', 
        value:counts[i]||0, 
        color:(s.color&&s.color!=='')?s.color:defaultPalette(i),
        order: i
    }));
    
    // Sort by order to maintain funnel sequence
    pairs.sort((a,b)=>a.order-b.order);
    
    const totalLeads = pairs.reduce((sum, p) => sum + p.value, 0);
    const maxValue = Math.max(...pairs.map(p => p.value), 1);
    const minValue = Math.min(...pairs.map(p => p.value > 0 ? p.value : maxValue), maxValue);
    
    let html = '<div class="funnel-container">';
    
    pairs.forEach((p, idx) => {
        const percentage = totalLeads > 0 ? ((p.value / totalLeads) * 100).toFixed(1) : 0;
        // Width com escala de 50% (mínimo) a 100% (máximo) para melhor visualização
        let width = 100;
        if (p.value > 0 && maxValue > 0) {
            // Escala de 50% a 100% baseada na proporção do valor
            const proportion = p.value / maxValue;
            width = 50 + (proportion * 50); // De 50% a 100%
        }
        const conversionRate = idx > 0 && pairs[idx-1].value > 0 ? ((p.value / pairs[idx-1].value) * 100).toFixed(1) : 100;
        
        html += `
            <div class="funnel-stage-wrapper" style="width: 100%; display: flex; align-items: center; margin-bottom: 1rem;">
                <div class="funnel-stage-number" style="min-width: 40px; text-align: center; font-weight: 700; font-size: 1.2rem; color: ${p.color};">
                    ${idx + 1}
                </div>
                <div class="funnel-stage-bar" style="flex: 1;">
                    <div class="funnel-stage" style="
                        border-left: 6px solid ${p.color}; 
                        width: ${width}%; 
                        background: linear-gradient(90deg, ${p.color}15 0%, ${p.color}05 100%);
                        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                        margin: 0;
                    ">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; font-size: 1rem; color: #1f2937; margin-bottom: 0.25rem;">
                                ${escapeHtml(p.label)}
                            </div>
                            <div class="funnel-percent" style="display: flex; gap: 1rem; font-size: 0.8rem;">
                                <span style="color: #64748b;">
                                    <i class="fa fa-users" style="font-size: 0.75rem;"></i> ${formatNumber(p.value)} leads
                                </span>
                                <span style="color: #64748b;">
                                    <i class="fa fa-percentage" style="font-size: 0.75rem;"></i> ${percentage}% do total
                                </span>
                                ${idx > 0 ? `<span style="color: ${conversionRate >= 50 ? '#10b981' : conversionRate >= 25 ? '#f59e0b' : '#ef4444'};">
                                    <i class="fa fa-arrow-down" style="font-size: 0.75rem;"></i> ${conversionRate}% conversão
                                </span>` : ''}
                            </div>
                        </div>
                        <div class="funnel-value" style="font-size: 2rem; color: ${p.color};">
                            ${formatNumber(p.value)}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    
    // Add summary at the bottom
    const firstStage = pairs[0]?.value || 0;
    const lastStage = pairs[pairs.length - 1]?.value || 0;
    const overallConversion = firstStage > 0 ? ((lastStage / firstStage) * 100).toFixed(1) : 0;
    
    html += `
        <div style="margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: #fff;">
            <div style="display: flex; justify-content: space-around; text-align: center;">
                <div>
                    <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.5rem;">Total no Funil</div>
                    <div style="font-size: 2rem; font-weight: 700;">${formatNumber(totalLeads)}</div>
                </div>
                <div>
                    <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.5rem;">Entrada</div>
                    <div style="font-size: 2rem; font-weight: 700;">${formatNumber(firstStage)}</div>
                </div>
                <div>
                    <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.5rem;">Conversão Final</div>
                    <div style="font-size: 2rem; font-weight: 700;">${formatNumber(lastStage)}</div>
                </div>
                <div>
                    <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.5rem;">Taxa Global</div>
                    <div style="font-size: 2rem; font-weight: 700;">${overallConversion}%</div>
                </div>
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

function renderTopSourcesTable() {
    const container = document.getElementById('tableTopSources');
    if(!container) return;
    
    let html = '<table class="data-table"><thead><tr><th>Origem</th><th>Quantidade</th><th>Percentual</th></tr></thead><tbody>';
    
    const total = REPORT_SOURCES.reduce((sum, s) => sum + Number(s.cnt || 0), 0);
    
    REPORT_SOURCES.forEach(s => {
        const count = Number(s.cnt || 0);
        const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
        html += `
            <tr>
                <td><strong>${escapeHtml(s.source || 'Sem origem')}</strong></td>
                <td>${formatNumber(count)}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 8px; width: 100px;">
                            <div class="progress-bar" style="width: ${percentage}%; background: #3b82f6;"></div>
                        </div>
                        <span class="small">${percentage}%</span>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function renderStagesDetailTable() {
    const container = document.getElementById('tableStagesDetail');
    if(!container) return;
    
    let html = '<table class="data-table"><thead><tr><th>Estágio</th><th>Leads</th><th>% do Total</th></tr></thead><tbody>';
    
    const total = REPORT_STAGE_COUNTS.reduce((sum, c) => sum + Number(c || 0), 0);
    
    REPORT_STAGES.forEach((s, i) => {
        const count = Number(REPORT_STAGE_COUNTS[i] || 0);
        const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
        const color = (s.color && s.color !== '') ? s.color : defaultPalette(i);
        
        html += `
            <tr>
                <td>
                    <span class="badge-stage" style="background: ${color}; color: #fff;">
                        ${escapeHtml(s.name || 'Sem nome')}
                    </span>
                </td>
                <td><strong>${formatNumber(count)}</strong></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 8px; width: 80px;">
                            <div class="progress-bar" style="width: ${percentage}%; background: ${color};"></div>
                        </div>
                        <span class="small">${percentage}%</span>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function renderTopSellersChart() {
    const ctx = document.getElementById('chartTopSellers');
    if (!ctx) return;
    
    destroyChart('chartTopSellers');
    
    const topUsers = REPORT_USERS_RANKING.slice(0, 10);
    const labels = topUsers.map(u => u.username || 'Usuário');
    const conversoes = topUsers.map(u => Number(u.conversoes || 0));
    const totalLeads = topUsers.map(u => Number(u.total_leads || 0));
    
    chartInstances['chartTopSellers'] = new Chart(ctx, { 
        type:'bar', 
        data:{ 
            labels, 
            datasets:[
                { 
                    label:'Conversões', 
                    data:conversoes, 
                    backgroundColor:'rgba(16,185,129,0.8)',
                    borderRadius: 6
                },
                { 
                    label:'Total de Leads', 
                    data:totalLeads, 
                    backgroundColor:'rgba(59,130,246,0.8)',
                    borderRadius: 6
                }
            ] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins:{ 
                legend:{ position:'top' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.x;
                        }
                    }
                }
            }, 
            scales:{ 
                x:{ beginAtZero:true, ticks: { precision: 0 } }
            } 
        } 
    });
}

function renderUserTasksChart() {
    const ctx = document.getElementById('chartUserTasks');
    if (!ctx) return;
    
    destroyChart('chartUserTasks');
    
    const topUsers = REPORT_USERS_TASKS.slice(0, 8);
    const labels = topUsers.map(u => u.username || 'Usuário');
    const concluidas = topUsers.map(u => Number(u.tarefas_concluidas || 0));
    const total = topUsers.map(u => Number(u.total_tarefas || 0));
    
    chartInstances['chartUserTasks'] = new Chart(ctx, { 
        type:'bar', 
        data:{ 
            labels, 
            datasets:[
                { 
                    label:'Concluídas', 
                    data:concluidas, 
                    backgroundColor:'rgba(16,185,129,0.8)',
                    borderRadius: 6
                },
                { 
                    label:'Total', 
                    data:total, 
                    backgroundColor:'rgba(148,163,184,0.6)',
                    borderRadius: 6
                }
            ] 
        }, 
        options:{ 
            responsive:true, 
            maintainAspectRatio: false,
            plugins:{ 
                legend:{ position:'top' }
            }, 
            scales:{ 
                y:{ beginAtZero:true, ticks: { precision: 0 } }
            } 
        } 
    });
}

function renderTopSellersTable() {
    const container = document.getElementById('tableTopSellers');
    if(!container) return;
    
    let html = '<table class="data-table"><thead><tr><th>Posição</th><th>Consultor</th><th>Leads</th><th>Conversões</th><th>Taxa</th><th>Valor Total</th></tr></thead><tbody>';
    
    REPORT_USERS_RANKING.forEach((u, idx) => {
        const totalLeads = Number(u.total_leads || 0);
        const conversoes = Number(u.conversoes || 0);
        const taxa = totalLeads > 0 ? ((conversoes / totalLeads) * 100).toFixed(1) : 0;
        const valorTotal = Number(u.valor_total || 0);
        
        const medalIcon = idx === 0 ? '🥇' : idx === 1 ? '🥈' : idx === 2 ? '🥉' : (idx + 1);
        
        html += `
            <tr>
                <td><strong style="font-size: 1.2rem;">${medalIcon}</strong></td>
                <td>
                    <div>
                        <strong>${escapeHtml(u.username || 'Usuário')}</strong>
                        <div class="small text-muted">${escapeHtml(u.email || '')}</div>
                    </div>
                </td>
                <td>${formatNumber(totalLeads)}</td>
                <td><strong style="color: #10b981;">${formatNumber(conversoes)}</strong></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 8px; width: 60px;">
                            <div class="progress-bar bg-success" style="width: ${taxa}%;"></div>
                        </div>
                        <span class="small">${taxa}%</span>
                    </div>
                </td>
                <td><strong>${formatCurrency(valorTotal)}</strong></td>
            </tr>
        `;
    });
    
    if (REPORT_USERS_RANKING.length === 0) {
        html += '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum dado disponível</td></tr>';
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function renderUsersTasksTable() {
    const container = document.getElementById('tableUsersTasks');
    if(!container) return;
    
    let html = '<table class="data-table"><thead><tr><th>Consultor</th><th>Total Tarefas</th><th>Concluídas</th><th>Taxa de Conclusão</th></tr></thead><tbody>';
    
    REPORT_USERS_TASKS.forEach(u => {
        const totalTarefas = Number(u.total_tarefas || 0);
        const concluidas = Number(u.tarefas_concluidas || 0);
        const taxa = totalTarefas > 0 ? ((concluidas / totalTarefas) * 100).toFixed(1) : 0;
        
        html += `
            <tr>
                <td><strong>${escapeHtml(u.username || 'Usuário')}</strong></td>
                <td>${formatNumber(totalTarefas)}</td>
                <td><strong style="color: #10b981;">${formatNumber(concluidas)}</strong></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 8px; width: 100px;">
                            <div class="progress-bar bg-info" style="width: ${taxa}%;"></div>
                        </div>
                        <span class="small">${taxa}%</span>
                    </div>
                </td>
            </tr>
        `;
    });
    
    if (REPORT_USERS_TASKS.length === 0) {
        html += '<tr><td colspan="4" class="text-center text-muted py-4">Nenhum dado disponível</td></tr>';
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function renderReports(){
    try {
        renderKPIs();
        renderLeadsMonthlyChart();
        renderLeadsByStageChart();
        renderConversionDonutChart();
        renderSourcesPieChart();
        renderCreatedClosedChart();
        renderTimeDistributionChart();
        renderTrendsChart();
        renderFunnel();
        renderTopSourcesTable();
        renderStagesDetailTable();
        renderTopSellersChart();
        renderUserTasksChart();
        renderTopSellersTable();
        renderUsersTasksTable();
        renderDailyCharts();
        renderFilteredStatusChart();
    } catch(e) { 
        console.error('Render reports failed', e); 
    }
}

function updateReports() {
    console.log('Updating reports with filters...');
    // Here you would typically fetch filtered data from server
    renderReports();
}

function resetFilters() {
    document.getElementById('filterPeriod').value = '365';
    document.getElementById('filterStartDate').value = '';
    document.getElementById('filterEndDate').value = '';
    updateReports();
}

function exportReport(format) {
    if (String(format).toLowerCase() !== 'pdf') {
        alert('Exportação para ' + String(format).toUpperCase() + ' será implementada em breve!');
        return;
    }

    try {
        const activePane = document.querySelector('#reportTabsContent .tab-pane.show.active')
            || document.querySelector('#reportTabsContent .tab-pane.active')
            || document.querySelector('#overview');
        if (!activePane) return;

        const tabBtn = document.querySelector(`#reportTabs [data-bs-target="#${activePane.id}"]`);
        const tabTitle = tabBtn ? tabBtn.textContent.replace(/\s+/g,' ').trim() : 'Relatório';

        // Clone and inline images for canvases
        const clone = activePane.cloneNode(true);
        const canvases = Array.from(activePane.querySelectorAll('canvas'));
        canvases.forEach((canvas) => {
            try {
                const id = canvas.id;
                if (!id) return;
                const cloneCanvas = clone.querySelector(`#${CSS.escape(id)}`);
                if (!cloneCanvas) return;
                const dataUrl = canvas.toDataURL('image/png');
                const img = document.createElement('img');
                img.src = dataUrl;
                img.alt = id;
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                img.style.display = 'block';
                cloneCanvas.replaceWith(img);
            } catch (e) { /* ignore */ }
        });

        // Prepend title and meta
        const wrapper = document.createElement('div');
        const titleEl = document.createElement('h1');
        titleEl.textContent = `Relatórios - ${tabTitle}`;
        const now = new Date();
        const metaEl = document.createElement('div');
        metaEl.className = 'meta';
        metaEl.textContent = `Gerado em: ${now.toLocaleDateString('pt-BR')} ${now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
        wrapper.appendChild(titleEl);
        wrapper.appendChild(metaEl);
        wrapper.appendChild(clone);

        // Use html2pdf to render the cloned DOM to PDF
        const opt = {
            margin:       [10,10,10,10],
            filename:     `${tabTitle.replace(/\s+/g,'_')}.pdf`,
            image:        { type: 'jpeg', quality: 0.95 },
            html2canvas:  { scale: 2, useCORS: true, allowTaint: true, logging: false },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:    { mode: ['css', 'legacy'] }
        };

        // Style adjustments for pdf render
        const style = document.createElement('style');
        style.textContent = `
            .meta { color: #6b7280; font-size: 12px; margin-bottom: 8px; }
            h1 { font-family: Arial, Helvetica, sans-serif; font-size: 18px; margin: 0 0 6px 0; }
            .report-card { background: #fff; border-radius: 8px; padding: 12px; margin-bottom: 8px; border: 1px solid #e6eef7; }
            .report-card-title { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
        `;
        wrapper.prepend(style);

        // Trigger save
        html2pdf().set(opt).from(wrapper).save();

    } catch (e) {
        console.error('Export PDF failed', e);
        alert('Falha ao exportar PDF.');
    }
}

document.addEventListener('DOMContentLoaded', function(){ 
    try{ 
        renderReports(); 
    }catch(e){ 
        console.error('Initial render failed', e); 
    } 
});

// Responsive resize handler
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        Object.keys(chartInstances).forEach(key => {
            if (chartInstances[key]) {
                chartInstances[key].resize();
            }
        });
    }, 250);
});
</script>

<?php include 'includes/footer.php'; ?>