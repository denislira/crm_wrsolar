<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
require_once dirname(__DIR__) . '/includes/config.php';

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') { echo json_encode([]); exit; }

try {
    $like = '%' . str_replace('%', '\\%', $q) . '%';
    $stmt = $pdo->prepare('SELECT id, username, COALESCE(avatar, "") AS avatar, email FROM users WHERE username LIKE :q OR email LIKE :q ORDER BY username LIMIT 30');
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
}
