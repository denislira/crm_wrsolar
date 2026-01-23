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

// Ensure a string is valid UTF-8; attempt to convert from common single-byte encodings
function ensure_utf8_local($s) {
    if ($s === null) return null;
    $s = (string)$s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    // Try detecting the source encoding first
    $detected = @mb_detect_encoding($s, ['UTF-8','Windows-1252','ISO-8859-1','CP1252','ASCII'], true);
    if ($detected && $detected !== 'UTF-8') {
        $try = @mb_convert_encoding($s, 'UTF-8', $detected);
        if ($try !== false && mb_check_encoding($try, 'UTF-8')) {
            _leads_api_log("ensure_utf8_local: converted from detected encoding {$detected}");
            return $try;
        }
    }
    foreach (['Windows-1252','ISO-8859-1'] as $enc) {
        $try = @mb_convert_encoding($s, 'UTF-8', $enc);
        if ($try !== false && mb_check_encoding($try, 'UTF-8')) {
            _leads_api_log("ensure_utf8_local: converted from {$enc} (fallback)");
            return $try;
        }
    }
    $clean = @preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]+/u', '', $s);
    if ($clean !== null && mb_check_encoding($clean, 'UTF-8')) { _leads_api_log('ensure_utf8_local: stripped invalid bytes'); return $clean; }
    $forced = @iconv('CP1252', 'UTF-8//IGNORE', $s);
    if ($forced !== false && mb_check_encoding($forced, 'UTF-8')) return $forced;
    return str_replace("\xEF\xBF\xBD", '', $s);
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

// Create attachments table to support multiple files per lead
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        user_id INT NOT NULL,
        filename VARCHAR(255) NULL,
        mimetype VARCHAR(255) NULL,
        data LONGBLOB NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lead_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // ignore non-fatal
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
        $stmt = $pdo->prepare("SELECT id, name, email, phone FROM leads WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 10");
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like, $like]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'list') {
        try {
            $stmt = $pdo->prepare('SELECT id, user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads ORDER BY created_at DESC');
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // fallback if error
            $rows = [];
        }
        // ensure orcamento_value exists for compatibility
        foreach ($rows as &$r) { if (!isset($r['orcamento_value'])) $r['orcamento_value'] = 0; }
        unset($r);

        // Attach attachments summary (count + filenames) for each lead
        try {
            $leadIds = array_column($rows, 'id');
            if (!empty($leadIds)) {
                // build placeholder list
                $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                $params = $leadIds;
                $sql = 'SELECT lead_id, id AS attachment_id, filename FROM leads_attachments WHERE lead_id IN (' . $placeholders . ') ORDER BY id ASC';
                $attStmt = $pdo->prepare($sql);
                $attStmt->execute($params);
                $atts = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                $map = [];
                foreach ($atts as $a) {
                    $lid = $a['lead_id'];
                    if (!isset($map[$lid])) $map[$lid] = [];
                    $map[$lid][] = ['attachment_id' => $a['attachment_id'], 'filename' => $a['filename']];
                }
                foreach ($rows as &$r) {
                    $r['anexos_count'] = isset($map[$r['id']]) ? count($map[$r['id']]) : 0;
                    $r['anexos_files'] = isset($map[$r['id']]) ? $map[$r['id']] : [];
                }
                unset($r);
            }
        } catch (Exception $e) {
            // ignore attachment summary errors
        }
        echo json_encode($rows);
        exit;
    }

    if ($action === 'get') {
        if (empty($_GET['id'])) { throw new Exception('Missing id'); }
        try {
                  $sql = 'SELECT l.id, l.user_id, l.name, l.cidade, l.email, l.phone, l.cpf_cnpj, l.source, l.status, l.stage_id, l.notes, l.consumo_cliente, l.estimativa_projeto_kwh, l.orcamento_value, l.envio_proposta, l.anexos_filename, l.anexos_mimetype, l.created_at, l.updated_at '
                      . 'FROM leads l '
                      . 'WHERE l.id = ? LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$_GET['id']]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, anexos_filename, anexos_mimetype, created_at, updated_at FROM leads WHERE id = ?');
            $stmt->execute([$_GET['id']]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lead && !isset($lead['orcamento_value'])) $lead['orcamento_value'] = 0;
        }
        if (!$lead) {
            http_response_code(404);
            echo json_encode(['error' => 'Lead not found']);
            exit;
        }

        // Fetch attachments for this lead
        try {
            $att = $pdo->prepare('SELECT id AS attachment_id, filename, mimetype FROM leads_attachments WHERE lead_id = ? ORDER BY id ASC');
            $att->execute([$_GET['id']]);
            $attachments = $att->fetchAll(PDO::FETCH_ASSOC);
            $lead['anexos_count'] = count($attachments);
            $lead['anexos_files'] = $attachments;
        } catch (Exception $e) {
            $lead['anexos_count'] = 0;
            $lead['anexos_files'] = [];
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

    // If a POST request arrives with empty $_POST and no $_FILES it is very likely
    // that the request exceeded PHP's `post_max_size` or `upload_max_filesize`.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($data) && empty($_FILES)) {
        // Convert ini shorthand (e.g. "8M") to bytes
        $toBytes = function($val) {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $num = (int)$val;
            switch($last) {
                case 'g': $num *= 1024;
                case 'm': $num *= 1024;
                case 'k': $num *= 1024;
            }
            return $num;
        };

        $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        $postMax = ini_get('post_max_size') ?: '8M';
        $postMaxBytes = $toBytes($postMax);

        if ($contentLen > 0 && $contentLen > $postMaxBytes) {
            http_response_code(413);
            $msg = 'Request too large: post_max_size (' . $postMax . ") exceeded";
            _leads_api_log($msg . ' - Content-Length: ' . $contentLen);
            echo json_encode(['error' => $msg]);
            exit;
        }

        // Generic fallback: nothing parsed from the request body
        // Log additional diagnostic info to help debugging (headers, raw input snippet, arrays)
        $diag = [
            'action_param' => $_REQUEST['action'] ?? null,
            'user_id' => $userId ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'post_keys' => array_keys($_POST),
            'files' => array_keys($_FILES),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        // capture request headers if available
        if (function_exists('getallheaders')) {
            $diag['headers'] = getallheaders();
        }
        // capture a small raw input sample (only if not huge)
        $rawSample = '';
        if ($contentLen > 0 && $contentLen < 1024 * 1024) {
            $raw = file_get_contents('php://input');
            $rawSample = substr($raw, 0, 2048);
            $diag['raw_sample'] = $rawSample;
        }

        http_response_code(400);
        $msg = 'Empty POST body (no form data parsed). Check request Content-Type and server upload limits.';
        _leads_api_log($msg . ' - Content-Length: ' . $contentLen . ' post_max_size=' . $postMax . ' - DIAG: ' . json_encode($diag, JSON_UNESCAPED_UNICODE));
        echo json_encode(['error' => $msg]);
        exit;
    }

    if ($action === 'add') {
        // Determine stage_id (if provided) but do NOT overwrite status with stage name.
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            $s = $pdo->prepare("SELECT id FROM funil_stages WHERE id = ? LIMIT 1");
            $s->execute([$data['stage_id']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) { $resolvedStageId = (int)$row['id']; }
        }
        // Prefer explicit status field from input; fallback to 'Novo' when not provided
        $resolvedStatus = isset($data['status']) && trim($data['status']) !== '' ? $data['status'] : 'Novo';
        // ensure utf8 for user-supplied status if present
        if (is_string($resolvedStatus)) { $resolvedStatus = ensure_utf8_local($resolvedStatus); }

        // Insert lead (without blobs) first
        // Normalize envio_proposta if provided (accept YYYY-MM-DD or YYYY-MM-DDTHH:MM)
        $envio = null;
        if (!empty($data['envio_proposta'])) {
            $v = trim($data['envio_proposta']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $v = $v . ' 00:00:00'; }
            elseif (strpos($v, 'T') !== false) { $v = str_replace('T', ' ', $v); if (strlen($v) == 16) $v .= ':00'; }
            $envio = $v;
        }

        $stmt = $pdo->prepare('INSERT INTO leads (user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $params = [
            $userId,
            $data['name'] ?? '',
            $data['cidade'] ?? '',
            $data['email'] ?? '',
            $data['phone'] ?? '',
            $data['cpf_cnpj'] ?? '',
            $data['source'] ?? '',
            $resolvedStatus,
            $resolvedStageId,
            $data['notes'] ?? '',
            !empty($data['consumo_cliente']) ? $data['consumo_cliente'] : null,
            !empty($data['estimativa_projeto_kwh']) ? $data['estimativa_projeto_kwh'] : null,
            !empty($data['orcamento_value']) ? floatval($data['orcamento_value']) : 0,
            $envio
        ];
        foreach ($params as &$p) { if (is_string($p)) $p = ensure_utf8_local($p); }
        $stmt->execute($params);

        $leadId = $pdo->lastInsertId();

        // Handle file attachments (multiple)
        if (!empty($_FILES['anexos'])) {
            $file = $_FILES['anexos'];
            $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
            // validate errors
            foreach ($errors as $err) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) {
                    $msg = 'Erro no upload do arquivo';
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { http_response_code(413); $msg = 'Arquivo muito grande (upload_max_filesize/post_max_size)'; }
                    elseif ($err === UPLOAD_ERR_PARTIAL) { $msg = 'Upload parcial do arquivo'; }
                    _leads_api_log('Upload error code: ' . $err . ' - ' . $msg);
                    echo json_encode(['error' => $msg]);
                    exit;
                }
            }

            $insertAtt = $pdo->prepare('INSERT INTO leads_attachments (lead_id, user_id, filename, mimetype, data, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $firstName = null; $firstType = null; $firstBlob = null; $inserted = 0;
            for ($i = 0; $i < count($file['name']); $i++) {
                if (!isset($file['error'][$i]) || $file['error'][$i] !== UPLOAD_ERR_OK) continue;
                $fname = $file['name'][$i];
                $ftype = $file['type'][$i] ?? 'application/octet-stream';
                $tmp = $file['tmp_name'][$i];
                $blob = file_get_contents($tmp);
                try {
                    $insertAtt->execute([$leadId, $userId, $fname, $ftype, $blob]);
                    if ($inserted === 0) { $firstName = $fname; $firstType = $ftype; $firstBlob = $blob; }
                    $inserted++;
                } catch (Exception $e) {
                    _leads_api_log('Failed inserting attachment: ' . $e->getMessage());
                }
            }

            // Update leads main table to keep legacy single-file fields populated with the first attachment (if any)
            if ($inserted > 0 && $firstName !== null) {
                try {
                    $upd = $pdo->prepare('UPDATE leads SET anexos = ?, anexos_filename = ?, anexos_mimetype = ? WHERE id = ?');
                    $upd->bindParam(1, $firstBlob, PDO::PARAM_LOB);
                    $upd->execute([$firstBlob, $firstName, $firstType, $leadId]);
                } catch (Exception $e) {
                    _leads_api_log('Failed updating lead with first attachment: ' . $e->getMessage());
                }
            }
        }

        echo json_encode(['ok' => true, 'id' => $leadId]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }

        // Fetch previous state to detect changes
        $pre = $pdo->prepare('SELECT id, status, stage_id FROM leads WHERE id = ? LIMIT 1');
        $pre->execute([$data['id']]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC);
        $fromStatus = $prev['status'] ?? null;
        $fromStageId = isset($prev['stage_id']) ? (int)$prev['stage_id'] : null;

        // Prefer explicit status input; if not present, keep previous or default
        $resolvedStatus = isset($data['status']) && trim($data['status']) !== '' ? $data['status'] : ($fromStatus ?? 'Novo');
        // Determine resolvedStageId from provided stage_id if valid; do not change status based on stage name
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            $s = $pdo->prepare("SELECT id FROM funil_stages WHERE id = ? LIMIT 1");
            $s->execute([$data['stage_id']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) { $resolvedStageId = (int)$row['id']; }
        }

        // Processar arquivos anexados se houver novos
        $updateAnexos = '';
        // Validate upload errors similar to add
        if (!empty($_FILES['anexos'])) {
            $file = $_FILES['anexos'];
            $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
            foreach ($errors as $err) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) {
                    $msg = 'Erro no upload do arquivo';
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        http_response_code(413);
                        $msg = 'Arquivo muito grande (upload_max_filesize/post_max_size)';
                    } elseif ($err === UPLOAD_ERR_PARTIAL) {
                        $msg = 'Upload parcial do arquivo';
                    }
                    _leads_api_log('Upload error code (update): ' . $err . ' - ' . $msg);
                    echo json_encode(['error' => $msg]);
                    exit;
                }
            }
        }

        $envio = null;
        if (!empty($data['envio_proposta'])) {
            $v = trim($data['envio_proposta']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $v = $v . ' 00:00:00'; }
            elseif (strpos($v, 'T') !== false) { $v = str_replace('T', ' ', $v); if (strlen($v) == 16) $v .= ':00'; }
            $envio = $v;
        }

        $params = [
            $data['name'] ?? '',
            $data['cidade'] ?? '',
            $data['email'] ?? '',
            $data['phone'] ?? '',
            $data['cpf_cnpj'] ?? '',
            $data['source'] ?? '',
            $resolvedStatus,
            $resolvedStageId,
            $data['notes'] ?? '',
            !empty($data['consumo_cliente']) ? $data['consumo_cliente'] : null,
            !empty($data['estimativa_projeto_kwh']) ? $data['estimativa_projeto_kwh'] : null,
            !empty($data['orcamento_value']) ? floatval($data['orcamento_value']) : 0,
            $envio
        ];

        if (!empty($_FILES['anexos'])) {
            $file = $_FILES['anexos'];
            $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
            foreach ($errors as $err) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) {
                    $msg = 'Erro no upload do arquivo';
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { http_response_code(413); $msg = 'Arquivo muito grande (upload_max_filesize/post_max_size)'; }
                    elseif ($err === UPLOAD_ERR_PARTIAL) { $msg = 'Upload parcial do arquivo'; }
                    _leads_api_log('Upload error code (update): ' . $err . ' - ' . $msg);
                    echo json_encode(['error' => $msg]);
                    exit;
                }
            }

            $insertAtt = $pdo->prepare('INSERT INTO leads_attachments (lead_id, user_id, filename, mimetype, data, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $firstName = null; $firstType = null; $firstBlob = null; $inserted = 0;
            for ($i = 0; $i < count($file['name']); $i++) {
                if (!isset($file['error'][$i]) || $file['error'][$i] !== UPLOAD_ERR_OK) continue;
                $fname = $file['name'][$i];
                $ftype = $file['type'][$i] ?? 'application/octet-stream';
                $tmp = $file['tmp_name'][$i];
                $blob = file_get_contents($tmp);
                try {
                    $insertAtt->execute([$data['id'], $userId, $fname, $ftype, $blob]);
                    if ($inserted === 0) { $firstName = $fname; $firstType = $ftype; $firstBlob = $blob; }
                    $inserted++;
                } catch (Exception $e) {
                    _leads_api_log('Failed inserting attachment (update): ' . $e->getMessage());
                }
            }

            if ($inserted > 0 && $firstName !== null) {
                $updateAnexos = ', anexos=?, anexos_filename=?, anexos_mimetype=?';
                $params = array_merge($params, [$firstBlob, $firstName, $firstType]);
            }
        }

        $params[] = $data['id'];
        $params[] = $userId;

        // Include stage_id column in the update SQL
        $stmt = $pdo->prepare('UPDATE leads SET name=?, cidade=?, email=?, phone=?, cpf_cnpj=?, source=?, status=?, stage_id=?, notes=?, consumo_cliente=?, estimativa_projeto_kwh=?, orcamento_value=?, envio_proposta=?, updated_at=NOW()' . $updateAnexos . ' WHERE id=? AND user_id=?');
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
        $stmt = $pdo->prepare('DELETE FROM leads WHERE id=?');
        $stmt->execute([$data['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_status') {
        if (empty($data['id']) || !isset($data['status'])) { throw new Exception('Missing id or status'); }

        // Fetch previous state to record movement
        $pre = $pdo->prepare('SELECT id, status, stage_id, user_id FROM leads WHERE id = ? LIMIT 1');
        $pre->execute([$data['id']]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC);
        $fromStatus = $prev['status'] ?? null;
        $fromStageId = isset($prev['stage_id']) ? (int)$prev['stage_id'] : null;

        // Try to resolve a stage_id for the provided status or explicit stage_id
        $resolvedStageId = null;
        if (!empty($data['stage_id'])) {
            $s = $pdo->prepare('SELECT id FROM funil_stages WHERE id = ? LIMIT 1');
            $s->execute([$data['stage_id']]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $resolvedStageId = (int)$r['id'];
        }
        if ($resolvedStageId === null && !empty($data['status'])) {
            $s = $pdo->prepare("SELECT id FROM funil_stages WHERE {$FS_NAME_COL} = ? LIMIT 1");
            $s->execute([is_string($data['status']) ? ensure_utf8_local($data['status']) : $data['status']]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $resolvedStageId = (int)$r['id'];
        }

        $stmt = $pdo->prepare('UPDATE leads SET status=?, stage_id=?, updated_at=NOW() WHERE id=?');
        $p0 = is_string($data['status']) ? ensure_utf8_local($data['status']) : $data['status'];
        $stmt->execute([$p0, $resolvedStageId, $data['id']]);

        // Log immutable movement for audit & metrics (best-effort)
        try {
            // use session user_id as changed_by if available
            $changedBy = $_SESSION['user_id'] ?? null;
            _log_lead_movement($pdo, (int)$data['id'], $userId, $fromStageId, $resolvedStageId, $fromStatus, $data['status'], $changedBy, null, 0);
        } catch (Exception $e) { /* swallow */ }

        // Auto-create task DISABLED - tasks should be created manually only
        /*
        $taskCreated = false;
        if ($resolvedStageId) {
            try {
                // Fetch full stage row and support legacy column names (name / stage_name, generate_task_on_enter)
                $stageCheck = $pdo->prepare('SELECT * FROM funil_stages WHERE id = ? LIMIT 1');
                $stageCheck->execute([$resolvedStageId]);
                $stageData = $stageCheck->fetch(PDO::FETCH_ASSOC);

                // write debug snapshot to logs for diagnosis
                try {
                    $dbg = ['ts'=>date('c'),'lead_id'=>$data['id'],'resolvedStageId'=>$resolvedStageId,'stage_row'=>$stageData];
                    file_put_contents(__DIR__ . '/../logs/auto_task_debug.log', json_encode($dbg, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
                } catch (Exception $e) { }

                // normalize possible column names
                $stageName = $stageData['name'] ?? ($stageData['stage_name'] ?? null);
                $generateFlag = null;
                if (array_key_exists('generate_task_on_enter', $stageData)) $generateFlag = $stageData['generate_task_on_enter'];
                elseif (array_key_exists('generate_task', $stageData)) $generateFlag = $stageData['generate_task'];

                if ($stageData && !empty($generateFlag)) {
                    // Fetch lead info for task title
                    $leadInfo = $pdo->prepare('SELECT name, email, phone FROM leads WHERE id = ? LIMIT 1');
                    $leadInfo->execute([$data['id']]);
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
        */

        // Return whether a task was created for easier debugging on client
        echo json_encode(['ok' => true, 'task_created' => false]); // Always false since auto-creation disabled
        exit;
    }

    if ($action === 'download_anexo') {
        if (empty($_GET['id'])) { throw new Exception('Missing id'); }
        $leadId = $_GET['id'];
        $fileId = $_GET['file_id'] ?? null;

        // If a specific attachment id requested, serve from leads_attachments
        if ($fileId) {
            $att = $pdo->prepare('SELECT filename, mimetype, data FROM leads_attachments WHERE id = ? LIMIT 1');
            $att->execute([$fileId]);
            $row = $att->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['data']) { http_response_code(404); echo json_encode(['error' => 'File not found']); exit; }
            header('Content-Type: ' . ($row['mimetype'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . ($row['filename'] ?: 'anexo') . '"');
            header('Content-Length: ' . strlen($row['data']));
            echo $row['data'];
            exit;
        }

        // try attachments table first (supports multiple)
        $a = $pdo->prepare('SELECT id, filename, mimetype, data FROM leads_attachments WHERE lead_id = ? ORDER BY id ASC');
        $a->execute([$leadId]);
        $atts = $a->fetchAll(PDO::FETCH_ASSOC);
        if ($atts && count($atts) > 0) {
            // legacy behavior: serve first attachment if multiple exist
            $first = $atts[0];
            if (!$first['data']) { http_response_code(404); echo json_encode(['error' => 'File not found']); exit; }
            header('Content-Type: ' . ($first['mimetype'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . ($first['filename'] ?: 'anexo') . '"');
            header('Content-Length: ' . strlen($first['data']));
            echo $first['data'];
            exit;
        }

        // fallback: old single-file column on leads table
        $stmt = $pdo->prepare('SELECT anexos, anexos_filename, anexos_mimetype FROM leads WHERE id=?');
        $stmt->execute([$leadId]);
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

    if ($action === 'delete_attachment') {
        // Expect POST with file_id and lead_id
        $fileId = $_POST['file_id'] ?? $_GET['file_id'] ?? null;
        $leadId = $_POST['lead_id'] ?? $_GET['lead_id'] ?? null;
        if (empty($fileId) || empty($leadId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file_id or lead_id']);
            exit;
        }

        // Ensure the attachment exists
        $att = $pdo->prepare('SELECT id, lead_id, filename FROM leads_attachments WHERE id = ? LIMIT 1');
        $att->execute([$fileId]);
        $row = $att->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Attachment not found']);
            exit;
        }

        // Delete the attachment
        try {
            $del = $pdo->prepare('DELETE FROM leads_attachments WHERE id = ?');
            $del->execute([$fileId]);
        } catch (Exception $e) {
            _leads_api_log('Failed to delete attachment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete attachment']);
            exit;
        }

        // If this was referenced in the legacy leads.anexos fields, update to first remaining attachment or clear fields
        try {
            $a = $pdo->prepare('SELECT id, filename, mimetype, data FROM leads_attachments WHERE lead_id = ? ORDER BY id ASC LIMIT 1');
            $a->execute([$leadId]);
            $first = $a->fetch(PDO::FETCH_ASSOC);
            if ($first) {
                // promote first attachment into legacy columns
                $upd = $pdo->prepare('UPDATE leads SET anexos = ?, anexos_filename = ?, anexos_mimetype = ? WHERE id = ?');
                $upd->execute([$first['data'], $first['filename'], $first['mimetype'], $leadId]);
            } else {
                $upd = $pdo->prepare('UPDATE leads SET anexos = NULL, anexos_filename = NULL, anexos_mimetype = NULL WHERE id = ?');
                $upd->execute([$leadId]);
            }
        } catch (Exception $e) {
            // non-fatal
            _leads_api_log('Failed promoting/clearing legacy attachment fields: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // Movements listing for a lead (audit trail)
    if ($action === 'movements') {
        $leadId = $_GET['lead_id'] ?? ($_POST['lead_id'] ?? null);
        if (empty($leadId)) { throw new Exception('Missing lead_id'); }
        $m = $pdo->prepare('SELECT id, lead_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE lead_id = ? ORDER BY created_at ASC');
        $m->execute([$leadId]);
        $rows = $m->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // List leads coming from ad campaigns (optional table `leads_anuncios`)
    if ($action === 'list_anuncios') {
        try {
            // Detect table existence first
            $t = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_anuncios'");
            $t->execute();
            $exists = (bool)$t->fetchColumn();
            if (!$exists) { echo json_encode([]); exit; }

            // Inspect columns and pick a safe ORDER BY column (prefer created_at/data_criacao/created, else id)
            $colChk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_anuncios'");
            $colChk->execute();
            $colsRaw = $colChk->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map(function($c){ return strtolower(trim($c)); }, $colsRaw ?: []);
            $hasUser = in_array('user_id', $cols);
            $preferred = ['created_at','data_criacao','created','createdat','timestamp'];
            $orderCol = 'id';
            foreach ($preferred as $pc) { if (in_array($pc, $cols)) { $orderCol = $pc; break; } }

            // Ensure the chosen column exists in original names (case-sensitive) to avoid SQL errors
            $orderColReal = null;
            foreach ($colsRaw as $c) { if (strtolower($c) === $orderCol) { $orderColReal = $c; break; } }
            if (!$orderColReal) $orderColReal = 'id';

            $sql = 'SELECT * FROM leads_anuncios ORDER BY `' . str_replace('`','', $orderColReal) . '` DESC LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows);
            exit;
        } catch (Exception $e) {
            _leads_api_log('list_anuncios failed: ' . $e->getMessage());
            echo json_encode([]);
            exit;
        }
    }

    // Promote an anuncio into the main leads table (creates a new lead)
    if ($action === 'promote_anuncio') {
        $anId = $_POST['id'] ?? $_GET['id'] ?? null;
        $targetStage = $_POST['stage_id'] ?? null;
        if (empty($anId)) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
        try {
            // ensure table exists
            $t = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_anuncios'");
            $t->execute(); if (!(bool)$t->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Anuncios table not found']); exit; }

            $stmt = $pdo->prepare('SELECT * FROM leads_anuncios WHERE id = ? LIMIT 1');
            $stmt->execute([$anId]); $an = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$an) { http_response_code(404); echo json_encode(['error' => 'Anuncio not found']); exit; }

            // Map common fields defensively
            $name = $an['name'] ?? $an['nome'] ?? ($an['contact_name'] ?? '');
            $email = $an['email'] ?? ($an['contact_email'] ?? '');
            $phone = $an['phone'] ?? ($an['telefone'] ?? '');
            $city = $an['cidade'] ?? ($an['city'] ?? '');
            $source = $an['source'] ?? 'Anúncios';
            // Build notes: include payload/obs, UTM info and creation date, and append full anuncio JSON for traceability
            $parts = [];
            if (!empty($an['payload'])) $parts[] = "Payload: " . substr($an['payload'],0,800);
            if (!empty($an['obs'])) $parts[] = $an['obs'];
            if (!empty($an['utm_origem'])) $parts[] = "UTM Origem: " . $an['utm_origem'];
            if (!empty($an['utm_campanha'])) $parts[] = "UTM Campanha: " . $an['utm_campanha'];
            if (!empty($an['created_at'])) $parts[] = "Criado em: " . $an['created_at'];
            if (!empty($an['data_criacao'])) $parts[] = "Criado em: " . $an['data_criacao'];
            // append a compact JSON dump of the original anuncio row for full data
            $parts[] = "--- Anuncio original (JSON) ---";
            $parts[] = json_encode($an, JSON_UNESCAPED_UNICODE);
            $notes = implode("\n", $parts);

            // insert into leads table for current user
            $ins = $pdo->prepare('INSERT INTO leads (user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $ins->execute([
                $userId,
                $name,
                $city,
                $email,
                $phone,
                $an['cpf_cnpj'] ?? null,
                $source,
                'Novo',
                $targetStage ?: null,
                $notes
            ]);
            $newId = $pdo->lastInsertId();
            // After successfully creating the lead, remove the original anuncio row
            try {
                $del = $pdo->prepare('DELETE FROM leads_anuncios WHERE id = ?');
                $del->execute([$anId]);
                _leads_api_log("promote_anuncio: deleted leads_anuncios id {$anId}");
            } catch (Exception $e) {
                _leads_api_log('promote_anuncio: failed to delete leads_anuncios id ' . $anId . ': ' . $e->getMessage());
            }
            echo json_encode(['ok' => true, 'id' => $newId]);
            exit;
        } catch (Exception $e) {
            _leads_api_log('promote_anuncio failed: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Failed to promote']); exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

