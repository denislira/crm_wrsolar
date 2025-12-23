<?php
// Simple leads API for CRUD and status updates
// Usage:
// GET  ?action=list           -> returns JSON list of leads for current user
// POST action=add             -> add new lead (name,email,phone,source,status)
// POST action=update          -> update lead by id
// POST action=delete          -> delete lead by id
// POST action=update_status   -> update only status by id

header('Content-Type: application/json');
// Enable verbose errors during debugging and capture uncaught errors to log
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function($e){
    // try to log and return JSON
    $msg = '['.date('c').'] Uncaught exception: ' . $e->getMessage();
    @file_put_contents(__DIR__ . '/../logs/leads_api_errors.log', $msg . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR))) {
        $msg = '['.date('c').'] Shutdown error: ' . json_encode($err);
        @file_put_contents(__DIR__ . '/../logs/leads_api_errors.log', $msg . "\n", FILE_APPEND | LOCK_EX);
        http_response_code(500);
        echo json_encode(['error' => $err['message'] ?? 'Shutdown error']);
        exit;
    }
});
// Guarded session start to prevent warnings if session already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

// Simple logger to help diagnose 500 errors during development
function _leads_api_log($msg) {
    $logDir = realpath(__DIR__ . '/../logs') ?: (__DIR__ . '/../logs');
    if (!is_dir($logDir)) {@mkdir($logDir, 0777, true);} 
    $file = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'leads_api_errors.log';
    $entry = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

// Ensure leads table has stage_id column for robust mapping to funil stages
try {
    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'stage_id'");
    $colCheck->execute();
    $hasStageId = (bool)$colCheck->fetchColumn();
    if (!$hasStageId) {
        // add nullable stage_id
        $pdo->exec("ALTER TABLE leads ADD COLUMN stage_id INT NULL AFTER status");
        // Attempt to populate stage_id for existing rows by matching status -> funil_stages (per user)
        // This will set stage_id where a stage with the same name exists for the same user
        // initial best-effort update; will be attempted again after detecting exact column name
        try { $pdo->exec("UPDATE leads l JOIN funil_stages s ON l.user_id = s.user_id AND l.status = s.name SET l.stage_id = s.id"); } catch(Exception $e) { /* ignore */ }
    }
} catch (Exception $e) {
    // ignore migration errors here; API will continue to work without stage_id
}

// Detect funil_stages column names to support legacy schemas (stage_name vs name)
try {
    $fsColCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $fsColCheck->execute();
    $fsExisting = $fsColCheck->fetchAll(PDO::FETCH_COLUMN);
    $FS_NAME_COL = in_array('name', $fsExisting) ? 'name' : (in_array('stage_name', $fsExisting) ? 'stage_name' : 'name');
} catch (Exception $e) {
    $FS_NAME_COL = 'name';
}

// After we know the correct name column for funil_stages, attempt to populate stage_id for existing leads
try {
    $pdo->exec("UPDATE leads l JOIN funil_stages s ON l.user_id = s.user_id AND l.status = s.{$FS_NAME_COL} SET l.stage_id = s.id");
} catch (Exception $e) {
    // ignore, non-critical
}

try {
    if ($action === 'list') {
        // Excluir o campo LONGBLOB 'anexos' da listagem para evitar problemas com JSON
        $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($action === 'get') {
        if (empty($_GET['id'])) { throw new Exception('Missing id'); }
        $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads WHERE id = ? AND user_id = ?');
        $stmt->execute([$_GET['id'], $userId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            http_response_code(404);
            echo json_encode(['error' => 'Lead not found']);
            exit;
        }
        echo json_encode($lead);
        exit;
    }

    // Read input for POST (form or JSON)
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $data = $parsed;
    }

    if ($action === 'add') {
        // Processar arquivos anexados
        $anexos_blob = null;
        $anexos_filename = null;
        $anexos_mimetype = null;
        
        if (!empty($_FILES['anexos']) && $_FILES['anexos']['error'][0] === UPLOAD_ERR_OK) {
            // Para simplificar, vamos pegar apenas o primeiro arquivo
            $file = $_FILES['anexos'];
            $anexos_filename = $file['name'][0];
            $anexos_mimetype = $file['type'][0];
            $anexos_blob = file_get_contents($file['tmp_name'][0]);
        }
        
        // Ensure the lead status maps to an existing funil stage. If not, pick the first stage for this user.
        // Resolve incoming stage_id or status to a valid stage and keep both stage_id and status for compatibility
        $resolvedStatus = 'Novo';
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            // If stage_id provided, fetch its name (and ensure it belongs to the user)
            $s = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1");
            $s->execute([$data['stage_id'], $userId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $resolvedStageId = (int)$row['id'];
                $resolvedStatus = $row['name'];
            }
        }
        if ($resolvedStageId === null) {
            // Fallback: try resolve by incoming status text
            $incomingStatus = isset($data['status']) && trim($data['status']) !== '' ? $data['status'] : null;
            if ($incomingStatus) {
                $s = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE user_id = ? AND {$FS_NAME_COL} = ? LIMIT 1");
                $s->execute([$userId, $incomingStatus]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $resolvedStageId = (int)$row['id'];
                    $resolvedStatus = $row['name'];
                } else {
                    // if no matching stage, pick first stage for the user if exists
                    $posCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages' AND COLUMN_NAME = 'position'");
                    $posCheck->execute();
                    $hasPos = (bool)$posCheck->fetchColumn();
                    if ($hasPos) {
                            $s2 = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE user_id = ? ORDER BY position ASC LIMIT 1");
                    } else {
                        $s2 = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                    }
                    $s2->execute([$userId]);
                    $first = $s2->fetch(PDO::FETCH_ASSOC);
                    if ($first) { $resolvedStageId = (int)$first['id']; $resolvedStatus = $first['name']; }
                    else { if ($incomingStatus) $resolvedStatus = $incomingStatus; }
                }
            } else {
                // No incoming info; try to pick first stage
                $s2 = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE user_id = ? ORDER BY COALESCE(position, id) ASC LIMIT 1");
                $s2->execute([$userId]);
                $first = $s2->fetch(PDO::FETCH_ASSOC);
                if ($first) { $resolvedStageId = (int)$first['id']; $resolvedStatus = $first['name']; }
            }
        }

        $stmt = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, anexos, anexos_filename, anexos_mimetype, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([
            $userId,
            $data['name'] ?? '',
            $data['email'] ?? '',
            $data['phone'] ?? '',
            $data['cpf_cnpj'] ?? '',
            $data['source'] ?? '',
            $resolvedStatus,
            $resolvedStageId,
            $data['notes'] ?? '',
            !empty($data['consumo_cliente']) ? floatval($data['consumo_cliente']) : null,
            !empty($data['estimativa_projeto_kwh']) ? floatval($data['estimativa_projeto_kwh']) : null,
            $anexos_blob,
            $anexos_filename,
            $anexos_mimetype
        ]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }

        // Resolve stage_id and status similar to add action so both stay in sync
        $resolvedStatus = isset($data['status']) && trim($data['status']) !== '' ? $data['status'] : 'Novo';
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            $s = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1");
            $s->execute([$data['stage_id'], $userId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) { $resolvedStageId = (int)$row['id']; $resolvedStatus = $row['name']; }
        }
        if ($resolvedStageId === null && !empty($data['status'])) {
            $s = $pdo->prepare("SELECT id, {$FS_NAME_COL} AS name FROM funil_stages WHERE user_id = ? AND {$FS_NAME_COL} = ? LIMIT 1");
            $s->execute([$userId, $data['status']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) { $resolvedStageId = (int)$row['id']; $resolvedStatus = $row['name']; }
        }

        // Processar arquivos anexados se houver novos
        $updateAnexos = '';
        $params = [
            $data['name'] ?? '',
            $data['email'] ?? '',
            $data['phone'] ?? '',
            $data['cpf_cnpj'] ?? '',
            $data['source'] ?? '',
            $resolvedStatus,
            $resolvedStageId,
            $data['notes'] ?? '',
            !empty($data['consumo_cliente']) ? floatval($data['consumo_cliente']) : null,
            !empty($data['estimativa_projeto_kwh']) ? floatval($data['estimativa_projeto_kwh']) : null
        ];

        if (!empty($_FILES['anexos']) && $_FILES['anexos']['error'][0] === UPLOAD_ERR_OK) {
            $file = $_FILES['anexos'];
            $anexos_filename = $file['name'][0];
            $anexos_mimetype = $file['type'][0];
            $anexos_blob = file_get_contents($file['tmp_name'][0]);

            $updateAnexos = ', anexos=?, anexos_filename=?, anexos_mimetype=?';
            $params = array_merge($params, [$anexos_blob, $anexos_filename, $anexos_mimetype]);
        }

        $params[] = $data['id'];
        $params[] = $userId;

        // Include stage_id column in the update SQL
        $stmt = $pdo->prepare('UPDATE leads SET name=?, email=?, phone=?, cpf_cnpj=?, source=?, status=?, stage_id=?, notes=?, consumo_cliente=?, estimativa_projeto_kwh=?, updated_at=NOW()' . $updateAnexos . ' WHERE id=? AND user_id=?');
        $stmt->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }
        $stmt = $pdo->prepare('DELETE FROM leads WHERE id=? AND user_id=?');
        $stmt->execute([$data['id'], $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_status') {
        if (empty($data['id']) || !isset($data['status'])) { throw new Exception('Missing id or status'); }

        // Try to resolve a stage_id for the provided status or explicit stage_id
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            $s = $pdo->prepare('SELECT id FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1');
            $s->execute([$data['stage_id'], $userId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $resolvedStageId = (int)$r['id'];
        }
        if ($resolvedStageId === null && !empty($data['status'])) {
            $s = $pdo->prepare("SELECT id FROM funil_stages WHERE user_id = ? AND {$FS_NAME_COL} = ? LIMIT 1");
            $s->execute([$userId, $data['status']]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $resolvedStageId = (int)$r['id'];
        }

        $stmt = $pdo->prepare('UPDATE leads SET status=?, stage_id=?, updated_at=NOW() WHERE id=? AND user_id=?');
        $stmt->execute([$data['status'], $resolvedStageId, $data['id'], $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'download_anexo') {
        if (empty($_GET['id'])) { throw new Exception('Missing id'); }
        $stmt = $pdo->prepare('SELECT anexos, anexos_filename, anexos_mimetype FROM leads WHERE id=? AND user_id=?');
        $stmt->execute([$_GET['id'], $userId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead || !$lead['anexos']) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        
        header('Content-Type: ' . ($lead['anexos_mimetype'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . ($lead['anexos_filename'] ?: 'anexo') . '"');
        header('Content-Length: ' . strlen($lead['anexos']));
        echo $lead['anexos'];
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    // Log detailed info for debugging (avoid logging raw file contents)
    try {
        $summary = [];
        $summary['action'] = $action;
        $summary['user_id'] = $userId ?? 'unknown';
        $summary['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'N/A';
        $summary['post_keys'] = array_keys($_POST);
        $summary['files'] = array_map(function($f){ return ['name'=>$f['name'] ?? null, 'size'=>isset($f['size'])?$f['size']:null]; }, $_FILES ?: []);
        $summary['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $summary['error'] = $e->getMessage();
        $summary['trace'] = $e->getTraceAsString();
        _leads_api_log(json_encode($summary));
    } catch (Exception $inner) {
        // swallow
        @file_put_contents(__DIR__ . '/../logs/leads_api_errors.log', "[".date('c')."] log failure: " . $inner->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

