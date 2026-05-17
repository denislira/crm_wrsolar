<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
$globalList = isset($_REQUEST['global']) && in_array(mb_strtolower(trim((string)$_REQUEST['global']), 'UTF-8'), ['1', 'true', 'on', 'yes', 'sim'], true);
$toBoolean = static function ($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    $normalized = mb_strtolower(trim((string) $value), 'UTF-8');
    return in_array($normalized, ['1', 'true', 'on', 'yes', 'sim'], true);
};

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_venda_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        color VARCHAR(7) DEFAULT '#6c757d',
        card_color VARCHAR(7) DEFAULT '#ffffff',
        sla_renewal_target TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stageColumns = $pdo->query('SHOW COLUMNS FROM pos_venda_stages')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('card_color', $stageColumns, true)) {
        $pdo->exec("ALTER TABLE pos_venda_stages ADD COLUMN card_color VARCHAR(7) DEFAULT '#ffffff' AFTER color");
    }
    if (!in_array('sla_renewal_target', $stageColumns, true)) {
        $pdo->exec('ALTER TABLE pos_venda_stages ADD COLUMN sla_renewal_target TINYINT(1) NOT NULL DEFAULT 0 AFTER card_color');
    }
    if (!in_array('updated_at', $stageColumns, true)) {
        $pdo->exec('ALTER TABLE pos_venda_stages ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }

    if ($action === 'list') {
        $sql = 'SELECT id, name, position, color, card_color, sla_renewal_target FROM pos_venda_stages ';
        $sql .= $globalList ? 'ORDER BY position ASC, id ASC' : 'WHERE user_id = ? ORDER BY position ASC, id ASC';
        $stmt = $pdo->prepare($sql);
        if ($globalList) {
            $stmt->execute();
        } else {
            $stmt->execute([$userId]);
        }
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
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new Exception('Nome do estágio é obrigatório');
        }
        $color = trim($data['color'] ?? '#6c757d');
        $cardColor = trim($data['card_color'] ?? '#ffffff');
        $slaRenewalTarget = array_key_exists('sla_renewal_target', $data) ? ($toBoolean($data['sla_renewal_target']) ? 1 : 0) : 0;
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM pos_venda_stages WHERE user_id = ?');
        $stmt->execute([$userId]);
        $position = (int)$stmt->fetchColumn() + 1;
        if ($slaRenewalTarget === 1) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE pos_venda_stages SET sla_renewal_target = 0 WHERE user_id = ?')->execute([$userId]);
        }
        $insert = $pdo->prepare('INSERT INTO pos_venda_stages (user_id, name, position, color, card_color, sla_renewal_target) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$userId, $name, $position, $color, $cardColor, $slaRenewalTarget]);
        if ($slaRenewalTarget === 1) {
            $pdo->commit();
        }
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $name = trim($data['name'] ?? '');
        if ($id <= 0 || $name === '') {
            throw new Exception('Dados de atualização inválidos');
        }
        $color = trim($data['color'] ?? '#6c757d');
        $cardColor = trim($data['card_color'] ?? '#ffffff');
        $hasSlaRenewalTarget = array_key_exists('sla_renewal_target', $data);
        $slaRenewalTarget = $hasSlaRenewalTarget ? ($toBoolean($data['sla_renewal_target']) ? 1 : 0) : null;

        if ($hasSlaRenewalTarget) {
            $pdo->beginTransaction();
            if ($slaRenewalTarget === 1) {
                $pdo->prepare('UPDATE pos_venda_stages SET sla_renewal_target = 0 WHERE user_id = ?')->execute([$userId]);
            }
        }

        $fields = ['name = ?', 'color = ?', 'card_color = ?'];
        $params = [$name, $color, $cardColor];
        if ($hasSlaRenewalTarget) {
            $fields[] = 'sla_renewal_target = ?';
            $params[] = $slaRenewalTarget;
        }
        $params[] = $id;
        $params[] = $userId;

        $stmt = $pdo->prepare('UPDATE pos_venda_stages SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?');
        $stmt->execute($params);
        if ($hasSlaRenewalTarget) {
            $pdo->commit();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        $stmt = $pdo->prepare('DELETE FROM pos_venda_stages WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        if (empty($data['positions']) || !is_array($data['positions'])) {
            throw new Exception('Posições inválidas');
        }
        $update = $pdo->prepare('UPDATE pos_venda_stages SET position = ? WHERE id = ? AND user_id = ?');
        foreach ($data['positions'] as $row) {
            if (!isset($row['id']) || !isset($row['position'])) {
                continue;
            }
            $update->execute([(int)$row['position'], (int)$row['id'], $userId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Ação inválida']);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
