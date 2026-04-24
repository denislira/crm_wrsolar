<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

if (!hasPermission('projetos')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';
try {
    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, name FROM payment_methods ORDER BY name ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { http_response_code(400); echo json_encode(['error'=>'Missing name']); exit; }
        $ins = $pdo->prepare('INSERT INTO payment_methods (name, code, created_at) VALUES (?, NULL, NOW())');
        $ins->execute([$name]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    if ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if (empty($id)) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $d = $pdo->prepare('DELETE FROM payment_methods WHERE id = ?');
        $d->execute([(int)$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'update') {
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        if (empty($id) || $name === '') { http_response_code(400); echo json_encode(['error'=>'Missing id or name']); exit; }
        $u = $pdo->prepare('UPDATE payment_methods SET name = ? WHERE id = ?');
        $u->execute([$name, (int)$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
