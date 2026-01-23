<?php
// Simple API for lead statuses (used in lead modals)
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
// support actions: list, add, update, delete
$action = $_REQUEST['action'] ?? 'list';

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_statuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Read POST/JSON input
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $data = $parsed;
    }

    if ($action === 'list') {
        // Return all status rows (global and user-specific) so everyone sees same set
        $stmt = $pdo->prepare("SELECT id, name, position, user_id FROM lead_statuses ORDER BY position ASC, id ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($action === 'add') {
        $name = trim($data['name'] ?? '');
        if ($name === '') { http_response_code(400); echo json_encode(['error'=>'Missing name']); exit; }
        // position: append to user's list
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM lead_statuses');
        $stmt->execute(); $mx = (int)$stmt->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO lead_statuses (user_id, name, position) VALUES (?, ?, ?)');
        $ins->execute([$userId, $name, $mx+1]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId(),'name'=>$name]);
        exit;
    }

    if ($action === 'update') {
        $id = $data['id'] ?? null; $name = trim($data['name'] ?? '');
        if (!$id || $name === '') { http_response_code(400); echo json_encode(['error'=>'Missing id or name']); exit; }
        // Allow any user to update statuses for now
        $q = $pdo->prepare('SELECT user_id FROM lead_statuses WHERE id = ? LIMIT 1'); $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        $upd = $pdo->prepare('UPDATE lead_statuses SET name = ? WHERE id = ?'); $upd->execute([$name, $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = $data['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $q = $pdo->prepare('SELECT user_id, name FROM lead_statuses WHERE id = ? LIMIT 1'); $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        // Before delete, ensure no leads use this status (best-effort across all leads)
        $c = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE status = ?');
        $c->execute([$row['name'] ?? '']);
        $num = (int)$c->fetchColumn();
        if ($num > 0) { http_response_code(409); echo json_encode(['error'=>'Status used by leads']); exit; }
        $del = $pdo->prepare('DELETE FROM lead_statuses WHERE id = ?'); $del->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
