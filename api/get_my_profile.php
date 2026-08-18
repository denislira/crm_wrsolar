<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$userId = (int) $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT id, username, email, nome_completo, biografia, avatar, ai_bot_mode FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Usuario nao encontrado']);
        exit;
    }

    echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
