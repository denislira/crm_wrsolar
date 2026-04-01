<?php
// API para gerenciar os estágios do Projeto (CRUD + reorder)
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

try {
    // ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS projeto_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        is_initial TINYINT(1) NOT NULL DEFAULT 0,
        color VARCHAR(7) DEFAULT '#6c757d',
        card_color VARCHAR(7) DEFAULT '#ffffff',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_stages'");
    $colCheck->execute();
    $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);

    $positionCol = in_array('position', $existingCols, true) ? 'position' : null;
    if (!$positionCol) {
        $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN position INT NOT NULL DEFAULT 0");
        $pdo->exec("SET @p=0; UPDATE projeto_stages SET position = (@p := @p + 1) ORDER BY id ASC");
        $existingCols[] = 'position';
        $positionCol = 'position';
    }

    $nameCol = in_array('name', $existingCols, true) ? 'name' : (in_array('stage_name', $existingCols, true) ? 'stage_name' : 'name');

    if (!in_array('is_initial', $existingCols, true)) {
        $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN is_initial TINYINT(1) NOT NULL DEFAULT 0");
        $existingCols[] = 'is_initial';
    }

    // Ensure history table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS projeto_stages_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stage_id INT NULL,
        user_id INT NULL,
        action VARCHAR(50) NOT NULL,
        changes JSON DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (stage_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    if ($action === 'list') {
        $cols = ['id', "{$nameCol} AS name", 'is_initial', 'color', 'card_color', 'position'];
        $sql = "SELECT " . implode(', ', $cols) . " FROM projeto_stages WHERE user_id = ? OR user_id IS NULL ORDER BY COALESCE({$positionCol}, id) ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) $data = $parsed;
        }
    }

    if ($action === 'add') {
        if (empty($data['name'])) throw new Exception('Missing name');
        $isInitial = !empty($data['is_initial']) ? 1 : 0;
        $color = !empty($data['color']) ? trim($data['color']) : '#6c757d';
        $cardColor = !empty($data['card_color']) ? trim($data['card_color']) : '#ffffff';

        if ($isInitial === 1) {
            $resetInitial = $pdo->prepare('UPDATE projeto_stages SET is_initial = 0 WHERE user_id = ?');
            $resetInitial->execute([$userId]);
        }

        $stmt = $pdo->prepare("SELECT COALESCE(MAX({$positionCol}),0) as mx FROM projeto_stages WHERE user_id = ?");
        $stmt->execute([$userId]);
        $mx = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO projeto_stages (user_id, {$nameCol}, position, is_initial, color, card_color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $data['name'], $mx + 1, $isInitial, $color, $cardColor]);
        $newId = $pdo->lastInsertId();

        $h = $pdo->prepare('INSERT INTO projeto_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)');
        $h->execute([$newId, $userId, 'add', json_encode(['name' => $data['name']])]);

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id']) || !isset($data['name'])) throw new Exception('Missing id or name');

        $pre = $pdo->prepare('SELECT * FROM projeto_stages WHERE id = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1');
        $pre->execute([$data['id'], $userId]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($prev)) throw new Exception('Etapa não encontrada ou sem permissão');

        $isInitial = !empty($data['is_initial']) ? 1 : 0;
        $color = !empty($data['color']) ? trim($data['color']) : ($prev['color'] ?? '#6c757d');
        $cardColor = !empty($data['card_color']) ? trim($data['card_color']) : ($prev['card_color'] ?? '#ffffff');

        if ($isInitial === 1) {
            $resetInitial = $pdo->prepare('UPDATE projeto_stages SET is_initial = 0 WHERE user_id = ?');
            $resetInitial->execute([$userId]);
        }

        $sets = ["{$nameCol} = ?", 'is_initial = ?', 'color = ?', 'card_color = ?'];
        $params = [$data['name'], $isInitial, $color, $cardColor, $data['id'], $userId];

        $sql = "UPDATE projeto_stages SET " . implode(', ', $sets) . " WHERE id = ? AND (user_id = ? OR user_id IS NULL)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $after = $pdo->prepare('SELECT * FROM projeto_stages WHERE id = ? LIMIT 1');
        $after->execute([$data['id']]);
        $new = $after->fetch(PDO::FETCH_ASSOC) ?: [];

        $changes = [];
        foreach ($new as $k => $v) {
            if (($prev[$k] ?? null) != $v) {
                $changes[$k] = ['from' => $prev[$k] ?? null, 'to' => $v];
            }
        }
        if (!empty($changes)) {
            $h = $pdo->prepare('INSERT INTO projeto_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)');
            $h->execute([$data['id'], $userId, 'update', json_encode($changes)]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) throw new Exception('Missing id');
        $stmt = $pdo->prepare('DELETE FROM projeto_stages WHERE id = ?');
        $stmt->execute([$data['id']]);

        $h = $pdo->prepare('INSERT INTO projeto_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)');
        $h->execute([$data['id'], $userId, 'delete', json_encode(['id' => $data['id']])]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        if (empty($data['positions']) || !is_array($data['positions'])) throw new Exception('Missing positions');
        $upd = $pdo->prepare("UPDATE projeto_stages SET position = ? WHERE id = ?");
        foreach ($data['positions'] as $row) {
            if (!isset($row['id']) || !isset($row['position'])) continue;
            $upd->execute([(int)$row['position'], (int)$row['id']]);
        }

        $h = $pdo->prepare('INSERT INTO projeto_stages_history (stage_id, user_id, action, changes) VALUES (?, ?, ?, ?)');
        $h->execute([null, $userId, 'reorder', json_encode(['positions' => $data['positions']])]);

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
