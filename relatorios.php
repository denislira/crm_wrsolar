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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ?');
        $stmt->execute([$userId]);
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
        $q = $pdo->prepare("SELECT {$selectCols} FROM funil_stages WHERE user_id = ? ORDER BY COALESCE({$positionCol}, id) ASC");
        $q->execute([$userId]);
        $stages = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND (stage_id = ? OR status = ?)');
                $c->execute([$userId, $s['id'], $s['name']]);
                $stageCounts[] = (int)$c->fetchColumn();
        }
} catch (Exception $e) { $stages = []; $stageCounts = []; }

// Last 12 months created
try {
        $m = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
        $m->execute([$userId]);
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
                $mc = $pdo->prepare("SELECT DATE_FORMAT(closed_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE user_id = ? AND closed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
                $mc->execute([$userId]);
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        }

        // avg days to close
        if ($hasClosedAt) {
                $ad = $pdo->prepare("SELECT AVG(DATEDIFF(closed_at, created_at)) as avgd FROM leads WHERE user_id = ? AND closed_at IS NOT NULL");
                $ad->execute([$userId]);
                $avgDaysToClose = round((float)$ad->fetchColumn(),2);
        }

        // avg ticket
        if ($valueCol) {
                $at = $pdo->prepare("SELECT AVG(CASE WHEN {$valueCol} IS NULL OR {$valueCol} = '' THEN NULL ELSE {$valueCol} END) FROM leads WHERE user_id = ?");
                $at->execute([$userId]);
                $avgTicket = $at->fetchColumn();
                if ($avgTicket !== null) $avgTicket = round((float)$avgTicket,2);
        }

        // sources
        if ($sourceCol) {
                $sstmt = $pdo->prepare("SELECT {$sourceCol} AS source, COUNT(*) AS cnt FROM leads WHERE user_id = ? GROUP BY {$sourceCol} ORDER BY cnt DESC LIMIT 10");
                $sstmt->execute([$userId]);
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
        $timelineSql = "SELECT {$selectSql} FROM activity_log a {$joins} WHERE a.user_id = ? OR ? IS NULL ORDER BY a.created_at DESC LIMIT 500";
        $tStmt = $pdo->prepare($timelineSql);
        $tStmt->execute([$userId, null]);
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
                $fstmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND (stage_id = ? OR status = ?)');
                $fstmt->execute([$userId, $final['id'], $final['name']]);
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
            <div class="filter-bar">
                <label class="mb-0 fw-semibold">Período:</label>
                <select id="filterPeriod" onchange="updateReports()">
                    <option value="30">Últimos 30 dias</option>
                    <option value="90">Últimos 90 dias</option>
                    <option value="180">Últimos 6 meses</option>
                    <option value="365" selected>Último ano</option>
                </select>
                <input type="date" id="filterStartDate" onchange="updateReports()" />
                <input type="date" id="filterEndDate" onchange="updateReports()" />
                <button class="btn btn-sm btn-outline-primary" onclick="resetFilters()">Limpar Filtros</button>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-pills mb-4" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="pill" data-bs-target="#overview" type="button" role="tab">
                        <i class="fa fa-home"></i> Visão Geral
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="funnel-tab" data-bs-toggle="pill" data-bs-target="#funnel" type="button" role="tab">
                        <i class="fa fa-filter"></i> Funil de Vendas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="temporal-tab" data-bs-toggle="pill" data-bs-target="#temporal" type="button" role="tab">
                        <i class="fa fa-chart-line"></i> Análise Temporal
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="consultores-tab" data-bs-toggle="pill" data-bs-target="#consultores" type="button" role="tab">
                        <i class="fa fa-users"></i> Consultores
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sources-tab" data-bs-toggle="pill" data-bs-target="#sources" type="button" role="tab">
                        <i class="fa fa-bullseye"></i> Fontes e Origem
                    </button>
                </li>
            </ul>

            <!-- Tabs Content -->
            <div class="tab-content" id="reportTabsContent">
                
                <!-- Overview Tab -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
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
                <div class="tab-pane fade" id="funnel" role="tabpanel">
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
                <div class="tab-pane fade" id="temporal" role="tabpanel">
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
                <div class="tab-pane fade" id="consultores" role="tabpanel">
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
                <div class="tab-pane fade" id="sources" role="tabpanel">
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

            </div>

        </div>
    </main>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    alert('Exportação para ' + format.toUpperCase() + ' será implementada em breve!');
    // Implementation for PDF/Excel export would go here
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