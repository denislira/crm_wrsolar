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
require_once __DIR__ . '/consultoria_externa_stages.php';

$action = $_REQUEST['action'] ?? 'list';
$userId = (int) $_SESSION['user_id'];

function ce_api_norm_stage($status): string {
    $s = strtolower(trim((string) $status));
    if ($s === '') return 'captacao_tecnica';
    if (preg_match('/(fechad|contrat|assin|finaliz|ganho|aprovad)/', $s)) return 'contrato_gerado';
    if (preg_match('/(financ|banc|credito|analise)/', $s)) return 'processo_bancario';
    if (preg_match('/(orcamento|proposta|propost|negoci)/', $s)) return 'aguardando_orcamento';
    return 'captacao_tecnica';
}

function ce_api_payload(): array {
    return [
        'client_name' => trim((string)($_POST['client_name'] ?? $_POST['name'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'cidade' => trim((string)($_POST['cidade'] ?? '')),
        'source' => trim((string)($_POST['source'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? '')),
        'stage_id' => (int)($_POST['stage_id'] ?? 0),
        'value' => (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', (string)($_POST['value'] ?? $_POST['orcamento_value'] ?? '0'))),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

try {
    ce_ensure_stage_tables($pdo);
    ce_seed_default_stages($pdo, $userId);

    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, client_name, phone, cidade, source, status, value, notes, stage_key, stage_id, exported_to_internal_queue, exported_at, created_at, updated_at FROM consultoria_externa_itens WHERE user_id = ? AND deleted = 0 ORDER BY created_at DESC, id DESC');
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, client_name, phone, cidade, source, status, value, notes, stage_key, stage_id, exported_to_internal_queue, exported_at, created_at, updated_at FROM consultoria_externa_itens WHERE id = ? AND user_id = ? AND deleted = 0 LIMIT 1');
        $stmt->execute([$id, $userId]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        exit;
    }

    if ($action === 'add') {
        $data = ce_api_payload();
        if ($data['client_name'] === '') {
            throw new Exception('Informe o nome');
        }

        $stageKey = ce_api_norm_stage($data['status']);
        $stageId = ce_resolve_stage_id($pdo, $userId, $data['stage_id'], $stageKey);
        $stmt = $pdo->prepare('INSERT INTO consultoria_externa_itens (user_id, client_name, phone, cidade, source, status, value, notes, stage_key, stage_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$userId, $data['client_name'], $data['phone'], $data['cidade'], $data['source'], $data['status'], $data['value'], $data['notes'], $stageKey, $stageId]);
        $newId = (int)$pdo->lastInsertId();
        ce_export_item_if_needed($pdo, $newId, $userId);

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $data = ce_api_payload();
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }

        $stageKey = ce_api_norm_stage($data['status']);
        $stageId = ce_resolve_stage_id($pdo, $userId, $data['stage_id'], $stageKey);
        $stmt = $pdo->prepare('UPDATE consultoria_externa_itens SET client_name=?, phone=?, cidade=?, source=?, status=?, value=?, notes=?, stage_key=?, stage_id=?, updated_at=NOW() WHERE id=? AND user_id=?');
        $stmt->execute([$data['client_name'], $data['phone'], $data['cidade'], $data['source'], $data['status'], $data['value'], $data['notes'], $stageKey, $stageId, $id, $userId]);
        ce_export_item_if_needed($pdo, $id, $userId);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'move_stage') {
        $id = (int)($_POST['id'] ?? 0);
        $stageId = ce_resolve_stage_id($pdo, $userId, (int)($_POST['stage_id'] ?? 0), null);
        if ($id <= 0 || !$stageId) {
            throw new Exception('Dados de movimentacao invalidos');
        }

        $stmt = $pdo->prepare('UPDATE consultoria_externa_itens SET stage_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$stageId, $id, $userId]);
        ce_export_item_if_needed($pdo, $id, $userId);

        echo json_encode(['ok' => true]);
        exit;
    }

    throw new Exception('Acao invalida');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
