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

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
$type = $_REQUEST['type'] ?? '';
$allowedTypes = ['technical', 'document'];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS projeto_checklist_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        checklist_type ENUM('technical','document') NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(checklist_type),
        INDEX(position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
    }

    if ($action === 'list') {
        if ($type && !in_array($type, $allowedTypes, true)) {
            throw new Exception('Tipo inválido');
        }

        if ($type) {
            $stmt = $pdo->prepare('SELECT id, name, position FROM projeto_checklist_items WHERE user_id = ? AND checklist_type = ? ORDER BY position ASC, id ASC');
            $stmt->execute([$userId, $type]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows);
            exit;
        }

        $result = [];
        foreach ($allowedTypes as $t) {
            $stmt = $pdo->prepare('SELECT id, name, position FROM projeto_checklist_items WHERE user_id = ? AND checklist_type = ? ORDER BY position ASC, id ASC');
            $stmt->execute([$userId, $t]);
            $result[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($result);
        exit;
    }

    if ($action === 'add') {
        if (empty($data['name']) || empty($data['type'])) {
            throw new Exception('Dados insuficientes');
        }
        if (!in_array($data['type'], $allowedTypes, true)) {
            throw new Exception('Tipo inválido');
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM projeto_checklist_items WHERE user_id = ? AND checklist_type = ?');
        $stmt->execute([$userId, $data['type']]);
        $maxPosition = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO projeto_checklist_items (user_id, checklist_type, name, position) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $data['type'], trim($data['name']), $maxPosition + 1]);
        $newId = $pdo->lastInsertId();

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id']) || empty($data['name'])) {
            throw new Exception('Dados insuficientes');
        }

        $stmt = $pdo->prepare('SELECT id, user_id FROM projeto_checklist_items WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$data['id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item || $item['user_id'] != $userId) {
            throw new Exception('Item não encontrado');
        }

        $stmt = $pdo->prepare('UPDATE projeto_checklist_items SET name = ? WHERE id = ?');
        $stmt->execute([trim($data['name']), (int)$data['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) {
            throw new Exception('Dados insuficientes');
        }

        $stmt = $pdo->prepare('DELETE FROM projeto_checklist_items WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$data['id'], $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        if (empty($data['positions']) || !is_array($data['positions'])) {
            throw new Exception('Dados de ordenação ausentes');
        }

        $stmt = $pdo->prepare('UPDATE projeto_checklist_items SET position = ? WHERE id = ? AND user_id = ?');
        foreach ($data['positions'] as $row) {
            if (!isset($row['id']) || !isset($row['position'])) {
                continue;
            }
            $stmt->execute([(int)$row['position'], (int)$row['id'], $userId]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Ação inválida']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
