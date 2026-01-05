<?php
// API para gerenciar os estágios do Funil (CRUD + reorder)
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

// simple admin check: user_id === 1 is administrator in this system (adjust to your needs)
function _is_admin(){ return isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1; }

try {
    // Ensure table exists (simple migration)
    $pdo->exec("CREATE TABLE IF NOT EXISTS funil_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Add 'position' column if it's missing (migration for older installs)
    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $colCheck->execute();
    $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
    $hasPosition = in_array('position', $existingCols);
    if (!$hasPosition) {
        // if legacy column exists, keep it; otherwise add a new 'position'
        if (!in_array('stage_order', $existingCols)) {
            $pdo->exec("ALTER TABLE funil_stages ADD COLUMN position INT NOT NULL DEFAULT 0");
            $pdo->exec("SET @p=0; UPDATE funil_stages SET position = (@p := @p + 1) ORDER BY id ASC");
            $existingCols[] = 'position';
        }
    }

    // Determine which column to use for 'name', 'position', and 'color' to support older schemas
    $nameCol = in_array('name', $existingCols) ? 'name' : (in_array('stage_name', $existingCols) ? 'stage_name' : 'name');
    $positionCol = in_array('position', $existingCols) ? 'position' : (in_array('stage_order', $existingCols) ? 'stage_order' : 'position');
    $colorCol = in_array('stage_color', $existingCols) ? 'stage_color' : (in_array('color', $existingCols) ? 'color' : 'stage_color');

    // Optional extra columns supporting advanced customization
    $cardColorCol = in_array('card_color', $existingCols) ? 'card_color' : null;
    $iconCol = in_array('icon', $existingCols) ? 'icon' : null;
    $finalCol = in_array('is_final', $existingCols) ? 'is_final' : null;
    $finalTypeCol = in_array('final_type', $existingCols) ? 'final_type' : null;
    $generateTaskCol = in_array('generate_task_on_enter', $existingCols) ? 'generate_task_on_enter' : null;
    $alertInactivityCol = in_array('alert_on_inactivity', $existingCols) ? 'alert_on_inactivity' : null;
    $requiredFieldsCol = in_array('required_fields', $existingCols) ? 'required_fields' : null;
    $slaDaysCol = in_array('sla_days', $existingCols) ? 'sla_days' : null;
    $blockAdvanceCol = in_array('block_advance', $existingCols) ? 'block_advance' : null;
    $includeForecastCol = in_array('include_in_forecast', $existingCols) ? 'include_in_forecast' : null;
    $qualifyCol = in_array('is_qualification', $existingCols) ? 'is_qualification' : null;
    $conversionCol = in_array('is_conversion', $existingCols) ? 'is_conversion' : null;
    $trackTimeCol = in_array('track_time_in_stage', $existingCols) ? 'track_time_in_stage' : null;

    // Ensure history table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS funil_stages_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stage_id INT NULL,
            user_id INT NULL,
            action VARCHAR(50) NOT NULL,
            changes JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (stage_id), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) { /* ignore */ }

    if ($action === 'list') {
        // Build SELECT with all available columns
        $cols = ["id", "{$nameCol} AS name", "{$positionCol} AS position", "{$colorCol} AS color"];
        if ($cardColorCol) $cols[] = "{$cardColorCol} AS card_color";
        if ($iconCol) $cols[] = "{$iconCol} AS icon";
        if ($finalCol) $cols[] = "{$finalCol} AS is_final";
        if ($finalTypeCol) $cols[] = "{$finalTypeCol} AS final_type";
        if ($generateTaskCol) $cols[] = "{$generateTaskCol} AS generate_task_on_enter";
        if ($alertInactivityCol) $cols[] = "{$alertInactivityCol} AS alert_on_inactivity";
        if ($requiredFieldsCol) $cols[] = "{$requiredFieldsCol} AS required_fields";
        if ($slaDaysCol) $cols[] = "{$slaDaysCol} AS sla_days";
        if ($blockAdvanceCol) $cols[] = "{$blockAdvanceCol} AS block_advance";
        if ($includeForecastCol) $cols[] = "{$includeForecastCol} AS include_in_forecast";
        if ($qualifyCol) $cols[] = "{$qualifyCol} AS is_qualification";
        if ($conversionCol) $cols[] = "{$conversionCol} AS is_conversion";
        if ($trackTimeCol) $cols[] = "{$trackTimeCol} AS track_time_in_stage";
        
        $sql = "SELECT " . implode(', ', $cols) . " FROM funil_stages WHERE user_id = ? ORDER BY {$positionCol} ASC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // read input for POST (form or JSON)
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $data = $parsed;
    }

    if ($action === 'add') {
        if (empty($data['name'])) throw new Exception('Missing name');
        $color = !empty($data['color']) ? $data['color'] : '#6c757d';
        $cardColor = $data['card_color'] ?? null;
        $icon = $data['icon'] ?? null;
        $isFinal = !empty($data['is_final']) ? 1 : 0;
        $finalType = $data['final_type'] ?? 'none';
        $generateTask = !empty($data['generate_task_on_enter']) ? 1 : 0;
        $alertInactivity = !empty($data['alert_on_inactivity']) ? 1 : 0;
        $requiredFields = isset($data['required_fields']) ? json_encode($data['required_fields']) : null;
        $slaDays = isset($data['sla_days']) ? (int)$data['sla_days'] : null;
        $blockAdvance = !empty($data['block_advance']) ? 1 : 0;
        $includeForecast = isset($data['include_in_forecast']) ? (int)$data['include_in_forecast'] : 1;
        $isQualification = !empty($data['is_qualification']) ? 1 : 0;
        $isConversion = !empty($data['is_conversion']) ? 1 : 0;
        $trackTime = !empty($data['track_time_in_stage']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT COALESCE(MAX({$positionCol}),0) as mx FROM funil_stages WHERE user_id = ?");
        $stmt->execute([$userId]);
        $mx = (int)$stmt->fetchColumn();

        $cols = ["user_id", $nameCol, $positionCol, $colorCol];
        $vals = ["?", "?", "?", "?"];
        $params = [$userId, $data['name'], $mx+1, $color];
        if ($cardColorCol) { $cols[] = $cardColorCol; $vals[] = "?"; $params[] = $cardColor; }
        if ($iconCol) { $cols[] = $iconCol; $vals[] = "?"; $params[] = $icon; }
        if ($finalCol) { $cols[] = $finalCol; $vals[] = "?"; $params[] = $isFinal; }
        if ($finalTypeCol) { $cols[] = $finalTypeCol; $vals[] = "?"; $params[] = $finalType; }
        if ($generateTaskCol) { $cols[] = $generateTaskCol; $vals[] = "?"; $params[] = $generateTask; }
        if ($alertInactivityCol) { $cols[] = $alertInactivityCol; $vals[] = "?"; $params[] = $alertInactivity; }
        if ($requiredFieldsCol) { $cols[] = $requiredFieldsCol; $vals[] = "?"; $params[] = $requiredFields; }
        if ($slaDaysCol) { $cols[] = $slaDaysCol; $vals[] = "?"; $params[] = $slaDays; }
        if ($blockAdvanceCol) { $cols[] = $blockAdvanceCol; $vals[] = "?"; $params[] = $blockAdvance; }
        if ($includeForecastCol) { $cols[] = $includeForecastCol; $vals[] = "?"; $params[] = $includeForecast; }
        if ($qualifyCol) { $cols[] = $qualifyCol; $vals[] = "?"; $params[] = $isQualification; }
        if ($conversionCol) { $cols[] = $conversionCol; $vals[] = "?"; $params[] = $isConversion; }
        if ($trackTimeCol) { $cols[] = $trackTimeCol; $vals[] = "?"; $params[] = $trackTime; }

        $sql = "INSERT INTO funil_stages (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $newId = $pdo->lastInsertId();

        // history log
        try { $h = $pdo->prepare('INSERT INTO funil_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)'); $h->execute([$newId, $userId, 'add', json_encode(['name'=>$data['name']])]); } catch(Exception $e) {}

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id']) || !isset($data['name'])) throw new Exception('Missing id or name');

        // fetch previous for diff
        $pre = $pdo->prepare('SELECT * FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1');
        $pre->execute([$data['id'], $userId]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC) ?: [];

        $color = isset($data['color']) ? $data['color'] : ($prev['color'] ?? null);
        $cardColor = isset($data['card_color']) ? $data['card_color'] : ($prev['card_color'] ?? null);
        $icon = isset($data['icon']) ? $data['icon'] : ($prev['icon'] ?? null);
        $isFinal = isset($data['is_final']) ? (int)$data['is_final'] : ($prev['is_final'] ?? 0);
        $finalType = isset($data['final_type']) ? $data['final_type'] : ($prev['final_type'] ?? 'none');
        $generateTask = isset($data['generate_task_on_enter']) ? (int)$data['generate_task_on_enter'] : ($prev['generate_task_on_enter'] ?? 0);
        $alertInactivity = isset($data['alert_on_inactivity']) ? (int)$data['alert_on_inactivity'] : ($prev['alert_on_inactivity'] ?? 0);
        $requiredFields = isset($data['required_fields']) ? json_encode($data['required_fields']) : ($prev['required_fields'] ?? null);
        $slaDays = isset($data['sla_days']) ? (int)$data['sla_days'] : ($prev['sla_days'] ?? null);
        $blockAdvance = isset($data['block_advance']) ? (int)$data['block_advance'] : ($prev['block_advance'] ?? 0);
        $includeForecast = isset($data['include_in_forecast']) ? (int)$data['include_in_forecast'] : ($prev['include_in_forecast'] ?? 1);
        $isQualification = isset($data['is_qualification']) ? (int)$data['is_qualification'] : ($prev['is_qualification'] ?? 0);
        $isConversion = isset($data['is_conversion']) ? (int)$data['is_conversion'] : ($prev['is_conversion'] ?? 0);
        $trackTime = isset($data['track_time_in_stage']) ? (int)$data['track_time_in_stage'] : ($prev['track_time_in_stage'] ?? 0);

        $sets = ["{$nameCol} = ?", "{$colorCol} = ?"];
        $params = [$data['name'], $color];
        if ($cardColorCol) { $sets[] = "{$cardColorCol} = ?"; $params[] = $cardColor; }
        if ($iconCol) { $sets[] = "{$iconCol} = ?"; $params[] = $icon; }
        if ($finalCol) { $sets[] = "{$finalCol} = ?"; $params[] = $isFinal; }
        if ($finalTypeCol) { $sets[] = "{$finalTypeCol} = ?"; $params[] = $finalType; }
        if ($generateTaskCol) { $sets[] = "{$generateTaskCol} = ?"; $params[] = $generateTask; }
        if ($alertInactivityCol) { $sets[] = "{$alertInactivityCol} = ?"; $params[] = $alertInactivity; }
        if ($requiredFieldsCol) { $sets[] = "{$requiredFieldsCol} = ?"; $params[] = $requiredFields; }
        if ($slaDaysCol) { $sets[] = "{$slaDaysCol} = ?"; $params[] = $slaDays; }
        if ($blockAdvanceCol) { $sets[] = "{$blockAdvanceCol} = ?"; $params[] = $blockAdvance; }
        if ($includeForecastCol) { $sets[] = "{$includeForecastCol} = ?"; $params[] = $includeForecast; }
        if ($qualifyCol) { $sets[] = "{$qualifyCol} = ?"; $params[] = $isQualification; }
        if ($conversionCol) { $sets[] = "{$conversionCol} = ?"; $params[] = $isConversion; }
        if ($trackTimeCol) { $sets[] = "{$trackTimeCol} = ?"; $params[] = $trackTime; }

        $params[] = $data['id'];
        $params[] = $userId;

        $sql = "UPDATE funil_stages SET " . implode(', ', $sets) . " WHERE id = ? AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // log diff
        try {
            $after = $pdo->prepare('SELECT * FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1'); $after->execute([$data['id'], $userId]); $new = $after->fetch(PDO::FETCH_ASSOC) ?: [];
            $changes = [];
            foreach ($new as $k => $v) {
                $old = $prev[$k] ?? null; if ($old != $v) $changes[$k] = ['from' => $old, 'to' => $v];
            }
            if (!empty($changes)) { $h = $pdo->prepare('INSERT INTO funil_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)'); $h->execute([$data['id'], $userId, 'update', json_encode($changes)]); }
        } catch (Exception $e) { /* ignore */ }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) throw new Exception('Missing id');
        // check leads associated
        $c = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND (stage_id = ? OR status = ?)');
        // attempt to resolve name if stage id exists
        $stageName = null; try { $s = $pdo->prepare("SELECT {$nameCol} AS name FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1"); $s->execute([$data['id'], $userId]); $row = $s->fetch(PDO::FETCH_ASSOC); if ($row) $stageName = $row['name']; } catch(Exception $e){}
        $c->execute([$userId, $data['id'], $stageName]); $num = (int)$c->fetchColumn();
        if ($num > 0 && empty($data['force'])) {
            http_response_code(409); echo json_encode(['error' => 'Stage has leads', 'leads' => $num]); exit;
        }

        $stmt = $pdo->prepare('DELETE FROM funil_stages WHERE id = ? AND user_id = ?');
        $stmt->execute([$data['id'], $userId]);

        // log history
        try { $h = $pdo->prepare('INSERT INTO funil_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)'); $h->execute([$data['id'], $userId, 'delete', json_encode(['forced' => !empty($data['force'])] )]); } catch(Exception $e) {}

        // Reorder remaining stages
        $stmt = $pdo->prepare("SELECT id FROM funil_stages WHERE user_id = ? ORDER BY {$positionCol} ASC, id ASC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pos = 1;
        $upd = $pdo->prepare("UPDATE funil_stages SET {$positionCol} = ? WHERE id = ? AND user_id = ?");
        foreach ($rows as $r) { $upd->execute([$pos++, $r, $userId]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        // Expecting data.positions = [{id:..., position:...}, ...]
        if (empty($data['positions']) || !is_array($data['positions'])) throw new Exception('Missing positions');
        $upd = $pdo->prepare("UPDATE funil_stages SET {$positionCol} = ? WHERE id = ? AND user_id = ?");
        foreach ($data['positions'] as $p) {
            $id = $p['id'] ?? null; $position = (int)($p['position'] ?? 0);
            if ($id) $upd->execute([$position, $id, $userId]);
        }
        // log history with ordered list
        try { $h = $pdo->prepare('INSERT INTO funil_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)'); $h->execute([null, $userId, 'reorder', json_encode(['positions' => $data['positions']])]); } catch(Exception $e) {}
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'history') {
        $stageId = $_GET['stage_id'] ?? ($_POST['stage_id'] ?? null);
        $q = $pdo->prepare('SELECT id, stage_id, user_id, action, changes, created_at FROM funil_stages_history ' . ($stageId ? 'WHERE stage_id = ? ' : '') . 'ORDER BY created_at DESC');
        $params = []; if ($stageId) $params[] = $stageId;
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
