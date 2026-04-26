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

$action = $_REQUEST['action'] ?? 'list';
$rawCode = isset($_REQUEST['code']) ? (int)$_REQUEST['code'] : 0;
$legacyScope = trim((string)($_REQUEST['scope'] ?? ''));
if ($rawCode === 0) {
    // Backward compatibility with old scope-based calls.
    if ($legacyScope === 'projetos') {
        $rawCode = 2;
    } else {
        $rawCode = 1;
    }
}

if (!in_array($rawCode, [1, 2], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid code']);
    exit;
}

$requiredPermission = ($rawCode === 2) ? 'projetos' : 'leads_gestao';
if (!hasPermission($requiredPermission)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    // Ensure code column exists and is indexed.
    $codeColumn = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'code'");
    if (!$codeColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE payment_methods ADD COLUMN code INT NULL AFTER name");
    }
    $codeIndex = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND INDEX_NAME = 'idx_payment_methods_code'");
    if (!$codeIndex->fetchColumn()) {
        $pdo->exec("CREATE INDEX idx_payment_methods_code ON payment_methods(code)");
    }

    // Fill missing codes preserving old scope separation when available.
    $scopeColumn = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'scope'");
    if ($scopeColumn->fetchColumn()) {
        $pdo->exec("UPDATE payment_methods SET code = 2 WHERE code IS NULL AND scope = 'projetos'");
        $pdo->exec("UPDATE payment_methods SET code = 1 WHERE code IS NULL AND (scope = 'leads' OR scope IS NULL OR scope = '')");
    } else {
        $pdo->exec("UPDATE payment_methods SET code = 1 WHERE code IS NULL");
    }

    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, name FROM payment_methods WHERE code = ? ORDER BY name ASC');
        $stmt->execute([$rawCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { http_response_code(400); echo json_encode(['error'=>'Missing name']); exit; }
        $ins = $pdo->prepare('INSERT INTO payment_methods (name, code, created_at) VALUES (?, ?, NOW())');
        $ins->execute([$name, $rawCode]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    if ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if (empty($id)) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $d = $pdo->prepare('DELETE FROM payment_methods WHERE id = ? AND code = ?');
        $d->execute([(int)$id, $rawCode]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'update') {
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        if (empty($id) || $name === '') { http_response_code(400); echo json_encode(['error'=>'Missing id or name']); exit; }
        $u = $pdo->prepare('UPDATE payment_methods SET name = ? WHERE id = ? AND code = ?');
        $u->execute([$name, (int)$id, $rawCode]);
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
