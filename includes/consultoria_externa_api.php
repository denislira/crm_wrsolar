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
$requestedUserId = isset($_REQUEST['consultor_id']) ? (int) $_REQUEST['consultor_id'] : 0;
$consultorName = '';
if ($requestedUserId > 0) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND LOWER(COALESCE(r.name, '')) = 'consultor_externo'
         LIMIT 1
    ");
    $stmt->execute([$requestedUserId]);
    $requestedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($requestedUser) {
        $userId = (int) $requestedUser['id'];
        $consultorName = trim((string) ($requestedUser['username'] ?? ''));
    }
}
if ($consultorName === '') {
    $stmt = $pdo->prepare("
        SELECT u.username
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND LOWER(COALESCE(r.name, '')) = 'consultor_externo'
         LIMIT 1
    ");
    $stmt->execute([$userId]);
    $consultorName = trim((string) ($stmt->fetchColumn() ?: 'Consultor Externo'));
}

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
        'email' => trim((string)($_POST['email'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'cpf_cnpj' => trim((string)($_POST['cpf_cnpj'] ?? '')),
        'cidade' => trim((string)($_POST['cidade'] ?? '')),
        'source' => trim((string)($_POST['source'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? '')),
        'stage_id' => (int)($_POST['stage_id'] ?? 0),
        'ultimo_contato' => trim((string)($_POST['ultimo_contato'] ?? '')),
        'created_entry_at' => trim((string)($_POST['created_entry_at'] ?? '')),
        'consumo' => trim((string)($_POST['consumo'] ?? '')),
        'estimativa_kwh' => trim((string)($_POST['estimativa_kwh'] ?? '')),
        'value' => (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', (string)($_POST['value'] ?? $_POST['orcamento_value'] ?? '0'))),
        'forma_pagamento_id' => (int)($_POST['forma_pagamento_id'] ?? 0),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

function ce_api_fetch_attachments(PDO $pdo, int $itemId): array {
    $attachments = [];

    $stmt = $pdo->prepare('
        SELECT
            a.id AS attachment_id,
            a.item_id,
            NULL AS demand_id,
            a.filename,
            a.mimetype,
            a.file_size,
            a.created_at,
            a.user_id,
            "external" AS origin
        FROM consultoria_externa_attachments a
        WHERE a.item_id = ?
        ORDER BY a.id DESC
    ');
    $stmt->execute([$itemId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('
        SELECT
            a.id AS attachment_id,
            NULL AS item_id,
            a.demand_id,
            a.filename,
            a.mimetype,
            a.file_size,
            a.created_at,
            a.user_id,
            "internal" AS origin
        FROM consultoria_interna_demandas d
        INNER JOIN consultoria_interna_demandas_attachments a ON a.demand_id = d.id
        WHERE d.external_item_id = ?
        ORDER BY a.id DESC
    ');
    $stmt->execute([$itemId]);
    return array_merge($attachments, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function ce_api_save_attachments(PDO $pdo, int $itemId, int $userId): array {
    if (empty($_FILES['anexos'])) {
        return [];
    }

    $files = $_FILES['anexos'];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $types = is_array($files['type']) ? $files['type'] : [$files['type']];

    $allowedExt = ['pdf','doc','docx','csv','xls','xlsx','xml','txt','rtf','odt','pptx','jpg','jpeg','png','gif','bmp','webp','jfif'];
    $dir = dirname(__DIR__) . '/uploads/consultoria_externa/' . $itemId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $insert = $pdo->prepare('INSERT INTO consultoria_externa_attachments (item_id, user_id, filename, stored_name, file_path, mimetype, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $saved = [];

    foreach ($names as $idx => $name) {
        if (($errors[$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($errors[$idx] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Falha no upload de anexo');
        }
        if ((int)($sizes[$idx] ?? 0) > 10 * 1024 * 1024) {
            throw new Exception('Arquivo muito grande. Limite de 10MB por arquivo.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)$name));
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            throw new Exception('Tipo de arquivo nao permitido: ' . $safeName);
        }

        $storedName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
        $targetPath = $dir . '/' . $storedName;
        if (!move_uploaded_file((string)$tmpNames[$idx], $targetPath)) {
            throw new Exception('Falha ao salvar anexo');
        }

        $relativePath = 'uploads/consultoria_externa/' . $itemId . '/' . $storedName;
        $insert->execute([
            $itemId,
            $userId,
            $safeName,
            $storedName,
            $relativePath,
            $types[$idx] ?? null,
            (int)($sizes[$idx] ?? 0),
        ]);

        $saved[] = [
            'attachment_id' => (int)$pdo->lastInsertId(),
            'item_id' => $itemId,
            'filename' => $safeName,
            'mimetype' => $types[$idx] ?? null,
            'file_size' => (int)($sizes[$idx] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
            'origin' => 'external',
        ];
    }

    return $saved;
}

try {
    ce_ensure_stage_tables($pdo);
    ce_seed_default_stages($pdo, $userId);

    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, client_name, email, phone, cpf_cnpj, cidade, source, status, ultimo_contato, created_entry_at, consumo, estimativa_kwh, value, forma_pagamento_id, notes, stage_key, stage_id, exported_to_internal_queue, exported_at, created_at, updated_at FROM consultoria_externa_itens WHERE user_id = ? AND COALESCE(deleted, 0) = 0 ORDER BY created_at DESC, id DESC');
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, client_name, email, phone, cpf_cnpj, cidade, source, status, ultimo_contato, created_entry_at, consumo, estimativa_kwh, value, forma_pagamento_id, notes, stage_key, stage_id, exported_to_internal_queue, exported_at, created_at, updated_at FROM consultoria_externa_itens WHERE id = ? AND user_id = ? AND COALESCE(deleted, 0) = 0 LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row) {
            $row['attachments'] = ce_api_fetch_attachments($pdo, $id);
        }
        echo json_encode($row);
        exit;
    }

    if ($action === 'add') {
        $data = ce_api_payload();
        if ($data['client_name'] === '') {
            throw new Exception('Informe o nome');
        }

        $stageKey = ce_api_norm_stage($data['status']);
        $stageId = ce_resolve_stage_id($pdo, $userId, $data['stage_id'], $stageKey);
        $stmt = $pdo->prepare('INSERT INTO consultoria_externa_itens (user_id, client_name, email, phone, cpf_cnpj, cidade, source, status, ultimo_contato, created_entry_at, consumo, estimativa_kwh, value, forma_pagamento_id, notes, stage_key, stage_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$userId, $data['client_name'], $data['email'], $data['phone'], $data['cpf_cnpj'], $data['cidade'], $consultorName, $data['status'], $data['ultimo_contato'] ?: null, $data['created_entry_at'] ?: null, $data['consumo'] ?: null, $data['estimativa_kwh'] ?: null, $data['value'], $data['forma_pagamento_id'] ?: null, $data['notes'], $stageKey, $stageId]);
        $newId = (int)$pdo->lastInsertId();
        ce_api_save_attachments($pdo, $newId, $userId);
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
        $stmt = $pdo->prepare('UPDATE consultoria_externa_itens SET client_name=?, email=?, phone=?, cpf_cnpj=?, cidade=?, source=?, status=?, ultimo_contato=?, created_entry_at=?, consumo=?, estimativa_kwh=?, value=?, forma_pagamento_id=?, notes=?, stage_key=?, stage_id=?, updated_at=NOW() WHERE id=? AND user_id=?');
        $stmt->execute([$data['client_name'], $data['email'], $data['phone'], $data['cpf_cnpj'], $data['cidade'], $consultorName, $data['status'], $data['ultimo_contato'] ?: null, $data['created_entry_at'] ?: null, $data['consumo'] ?: null, $data['estimativa_kwh'] ?: null, $data['value'], $data['forma_pagamento_id'] ?: null, $data['notes'], $stageKey, $stageId, $id, $userId]);
        ce_api_save_attachments($pdo, $id, $userId);
        ce_export_item_if_needed($pdo, $id, $userId);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'upload_attachment') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare('SELECT id FROM consultoria_externa_itens WHERE id = ? AND user_id = ? AND COALESCE(deleted, 0) = 0 LIMIT 1');
        $stmt->execute([$id, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Registro nao encontrado');
        }
        $saved = ce_api_save_attachments($pdo, $id, $userId);
        echo json_encode(['ok' => true, 'attachments' => $saved]);
        exit;
    }

    if ($action === 'download_attachment') {
        $attachmentId = (int)($_GET['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            throw new Exception('Anexo invalido');
        }
        $stmt = $pdo->prepare('
            SELECT a.*
            FROM consultoria_externa_attachments a
            INNER JOIN consultoria_externa_itens i ON i.id = a.item_id
            WHERE a.id = ? AND i.user_id = ? AND COALESCE(i.deleted, 0) = 0
            LIMIT 1
        ');
        $stmt->execute([$attachmentId, $userId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            throw new Exception('Anexo nao encontrado');
        }
        $path = dirname(__DIR__) . '/' . ltrim((string)$att['file_path'], '/\\');
        if (!is_file($path)) {
            throw new Exception('Arquivo nao encontrado');
        }
        header('Content-Type: ' . ($att['mimetype'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($att['filename'] ?: 'anexo') . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if ($action === 'delete_attachment') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            throw new Exception('Anexo invalido');
        }

        $stmt = $pdo->prepare('
            SELECT a.id, a.item_id, a.file_path
            FROM consultoria_externa_attachments a
            INNER JOIN consultoria_externa_itens i ON i.id = a.item_id
            WHERE a.id = ? AND i.user_id = ? AND COALESCE(i.deleted, 0) = 0
            LIMIT 1
        ');
        $stmt->execute([$attachmentId, $userId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            throw new Exception('Anexo nao encontrado');
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM consultoria_externa_attachments WHERE id = ? LIMIT 1');
            $del->execute([$attachmentId]);

            $path = dirname(__DIR__) . '/' . ltrim((string)($att['file_path'] ?? ''), '/\\');
            if ($path && is_file($path)) {
                @unlink($path);
            }

            $dir = dirname($path);
            $expectedRoot = realpath(dirname(__DIR__) . '/uploads/consultoria_externa');
            $realDir = $dir && is_dir($dir) ? realpath($dir) : false;
            if ($expectedRoot && $realDir && strpos($realDir, $expectedRoot) === 0) {
                $files = @scandir($realDir);
                if (is_array($files) && !array_diff($files, ['.', '..'])) {
                    @rmdir($realDir);
                }
            }

            $pdo->commit();
            echo json_encode(['ok' => true]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'move_stage') {
        $id = (int)($_POST['id'] ?? 0);
        $stageId = ce_resolve_global_stage_id($pdo, (int)($_POST['stage_id'] ?? 0));
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
