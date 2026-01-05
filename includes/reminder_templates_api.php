<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
try {
    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, name, message, default_days_offset, default_time, channel FROM reminder_templates WHERE active = 1 ORDER BY id ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    if ($action === 'create' || $action === 'add') {
        // create a new reminder template
        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $default_days_offset = isset($_POST['default_days_offset']) ? intval($_POST['default_days_offset']) : 0;
        $default_time = $_POST['default_time'] ?? null;
        $channel = $_POST['channel'] ?? 'in-app';
        if ($name === '' || $message === '') { http_response_code(400); echo json_encode(['error'=>'name and message required']); exit; }
        $created_by = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO reminder_templates (name, message, default_days_offset, default_time, channel, created_by, created_at, active) VALUES (:name,:message,:offset,:dtime,:channel,:created_by,NOW(),1)');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':offset', $default_days_offset, PDO::PARAM_INT);
        $stmt->bindValue(':dtime', $default_time);
        $stmt->bindValue(':channel', $channel);
        $stmt->bindValue(':created_by', $created_by);
        $ok = $stmt->execute();
        if (!$ok) { http_response_code(500); echo json_encode(['error'=>'Failed to create template']); exit; }
        $id = $pdo->lastInsertId();
        http_response_code(201); echo json_encode(['id'=>$id]); exit;
    }
    // future: update/delete for admins
    http_response_code(400); echo json_encode(['error'=>'Unknown action']);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
?>