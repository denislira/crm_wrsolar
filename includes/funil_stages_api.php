<?php
// API para gerenciar os estágios do Funil (CRUD + reorder)
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

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

    if ($action === 'list') {
        $sql = "SELECT id, {$nameCol} AS name, {$positionCol} AS position, {$colorCol} AS color FROM funil_stages WHERE user_id = ? ORDER BY {$positionCol} ASC, id ASC";
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
        $stmt = $pdo->prepare("SELECT COALESCE(MAX({$positionCol}),0) as mx FROM funil_stages WHERE user_id = ?");
        $stmt->execute([$userId]);
        $mx = (int)$stmt->fetchColumn();
        $sql = "INSERT INTO funil_stages (user_id, {$nameCol}, {$positionCol}, {$colorCol}) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $data['name'], $mx+1, $color]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id']) || !isset($data['name'])) throw new Exception('Missing id or name');
        $color = isset($data['color']) ? $data['color'] : null;
        if ($color !== null) {
            $sql = "UPDATE funil_stages SET {$nameCol} = ?, {$colorCol} = ? WHERE id = ? AND user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data['name'], $color, $data['id'], $userId]);
        } else {
            $sql = "UPDATE funil_stages SET {$nameCol} = ? WHERE id = ? AND user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data['name'], $data['id'], $userId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) throw new Exception('Missing id');
        $stmt = $pdo->prepare('DELETE FROM funil_stages WHERE id = ? AND user_id = ?');
        $stmt->execute([$data['id'], $userId]);
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
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
