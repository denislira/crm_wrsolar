<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/config.php';

$userId = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
$fieldKey = trim((string)($_REQUEST['field_key'] ?? ''));
$allowedFieldKeys = ['client_type', 'client_status'];

if (!in_array($fieldKey, $allowedFieldKeys, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'field_key invalido']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_venda_field_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        field_key VARCHAR(40) NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pv_field_options_user_field (user_id, field_key),
        INDEX idx_pv_field_options_position (position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $seedDefaults = static function (PDO $pdo, int $uid, string $key): void {
        $defaults = [
            'client_type' => ['Degustacao', 'Cortesia', 'Embaixador', 'Assinante Ativo'],
            'client_status' => ['Assinante', 'Ex-Cliente']
        ];
        if (!isset($defaults[$key])) return;

        $exists = $pdo->prepare('SELECT COUNT(*) FROM pos_venda_field_options WHERE user_id = ? AND field_key = ?');
        $exists->execute([$uid, $key]);
        if ((int)$exists->fetchColumn() > 0) return;

        $ins = $pdo->prepare('INSERT INTO pos_venda_field_options (user_id, field_key, name, position) VALUES (?, ?, ?, ?)');
        $position = 1;
        foreach ($defaults[$key] as $name) {
            $ins->execute([$uid, $key, $name, $position]);
            $position++;
        }
    };

    $seedDefaults($pdo, $userId, $fieldKey);

    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, name, position FROM pos_venda_field_options WHERE user_id = ? AND field_key = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$userId, $fieldKey]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

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

    if ($action === 'add') {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Nome obrigatorio');
        }

        $maxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM pos_venda_field_options WHERE user_id = ? AND field_key = ?');
        $maxPosStmt->execute([$userId, $fieldKey]);
        $nextPosition = (int)$maxPosStmt->fetchColumn() + 1;

        $ins = $pdo->prepare('INSERT INTO pos_venda_field_options (user_id, field_key, name, position) VALUES (?, ?, ?, ?)');
        $ins->execute([$userId, $fieldKey, $name, $nextPosition]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $name = trim((string)($data['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            throw new Exception('Dados invalidos');
        }

        $upd = $pdo->prepare('UPDATE pos_venda_field_options SET name = ? WHERE id = ? AND user_id = ? AND field_key = ?');
        $upd->execute([$name, $id, $userId, $fieldKey]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }

        $del = $pdo->prepare('DELETE FROM pos_venda_field_options WHERE id = ? AND user_id = ? AND field_key = ?');
        $del->execute([$id, $userId, $fieldKey]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        if (empty($data['positions']) || !is_array($data['positions'])) {
            throw new Exception('Posicoes invalidas');
        }
        $upd = $pdo->prepare('UPDATE pos_venda_field_options SET position = ? WHERE id = ? AND user_id = ? AND field_key = ?');
        foreach ($data['positions'] as $row) {
            if (!isset($row['id']) || !isset($row['position'])) {
                continue;
            }
            $upd->execute([(int)$row['position'], (int)$row['id'], $userId, $fieldKey]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Acao invalida']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
