<?php
require_once __DIR__ . '/../includes/config.php';
try{
    // detect name column (compatibility with older schemas)
    $colStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $nameCol = in_array('name', $cols) ? 'name' : (in_array('stage_name', $cols) ? 'stage_name' : null);
    if (!$nameCol) { echo "No name/stage_name column found on funil_stages\n"; exit; }
    // build select list based on existing columns
    $want = [ 'position' => ['position','stage_order'], 'color' => ['color','stage_color'], 'card_color' => ['card_color'], 'icon' => ['icon'], 'is_final'=>['is_final'], 'final_type'=>['final_type'], 'sla_days'=>['sla_days'], 'generate_task_on_enter'=>['generate_task_on_enter'], 'alert_on_inactivity'=>['alert_on_inactivity'], 'include_in_forecast'=>['include_in_forecast'] ];
    $selectParts = ["id", sprintf("%s AS name", $nameCol)];
    foreach ($want as $alias => $opts) {
        $found = null;
        foreach ($opts as $c) if (in_array($c, $cols)) { $found = $c; break; }
        if ($found) $selectParts[] = sprintf("%s AS %s", $found, $alias);
        else $selectParts[] = sprintf("NULL AS %s", $alias);
    }

    $sql = sprintf('SELECT %s FROM funil_stages WHERE user_id = ? ORDER BY COALESCE(position, id) ASC', implode(', ', $selectParts));
    $stmt = $pdo->prepare($sql);
    $stmt->execute([1]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "funil_stages rows: \n";
    foreach ($rows as $r) {
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }
