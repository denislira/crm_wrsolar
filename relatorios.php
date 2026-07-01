<?php
// relatorios.php - Extended reports with multiple charts and a funnel
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit(); }
require_once 'includes/config.php';
require_once 'includes/permissions.php';

checkAccessOrRedirect('relatorios');

// Determine which lead column should be used to attribute activity to a consultant.
// Newer schemas track the last editor in `user_id_update`, but older schemas only have `user_id`.
// When available, prefer `user_id_update` but fall back to `user_id` so leads without updates still count.
$leadCols = [];
try {
    $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* ignore */ }
$leadOwnerJoinExpr = in_array('user_id_update', $leadCols, true) ? 'COALESCE(l.user_id_update, l.user_id)' : 'l.user_id';

$pageTitle = 'Relatórios';
include 'includes/header.php';

// Defensive server-side data collection
$userId = $_SESSION['user_id'];

// Active tab (keep user on the chosen report after submit)
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'overview';
$tabAliases = ['sql' => 'qualificacao', 'speed-to-lead' => 'sla'];
if (isset($tabAliases[$activeTab])) {
    $activeTab = $tabAliases[$activeTab];
}
$allowedTabs = ['overview','funnel','temporal','consultores','sources','daily','qualificacao','sla','financeiro'];
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
    // prefer data_inicio as the canonical lead entry date; fallback to created_at if missing
    $dateCol = in_array('data_inicio', $leadColsDaily, true) ? 'data_inicio' : (in_array('created_at', $leadColsDaily, true) ? 'created_at' : 'data_inicio');

    $start = new DateTime($dailyDate . ' 00:00:00');
    $end = (clone $start)->modify('+1 day');

    $where = "{$dateCol} >= ? AND {$dateCol} < ?";
    if ($hasDeleted) $where .= " AND deleted = 0";
    // Exclude leads that are already closed/lost to count only active leads
    $where .= " AND (COALESCE(status,'') NOT LIKE '%fechado%' AND COALESCE(status,'') NOT LIKE '%ganho%' AND COALESCE(status,'') NOT LIKE '%perdido%')";

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$where}");
    $cntStmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    $dailyCount = (int)$cntStmt->fetchColumn();

    $listCols = ['id'];
    // use data_inicio as the created date column in lists
    foreach (['name','source','status','data_inicio'] as $c) if (in_array($c, $leadColsDaily, true)) $listCols[] = $c;
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

// ── Reusable filter conditions (date range + sources + deleted) ───────────────
$baseDelCond = $hasDeleted ? " AND deleted = 0" : "";
$fStartStr = $fStart->format('Y-m-d H:i:s');
$fEndStr   = $fEnd->format('Y-m-d H:i:s');
$srcCond   = '';
$srcParams = [];
if (!empty($filterSources)) {
    $placeholders = implode(',', array_fill(0, count($filterSources), '?'));
    $srcCond = " AND COALESCE(NULLIF(source,''),'') IN ($placeholders)";
    foreach ($filterSources as $s) $srcParams[] = (string)$s;
}
// Full WHERE: date range + deleted + source
$filterWhere  = "{$dateCol} >= ? AND {$dateCol} <= ?{$baseDelCond}{$srcCond}";
$filterParams = array_merge([$fStartStr, $fEndStr], $srcParams);

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
$timeDistribution = array_fill(1, 7, 0);
$trendSeries = [];

try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$filterWhere}");
        $stmt->execute($filterParams);
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
                $c = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE (stage_id = ? OR status = ?) AND {$filterWhere}");
                $c->execute(array_merge([$s['id'], $s['name']], $filterParams));
                $stageCounts[] = (int)$c->fetchColumn();
        }
} catch (Exception $e) { $stages = []; $stageCounts = []; }

// Monthly series follows the same selected filter range
$reportMonthsStart = $fStart;
$reportMonthsEnd = $fEnd;
$reportMonthsWhere = "{$dateCol} >= ? AND {$dateCol} <= ?{$baseDelCond}{$srcCond}";
$reportMonthsParams = array_merge([$reportMonthsStart->format('Y-m-d H:i:s'), $reportMonthsEnd->format('Y-m-d H:i:s')], $srcParams);

try {
        $m = $pdo->prepare("SELECT DATE_FORMAT({$dateCol}, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE {$reportMonthsWhere} GROUP BY ym ORDER BY ym ASC");
        $m->execute($reportMonthsParams);
        $monthsRows = $m->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $monthsRows = []; }

// Last 12 months closed — based on funil_stages.is_conversion = 1 (primary),
// with optional fallback to closed_at or is_conversation if that column doesn't exist.
try {
        $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
        $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasClosedAt = in_array('closed_at', $leadCols);
        $hasIsConversation = in_array('is_conversation', $leadCols);
        $valueCol = null;
        foreach (['orcamento_value','proposal_value','value','amount','budget','estimated_value'] as $vc) {
            if (in_array($vc, $leadCols, true)) { $valueCol = $vc; break; }
        }
        $sourceCol = null;
        foreach (['source','origem','lead_source'] as $sc) if (in_array($sc, $leadCols, true)) { $sourceCol = $sc; break; }

        // Check if funil_stages has is_conversion column
        $fsColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
        $fsCols = $fsColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasIsConversionStage = in_array('is_conversion', $fsCols, true);

        if ($hasIsConversionStage) {
                // PRIMARY: leads cujo stage está marcado como is_conversion = 1 no funil
                $mc = $pdo->prepare(
                        "SELECT DATE_FORMAT(l.{$dateCol}, '%Y-%m') as ym, COUNT(*) as cnt
                         FROM leads l
                         INNER JOIN funil_stages fs ON fs.id = l.stage_id AND fs.is_conversion = 1
                         WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$baseDelCond}{$srcCond}
                         GROUP BY ym ORDER BY ym ASC"
                );
                $mc->execute($reportMonthsParams);
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        } elseif (in_array('final_type', $fsCols, true)) {
                // fallback: considerar final_type=won como conversão para funil de conversão
                $mc = $pdo->prepare(
                        "SELECT DATE_FORMAT(l.{$dateCol}, '%Y-%m') as ym, COUNT(*) as cnt
                         FROM leads l
                         INNER JOIN funil_stages fs ON fs.id = l.stage_id AND fs.final_type = 'won'
                         WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$baseDelCond}{$srcCond}
                         GROUP BY ym ORDER BY ym ASC"
                );
                $mc->execute($reportMonthsParams);
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($hasClosedAt || $hasIsConversation) {
                // FALLBACK: closed_at ou is_conversation
                $closedDateExpr = $hasClosedAt ? 'COALESCE(l.closed_at, l.created_at)' : "l.{$dateCol}";
                if ($hasClosedAt && $hasIsConversation) {
                        $closedCondition = '(l.closed_at IS NOT NULL OR l.is_conversation = 1)';
                } elseif ($hasClosedAt) {
                        $closedCondition = 'l.closed_at IS NOT NULL';
                } else {
                        $closedCondition = 'l.is_conversation = 1';
                }
                $mc = $pdo->prepare(
                        "SELECT DATE_FORMAT({$closedDateExpr}, '%Y-%m') as ym, COUNT(*) as cnt
                         FROM leads l
                         WHERE {$closedCondition} AND l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$baseDelCond}{$srcCond}
                         GROUP BY ym ORDER BY ym ASC"
                );
                $mc->execute($reportMonthsParams);
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        }

        // avg days to close — usando estágio is_conversion ou closed_at
        if ($hasIsConversionStage) {
                $ad = $pdo->prepare(
                        "SELECT AVG(DATEDIFF(l.{$dateCol}, l.created_at)) as avgd
                         FROM leads l
                         INNER JOIN funil_stages fs ON fs.id = l.stage_id AND fs.is_conversion = 1
                         WHERE {$filterWhere}"
                );
                $ad->execute($filterParams);
                $avgDaysToClose = round((float)$ad->fetchColumn(), 2);
        } elseif ($hasClosedAt) {
                $ad = $pdo->prepare("SELECT AVG(DATEDIFF(closed_at, created_at)) as avgd FROM leads WHERE closed_at IS NOT NULL AND {$filterWhere}");
                $ad->execute($filterParams);
                $avgDaysToClose = round((float)$ad->fetchColumn(),2);
        }

        // avg ticket
        if ($valueCol) {
                $at = $pdo->prepare("SELECT AVG(CASE WHEN {$valueCol} IS NULL OR {$valueCol} = '' THEN NULL ELSE {$valueCol} END) FROM leads WHERE {$filterWhere}");
                $at->execute($filterParams);
                $avgTicket = $at->fetchColumn();
                if ($avgTicket !== null) $avgTicket = round((float)$avgTicket,2);
        }

        // sources
        if ($sourceCol) {
                $sstmt = $pdo->prepare("SELECT COALESCE(NULLIF({$sourceCol},''),'Sem origem') AS source, COUNT(*) AS cnt FROM leads WHERE {$filterWhere} GROUP BY COALESCE(NULLIF({$sourceCol},''),'Sem origem') ORDER BY cnt DESC");
                $sstmt->execute($filterParams);
                $sources = $sstmt->fetchAll(PDO::FETCH_ASSOC);
        }

        try {
                $dowStmt = $pdo->prepare(
                        "SELECT DAYOFWEEK({$dateCol}) AS dow, COUNT(*) AS cnt
                         FROM leads
                         WHERE {$filterWhere}
                         GROUP BY dow
                         ORDER BY dow ASC"
                );
                $dowStmt->execute($filterParams);
                foreach ($dowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $dow = (int)($row['dow'] ?? 0);
                        if ($dow >= 1 && $dow <= 7) {
                                $timeDistribution[$dow] = (int)($row['cnt'] ?? 0);
                        }
                }
        } catch (Exception $e) { /* ignore */ }

        try {
                $trendStmt = $pdo->prepare(
                        "SELECT DATE_FORMAT({$dateCol}, '%Y-%m') AS ym, COUNT(*) AS cnt
                         FROM leads
                         WHERE {$filterWhere}
                         GROUP BY ym
                         ORDER BY ym ASC"
                );
                $trendStmt->execute($filterParams);
                $trendSeries = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $trendSeries = []; }

} catch (Exception $e) { /* ignore and continue */ }

try {
        if (empty($sources)) {
                $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
                $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
                $sourceCol = null;
                foreach (['source','origem','lead_source'] as $sc) {
                        if (in_array($sc, $leadCols, true)) { $sourceCol = $sc; break; }
                }
                if ($sourceCol) {
                        $sstmt = $pdo->prepare("SELECT COALESCE(NULLIF({$sourceCol},''),'Sem origem') AS source, COUNT(*) AS cnt FROM leads WHERE {$filterWhere} GROUP BY COALESCE(NULLIF({$sourceCol},''),'Sem origem') ORDER BY cnt DESC");
                        $sstmt->execute($filterParams);
                        $sources = $sstmt->fetchAll(PDO::FETCH_ASSOC);
                }
        }
} catch (Exception $e) { /* ignore */ }

// --- Temporal analysis heuristics (automated insights) ---
$temporalInsights = [];
$activityCount = 0;
$activityProposalCount = 0;
$activityByUser = [];
$conversionBySource = [];
$avgActivitiesPerLead = null;
$stageDropoffs = [];
try {
    // activity counts (use filter date range)
    $actStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE created_at >= ? AND created_at <= ?");
    $actStmt->execute([$fStartStr, $fEndStr]);
    $activityCount = (int)$actStmt->fetchColumn();

    $propStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE created_at >= ? AND created_at <= ? AND message LIKE ?");
    $propStmt->execute([$fStartStr, $fEndStr, '%proposta%']);
    $activityProposalCount = (int)$propStmt->fetchColumn();

    // activity by user
    $abu = $pdo->prepare("SELECT COALESCE(u.username,'(desconhecido)') AS username, COUNT(*) as cnt FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.created_at >= ? AND a.created_at <= ? GROUP BY a.user_id ORDER BY cnt DESC LIMIT 12");
    $abu->execute([$fStartStr, $fEndStr]);
    $activityByUser = $abu->fetchAll(PDO::FETCH_ASSOC);

    // conversion by source in period — primary: funil_stages.is_conversion = 1
    $leadColsCheck = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadColsList = $leadColsCheck->fetchAll(PDO::FETCH_COLUMN);
    $hasClosedAtCol = in_array('closed_at', $leadColsList, true);
    $hasIsConversationCol = in_array('is_conversation', $leadColsList, true);
    $fsColsCheck = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $fsColsList = $fsColsCheck->fetchAll(PDO::FETCH_COLUMN);
    $hasIsConversionStageCol = in_array('is_conversion', $fsColsList, true);

    if ($hasIsConversionStageCol) {
        $convSql = "SELECT COALESCE(NULLIF(l.source,''),'Sem origem') AS source, COUNT(*) AS total,
                    SUM(CASE WHEN fs.is_conversion = 1 THEN 1 WHEN fs.final_type = 'won' THEN 1 ELSE 0 END) AS closed
                    FROM leads l
                    LEFT JOIN funil_stages fs ON fs.id = l.stage_id
                    WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$baseDelCond}{$srcCond}
                    GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    } elseif (in_array('final_type', $fsColsList, true)) {
        $convSql = "SELECT COALESCE(NULLIF(l.source,''),'Sem origem') AS source, COUNT(*) AS total,
                    SUM(CASE WHEN fs.final_type = 'won' THEN 1 ELSE 0 END) AS closed
                    FROM leads l
                    LEFT JOIN funil_stages fs ON fs.id = l.stage_id
                    WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$baseDelCond}{$srcCond}
                    GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    } elseif ($hasClosedAtCol && $hasIsConversationCol) {
        $convSql = "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN closed_at IS NOT NULL OR is_conversation = 1 THEN 1 ELSE 0 END) AS closed FROM leads WHERE {$filterWhere} GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    } elseif ($hasClosedAtCol) {
        $convSql = "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS closed FROM leads WHERE {$filterWhere} GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    } elseif ($hasIsConversationCol) {
        $convSql = "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN is_conversation = 1 THEN 1 ELSE 0 END) AS closed FROM leads WHERE {$filterWhere} GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    } else {
        $convSql = "SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, 0 AS closed FROM leads WHERE {$filterWhere} GROUP BY source ORDER BY total DESC LIMIT 20";
        $convStmt = $pdo->prepare($convSql);
        $convStmt->execute($filterParams);
    }
    $conversionBySource = $convStmt->fetchAll(PDO::FETCH_ASSOC);

    // avg activities per lead (approx)
    $leadsCntStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$filterWhere}");
    $leadsCntStmt->execute($filterParams);
    $leadsCreated = max(1, (int)$leadsCntStmt->fetchColumn());
    $avgActivitiesPerLead = round($activityCount / $leadsCreated, 2);

    // stage drop-offs using previously loaded $stages and $stageCounts
    if (count($stages) > 1 && count($stageCounts) === count($stages)) {
        for ($i = 0; $i < count($stages)-1; $i++) {
            $from = (int)$stageCounts[$i];
            $to = (int)$stageCounts[$i+1];
            $dropPct = $from > 0 ? round((($from - $to) / $from) * 100, 1) : 0;
            $stageDropoffs[] = ['from'=>$stages[$i]['name'] ?? 'Sem nome', 'to'=>$stages[$i+1]['name'] ?? 'Sem nome', 'from_count'=>$from, 'to_count'=>$to, 'drop_pct'=>$dropPct];
        }
    }

    // Heuristics for insights
    if ($activityCount < max(10, $leadsCreated * 0.5)) {
        $temporalInsights[] = 'Atividade geral baixa no período. A equipe precisa aumentar toques e follow-ups; sugerido: +2 contatos por lead.';
    }
    if ($avgActivitiesPerLead < 1.5) {
        $temporalInsights[] = 'Poucas ações por lead (média de ' . $avgActivitiesPerLead . '). Aumente cadência de contato e registre ações na plataforma.';
    }
    if ($activityProposalCount < max(5, $leadsCreated * 0.05)) {
        $temporalInsights[] = 'Poucas propostas enviadas no período; reveja o processo de qualificação e prepare templates de proposta.';
    }
    if ($avgDaysToClose !== null && $avgDaysToClose > 14) {
        $temporalInsights[] = 'Tempo médio para fechar é alto (' . $avgDaysToClose . ' dias). Analise gargalos entre primeiro contato e proposta.';
    }

    // sources: flag high volume but low conversion
    foreach ($conversionBySource as $s) {
        $total = (int)$s['total']; $closed = (int)$s['closed'];
        $conv = $total>0?round(($closed/$total)*100,1):0;
        if ($total > 20 && $conv < 5) {
            $temporalInsights[] = 'Fonte "' . ($s['source']?:'Sem origem') . '" tem alto volume (' . $total . ') e baixa conversão (' . $conv . '%) — investigar qualidade da origem.';
        }
    }

    // stages with big drop-offs
    foreach ($stageDropoffs as $d) {
        if ($d['drop_pct'] >= 50) {
            $temporalInsights[] = 'Grande perda entre "' . $d['from'] . '" → "' . $d['to'] . '" (' . $d['drop_pct'] . '%). Reveja critérios de passagem de etapa.';
        }
    }

    if (empty($temporalInsights)) $temporalInsights[] = 'Nenhum problema crítico detectado no período; continue monitorando os indicadores.';

} catch (Exception $e) {
    // fallback
    $temporalInsights[] = 'Não foi possível gerar insights automáticos (erro de análise).';
}

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

// Final stage and conversion — uses funil_stages.is_conversion = 1 when available
$finalStageCount = 0; $conversionRate = 0.0;
try {
        $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
        $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStageId = in_array('stage_id', $leadCols, true);

        $fsConvColStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
        $fsConvCols = $fsConvColStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($hasStageId && in_array('is_conversion', $fsConvCols, true)) {
                // count leads whose current stage is marked as conversion
                $fstmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM leads l
                         INNER JOIN funil_stages fs ON fs.id = l.stage_id AND fs.is_conversion = 1
                         WHERE {$filterWhere}"
                );
                $fstmt->execute($filterParams);
        } elseif ($hasStageId && count($stages) > 0) {
                // fallback: last stage by stage_id or matching status name
                $final = end($stages);
                $fstmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE (stage_id = ? OR status = ?) AND ' . $filterWhere);
                $fstmt->execute(array_merge([$final['id'], $final['name']], $filterParams));
        } else {
                // fallback by status text when stage system is not available
                $statusPattern = "(status LIKE '%fechado%' OR status LIKE '%ganho%' OR status LIKE '%convertido%')";
                $fstmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$statusPattern} AND {$filterWhere}");
                $fstmt->execute($filterParams);
        }
        if ($fstmt) {
                $finalStageCount = (int)$fstmt->fetchColumn();
                $conversionRate = $leadsTotal > 0 ? round(($finalStageCount / $leadsTotal) * 100, 2) : 0;
        }
} catch (Exception $e) { }

// Consultores/Usuários ranking
$usersRanking = [];
try {
        // Determine stage column that controls pipeline summation (for compatibility across installs)
        $stageIncludeCol = null;
        try {
            $stageColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
            $stageCols = $stageColsStmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('include_in_forecast', $stageCols, true)) {
                $stageIncludeCol = 'include_in_forecast';
            } elseif (in_array('include_in_pipeline', $stageCols, true)) {
                $stageIncludeCol = 'include_in_pipeline';
            }
        } catch (Exception $e) { /* ignore */ }

        $stageIncludeExpr = $stageIncludeCol ? "COALESCE(f.{$stageIncludeCol}, 1)" : '1';

        // Get all users with their lead counts, conversions, and total value
        $usersSrcCond = !empty($filterSources)
            ? " AND COALESCE(NULLIF(l.source,''),'') IN (" . implode(',', array_fill(0, count($filterSources), '?')) . ")"
            : '';
        $usersDelCond = $hasDeleted ? " AND l.deleted = 0" : '';
        $usersQuery = "
                SELECT 
                        u.id,
                        u.username,
                        u.email,
                        COUNT(DISTINCT l.id) as total_leads,
                        SUM(CASE WHEN l.stage_id = ? OR l.status LIKE '%fechado%' OR l.status LIKE '%ganho%' THEN 1 ELSE 0 END) as conversoes,
                        SUM(CASE WHEN {$stageIncludeExpr} = 1 THEN COALESCE(l.orcamento_value, 0) ELSE 0 END) as valor_total
                FROM users u
                LEFT JOIN leads l ON {$leadOwnerJoinExpr} = u.id
                    AND l.{$dateCol} >= ? AND l.{$dateCol} <= ?{$usersDelCond}{$usersSrcCond}
                LEFT JOIN funil_stages f ON f.id = l.stage_id
                GROUP BY u.id, u.username, u.email
                HAVING total_leads > 0
                ORDER BY conversoes DESC, total_leads DESC
                LIMIT 20
        ";
        $finalStageId = count($stages) > 0 ? end($stages)['id'] : 0;
        $usersStmt = $pdo->prepare($usersQuery);
        $usersStmt->execute(array_merge([$finalStageId, $fStartStr, $fEndStr], $srcParams));
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
                    AND t.created_at >= ? AND t.created_at <= ?
                GROUP BY u.id, u.username
                HAVING total_tarefas > 0
                ORDER BY tarefas_concluidas DESC, total_tarefas DESC
                LIMIT 10
        ";
        $tasksStmt = $pdo->prepare($tasksQuery);
        $tasksStmt->execute([$fStartStr, $fEndStr]);
        $usersTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
        $usersTasks = []; 
}

// Movements per user (lead_movements) and leads updated in the period.
$movementsByUser = [];
$dateUpdatesByUser = [];
try {
    $mvStmt = $pdo->prepare("SELECT COALESCE(u.username, '(desconhecido)') AS username, COUNT(*) AS cnt
        FROM lead_movements lm
        LEFT JOIN users u ON u.id = lm.user_id
        WHERE lm.created_at >= ? AND lm.created_at <= ?
        GROUP BY lm.user_id ORDER BY cnt DESC LIMIT 20");
    $mvStmt->execute([$fStartStr, $fEndStr]);
    $movementsByUser = $mvStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasLeadUpdateLogs = false;
    try {
        $logTableStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_update_logs'");
        $logTableStmt->execute();
        $hasLeadUpdateLogs = (bool)$logTableStmt->fetchColumn();
    } catch (Exception $e) { $hasLeadUpdateLogs = false; }

    if ($hasLeadUpdateLogs) {
        $duSrcCond = '';
        $duParams = [$fStartStr, $fEndStr];
        if (!empty($filterSources)) {
            $duSrcCond = " AND COALESCE(NULLIF(l.source,''),'') IN (" . implode(',', array_fill(0, count($filterSources), '?')) . ")";
            foreach ($filterSources as $s) $duParams[] = (string)$s;
        }
        $duDelCond = in_array('deleted', $leadCols, true) ? " AND COALESCE(l.deleted, 0) = 0" : "";
        $duStmt = $pdo->prepare("SELECT COALESCE(u.username, '(desconhecido)') AS username, COUNT(*) AS cnt
            FROM lead_update_logs lul
            LEFT JOIN users u ON u.id = lul.user_id
            LEFT JOIN leads l ON l.id = lul.lead_id
            WHERE lul.created_at >= ? AND lul.created_at <= ?
              AND lul.updated_field = 'ultimo_contato'
              {$duDelCond}{$duSrcCond}
            GROUP BY lul.user_id, u.username
            ORDER BY cnt DESC LIMIT 20");
        $duStmt->execute($duParams);
        $dateUpdatesByUser = $duStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $movementsByUser = [];
    $dateUpdatesByUser = [];
}

// --- Filtered status counts and last-24h summary ---
try {
    $leadColsStmt2 = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
    $leadCols2 = $leadColsStmt2->fetchAll(PDO::FETCH_COLUMN);
    $dateCol2 = in_array('data_inicio', $leadCols2, true) ? 'data_inicio' : (in_array('created_at', $leadCols2, true) ? 'created_at' : 'data_inicio');
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

// ============================================================
// NEW: Qualification Module (MQL → SQL)
// ============================================================
$sqlRate              = null;   // % leads classified as SQL
$disqualReasons       = [];     // Pareto: motivos de descarte
$disqualBySource      = [];     // desqualificações por canal
$totalMql             = 0;
$totalSql             = 0;
$lostReasons          = [];     // motivos de perda no fechamento
$paymentDistribution  = [];     // à vista vs financiado
$avgKwp               = null;   // kWp médio
$avgTicketKwp         = null;   // R$/kWp médio

try {
    $qLeadCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsSQL    = in_array('is_sql', $qLeadCols, true);
    $hasDisqual  = in_array('disqualification_reason', $qLeadCols, true);
    $hasLost     = in_array('lost_reason', $qLeadCols, true);
    $hasKwp      = in_array('kwp', $qLeadCols, true);
    $hasPayType  = in_array('payment_type', $qLeadCols, true);
    $hasOrcamento = in_array('orcamento_value', $qLeadCols, true);

    $qualDelBase = $hasDeleted2 ? " AND deleted = 0" : "";
    $qualDateBase = in_array('data_inicio', $qLeadCols, true) ? 'data_inicio' : (in_array('created_at', $qLeadCols, true) ? 'created_at' : 'data_inicio');
    $qualSrcCond = $srcCond; // reuse source condition built earlier
    $qualBaseWhere = "{$qualDateBase} >= ? AND {$qualDateBase} <= ?{$qualDelBase}{$qualSrcCond}";
    $qualBaseParams = array_merge([$fStartStr, $fEndStr], $srcParams);

    // Total MQL and SQL within filter period
    $totalMqlStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$qualBaseWhere}");
    $totalMqlStmt->execute($qualBaseParams);
    $totalMql = (int)$totalMqlStmt->fetchColumn();

    if ($hasIsSQL) {
        $totalSqlStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE is_sql = 1 AND {$qualBaseWhere}");
        $totalSqlStmt->execute($qualBaseParams);
        $totalSql = (int)$totalSqlStmt->fetchColumn();
    } else {
        // Fallback: SQL = leads that have a proposta stage or orcamento_value > 0
        $sqlFallbackCond = $hasOrcamento ? "orcamento_value > 0" : "status LIKE '%proposta%' OR status LIKE '%qualif%'";
        $sqlFallbackStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE ({$sqlFallbackCond}) AND {$qualBaseWhere}");
        $sqlFallbackStmt->execute($qualBaseParams);
        $totalSql = (int)$sqlFallbackStmt->fetchColumn();
    }
    $sqlRate = $totalMql > 0 ? round(($totalSql / $totalMql) * 100, 1) : 0;

    // Disqualification reasons (Pareto)
    if ($hasDisqual) {
        $dqStmt = $pdo->prepare("SELECT COALESCE(NULLIF(disqualification_reason,''),'Não informado') AS reason, COUNT(*) AS cnt FROM leads WHERE disqualification_reason IS NOT NULL AND {$qualBaseWhere} GROUP BY reason ORDER BY cnt DESC LIMIT 10");
        $dqStmt->execute($qualBaseParams);
        $disqualReasons = $dqStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Disqualifications by source
    if ($hasDisqual) {
        $dqSrcStmt = $pdo->prepare("SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN disqualification_reason IS NOT NULL AND disqualification_reason != '' THEN 1 ELSE 0 END) AS disqualified FROM leads WHERE {$qualBaseWhere} GROUP BY source ORDER BY disqualified DESC LIMIT 10");
        $dqSrcStmt->execute($qualBaseParams);
        $disqualBySource = $dqSrcStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback using status
        $dqSrcStmt = $pdo->prepare("SELECT COALESCE(NULLIF(source,''),'Sem origem') AS source, COUNT(*) AS total, SUM(CASE WHEN status LIKE '%descartado%' OR status LIKE '%desqualif%' OR status LIKE '%perdido%' THEN 1 ELSE 0 END) AS disqualified FROM leads WHERE {$qualBaseWhere} GROUP BY source ORDER BY disqualified DESC LIMIT 10");
        $dqSrcStmt->execute($qualBaseParams);
        $disqualBySource = $dqSrcStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Lost reasons
    if ($hasLost) {
        $lostStmt = $pdo->prepare("SELECT COALESCE(NULLIF(lost_reason,''),'Não informado') AS reason, COUNT(*) AS cnt FROM leads WHERE lost_reason IS NOT NULL AND {$qualBaseWhere} GROUP BY reason ORDER BY cnt DESC LIMIT 10");
        $lostStmt->execute($qualBaseParams);
        $lostReasons = $lostStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback from status
        $lostStmt = $pdo->prepare("SELECT COALESCE(NULLIF(status,''),'Sem status') AS reason, COUNT(*) AS cnt FROM leads WHERE (status LIKE '%perdido%' OR status LIKE '%descartado%' OR status LIKE '%cancelado%') AND {$qualBaseWhere} GROUP BY reason ORDER BY cnt DESC LIMIT 10");
        $lostStmt->execute($qualBaseParams);
        $lostReasons = $lostStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Payment distribution
    $payColSrc = $hasPayType ? 'payment_type' : (in_array('forma_pagamento', $qLeadCols, true) ? 'forma_pagamento' : null);
    if ($payColSrc) {
        $payStmt = $pdo->prepare("SELECT COALESCE(NULLIF({$payColSrc},''),'Não informado') AS label, COUNT(*) AS cnt FROM leads WHERE {$qualBaseWhere} GROUP BY label ORDER BY cnt DESC");
        $payStmt->execute($qualBaseParams);
        $paymentDistribution = $payStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // kWp metrics
    $kwpCol = $hasKwp ? 'kwp' : (in_array('estimativa_projeto_kwh', $qLeadCols, true) ? 'estimativa_projeto_kwh' : null);
    if ($kwpCol) {
        $kwpStmt = $pdo->prepare("SELECT AVG(CASE WHEN {$kwpCol} > 0 THEN {$kwpCol} ELSE NULL END) AS avg_kwp FROM leads WHERE {$qualBaseWhere}");
        $kwpStmt->execute($qualBaseParams);
        $avgKwp = round((float)$kwpStmt->fetchColumn(), 2);

        if ($hasOrcamento) {
            $tktKwpStmt = $pdo->prepare("SELECT AVG(CASE WHEN {$kwpCol} > 0 AND orcamento_value > 0 THEN orcamento_value / {$kwpCol} ELSE NULL END) AS ratio FROM leads WHERE {$qualBaseWhere}");
            $tktKwpStmt->execute($qualBaseParams);
            $avgTicketKwp = round((float)$tktKwpStmt->fetchColumn(), 2);
        }
    }
} catch (Exception $e) { /* ignore */ }

$financeDataAvailable = (
    $avgKwp !== null ||
    $avgTicketKwp !== null ||
    $avgTicket !== null ||
    $avgDaysToClose !== null ||
    !empty($paymentDistribution) ||
    !empty($lostReasons) ||
    !empty($monthsRows)
);

// ============================================================
// NEW: SLA / Speed-to-Lead diagnostics
// ============================================================
$speedToLeadAvg    = null;   // avg hours from created_at to first_contact_at
$slaAlertLeads     = [];     // leads sem contato >24h
$staleLeads        = [];     // leads parados na mesma etapa >7 dias
$staleThreshDays   = 7;

try {
    $slaLeadCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'")->fetchAll(PDO::FETCH_COLUMN);
    $hasFirstContact = in_array('first_contact_at', $slaLeadCols, true);
    $hasUltimoCtato  = in_array('ultimo_contato', $slaLeadCols, true);
    $slaDateCol      = in_array('data_inicio', $slaLeadCols, true) ? 'data_inicio' : (in_array('created_at', $slaLeadCols, true) ? 'created_at' : 'data_inicio');
    $slaDelBase      = in_array('deleted', $slaLeadCols, true) ? " AND deleted = 0" : "";

    // Speed-to-Lead average (hours)
    if ($hasFirstContact) {
        $stlStmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, {$slaDateCol}, first_contact_at)) FROM leads WHERE first_contact_at IS NOT NULL AND {$slaDateCol} >= ? AND {$slaDateCol} <= ?{$slaDelBase}{$srcCond}");
        $stlStmt->execute(array_merge([$fStartStr, $fEndStr], $srcParams));
        $speedToLeadAvg = round((float)$stlStmt->fetchColumn(), 1);
    }

    // Leads without contact in > 24h (alert list)
    $threshold24 = (new DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
    $alertCond = $hasFirstContact
        ? "first_contact_at IS NULL AND {$slaDateCol} < ?"
        : ($hasUltimoCtato ? "(ultimo_contato IS NULL OR ultimo_contato < ?) AND {$slaDateCol} < ?" : "{$slaDateCol} < ?");

    $alertCols = "id, name, COALESCE(NULLIF(source,''),'Sem origem') AS source, COALESCE(NULLIF(status,''),'Sem status') AS status, {$slaDateCol} AS created_at";
    if ($hasFirstContact) {
        $alertStmt = $pdo->prepare("SELECT {$alertCols} FROM leads WHERE first_contact_at IS NULL AND {$slaDateCol} < ?{$slaDelBase} ORDER BY {$slaDateCol} ASC LIMIT 50");
        $alertStmt->execute([$threshold24]);
    } elseif ($hasUltimoCtato) {
        $alertStmt = $pdo->prepare("SELECT {$alertCols} FROM leads WHERE (ultimo_contato IS NULL OR ultimo_contato < ?) AND {$slaDateCol} < ?{$slaDelBase} ORDER BY {$slaDateCol} ASC LIMIT 50");
        $alertStmt->execute([$threshold24, $threshold24]);
    } else {
        $alertStmt = $pdo->prepare("SELECT {$alertCols} FROM leads WHERE {$slaDateCol} < ?{$slaDelBase} ORDER BY {$slaDateCol} ASC LIMIT 50");
        $alertStmt->execute([$threshold24]);
    }
    $slaAlertLeads = $alertStmt->fetchAll(PDO::FETCH_ASSOC);

    // Stale leads (parados >7 dias na mesma etapa) via lead_movements
    try {
        $staleThreshold = (new DateTime())->modify("-{$staleThreshDays} days")->format('Y-m-d H:i:s');
        $staleStmt = $pdo->prepare("
            SELECT l.id, l.name,
                   COALESCE(NULLIF(l.source,''),'Sem origem') AS source,
                   COALESCE(NULLIF(l.status,''),'Sem status') AS status,
                   COALESCE(fs.stage_name, l.status) AS stage_label,
                   l.{$slaDateCol} AS created_at,
                   MAX(lm.created_at) AS last_movement
            FROM leads l
            LEFT JOIN funil_stages fs ON fs.id = l.stage_id
            LEFT JOIN lead_movements lm ON lm.lead_id = l.id
            WHERE l.{$slaDateCol} >= ? AND l.{$slaDateCol} <= ?
              AND (l.status NOT LIKE '%fechado%' AND l.status NOT LIKE '%ganho%' AND l.status NOT LIKE '%perdido%')
              {$slaDelBase}{$srcCond}
            GROUP BY l.id, l.name, l.source, l.status, stage_label, l.{$slaDateCol}
            HAVING (last_movement IS NULL OR last_movement < ?)
            ORDER BY last_movement ASC LIMIT 50
        ");
        $staleStmt->execute(array_merge([$fStartStr, $fEndStr], $srcParams, [$staleThreshold]));
        $staleLeads = $staleStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $staleLeads = []; }
} catch (Exception $e) { /* ignore */ }

// ============================================================
// NEW: Consultant comparison (conversion + lost reasons per user)
// ============================================================
$consultorComparison = [];

try {
    $ccLeadCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'")->fetchAll(PDO::FETCH_COLUMN);
    $ccDateCol  = in_array('data_inicio', $ccLeadCols, true) ? 'data_inicio' : (in_array('created_at', $ccLeadCols, true) ? 'created_at' : 'data_inicio');
    $ccDelBase  = in_array('deleted', $ccLeadCols, true) ? " AND l.deleted = 0" : "";
    $ccHasLost  = in_array('lost_reason', $ccLeadCols, true);
    $ccHasOrc   = in_array('orcamento_value', $ccLeadCols, true);
    $ccHasStage = in_array('stage_id', $ccLeadCols, true);

    $leadOwnerExpr = in_array('user_id_update', $ccLeadCols, true) ? 'COALESCE(l.user_id_update, l.user_id)' : 'l.user_id';

    $creditLostCond = $ccHasLost
        ? "SUM(CASE WHEN LOWER(l.lost_reason) LIKE '%cr%dito%' OR LOWER(l.lost_reason) LIKE '%financiamento%' THEN 1 ELSE 0 END)"
        : "0";
    $avgOrcSel = $ccHasOrc ? ", AVG(CASE WHEN l.orcamento_value > 0 THEN l.orcamento_value ELSE NULL END) AS avg_ticket" : ", NULL AS avg_ticket";

    $stageConversionCondition = $ccHasStage ? "l.stage_id = ? OR " : "";
    $createdLeadsExpr = in_array('user_id', $ccLeadCols, true) ? "SUM(CASE WHEN l.user_id = u.id THEN 1 ELSE 0 END) AS leads_criados" : "0 AS leads_criados";
    $ccSql = "
        SELECT
            u.id, u.username,
            COUNT(DISTINCT l.id) AS total_leads,
            {$createdLeadsExpr},
            SUM(CASE WHEN ({$stageConversionCondition} l.status LIKE '%fechado%' OR l.status LIKE '%ganho%') THEN 1 ELSE 0 END) AS conversoes,
            SUM(CASE WHEN l.status LIKE '%perdido%' OR l.status LIKE '%descartado%' THEN 1 ELSE 0 END) AS perdidos,
            {$creditLostCond} AS credito_negado
            {$avgOrcSel}
        FROM users u
        LEFT JOIN leads l ON {$leadOwnerExpr} = u.id
            AND l.{$ccDateCol} >= ? AND l.{$ccDateCol} <= ?
            {$ccDelBase}{$srcCond}
        GROUP BY u.id, u.username
        HAVING total_leads > 0
        ORDER BY conversoes DESC, total_leads DESC
        LIMIT 20
    ";
    $ccStmt = $pdo->prepare($ccSql);
    $ccFinalStageId = ($ccHasStage && count($stages) > 0) ? end($stages)['id'] : null;

    $ccParams = [];
    if ($ccHasStage) {
        $ccParams[] = $ccFinalStageId;
    }
    $ccParams[] = $fStartStr;
    $ccParams[] = $fEndStr;
    $ccParams = array_merge($ccParams, $srcParams);

    $ccStmt->execute($ccParams);
    $consultorComparison = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $consultorComparison = []; }

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

body.theme-dark .report-card,
body.theme-dark .insight-card,
body.theme-dark .kpi-card {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}
body.theme-dark .kpi-card::before {
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%) !important;
}
body.theme-dark .report-card-title,
body.theme-dark .insight-title,
body.theme-dark .insight-text,
body.theme-dark .insight-badge,
body.theme-dark .source-dropdown-menu,
body.theme-dark .source-dropdown-item,
body.theme-dark .text-muted {
    color: #e6eef8 !important;
}
body.theme-dark .chart-container,
body.theme-dark .funnel-container,
body.theme-dark .table-responsive {
    background: rgba(255,255,255,0.02) !important;
}
body.theme-dark .data-table thead,
body.theme-dark .table thead {
    background: rgba(255,255,255,0.04) !important;
}
body.theme-dark .data-table th,
body.theme-dark .data-table td,
body.theme-dark .table th,
body.theme-dark .table td {
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .insight-card {
    background: rgba(255,255,255,0.03) !important;
}
body.theme-dark .insight-cta .btn-outline,
body.theme-dark .btn-outline-secondary {
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.12) !important;
}
body.theme-dark .insight-badge {
    background: rgba(255,255,255,0.08) !important;
}
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead { background: #f8fafc; }
.data-table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.875rem; border-bottom: 2px solid #e2e8f0; background: transparent !important; }
.data-table td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; color: #1e293b; background: transparent !important; }
.data-table tr:hover { background: #f8fafc; }
body.theme-dark .data-table thead { background: rgba(255,255,255,0.05) !important; }
body.theme-dark .data-table th,
body.theme-dark .data-table td { background: transparent !important; color: #e6eef8 !important; border-color: rgba(255,255,255,0.08) !important; }
body.theme-dark .data-table tbody tr { background: rgba(255,255,255,0.02) !important; }
body.theme-dark .data-table tbody tr:hover,
body.theme-dark .data-table tr:hover { background: rgba(255,255,255,0.06) !important; }
body.theme-dark .data-table tbody tr:nth-of-type(odd) { background: rgba(255,255,255,0.03) !important; }
.badge-stage { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
.pyramid-svg { max-width: 100%; height: auto; display: block; margin: 2rem auto; }
.filter-bar { background: #f8fafc; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
.filter-bar select, .filter-bar input { border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.875rem; }
body.theme-dark .filter-bar { background: rgba(255,255,255,0.05) !important; border: 1px solid rgba(255,255,255,0.08) !important; }
body.theme-dark .filter-bar select,
body.theme-dark .filter-bar input,
body.theme-dark .filter-bar .form-select {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.10) !important;
}
body.theme-dark .filter-bar select:focus,
body.theme-dark .filter-bar input:focus,
body.theme-dark .filter-bar .form-select:focus {
    border-color: var(--primary-500) !important;
    box-shadow: 0 0 0 0.2rem rgba(10,88,168,0.25) !important;
}
body.theme-dark .source-dropdown-btn,
body.theme-dark .source-dropdown-menu,
body.theme-dark .source-dropdown-item,
body.theme-dark .source-dropdown-all,
body.theme-dark .source-dropdown-divider {
    background: transparent !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.10) !important;
}
body.theme-dark .source-dropdown-btn { background: rgba(255,255,255,0.03) !important; border-color: rgba(255,255,255,0.10) !important; }
body.theme-dark .source-dropdown-btn:hover { border-color: rgba(255,255,255,0.18) !important; }
body.theme-dark .source-dropdown-menu { background: #081124 !important; box-shadow: 0 8px 24px rgba(0,0,0,0.45) !important; border-color: rgba(255,255,255,0.10) !important; }
body.theme-dark .source-dropdown-item:hover { background: rgba(255,255,255,0.05) !important; }
body.theme-dark .source-dropdown-item { color: #e6eef8 !important; }
body.theme-dark .source-dropdown-all { color: #e6eef8 !important; }
body.theme-dark .source-dropdown-divider { background: rgba(255,255,255,0.10) !important; }
body.theme-dark .export-btn { background: #2563eb !important; }
/* Source dropdown */
.source-dropdown-btn {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.5rem 1rem;
    font-size: 0.875rem; cursor: pointer; display: flex; align-items: center; gap: 0.25rem;
    white-space: nowrap; min-width: 180px; color: #334155; font-weight: 500;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.source-dropdown-btn:hover { border-color: #94a3b8; }
.source-dropdown-btn:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
.source-dropdown-menu {
    display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 1050;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 220px; max-height: 280px;
    overflow-y: auto; padding: 0.5rem 0;
}
.source-dropdown-menu.show { display: block; }
.source-dropdown-item {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 1rem;
    cursor: pointer; font-size: 0.85rem; color: #334155; transition: background 0.15s;
    margin: 0; font-weight: 400;
}
.source-dropdown-item:hover { background: #f1f5f9; }
.source-dropdown-item input[type="checkbox"] {
    width: 16px; height: 16px; accent-color: #667eea; cursor: pointer; flex-shrink: 0;
}
.source-dropdown-all { font-weight: 600; color: #1e293b; }
.source-dropdown-divider { height: 1px; background: #e2e8f0; margin: 0.25rem 0; }
.export-btn { background: #3b82f6; color: #fff; border: none; padding: 0.5rem 1.5rem; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s; }
.export-btn:hover { background: #2563eb; transform: translateY(-1px); }
.stat-change { font-size: 0.75rem; margin-top: 0.25rem; }
.stat-change.positive { color: #10b981; }
.stat-change.negative { color: #ef4444; }
.funnel-stage { background: #0f172a; color: #f8fafc; border-left: 4px solid; padding: 1rem 1.5rem; margin-bottom: 0.5rem; border-radius: 0 8px 8px 0; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s; box-shadow: 0 4px 16px rgba(0,0,0,0.45); }
.funnel-stage:hover { transform: translateX(4px); box-shadow: 0 8px 24px rgba(0,0,0,0.40); }
.funnel-value { font-size: 1.5rem; font-weight: 700; }
.funnel-percent { font-size: 0.875rem; color: #64748b; }
.funnel-container { padding: 1rem 0; }
.funnel-stage-wrapper { animation: slideInLeft 0.5s ease-out forwards; opacity: 0; }
.funnel-compact .funnel-stage { padding: 0.2rem 0.4rem; margin-bottom: 0.25rem; border-radius: 0 4px 4px 0; }
.funnel-compact .funnel-stage-number { min-width: 24px; font-size: 0.75rem; }
.funnel-compact .funnel-stage .funnel-value { font-size: 0.95rem; }
.funnel-compact .funnel-stage .funnel-percent { font-size: 0.6rem; gap: 0.35rem; }
.funnel-compact .funnel-stage div[style*="font-weight: 600"] { font-size: 0.7rem !important; }
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
body.theme-dark #reportTabs.nav-pills .nav-link { color: #c3d5ea !important; background: rgba(255,255,255,0.03) !important; border-color: rgba(255,255,255,0.08) !important; }
body.theme-dark #reportTabs.nav-pills .nav-link:hover { background: rgba(255,255,255,0.08) !important; color: #e6eef8 !important; border-color: rgba(255,255,255,0.18) !important; }
body.theme-dark #reportTabs.nav-pills .nav-link.active { background: rgba(59,130,246,0.18) !important; color: #fff !important; border-color: rgba(59,130,246,0.28) !important; box-shadow: 0 4px 12px rgba(3,10,18,0.35) !important; }
#reportTabsContent .tab-content { animation: fadeIn 0.4s ease-in; }
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.sla-alert-row-danger { background: #fef2f2 !important; }
.sla-alert-row-warning { background: #fffbeb !important; }
.pareto-bar-container { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.pareto-label { min-width:180px; font-size:0.85rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pareto-bar-wrap { flex:1; background:#f1f5f9; border-radius:6px; height:22px; position:relative; }
.pareto-bar-fill { height:100%; border-radius:6px; display:flex; align-items:center; padding-left:8px; font-size:0.75rem; font-weight:700; color:#fff; transition:width 0.6s ease; }
.pareto-value { min-width:32px; text-align:right; font-weight:700; font-size:0.85rem; color:#374151; }
.kpi-card.red { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }

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
                <div class="source-dropdown-wrap" style="position:relative;">
                    <button type="button" class="source-dropdown-btn" id="sourceDropdownBtn" onclick="toggleSourceDropdown()">
                        <i class="fas fa-filter me-1"></i>
                        <span id="sourceDropdownLabel">Todas as fontes</span>
                        <i class="fas fa-chevron-down ms-2" style="font-size:0.7rem;"></i>
                    </button>
                    <div class="source-dropdown-menu" id="sourceDropdownMenu">
                        <label class="source-dropdown-item source-dropdown-all">
                            <input type="checkbox" id="sourceAll" onchange="toggleAllSources(this)" <?php echo empty($filterSources) ? 'checked' : ''; ?> />
                            <span>Todos</span>
                        </label>
                        <div class="source-dropdown-divider"></div>
                        <?php foreach ($sources as $s): 
                            $val = (string)($s['source'] ?? '');
                            $label = (string)($s['source'] ?? 'Sem origem');
                            $checked = in_array($val, $filterSources, true) ? 'checked' : '';
                        ?>
                        <label class="source-dropdown-item">
                            <input type="checkbox" name="sources[]" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $checked; ?> onchange="updateSourceLabel()" />
                            <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="margin-left:auto; display:flex; gap:0.5rem;">
                    <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
                    <a class="btn btn-sm btn-outline-secondary" href="relatorios.php">Limpar</a>
                </div>
            </form>

            <!-- Last 24h summary -->
            <?php
                // compute 24h per-source for the summary bar
                $last24BySrc = [];
                foreach ($last24Leads as $lr) {
                    $src = (string)($lr['source'] ?? 'Sem origem');
                    if (!isset($last24BySrc[$src])) $last24BySrc[$src] = 0;
                    $last24BySrc[$src]++;
                }
                arsort($last24BySrc);
                $last24BySrcTop = array_slice($last24BySrc, 0, 5, true);
                // SLA alert count visible from 24h data
                $slaAlertCountDisplay = count($slaAlertLeads ?? []);
            ?>
            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <div class="report-card">
                        <div class="report-card-title"><i class="fa fa-clock"></i> Últimas 24 horas</div>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div>
                                <div class="small text-muted">Chegaram</div>
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
                            <?php if ($slaAlertCountDisplay > 0): ?>
                            <div>
                                <div class="small text-muted">Sem contato &gt;24h</div>
                                <div class="h5 mb-0 text-danger"><?php echo $slaAlertCountDisplay; ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($last24BySrcTop)): ?>
                        <div class="mb-2">
                            <div class="small text-muted mb-1">Por fonte (top 5):</div>
                            <?php foreach ($last24BySrcTop as $srcLbl => $srcCnt): ?>
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span class="text-truncate" style="max-width:130px;"><?php echo htmlspecialchars($srcLbl, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="badge bg-secondary"><?php echo (int)$srcCnt; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:8px; height:150px;" class="chart-container">
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

            <!-- Top movers (lead movements) -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="report-card">
                        <div class="report-card-title"><i class="fa fa-exchange-alt"></i> Top movimentações por consultor (período)</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Consultor</th>
                                        <th style="width:140px;">Movimentações</th>
                                        <th style="width:140px;">Data Retomada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $maxRows = max(count($movementsByUser), count($dateUpdatesByUser));
                                        if ($maxRows === 0) {
                                            echo '<tr><td colspan="3" class="text-center text-muted py-4">Nenhuma movimentação registrada no período.</td></tr>';
                                        } else {
                                            for ($i=0;$i<$maxRows;$i++) {
                                                $mv = $movementsByUser[$i] ?? null;
                                                $du = $dateUpdatesByUser[$i] ?? null;
                                                $name = $mv['username'] ?? $du['username'] ?? '(desconhecido)';
                                                $mvCnt = isset($mv['cnt']) ? (int)$mv['cnt'] : 0;
                                                $duCnt = isset($du['cnt']) ? (int)$du['cnt'] : 0;
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
                                                echo '<td>' . $mvCnt . '</td>';
                                                echo '<td>' . $duCnt . '</td>';
                                                echo '</tr>';
                                            }
                                        }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-pills mb-4" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='overview'?'active':''; ?>" id="overview-tab" data-tab="overview" data-bs-toggle="pill" data-bs-target="#overview" type="button" role="tab">
                        <i class="fa fa-home"></i> Visão Geral
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='funnel'?'active':''; ?>" id="funnel-tab" data-tab="funnel" data-bs-toggle="pill" data-bs-target="#funnel" type="button" role="tab">
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
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='qualificacao'?'active':''; ?>" id="qualificacao-tab" data-tab="qualificacao" data-bs-toggle="pill" data-bs-target="#qualificacao" type="button" role="tab">
                        <i class="fa fa-filter"></i> SQL
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='sla'?'active':''; ?>" id="sla-tab" data-tab="sla" data-bs-toggle="pill" data-bs-target="#sla" type="button" role="tab">
                        <i class="fa fa-stopwatch"></i> Speed-to-Lead
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab==='financeiro'?'active':''; ?>" id="financeiro-tab" data-bs-toggle="pill" data-bs-target="#financeiro" type="button" role="tab">
                        <i class="fa fa-dollar-sign"></i> Financeiro
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
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="report-card-title"><i class="fa fa-filter"></i> Funil de Vendas Completo</div>
                                    <button id="btnCompactFunnel" class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleCompactFunnel()">Compactar Funil</button>
                                </div>
                                <div id="chartFunnel" class="funnel-container"></div>
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

                        <!-- Automated Insights -->
                        <div class="col-12 mt-3">
                            <style>
                            /* Ultra-modern insights cards */
                            .insights-grid { display:flex; gap:12px; flex-wrap:wrap; }
                            .insight-card { flex:1 1 280px; background:linear-gradient(180deg,#ffffffcc,#f7fbff); border-radius:12px; padding:14px; box-shadow:0 6px 18px rgba(14,30,60,0.08); display:flex; gap:12px; align-items:flex-start; border-left:6px solid transparent; transition:transform .18s ease, box-shadow .18s ease; }
                            .insight-card:hover { transform:translateY(-6px); box-shadow:0 12px 30px rgba(14,30,60,0.12); }
                            .insight-icon { width:44px; height:44px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-weight:700; flex-shrink:0; }
                            .insight-body { flex:1; }
                            .insight-title { font-weight:700; margin:0 0 6px 0; color:#0f172a; font-size:0.95rem; white-space:normal; word-break:break-word; }
                            .insight-text { margin:0; color:#475569; font-size:0.875rem; line-height:1.3; white-space:normal; word-break:break-word; }
                            .insight-meta { margin-top:8px; display:flex; gap:8px; align-items:center; }
                            .insight-badge { font-size:0.75rem; padding:6px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; }
                            .insight-cta { margin-left:auto; display:flex; gap:8px; }
                            .insight-cta button { border:0; padding:6px 10px; border-radius:8px; font-size:0.8rem; cursor:pointer; }
                            .insight-cta .btn-primary { background:#2563eb; color:#fff; }
                            .insight-cta .btn-outline { background:transparent; border:1px solid #e6eef7; color:#0f172a; }
                            .insight-high { border-left-color: #ef4444; }
                            .insight-medium { border-left-color: #f59e0b; }
                            .insight-low { border-left-color: #10b981; }
                            .insight-icon.high { background:linear-gradient(135deg,#ef4444,#f97316); }
                            .insight-icon.medium { background:linear-gradient(135deg,#f59e0b,#fb923c); }
                            .insight-icon.low { background:linear-gradient(135deg,#10b981,#34d399); }
                            @media (max-width:800px){ .insight-card { flex:1 1 100%; } }
                            </style>
                            <div class="report-card">
                                <div class="report-card-title"><i class="fa fa-lightbulb"></i> Insights automáticos (Análise Temporal)</div>
                                <div id="temporalInsights" style="min-height:120px;">
                                    <div id="temporalInsightsGrid" class="insights-grid">
                                        <?php if (!empty($temporalInsights) && is_array($temporalInsights)): ?>
                                            <?php foreach ($temporalInsights as $ti): ?>
                                                <div class="insight-card insight-low">
                                                    <div class="insight-icon low">✅</div>
                                                    <div class="insight-body">
                                                        <div class="insight-title"><?php echo htmlspecialchars($ti, ENT_QUOTES, 'UTF-8'); ?></div>
                                                        <div class="insight-text"><?php echo htmlspecialchars($ti, ENT_QUOTES, 'UTF-8'); ?></div>
                                                        <div class="insight-meta"><div class="insight-badge">Info</div></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="margin-top:12px;">
                                    <strong>Atividade por usuário (últimos 90 dias)</strong>
                                    <div id="temporalActivityByUser" style="margin-top:8px;"></div>
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
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="chartConsultorComparison"></canvas>
                                </div>
                                <div id="tableConsultorComparison" style="margin-top: 1rem;"></div>
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

            <!-- /daily tab-pane -->

            <!-- ===================================================
                 TAB: Qualificação (MQL → SQL)
                 =================================================== -->
            <div class="tab-pane fade <?php echo $activeTab==='qualificacao'?'show active':''; ?>" id="qualificacao" role="tabpanel">
                <!-- KPIs de qualificação -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value"><?php echo (int)$totalMql; ?></div>
                            <div class="kpi-label">Total MQL (leads recebidos)</div>
                            <i class="fa fa-inbox kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card green">
                            <div class="kpi-value"><?php echo (int)$totalSql; ?></div>
                            <div class="kpi-label">SQL (leads qualificados)</div>
                            <i class="fa fa-check-double kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card <?php echo ($sqlRate !== null && $sqlRate < 30) ? 'orange' : 'blue'; ?>">
                            <div class="kpi-value"><?php echo $sqlRate !== null ? $sqlRate . '%' : '—'; ?></div>
                            <div class="kpi-label">Taxa de SQL (MQL→SQL) <?php echo ($sqlRate !== null && $sqlRate < 30) ? ' ⚠ Baixa' : ''; ?></div>
                            <i class="fa fa-percentage kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                            <div class="kpi-value"><?php echo $totalMql > $totalSql ? (int)($totalMql - $totalSql) : 0; ?></div>
                            <div class="kpi-label">Leads descartados/não qualificados</div>
                            <i class="fa fa-ban kpi-icon"></i>
                        </div>
                    </div>
                </div>

                <?php if ($sqlRate !== null && $sqlRate < 30): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="fa fa-exclamation-triangle fa-lg"></i>
                    <div><strong>Alerta de Qualidade:</strong> Taxa de SQL abaixo de 30% — o marketing pode estar atraindo perfis errados (conta de luz baixa, inquilinos, sem telhado adequado). Revise os públicos dos anúncios e canais de aquisição.</div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-chart-bar"></i> Pareto — Motivos de Descarte</div>
                            <div id="chartDisqualReasons" style="height:320px; position:relative;"></div>
                            <canvas id="canvasDisqualReasons" style="display:none; height:320px; position:relative;"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-bullseye"></i> Lead "Sujo" por Canal — Desqualificações</div>
                            <div class="chart-container" style="height:320px;">
                                <canvas id="chartDisqualBySource"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-times-circle"></i> Motivos de Perda no Fechamento</div>
                            <div class="chart-container" style="height:320px;">
                                <canvas id="chartLostReasons"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-table"></i> Detalhamento de Desqualificações por Canal</div>
                            <div id="tableDisqualBySource"></div>
                        </div>
                    </div>
                </div>
            </div><!-- /qualificacao -->

            <!-- ===================================================
                 TAB: SLA / Speed-to-Lead
                 =================================================== -->
            <div class="tab-pane fade <?php echo $activeTab==='sla'?'show active':''; ?>" id="sla" role="tabpanel">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="kpi-card <?php echo ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? 'orange' : 'blue'; ?>">
                            <div class="kpi-value"><?php echo $speedToLeadAvg !== null ? $speedToLeadAvg . 'h' : '—'; ?></div>
                            <div class="kpi-label">Speed-to-Lead (média)<?php echo ($speedToLeadAvg !== null && $speedToLeadAvg > 24) ? ' ⚠ Acima de 24h' : ''; ?></div>
                            <i class="fa fa-bolt kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="kpi-card orange">
                            <div class="kpi-value"><?php echo count($slaAlertLeads); ?></div>
                            <div class="kpi-label">Leads sem contato >24h</div>
                            <i class="fa fa-exclamation-circle kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="kpi-card" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);">
                            <div class="kpi-value"><?php echo count($staleLeads); ?></div>
                            <div class="kpi-label">Leads parados >7 dias</div>
                            <i class="fa fa-clock kpi-icon"></i>
                        </div>
                    </div>
                </div>

                <?php if ($speedToLeadAvg !== null && $speedToLeadAvg > 24): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="fa fa-bolt fa-lg"></i>
                    <div><strong>Speed-to-Lead crítico:</strong> Média de <?php echo $speedToLeadAvg; ?>h para primeiro contato. Leads contactados em menos de 5 minutos convertem até 21× mais. Implemente alertas automáticos para a equipe ao receber novos leads.</div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-exclamation-triangle" style="color:#ef4444;"></i> Leads sem contato há mais de 24h <span class="badge bg-danger ms-2"><?php echo count($slaAlertLeads); ?></span></div>
                            <div class="table-responsive" style="max-height:340px; overflow:auto;">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nome</th>
                                            <th>Fonte</th>
                                            <th>Status</th>
                                            <th>Criado em</th>
                                            <th>Espera</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($slaAlertLeads)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fa fa-check-circle text-success"></i> Nenhum lead sem contato nas últimas 24h.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($slaAlertLeads as $sl): ?>
                                            <?php
                                                $createdRaw = $sl['created_at'] ?? null;
                                                $createdDt = null;
                                                try { if ($createdRaw) $createdDt = new DateTime((string)$createdRaw); } catch (Exception $e) {}
                                                $hoursWaiting = $createdDt ? round((new DateTime())->getTimestamp() - $createdDt->getTimestamp()) / 3600 : null;
                                                if ($hoursWaiting === null) {
                                                    $waitLabel = '—';
                                                } else {
                                                    $daysWaiting = $hoursWaiting / 24;
                                                    if ($hoursWaiting < 24) {
                                                        $waitLabel = round($hoursWaiting) . 'h';
                                                    } elseif ($daysWaiting < 365) {
                                                        $waitLabel = round($daysWaiting) . ' dia' . (round($daysWaiting) === 1 ? '' : 's');
                                                    } else {
                                                        $yearsWaiting = $daysWaiting / 365;
                                                        $waitLabel = round($yearsWaiting, 1) . ' ano' . (round($yearsWaiting, 1) === 1.0 ? '' : 's');
                                                    }
                                                }
                                                $rowClass = ($hoursWaiting !== null && $hoursWaiting > 48) ? 'table-danger' : 'table-warning';
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td><?php echo (int)($sl['id'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars((string)($sl['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($sl['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($sl['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo $createdDt ? $createdDt->format('d/m/Y H:i') : htmlspecialchars((string)($createdRaw ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><strong style="color:<?php echo ($hoursWaiting !== null && $hoursWaiting > 48) ? '#ef4444' : '#f59e0b'; ?>"><?php echo $waitLabel; ?></strong></td>
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
                    <div class="col-12">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-pause-circle" style="color:#7c3aed;"></i> Leads parados >7 dias na mesma etapa <span class="badge ms-2" style="background:#7c3aed;"><?php echo count($staleLeads); ?></span></div>
                            <p class="small text-muted mb-3">Leads nesta lista perderam o timing. Agir agora pode recuperar até 20% dessas oportunidades.</p>
                            <div class="table-responsive" style="max-height:340px; overflow:auto;">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nome</th>
                                            <th>Fonte</th>
                                            <th>Etapa</th>
                                            <th>Criado em</th>
                                            <th>Último Movimento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($staleLeads)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fa fa-check-circle text-success"></i> Nenhum lead parado encontrado.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($staleLeads as $st): ?>
                                            <?php
                                                $lastMov = $st['last_movement'] ?? null;
                                                $lastMovDt = null;
                                                try { if ($lastMov) $lastMovDt = new DateTime((string)$lastMov); } catch (Exception $e) {}
                                                $creRaw = $st['created_at'] ?? null;
                                                $creDt = null;
                                                try { if ($creRaw) $creDt = new DateTime((string)$creRaw); } catch (Exception $e) {}
                                                $daysSince = $lastMovDt ? round((new DateTime())->getTimestamp() - $lastMovDt->getTimestamp()) / 86400 : null;
                                            ?>
                                            <tr class="<?php echo ($daysSince !== null && $daysSince > 14) ? 'table-danger' : 'table-warning'; ?>">
                                                <td><?php echo (int)($st['id'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars((string)($st['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($st['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($st['stage_label'] ?? $st['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo $creDt ? $creDt->format('d/m/Y H:i') : '—'; ?></td>
                                                <td>
                                                    <?php if ($lastMovDt): ?>
                                                        <strong><?php echo $lastMovDt->format('d/m/Y H:i'); ?></strong><br>
                                                        <small class="text-muted">(<?php echo round($daysSince); ?> dias atrás)</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Sem movimentos</span>
                                                    <?php endif; ?>
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

            </div><!-- /sla -->

            <!-- ===================================================
                 TAB: Financeiro
                 =================================================== -->
            <div class="tab-pane fade <?php echo $activeTab==='financeiro'?'show active':''; ?>" id="financeiro" role="tabpanel">
                <?php if (!$financeDataAvailable): ?>
                <div class="alert alert-info">Nenhum dado financeiro disponível para o período selecionado. Verifique se leads possuem valores (orcamento, kWp), forma de pagamento e motivo de perda preenchidos.</div>
                <?php endif; ?>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="kpi-card blue">
                            <div class="kpi-value"><?php echo $avgKwp !== null ? number_format($avgKwp, 1, ',', '.') . ' kWp' : '—'; ?></div>
                            <div class="kpi-label">Tamanho Médio do Sistema</div>
                            <i class="fa fa-solar-panel kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card green">
                            <div class="kpi-value"><?php echo $avgTicketKwp !== null ? 'R$' . number_format($avgTicketKwp, 0, ',', '.') . '/kWp' : '—'; ?></div>
                            <div class="kpi-label">Ticket Médio por kWp</div>
                            <i class="fa fa-tags kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value"><?php echo $avgTicket !== null ? 'R$' . number_format($avgTicket, 0, ',', '.') : '—'; ?></div>
                            <div class="kpi-label">Ticket Médio Geral</div>
                            <i class="fa fa-dollar-sign kpi-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card orange">
                            <div class="kpi-value"><?php echo $avgDaysToClose !== null ? $avgDaysToClose . 'd' : '—'; ?></div>
                            <div class="kpi-label">Ciclo Médio de Venda</div>
                            <i class="fa fa-hourglass kpi-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-lg-5">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-chart-pie"></i> Distribuição: À Vista vs Financiado</div>
                            <div class="chart-container" style="height:300px;">
                                <canvas id="chartPaymentDist"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-table"></i> Motivos de Perda no Fechamento</div>
                            <div id="tableLostReasons"></div>
                            <div class="chart-container mt-3" style="height:220px;">
                                <canvas id="chartLostReasonsFinanceiro"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="report-card">
                            <div class="report-card-title"><i class="fa fa-chart-bar"></i> Evolução Financeira — Receita por Mês</div>
                            <div class="chart-container" style="height:320px;">
                                <canvas id="chartRevenueMonthly"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- /financeiro -->

            </div><!-- /.tab-content#reportTabsContent -->

        </div><!-- /.container-fluid -->
    </main>
</div>

<!-- Chart.js -->
<script src="assets/js/chart.min.js"></script>
<!-- html2pdf (bundles html2canvas + jsPDF) for accurate PDF export of the on-screen report -->
<script src="assets/js/html2pdf.bundle.min.js"></script>
<script>
// ── Source dropdown logic ──
function toggleSourceDropdown() {
    document.getElementById('sourceDropdownMenu').classList.toggle('show');
}
function toggleAllSources(el) {
    var cbs = document.querySelectorAll('#sourceDropdownMenu input[name="sources[]"]');
    if (el.checked) {
        cbs.forEach(function(cb){ cb.checked = false; });
    }
    updateSourceLabel();
}

function persistTabOnClick() {
    document.querySelectorAll('#reportTabs .nav-link[data-tab]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var selectedTab = this.getAttribute('data-tab');
            if (!selectedTab) return;
            var url = new URL(window.location);
            url.searchParams.set('tab', selectedTab);
            window.history.replaceState({}, '', url.toString());
        });
    });
}

document.addEventListener('DOMContentLoaded', function(){
    updateSourceLabel();
    persistTabOnClick();
});
function updateSourceLabel() {
    var cbs = document.querySelectorAll('#sourceDropdownMenu input[name="sources[]"]');
    var checked = Array.from(cbs).filter(function(cb){ return cb.checked; });
    var allCb = document.getElementById('sourceAll');
    var label = document.getElementById('sourceDropdownLabel');
    if (checked.length === 0) {
        allCb.checked = true;
        label.textContent = 'Todas as fontes';
    } else {
        allCb.checked = false;
        if (checked.length === 1) {
            label.textContent = checked[0].parentElement.querySelector('span').textContent;
        } else {
            label.textContent = checked.length + ' fontes selecionadas';
        }
    }
}
// Close dropdown on outside click
document.addEventListener('click', function(e) {
    var wrap = document.querySelector('.source-dropdown-wrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('sourceDropdownMenu').classList.remove('show');
    }
});
// Init label on page load
document.addEventListener('DOMContentLoaded', function(){ updateSourceLabel(); });

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
const REPORT_TIME_DISTRIBUTION = <?php echo json_encode($timeDistribution, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TRENDS_SERIES = <?php echo json_encode($trendSeries, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
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
// Temporal analysis results
const REPORT_TEMPORAL_INSIGHTS = <?php echo json_encode($temporalInsights ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_ACTIVITY_BY_USER = <?php echo json_encode($activityByUser ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_CONVERSION_BY_SOURCE = <?php echo json_encode($conversionBySource ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_ACTIVITIES_PER_LEAD = <?php echo json_encode($avgActivitiesPerLead ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_STAGE_DROPOFFS = <?php echo json_encode($stageDropoffs ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

// Qualification module
const REPORT_SQL_RATE = <?php echo json_encode($sqlRate, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TOTAL_MQL = <?php echo json_encode($totalMql ?? 0, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TOTAL_SQL = <?php echo json_encode($totalSql ?? 0, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DISQUAL_REASONS = <?php echo json_encode($disqualReasons ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_DISQUAL_BY_SOURCE = <?php echo json_encode($disqualBySource ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LOST_REASONS = <?php echo json_encode($lostReasons ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_PAYMENT_DIST = <?php echo json_encode($paymentDistribution ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_KWP = <?php echo json_encode($avgKwp, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_TICKET_KWP = <?php echo json_encode($avgTicketKwp, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
// SLA / Speed-to-Lead
const REPORT_SPEED_TO_LEAD_AVG = <?php echo json_encode($speedToLeadAvg, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_SLA_ALERT_LEADS = <?php echo json_encode($slaAlertLeads ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_STALE_LEADS = <?php echo json_encode($staleLeads ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
// Consultant comparison
const REPORT_CONSULTOR_COMPARISON = <?php echo json_encode($consultorComparison ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;


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

function buildSeriesMonths(series) {
    const rows = Array.isArray(series) ? series : [];
    const labels = rows.map(r => r.ym || '');
    const values = rows.map(r => Number(r.cnt) || 0);
    return { labels, values };
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

function isDarkThemeActive() {
    return document.body.classList.contains('theme-dark') || document.documentElement.getAttribute('data-theme') === 'dark';
}

function applyChartThemeDefaults() {
    if (typeof Chart === 'undefined') return;
    const dark = isDarkThemeActive();
    const textColor = dark ? '#e6eef8' : '#334155';
    const gridColor = dark ? 'rgba(230,238,248,0.14)' : 'rgba(15,23,42,0.12)';
    Chart.defaults.color = textColor;
    Chart.defaults.borderColor = gridColor;
    if (Chart.defaults.plugins && Chart.defaults.plugins.legend && Chart.defaults.plugins.legend.labels) {
        Chart.defaults.plugins.legend.labels.color = textColor;
    }
    if (Chart.defaults.plugins && Chart.defaults.plugins.tooltip) {
        Chart.defaults.plugins.tooltip.backgroundColor = dark ? 'rgba(7,20,39,0.96)' : 'rgba(255,255,255,0.98)';
        Chart.defaults.plugins.tooltip.titleColor = textColor;
        Chart.defaults.plugins.tooltip.bodyColor = textColor;
        Chart.defaults.plugins.tooltip.borderColor = gridColor;
        Chart.defaults.plugins.tooltip.borderWidth = 1;
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
    const createdSeries = buildSeriesMonths(REPORT_MONTHS);
    const closedSeries = buildSeriesMonths(REPORT_MONTHS_CLOSED);
    const labels = createdSeries.labels.length > 0 ? createdSeries.labels : closedSeries.labels;
    const createdData = labels.map((m, i) => createdSeries.values[i] || 0);
    const closedData = labels.map((m, i) => closedSeries.values[i] || 0);
    const maxValue = Math.max(...createdData, ...closedData, 1);
    const desiredLines = 12;
    const stepSize = Math.max(1, Math.ceil(maxValue / desiredLines));
    const maxTick = Math.ceil(maxValue / stepSize) * stepSize;

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
                    fill:false,
                    tension: 0.4,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor:'#10b981'
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
                    callbacks: {
                        label: function(context) {
                            const index = context.dataIndex;
                            const actual = context.dataset.label === 'Leads Criados'
                                ? createdData[index]
                                : closedData[index];
                            return `${context.dataset.label}: ${actual}`;
                        }
                    }
                }
            }, 
            scales:{ 
                y:{
                    beginAtZero:true,
                    max: maxTick,
                    grace: '5%',
                    ticks: {
                        precision: 0,
                        stepSize: stepSize,
                        maxTicksLimit: desiredLines,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : value.toFixed(0);
                        }
                    }
                }
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
    const createdSeries = buildSeriesMonths(REPORT_MONTHS);
    const closedSeries = buildSeriesMonths(REPORT_MONTHS_CLOSED);
    const labels = createdSeries.labels.length > 0 ? createdSeries.labels : closedSeries.labels;
    const createdData = labels.map((m, i) => createdSeries.values[i] || 0);
    const closedData = labels.map((m, i) => closedSeries.values[i] || 0);
    
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
    const labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const weekData = labels.map((_, idx) => Number(REPORT_TIME_DISTRIBUTION[idx + 1] || 0));
    
    chartInstances['chartTimeDistribution'] = new Chart(ctx, { 
        type:'line', 
        data:{ 
            labels: labels, 
            datasets:[{ 
                label:'Leads por dia da semana', 
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

    const rows = Array.isArray(REPORT_TRENDS_SERIES) ? REPORT_TRENDS_SERIES : [];
    const labels = rows.map(r => r.ym || '');
    const trendData = rows.map(r => Number(r.cnt) || 0);

    chartInstances['chartTrends'] = new Chart(ctx, { 
        type:'line', 
        data:{ 
            labels, 
            datasets:[{ 
                label:'Leads no período', 
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

function renderTemporalInsights() {
    const grid = document.getElementById('temporalInsightsGrid');
    const insights = Array.isArray(REPORT_TEMPORAL_INSIGHTS) ? REPORT_TEMPORAL_INSIGHTS : [];
    if (!grid) return;
    // If server already rendered fallback and there are no client-side insights, keep the server markup
    if (!insights || insights.length === 0) return;
    grid.innerHTML = '';

    function detectSeverity(text){
        if (!text) return 'low';
        const t = String(text).toLowerCase();
        if (/grande perda|perda|alto tempo|alto|muito baixo|muito pouco/.test(t)) return 'high';
        if (/poucas|baixa|média|muito|reduzido/.test(t)) return 'medium';
        return 'low';
    }

    insights.forEach((ins, idx) => {
        const sev = detectSeverity(ins);
        const card = document.createElement('div');
        card.className = `insight-card insight-${sev}`;
        const icon = document.createElement('div');
        icon.className = `insight-icon ${sev}`;
        icon.innerHTML = sev === 'high' ? '⚠' : (sev === 'medium' ? '💡' : '✅');
        const body = document.createElement('div');
        body.className = 'insight-body';
        const title = document.createElement('div');
        title.className = 'insight-title';
        title.textContent = ins;
        const meta = document.createElement('div');
        meta.className = 'insight-meta';
        const badge = document.createElement('div');
        badge.className = 'insight-badge';
        badge.textContent = sev === 'high' ? 'Alto' : sev === 'medium' ? 'Médio' : 'Baixo';
        meta.appendChild(badge);
        body.appendChild(title); body.appendChild(meta);
        card.appendChild(icon); card.appendChild(body);
        grid.appendChild(card);
    });

    // Activity by user small table (kept compact)
    const abContainer = document.getElementById('temporalActivityByUser');
    if (!abContainer) return;
    const rows = Array.isArray(REPORT_ACTIVITY_BY_USER) ? REPORT_ACTIVITY_BY_USER : [];
    let html = '<div style="overflow:auto"><table class="data-table"><thead><tr><th>Usuário</th><th>Atividades</th></tr></thead><tbody>';
    rows.forEach(r => {
        html += `<tr><td>${escapeHtml(r.username||'(desconhecido)')}</td><td>${formatNumber(r.cnt||0)}</td></tr>`;
    });
    if (rows.length === 0) html += '<tr><td colspan="2" class="text-center text-muted py-3">Nenhuma atividade registrada</td></tr>';
    html += '</tbody></table></div>';
    abContainer.innerHTML = html;
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
        // Width com escala de 30% (mínimo) a 100% (máximo) para manter o texto dentro da barra
        // e ainda diferenciar claramente os valores.
        let width = 30;
        if (p.value > 0 && maxValue > 0) {
            const proportion = p.value / maxValue;
            width = Math.max(30, 20 + (proportion * 80)); // De 30% a 100%
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
                        background: linear-gradient(90deg, ${p.color}35 0%, ${p.color}22 100%);
                        box-shadow: 0 3px 12px rgba(0,0,0,0.35);
                        margin: 0;
                        color: #f8fafc;
                    ">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; font-size: 1rem; color: ${p.color || '#f8fafc'}; margin-bottom: 0.25rem;">
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

    // Toggle compact mode class state (keeps layout after render)
    const funnelWrap = document.getElementById('chartFunnel');
    if (funnelWrap && funnelWrap.classList.contains('funnel-compact')) {
        funnelWrap.classList.add('funnel-compact');
    }
}

let isFunnelCompact = false;
function toggleCompactFunnel() {
    const container = document.getElementById('chartFunnel');
    const btn = document.getElementById('btnCompactFunnel');
    if (!container || !btn) return;

    isFunnelCompact = !isFunnelCompact;
    container.classList.toggle('funnel-compact', isFunnelCompact);
    btn.textContent = isFunnelCompact ? 'Expandir Funil' : 'Compactar Funil';
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

// ============================================================
// Qualification module charts & tables
// ============================================================
function renderQualificationCharts() {
    // Pareto: disqualification reasons
    const dqEl = document.getElementById('chartDisqualReasons');
    if (dqEl && Array.isArray(REPORT_DISQUAL_REASONS) && REPORT_DISQUAL_REASONS.length > 0) {
        const total = REPORT_DISQUAL_REASONS.reduce((s, r) => s + Number(r.cnt || 0), 0);
        let cumPct = 0;
        let html = '';
        REPORT_DISQUAL_REASONS.forEach((r, i) => {
            const cnt = Number(r.cnt || 0);
            const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            cumPct += pct;
            const hue = 220 - Math.round(i * 18);
            html += `<div class="pareto-bar-container">
                <div class="pareto-label" title="${escapeHtml(r.reason)}">${escapeHtml(r.reason)}</div>
                <div class="pareto-bar-wrap">
                    <div class="pareto-bar-fill" style="width:${pct}%; background:hsl(${hue},72%,52%);">${pct}%</div>
                </div>
                <div class="pareto-value">${formatNumber(cnt)}</div>
            </div>`;
        });
        dqEl.innerHTML = `<div style="padding:8px 0;">${html}</div>`;
    } else if (dqEl) {
        dqEl.innerHTML = '<p class="text-muted text-center py-5">Nenhum motivo de descarte registrado no período.<br><small>Ative o campo "Motivo de Desqualificação" ao mover leads para descarte.</small></p>';
    }

    // Disqual by source: stacked/grouped bar
    const dsEl = document.getElementById('chartDisqualBySource');
    if (dsEl) {
        destroyChart('chartDisqualBySource');
        const rows = Array.isArray(REPORT_DISQUAL_BY_SOURCE) ? REPORT_DISQUAL_BY_SOURCE : [];
        if (rows.length > 0) {
            const labels = rows.map(r => r.source || 'Sem origem');
            const totals = rows.map(r => Number(r.total || 0));
            const disqualified = rows.map(r => Number(r.disqualified || 0));
            const qualified = totals.map((t, i) => Math.max(0, t - disqualified[i]));
            chartInstances['chartDisqualBySource'] = new Chart(dsEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Qualificados', data: qualified, backgroundColor: 'rgba(16,185,129,0.75)', borderRadius: 4 },
                        { label: 'Desqualificados', data: disqualified, backgroundColor: 'rgba(239,68,68,0.75)', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' }, tooltip: { mode: 'index' } },
                    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        } else {
            dsEl.parentElement.innerHTML = '<p class="text-muted text-center py-5">Sem dados de desqualificação por canal no período.</p>';
        }
    }

    // Lost reasons horizontal bar
    const lrEl = document.getElementById('chartLostReasons');
    if (lrEl) {
        destroyChart('chartLostReasons');
        const rows = Array.isArray(REPORT_LOST_REASONS) ? REPORT_LOST_REASONS : [];
        if (rows.length > 0) {
            const labels = rows.map(r => r.reason || 'Não informado');
            const data = rows.map(r => Number(r.cnt || 0));
            chartInstances['chartLostReasons'] = new Chart(lrEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ label: 'Perdas', data, backgroundColor: labels.map((_, i) => defaultPalette(i)), borderRadius: 6, borderWidth: 0 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        } else {
            lrEl.parentElement.innerHTML = '<p class="text-muted text-center py-5">Nenhum motivo de perda registrado no período.<br><small>Cadastre "Motivo de Perda" ao descartar/perder leads.</small></p>';
        }
    }

    // Disqual-by-source table
    const tbl = document.getElementById('tableDisqualBySource');
    if (tbl) {
        const rows = Array.isArray(REPORT_DISQUAL_BY_SOURCE) ? REPORT_DISQUAL_BY_SOURCE : [];
        let html = '<div class="table-responsive"><table class="data-table"><thead><tr><th>Canal / Fonte</th><th>Total</th><th>Desqualificados</th><th>% Sujo</th><th>Alerta</th></tr></thead><tbody>';
        rows.forEach(r => {
            const total = Number(r.total || 0);
            const dq = Number(r.disqualified || 0);
            const pct = total > 0 ? ((dq / total) * 100).toFixed(1) : 0;
            const isAlert = Number(pct) >= 40;
            html += `<tr>
                <td><strong>${escapeHtml(r.source || 'Sem origem')}</strong></td>
                <td>${formatNumber(total)}</td>
                <td style="color:#ef4444;font-weight:600;">${formatNumber(dq)}</td>
                <td><span style="color:${Number(pct)>=40?'#ef4444':Number(pct)>=20?'#f59e0b':'#10b981'};font-weight:700;">${pct}%</span></td>
                <td>${isAlert ? '<span class="badge bg-danger">Cortar verba</span>' : '<span class="badge bg-success">OK</span>'}</td>
            </tr>`;
        });
        if (rows.length === 0) html += '<tr><td colspan="5" class="text-center text-muted py-4">Sem dados disponíveis.</td></tr>';
        html += '</tbody></table></div>';
        tbl.innerHTML = html;
    }
}

// ============================================================
// SLA / Speed-to-Lead charts
// ============================================================
function renderSLACharts() {
    // Consultant comparison chart
    const ccEl = document.getElementById('chartConsultorComparison');
    if (ccEl) {
        destroyChart('chartConsultorComparison');
        const rows = Array.isArray(REPORT_CONSULTOR_COMPARISON) ? REPORT_CONSULTOR_COMPARISON : [];
        if (rows.length > 0) {
            const labels = rows.map(r => r.username || 'Usuário');
            const conversoes = rows.map(r => Number(r.conversoes || 0));
            const perdidos = rows.map(r => Number(r.perdidos || 0));
            const creditoNegado = rows.map(r => Number(r.credito_negado || 0));
            const totalLeads = rows.map(r => Number(r.total_leads || 0));
            chartInstances['chartConsultorComparison'] = new Chart(ccEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Total Leads', data: totalLeads, backgroundColor: 'rgba(148,163,184,0.5)', borderRadius: 4 },
                        { label: 'Conversões', data: conversoes, backgroundColor: 'rgba(16,185,129,0.85)', borderRadius: 4 },
                        { label: 'Perdidos', data: perdidos, backgroundColor: 'rgba(239,68,68,0.75)', borderRadius: 4 },
                        { label: 'Crédito Negado', data: creditoNegado, backgroundColor: 'rgba(245,158,11,0.85)', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        } else {
            ccEl.parentElement.innerHTML = '<p class="text-muted text-center py-5">Sem dados de consultores no período.</p>';
        }
    }

    // Consultant table
    const tbl = document.getElementById('tableConsultorComparison');
    if (tbl) {
        const rows = Array.isArray(REPORT_CONSULTOR_COMPARISON) ? REPORT_CONSULTOR_COMPARISON : [];
        const teamAvgConv = rows.length > 0 ? rows.reduce((s, r) => s + Number(r.conversoes || 0), 0) / rows.reduce((s, r) => s + Number(r.total_leads || 0), 1) * 100 : 0;
        let html = '<div class="table-responsive"><table class="data-table"><thead><tr><th>Consultor</th><th>Leads</th><th>Criados</th><th>Conversões</th><th>Taxa Conv.</th><th>Perdidos</th><th>Cred. Negado</th><th>Ticket Médio</th><th>vs Equipe</th></tr></thead><tbody>';
        rows.forEach((r, idx) => {
            const total = Number(r.total_leads || 0);
            const created = Number(r.leads_criados || 0);
            const conv = Number(r.conversoes || 0);
            const taxa = total > 0 ? ((conv / total) * 100).toFixed(1) : 0;
            const diff = (Number(taxa) - teamAvgConv).toFixed(1);
            const diffColor = Number(diff) >= 0 ? '#10b981' : '#ef4444';
            const cn = Number(r.credito_negado || 0);
            const ticket = r.avg_ticket !== null && r.avg_ticket !== undefined ? formatCurrency(r.avg_ticket) : '—';
            html += `<tr>
                <td><strong>${escapeHtml(r.username || 'Usuário')}</strong></td>
                <td>${formatNumber(total)}</td>
                <td>${formatNumber(created)}</td>
                <td style="color:#10b981;font-weight:700;">${formatNumber(conv)}</td>
                <td>${taxa}%</td>
                <td style="color:#ef4444;">${formatNumber(Number(r.perdidos || 0))}</td>
                <td style="color:${cn > 2 ? '#ef4444' : '#374151'};font-weight:${cn > 2 ? '700' : '400'};">${formatNumber(cn)}${cn > 2 ? ' ⚠' : ''}</td>
                <td>${ticket}</td>
                <td style="color:${diffColor};font-weight:700;">${Number(diff) >= 0 ? '+' : ''}${diff}%</td>
            </tr>`;
        });
        if (rows.length === 0) html += '<tr><td colspan="8" class="text-center text-muted py-4">Sem dados disponíveis.</td></tr>';
        html += `</tbody><tfoot><tr><td colspan="3"><small class="text-muted">Média da equipe: ${teamAvgConv.toFixed(1)}%</small></td><td colspan="5"></td></tr></tfoot></table></div>`;
        tbl.innerHTML = html;
    }
}

// ============================================================
// Financeiro charts
// ============================================================
function renderFinanceiroCharts() {
    // Payment distribution donut
    const pdEl = document.getElementById('chartPaymentDist');
    if (pdEl) {
        destroyChart('chartPaymentDist');
        const rows = Array.isArray(REPORT_PAYMENT_DIST) ? REPORT_PAYMENT_DIST : [];
        if (rows.length > 0) {
            const labels = rows.map(r => {
                const lbl = (r.label || '').toLowerCase();
                if (lbl === 'avista' || lbl === 'à vista' || lbl === 'a_vista') return 'À Vista';
                if (lbl === 'financiado' || lbl === 'financiamento') return 'Financiado';
                return r.label || 'Não informado';
            });
            const data = rows.map(r => Number(r.cnt || 0));
            chartInstances['chartPaymentDist'] = new Chart(pdEl, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data, backgroundColor: ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#94a3b8'], borderWidth: 0 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = tot > 0 ? ((ctx.parsed / tot) * 100).toFixed(1) : 0;
                                    return `${ctx.label}: ${formatNumber(ctx.parsed)} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            pdEl.parentElement.innerHTML = '<p class="text-muted text-center py-5">Sem dados de forma de pagamento no período.<br><small>Preencha o campo "Forma de Pagamento" nos leads.</small></p>';
        }
    }

    // Lost reasons (financeiro tab)
    const lrfEl = document.getElementById('chartLostReasonsFinanceiro');
    if (lrfEl) {
        destroyChart('chartLostReasonsFinanceiro');
        const rows = Array.isArray(REPORT_LOST_REASONS) ? REPORT_LOST_REASONS : [];
        if (rows.length > 0) {
            const labels = rows.map(r => r.reason || 'Não informado');
            const data = rows.map(r => Number(r.cnt || 0));
            chartInstances['chartLostReasonsFinanceiro'] = new Chart(lrfEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ label: 'Perdas', data, backgroundColor: labels.map((_, i) => defaultPalette(i + 3)), borderRadius: 6, borderWidth: 0 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }
    }

    // Lost reasons table
    const lrtEl = document.getElementById('tableLostReasons');
    if (lrtEl) {
        const rows = Array.isArray(REPORT_LOST_REASONS) ? REPORT_LOST_REASONS : [];
        const total = rows.reduce((s, r) => s + Number(r.cnt || 0), 0);
        let html = '<div class="table-responsive"><table class="data-table"><thead><tr><th>Motivo</th><th>Quantidade</th><th>%</th></tr></thead><tbody>';
        rows.forEach(r => {
            const cnt = Number(r.cnt || 0);
            const pct = total > 0 ? ((cnt / total) * 100).toFixed(1) : 0;
            const isCredit = /cr.dito|financiamento|negado/i.test(r.reason || '');
            html += `<tr${isCredit ? ' style="background:#fffbeb;"' : ''}>
                <td><strong>${escapeHtml(r.reason || 'Não informado')}</strong>${isCredit ? ' <span class="badge bg-warning text-dark">Monitorar</span>' : ''}</td>
                <td>${formatNumber(cnt)}</td>
                <td>${pct}%</td>
            </tr>`;
        });
        if (rows.length === 0) html += '<tr><td colspan="3" class="text-center text-muted py-4">Sem dados disponíveis.</td></tr>';
        html += '</tbody></table></div>';
        lrtEl.innerHTML = html;
    }

    // Revenue monthly chart (orcamento by month)
    const revEl = document.getElementById('chartRevenueMonthly');
    if (revEl) {
        destroyChart('chartRevenueMonthly');
        const months = Array.isArray(REPORT_MONTHS) ? REPORT_MONTHS : [];
        const labels = months.map(r => r.ym || '');
        const leadCounts = months.map(r => Number(r.cnt) || 0);
        const avgT = REPORT_AVG_TICKET || 0;
        const revenueData = leadCounts.map(cnt => cnt * avgT);
        const hasRevenue = revenueData.some(v => v > 0);

        if (!hasRevenue) {
            revEl.parentElement.innerHTML = '<p class="text-muted text-center py-5">Não há dados de receita suficientes para gerar gráfico. Insira orçamentos/valor de vendas nos leads.</p>';
        } else {
            chartInstances['chartRevenueMonthly'] = new Chart(revEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Receita Estimada (R$)',
                        data: revenueData,
                        backgroundColor: 'rgba(16,185,129,0.7)',
                        borderRadius: 6,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: ctx => 'Estimado: ' + formatCurrency(ctx.parsed.y)
                            }
                        }
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: v => 'R$' + formatNumber(v) } } }
                }
            });
        }
    }
}

function renderReports(){
    try {
        applyChartThemeDefaults();
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
        renderTemporalInsights();
        // New modules
        renderQualificationCharts();
        renderSLACharts();
        renderFinanceiroCharts();
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
