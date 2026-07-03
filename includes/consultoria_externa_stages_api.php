<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/consultoria_externa_stages.php';

$userId = ce_stage_owner_id();
$requestedUserId = isset($_REQUEST['consultor_id']) ? (int) $_REQUEST['consultor_id'] : 0;
if ($requestedUserId > 0) {
    $isDirector = function_exists('isDirector') && isDirector();
    if ($isDirector) {
        $userId = ce_stage_owner_id();
    }
}
$action = $_REQUEST['action'] ?? 'list';
$roleName = $_SESSION['role_name'] ?? null;
if (!$roleName && !empty($_SESSION['role_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
    $stmt->execute([$_SESSION['role_id']]);
    $roleName = $stmt->fetchColumn();
}
$canManageStages = strtolower((string)$roleName) !== 'consultor_externo';

function ce_stage_bool($value): int {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_numeric($value)) {
        return (int)$value === 1 ? 1 : 0;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes', 'sim'], true) ? 1 : 0;
}

function ce_stage_payload(): array {
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        if (is_array($json)) {
            $data = $json;
        }
    }
    return $data;
}

try {
    ce_ensure_stage_tables($pdo);
    ce_seed_default_stages($pdo, $userId);

    if ($action === 'list') {
        echo json_encode(ce_list_stages($pdo, $userId));
        exit;
    }

    if (!$canManageStages) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão para configurar colunas']);
        exit;
    }

    $data = ce_stage_payload();

    if ($action === 'add') {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Informe o nome da coluna');
        }
        $color = trim((string)($data['color'] ?? '#6c757d'));
        $cardColor = trim((string)($data['card_color'] ?? '#ffffff'));
        $icon = trim((string)($data['icon'] ?? 'fa-layer-group'));
        $isInitial = ce_stage_bool($data['is_initial'] ?? 0);
        $export = ce_stage_bool($data['export_to_internal_queue'] ?? 0);

        if ($isInitial === 1) {
            $pdo->prepare('UPDATE consultoria_externa_stages SET is_initial = 0 WHERE user_id = ?')->execute([$userId]);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM consultoria_externa_stages WHERE user_id = ?');
        $stmt->execute([$userId]);
        $position = (int)$stmt->fetchColumn() + 1;

        $insert = $pdo->prepare('INSERT INTO consultoria_externa_stages (user_id, name, position, color, card_color, icon, is_initial, export_to_internal_queue) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$userId, $name, $position, $color, $cardColor, $icon, $isInitial, $export]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            throw new Exception('Dados invalidos');
        }
        $color = trim((string)($data['color'] ?? '#6c757d'));
        $cardColor = trim((string)($data['card_color'] ?? '#ffffff'));
        $icon = trim((string)($data['icon'] ?? 'fa-layer-group'));
        $isInitial = ce_stage_bool($data['is_initial'] ?? 0);
        $export = ce_stage_bool($data['export_to_internal_queue'] ?? 0);

        if ($isInitial === 1) {
            $pdo->prepare('UPDATE consultoria_externa_stages SET is_initial = 0 WHERE user_id = ?')->execute([$userId]);
        }

        $stmt = $pdo->prepare('UPDATE consultoria_externa_stages SET name = ?, color = ?, card_color = ?, icon = ?, is_initial = ?, export_to_internal_queue = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $color, $cardColor, $icon, $isInitial, $export, $id, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $fallbackId = ce_initial_stage_id($pdo, $userId);
        if ($fallbackId === $id) {
            $stmt = $pdo->prepare('SELECT id FROM consultoria_externa_stages WHERE user_id = ? AND id <> ? ORDER BY position ASC, id ASC LIMIT 1');
            $stmt->execute([$userId, $id]);
            $nextId = $stmt->fetchColumn();
            $fallbackId = $nextId ? (int)$nextId : null;
        }
        if ($fallbackId) {
            $pdo->prepare('UPDATE consultoria_externa_itens SET stage_id = ? WHERE user_id = ? AND stage_id = ?')->execute([$fallbackId, $userId, $id]);
        }
        $stmt = $pdo->prepare('DELETE FROM consultoria_externa_stages WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        if (empty($data['positions']) || !is_array($data['positions'])) {
            throw new Exception('Posicoes invalidas');
        }
        $update = $pdo->prepare('UPDATE consultoria_externa_stages SET position = ? WHERE id = ? AND user_id = ?');
        foreach ($data['positions'] as $row) {
            if (!isset($row['id'], $row['position'])) {
                continue;
            }
            $update->execute([(int)$row['position'], (int)$row['id'], $userId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new Exception('Acao invalida');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
