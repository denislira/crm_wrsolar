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

// Ensure lead_movements table exists (audit trail of stage/status changes)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        user_id INT NOT NULL,
        from_stage_id INT NULL,
        to_stage_id INT NULL,
        from_status VARCHAR(255) NULL,
        to_status VARCHAR(255) NULL,
        changed_by INT NULL,
        note TEXT NULL,
        is_alert TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lead_id), INDEX (user_id), INDEX (from_stage_id), INDEX (to_stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // not critical — if creation fails we'll still proceed without movements logging
}

// Helper: inserts a movement record (best-effort, swallow errors)
function _log_lead_movement($pdo, $leadId, $userId, $fromStageId, $toStageId, $fromStatus, $toStatus, $changedBy = null, $note = null, $isAlert = 0) {
    try {
        $ins = $pdo->prepare('INSERT INTO lead_movements (lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $ins->execute([$leadId, $userId, $fromStageId, $toStageId, $fromStatus, $toStatus, $changedBy, $note, $isAlert]);
    } catch (Exception $e) {
        // log but don't break main flow
        @file_put_contents(__DIR__ . '/../logs/lead_movements.log', "[".date('Y-m-d H:i:s')."] movement log failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}


    if ($action === 'search') {
        $query = $_GET['q'] ?? '';
        if (strlen($query) < 2) { 
            echo json_encode([]); 
            exit; 
        }
        $stmt = $pdo->prepare("SELECT id, name, email, phone FROM leads WHERE user_id = ? AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 10");
        $like = '%' . $query . '%';
        $stmt->execute([$userId, $like, $like, $like]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'list') {
        try {
            $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads WHERE user_id = ? ORDER BY created_at DESC');
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // fallback if error
            $rows = [];
        }
        // ensure orcamento_value exists for compatibility
        foreach ($rows as &$r) { if (!isset($r['orcamento_value'])) $r['orcamento_value'] = 0; }
        unset($r);
        echo json_encode($rows);
        exit;
    }

    if ($action === 'get') {
        if (empty($_GET['id'])) { throw new Exception('Missing id'); }
        try {
            $sql = 'SELECT l.id, l.user_id, l.name, l.email, l.phone, l.cpf_cnpj, l.source, l.status, l.stage_id, l.notes, l.consumo_cliente, l.estimativa_projeto_kwh, l.orcamento_value, l.anexos_filename, l.anexos_mimetype, l.created_at, l.updated_at '
                 . 'FROM leads l '
                 . 'WHERE l.id = ? AND l.user_id = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_GET['id'], $userId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads WHERE id = ? AND user_id = ?');
            $stmt->execute([$_GET['id'], $userId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lead && !isset($lead['orcamento_value'])) $lead['orcamento_value'] = 0;
        }
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

        $stmt = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, anexos, anexos_filename, anexos_mimetype, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
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
            !empty($data['orcamento_value']) ? floatval($data['orcamento_value']) : 0,
            $anexos_blob,
            $anexos_filename,
            $anexos_mimetype
        ]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }

        // Fetch previous state to detect changes
        $pre = $pdo->prepare('SELECT id, status, stage_id FROM leads WHERE id = ? AND user_id = ? LIMIT 1');
        $pre->execute([$data['id'], $userId]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC);
        $fromStatus = $prev['status'] ?? null;
        $fromStageId = isset($prev['stage_id']) ? (int)$prev['stage_id'] : null;

        // Resolve stage_id and status similar to add action so both stay in sync
        $resolvedStatus = isset($data['status']) && trim($data['status']) !== '' ? $data['status'] : ($fromStatus ?? 'Novo');
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
            !empty($data['estimativa_projeto_kwh']) ? floatval($data['estimativa_projeto_kwh']) : null,
            !empty($data['orcamento_value']) ? floatval($data['orcamento_value']) : 0
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
        $stmt = $pdo->prepare('UPDATE leads SET name=?, email=?, phone=?, cpf_cnpj=?, source=?, status=?, stage_id=?, notes=?, consumo_cliente=?, estimativa_projeto_kwh=?, orcamento_value=?, updated_at=NOW()' . $updateAnexos . ' WHERE id=? AND user_id=?');
        $stmt->execute($params);

        // If status or stage changed, log movement
        try {
            if ($fromStatus !== $resolvedStatus || $fromStageId !== $resolvedStageId) {
                $changedBy = $_SESSION['user_id'] ?? null;
                _log_lead_movement($pdo, (int)$data['id'], $userId, $fromStageId, $resolvedStageId, $fromStatus, $resolvedStatus, $changedBy, 'Atualização via edit', 0);
            }
        } catch (Exception $e) { /* swallow */ }

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

        // Fetch previous state to record movement
        $pre = $pdo->prepare('SELECT id, status, stage_id, user_id FROM leads WHERE id = ? AND user_id = ? LIMIT 1');
        $pre->execute([$data['id'], $userId]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC);
        $fromStatus = $prev['status'] ?? null;
        $fromStageId = isset($prev['stage_id']) ? (int)$prev['stage_id'] : null;

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

        // Log immutable movement for audit & metrics (best-effort)
        try {
            // use session user_id as changed_by if available
            $changedBy = $_SESSION['user_id'] ?? null;
            _log_lead_movement($pdo, (int)$data['id'], $userId, $fromStageId, $resolvedStageId, $fromStatus, $data['status'], $changedBy, null, 0);
        } catch (Exception $e) { /* swallow */ }

        // Auto-create task if stage has generate_task_on_enter enabled
        $taskCreated = false;
        if ($resolvedStageId) {
            try {
                // Fetch full stage row and support legacy column names (name / stage_name, generate_task_on_enter)
                $stageCheck = $pdo->prepare('SELECT * FROM funil_stages WHERE id = ? AND user_id = ? LIMIT 1');
                $stageCheck->execute([$resolvedStageId, $userId]);
                $stageData = $stageCheck->fetch(PDO::FETCH_ASSOC);

                // write debug snapshot to logs for diagnosis
                try {
                    $dbg = ['ts'=>date('c'),'lead_id'=>$data['id'],'resolvedStageId'=>$resolvedStageId,'stage_row'=>$stageData];
                    file_put_contents(__DIR__ . '/../logs/auto_task_debug.log', json_encode($dbg, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
                } catch (Exception $e) { /* ignore */ }

                // normalize possible column names
                $stageName = $stageData['name'] ?? ($stageData['stage_name'] ?? null);
                $generateFlag = null;
                if (array_key_exists('generate_task_on_enter', $stageData)) $generateFlag = $stageData['generate_task_on_enter'];
                elseif (array_key_exists('generate_task', $stageData)) $generateFlag = $stageData['generate_task'];

                if ($stageData && !empty($generateFlag)) {
                    // Fetch lead info for task title
                    $leadInfo = $pdo->prepare('SELECT name, email, phone FROM leads WHERE id = ? AND user_id = ? LIMIT 1');
                    $leadInfo->execute([$data['id'], $userId]);
                    $lead = $leadInfo->fetch(PDO::FETCH_ASSOC);
                    
                    // Create task
                    $taskTitle = "Ação necessária: " . ($lead['name'] ?? 'Lead') . " entrou em " . ($stageName ?? 'estágio');
                    $taskDesc = "Lead movido para o estágio '" . ($stageName ?? '') . "'.\n";
                    $taskDesc .= "Contato: " . ($lead['email'] ?? '') . " " . ($lead['phone'] ?? '');
                    
                    $taskInsert = $pdo->prepare('INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, data_vencimento) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY))');
                    $taskInsert->execute([
                        $userId,
                        'Vendas',
                        $taskTitle,
                        $taskDesc,
                        'Pendente'
                    ]);
                    $taskCreated = (bool)$pdo->lastInsertId();
                    error_log("Auto-task created for lead {$data['id']} -> task_id=" . $pdo->lastInsertId());
                }
            } catch (Exception $e) { 
                error_log("Auto-task creation failed: " . $e->getMessage());
            }
        }

        // Return whether a task was created for easier debugging on client
        echo json_encode(['ok' => true, 'task_created' => $taskCreated]);
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

    // Movements listing for a lead (audit trail)
    if ($action === 'movements') {
        $leadId = $_GET['lead_id'] ?? ($_POST['lead_id'] ?? null);
        if (empty($leadId)) { throw new Exception('Missing lead_id'); }
        $m = $pdo->prepare('SELECT id, lead_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE lead_id = ? AND user_id = ? ORDER BY created_at ASC');
        $m->execute([$leadId, $userId]);
        $rows = $m->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

