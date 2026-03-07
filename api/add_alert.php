<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('projetos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : null;
$type = trim($_POST['type'] ?? 'notification');
$message = trim($_POST['message'] ?? '');

try {
    // create alerts table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        project_id INT NULL,
        type VARCHAR(50) DEFAULT 'notification',
        message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $ins = $pdo->prepare('INSERT INTO alerts (user_id, project_id, type, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$_SESSION['user_id'], $project_id, $type, $message]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
