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

$userId = (int) $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
$roleName = $_SESSION['role_name'] ?? null;

if (!$roleName && !empty($_SESSION['role_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['role_id']]);
    $roleName = $stmt->fetchColumn();
}

$canAccessDemandQueue = function_exists('isDirector') && isDirector() ? true : hasPermission('fila_demandas');
if (strtolower((string)$roleName) === 'consultor_externo' || !$canAccessDemandQueue) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

try {
    ce_ensure_stage_tables($pdo);

    if ($action === 'list') {
        $status = trim((string)($_GET['status'] ?? ''));
        $where = ['COALESCE(i.deleted, 0) = 0'];
        $params = [];
        if ($status !== '') {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }

        $sql = "
            SELECT
                d.id AS demand_id,
                d.external_item_id,
                d.external_stage_id,
                d.external_user_id,
                d.status AS demand_status,
                d.accepted_by,
                d.accepted_at,
                d.created_at AS queued_at,
                d.updated_at AS demand_updated_at,
                i.client_name,
                i.phone,
                i.cidade,
                i.source,
                i.value,
                i.notes,
                i.status AS external_status,
                i.created_at AS external_created_at,
                s.name AS stage_name,
                s.color AS stage_color,
                u.username AS external_consultor,
                au.username AS accepted_by_name
            FROM consultoria_interna_demandas d
            INNER JOIN consultoria_externa_itens i ON i.id = d.external_item_id
            LEFT JOIN consultoria_externa_stages s ON s.id = d.external_stage_id
            LEFT JOIN users u ON u.id = d.external_user_id
            LEFT JOIN users au ON au.id = d.accepted_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE d.status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
                d.created_at DESC,
                d.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'attachments') {
        $demandId = (int)($_GET['demand_id'] ?? 0);
        if ($demandId <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare('SELECT id FROM consultoria_interna_demandas WHERE id = ? LIMIT 1');
        $stmt->execute([$demandId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Demanda nao encontrada');
        }
        $stmt = $pdo->prepare('SELECT id, filename, mimetype, file_size, created_at, user_id FROM consultoria_interna_demandas_attachments WHERE demand_id = ? ORDER BY id DESC');
        $stmt->execute([$demandId]);
        echo json_encode(['ok' => true, 'attachments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'upload_attachment') {
        $demandId = (int)($_POST['demand_id'] ?? 0);
        if ($demandId <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare('SELECT id FROM consultoria_interna_demandas WHERE id = ? LIMIT 1');
        $stmt->execute([$demandId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Demanda nao encontrada');
        }
        if (empty($_FILES['attachment'])) {
            throw new Exception('Nenhum arquivo enviado');
        }
        $file = $_FILES['attachment'];
        if (!empty($file['error'])) {
            throw new Exception('Falha no upload');
        }
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new Exception('Arquivo muito grande');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)($file['name'] ?? 'arquivo')));
        $storedName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
        $dir = dirname(__DIR__) . '/uploads/demandas/' . $demandId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $targetPath = $dir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Falha ao salvar arquivo');
        }

        $relativePath = 'uploads/demandas/' . $demandId . '/' . $storedName;
        $stmt = $pdo->prepare('INSERT INTO consultoria_interna_demandas_attachments (demand_id, user_id, filename, stored_name, file_path, mimetype, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $demandId,
            $userId,
            $safeName,
            $storedName,
            $relativePath,
            $file['type'] ?? null,
            (int)($file['size'] ?? 0),
        ]);

        echo json_encode(['ok' => true, 'attachment' => [
            'id' => (int)$pdo->lastInsertId(),
            'filename' => $safeName,
            'mimetype' => $file['type'] ?? null,
            'file_size' => (int)($file['size'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
            'file_path' => $relativePath
        ]]);
        exit;
    }

    if ($action === 'download_attachment') {
        $attachmentId = (int)($_GET['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            throw new Exception('Anexo invalido');
        }
        $stmt = $pdo->prepare('SELECT a.*, d.id AS demand_exists FROM consultoria_interna_demandas_attachments a INNER JOIN consultoria_interna_demandas d ON d.id = a.demand_id WHERE a.id = ? LIMIT 1');
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            throw new Exception('Anexo nao encontrado');
        }
        $path = dirname(__DIR__) . '/' . $att['file_path'];
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

        $stmt = $pdo->prepare('SELECT a.id, a.demand_id, a.file_path FROM consultoria_interna_demandas_attachments a INNER JOIN consultoria_interna_demandas d ON d.id = a.demand_id WHERE a.id = ? LIMIT 1');
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            throw new Exception('Anexo nao encontrado');
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM consultoria_interna_demandas_attachments WHERE id = ? LIMIT 1');
            $del->execute([$attachmentId]);

            $path = dirname(__DIR__) . '/' . ltrim((string)($att['file_path'] ?? ''), '/\\');
            if ($path && is_file($path)) {
                @unlink($path);
            }

            $dir = dirname($path);
            if ($dir && is_dir($dir)) {
                $files = @scandir($dir);
                if (is_array($files)) {
                    $remaining = array_diff($files, ['.', '..']);
                    if (!$remaining) {
                        @rmdir($dir);
                    }
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

    if ($action === 'accept') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'accepted', accepted_by = ?, accepted_at = COALESCE(accepted_at, NOW()), updated_at = NOW() WHERE id = ? AND status IN ('pending', 'accepted')");
        $stmt->execute([$userId, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'complete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'done', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reopen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'pending', accepted_by = NULL, accepted_at = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new Exception('Acao invalida');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
