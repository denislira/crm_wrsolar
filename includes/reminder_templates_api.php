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
    // future: add/create/update/delete for admins
    http_response_code(400); echo json_encode(['error'=>'Unknown action']);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
?>