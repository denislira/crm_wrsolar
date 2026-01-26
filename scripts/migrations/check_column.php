<?php
$expectedToken = trim(file_get_contents(__DIR__ . '/migrations.secret'));
if (empty($expectedToken)) {
    http_response_code(500);
    echo json_encode(['error' => 'Migration secret not configured']);
    exit;
}
$providedToken = $_GET['token'] ?? '';
if ($providedToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
require_once __DIR__ . '/../includes/config.php';
try {
    $stmt = $pdo->query("DESCRIBE leads forma_pagamento");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['type' => $row['Type'] ?? 'unknown']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>