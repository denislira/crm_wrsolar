<?php
// Simple leads API for CRUD and status updates
// Usage:
// GET  ?action=list           -> returns JSON list of active leads for current user
// GET  ?action=list_trash     -> returns JSON list of trashed leads for current user
// POST action=add             -> add new lead (name,email,phone,source,status)
// POST action=update          -> update lead by id
// POST action=delete          -> move lead to trash by id
// POST action=restore         -> restore lead from trash by id
// POST action=delete_permanent -> permanently delete trashed lead by id
// POST action=update_status   -> update only status by id

header('Content-Type: application/json');
// Enable verbose errors during debugging and capture uncaught errors to log
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
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
require_once __DIR__ . '/permissions.php';

// Ensure FS_NAME_COL default (some installs use 'stage_name' column)
if (!isset($FS_NAME_COL) || !$FS_NAME_COL) {
    $FS_NAME_COL = 'name';
}

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
// Consider user with role_id==1 or the initial superuser (id==1) as admin
$isAdmin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) || ($userId == 1);

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

// NOTE: runtime schema checks and CREATE/ALTER statements were removed from this API
// to avoid performing metadata queries or DDL on each request. Migrations should be
// applied manually using the scripts/ tools (for example scripts/add_new_leads_columns.php).

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
        $hasProjetosTable = false;
        try {
            $tbl = $pdo->query("SHOW TABLES LIKE 'projetos'");
            $hasProjetosTable = (bool)$tbl->fetchColumn();
        } catch (Exception $e) {
            $hasProjetosTable = false;
        }

        $projectFlagSql = $hasProjetosTable
            ? "(CASE WHEN EXISTS(SELECT 1 FROM projetos p WHERE p.lead_id = leads.id) THEN 1 ELSE 0 END) AS has_project"
            : '0 AS has_project';

        try {
            $stmt = $pdo->prepare('SELECT id, user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, ultimo_contato, forma_pagamento, anexos_filename, anexos_mimetype, created_at, data_inicio, updated_at, deleted, deleted_at, ' . $projectFlagSql . ' FROM leads WHERE deleted = 0 ORDER BY created_at DESC');
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

    if ($action === 'list_trash') {
        try {
            // Allow all users to view trashed leads (no user_id restriction)
            $stmt = $pdo->prepare('SELECT id, user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, ultimo_contato, forma_pagamento, anexos_filename, anexos_mimetype, created_at, data_inicio, updated_at, deleted, deleted_at FROM leads WHERE deleted = 1 ORDER BY deleted_at DESC');
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
            // Allow all users to fetch any lead (read-only access)
            $sql = 'SELECT l.id, l.user_id, l.name, l.cidade, l.email, l.phone, l.cpf_cnpj, l.source, l.status, l.stage_id, l.notes, l.consumo_cliente, l.estimativa_projeto_kwh, l.orcamento_value, l.envio_proposta, l.ultimo_contato, l.forma_pagamento, l.anexos_filename, l.anexos_mimetype, l.created_at, l.data_inicio, l.updated_at, l.deleted, l.deleted_at '
                . 'FROM leads l '
                . 'WHERE l.id = ? AND l.deleted = 0 LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_GET['id']]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback select
            $stmt = $pdo->prepare('SELECT id, user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, anexos_filename, anexos_mimetype, created_at, data_inicio, updated_at, deleted, deleted_at FROM leads WHERE id = ? AND deleted = 0');
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

    // Handle attachment-only uploads: insert attachments without modifying other lead fields
    if ($action === 'upload_attachment') {
        // Expecting multipart/form-data with 'id' and files in 'anexos' or 'anexos[]'
        $leadId = $_POST['id'] ?? null;
        if (empty($leadId)) { http_response_code(400); echo json_encode(['error' => 'Missing lead id']); exit; }
        if (empty($_FILES['anexos'])) { http_response_code(400); echo json_encode(['error' => 'No files provided']); exit; }

        $file = $_FILES['anexos'];
        $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
        $inserted = 0; $firstName = null; $firstType = null; $firstBlob = null;
        $insertAtt = $pdo->prepare('INSERT INTO leads_attachments (lead_id, user_id, filename, mimetype, data, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        for ($i = 0; $i < count($file['name']); $i++) {
            if (!isset($file['error'][$i]) || $file['error'][$i] !== UPLOAD_ERR_OK) continue;
            $fname = $file['name'][$i];
            $ftype = $file['type'][$i] ?? 'application/octet-stream';
            $tmp = $file['tmp_name'][$i];
            $blob = file_get_contents($tmp);
            try {
                $insertAtt->execute([(int)$leadId, $userId, $fname, $ftype, $blob]);
                if ($inserted === 0) { $firstName = $fname; $firstType = $ftype; $firstBlob = $blob; }
                $inserted++;
            } catch (Exception $e) {
                _leads_api_log('Failed inserting attachment (upload_attachment): ' . $e->getMessage());
            }
        }

        // For backward compatibility: if legacy leads.anexos fields exist, update with first attachment
        if ($inserted > 0 && $firstName !== null) {
            try {
                $upd = $pdo->prepare('UPDATE leads SET anexos = ?, anexos_filename = ?, anexos_mimetype = ? WHERE id = ?');
                $upd->execute([$firstBlob, $firstName, $firstType, (int)$leadId]);
            } catch (Exception $e) { /* ignore */ }
        }

        // Return updated lead record
        try {
            $g = $pdo->prepare('SELECT id, user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, ultimo_contato, anexos_filename, anexos_mimetype, created_at, data_inicio, updated_at FROM leads WHERE id = ? LIMIT 1');
            $g->execute([(int)$leadId]);
            $nl = $g->fetch(PDO::FETCH_ASSOC);
            // fetch attachments list
            $att = $pdo->prepare('SELECT id AS attachment_id, filename, mimetype FROM leads_attachments WHERE lead_id = ? ORDER BY id ASC');
            $att->execute([(int)$leadId]);
            $nl['anexos_files'] = $att->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'lead' => $nl]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed fetching lead after upload']);
            exit;
        }
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
        // Status should be stage name (text), stage_id should be numeric
        // JavaScript now sends both status (name) and stage_id (id)
        $incomingStatus = isset($data['status']) && trim($data['status']) !== '' ? trim($data['status']) : '';
        $incomingStageId = isset($data['stage_id']) && trim($data['stage_id']) !== '' ? trim($data['stage_id']) : '';
        
        // If stage_id is provided, use it directly along with status name
        if (!empty($incomingStageId) && is_numeric($incomingStageId)) {
            $resolvedStageId = (int)$incomingStageId;
            $resolvedStatus = $incomingStatus; // Keep the name
        } 
        // If status is numeric (legacy behavior), use it as stage_id
        elseif (is_numeric($incomingStatus)) {
            $resolvedStageId = (int)$incomingStatus;
            // Try to get the stage name from funil_stages
            $stageQuery = $pdo->prepare("SELECT stage_name FROM funil_stages WHERE id = ? LIMIT 1");
            $stageQuery->execute([$resolvedStageId]);
            $stageRow = $stageQuery->fetch(PDO::FETCH_ASSOC);
            $resolvedStatus = $stageRow ? $stageRow['stage_name'] : $incomingStatus;
        } 
        // If status is a text name, look up the stage_id from funil_stages
        elseif (!empty($incomingStatus)) {
            $stageQuery = $pdo->prepare("SELECT id, stage_name FROM funil_stages WHERE stage_name = ? LIMIT 1");
            $stageQuery->execute([$incomingStatus]);
            $stageRow = $stageQuery->fetch(PDO::FETCH_ASSOC);
            
            if ($stageRow) {
                $resolvedStageId = (int)$stageRow['id'];
                $resolvedStatus = $stageRow['stage_name'];
            } else {
                $resolvedStageId = 0;
                $resolvedStatus = $incomingStatus;
            }
        } 
        // Default to 0 if nothing provided
        else {
            $resolvedStageId = 0;
            $resolvedStatus = '';
        }

        // Insert lead (without blobs) first
        // Normalize envio_proposta and ultimo_contato if provided (accept YYYY-MM-DD or YYYY-MM-DDTHH:MM)
        $envio = null;
        if (!empty($data['envio_proposta'])) {
            $v = trim($data['envio_proposta']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $v = $v . ' 00:00:00'; }
            elseif (strpos($v, 'T') !== false) { $v = str_replace('T', ' ', $v); if (strlen($v) == 16) $v .= ':00'; }
            $envio = $v;
        }

        $ultimoContato = null;
        if (!empty($data['ultimo_contato'])) {
            $u = trim($data['ultimo_contato']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $u)) { $u = $u . ' 00:00:00'; }
            elseif (strpos($u, 'T') !== false) { $u = str_replace('T', ' ', $u); if (strlen($u) == 16) $u .= ':00'; }
            $ultimoContato = $u;
        }

        $dataInicio = null;
        if (!empty($data['data_inicio'])) {
            $d = trim($data['data_inicio']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $dataInicio = $d; }
            elseif (strpos($d, 'T') !== false) { $dataInicio = substr($d, 0, 10); }
        }

        // Log data_inicio for debugging
        try {
            _leads_api_log('ADD data_inicio (raw): ' . var_export($data['data_inicio'] ?? null, true));
            _leads_api_log('ADD data_inicio (parsed): ' . var_export($dataInicio, true));
        } catch (Exception $e) { /* ignore */ }

        // Debug logging: record incoming update payload and files (temporary)
        try {
            _leads_api_log('ADD payload: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            _leads_api_log('ADD data_inicio (raw): ' . var_export($data['data_inicio'] ?? null, true));
            _leads_api_log('ADD data_inicio (parsed): ' . var_export($dataInicio, true));
            _leads_api_log('ADD forma_pagamento (raw): ' . var_export($data['forma_pagamento'] ?? null, true));
            _leads_api_log('ADD _FILES keys: ' . json_encode(array_keys($_FILES), JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) { /* ignore logging errors */ }

        // Parse forma_pagamento for update
        $formaPagamento = null;
        if (!empty($data['forma_pagamento'])) {
            $formaPagamento = trim($data['forma_pagamento']);
        }

        $formaPagamento = null;
        if (!empty($data['forma_pagamento'])) {
            $formaPagamento = trim($data['forma_pagamento']);
        }

        $formaPagamento = null;
        if (!empty($data['forma_pagamento'])) {
            $formaPagamento = trim($data['forma_pagamento']);
        }

        $formaPagamento = null;
        if (!empty($data['forma_pagamento'])) {
            $formaPagamento = trim($data['forma_pagamento']);
        }

        $stmt = $pdo->prepare('INSERT INTO leads (user_id, name, cidade, email, phone, cpf_cnpj, source, status, stage_id, initial_stage_id, notes, consumo_cliente, estimativa_projeto_kwh, orcamento_value, envio_proposta, ultimo_contato, forma_pagamento, data_inicio, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
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
            $resolvedStageId,
            $data['notes'] ?? '',
            !empty($data['consumo_cliente']) ? $data['consumo_cliente'] : null,
            !empty($data['estimativa_projeto_kwh']) ? $data['estimativa_projeto_kwh'] : null,
            !empty($data['orcamento_value']) ? floatval($data['orcamento_value']) : 0,
            $envio,
            $ultimoContato,
            $formaPagamento,
            $dataInicio
        ];
        foreach ($params as &$p) { if (is_string($p)) $p = ensure_utf8_local($p); }
        try {
            $stmt->execute($params);
        } catch (Exception $e) {
            _leads_api_log('add:insert failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during insert']);
            exit;
        }

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
        // Create an initial immutable movement entry using the lead's own created_at and user_id.
        // This avoids inserting a generic "Lead criado" movement when the lead already has created_at/user information.
        try {
            // Fetch created_at and user_id from the inserted lead
            $g = $pdo->prepare('SELECT created_at, user_id FROM leads WHERE id = ? LIMIT 1');
            $g->execute([(int)$leadId]);
            $leadRow = $g->fetch(PDO::FETCH_ASSOC);
            $movementCreatedAt = ($leadRow && !empty($leadRow['created_at'])) ? $leadRow['created_at'] : date('Y-m-d H:i:s');
            $movementUserId = ($leadRow && !empty($leadRow['user_id'])) ? (int)$leadRow['user_id'] : $userId;

            // Only insert if there are no existing movements for this lead (prevents duplicates)
            $chk = $pdo->prepare('SELECT COUNT(*) AS c FROM lead_movements WHERE lead_id = ?');
            $chk->execute([(int)$leadId]);
            $cnt = (int)($chk->fetchColumn() ?: 0);
            if ($cnt === 0) {
                // Try to find username for nicer note; fallback to user id
                $uname = null;
                try {
                    $u = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                    $u->execute([$movementUserId]);
                    $uu = $u->fetch(PDO::FETCH_ASSOC);
                    if ($uu && !empty($uu['username'])) $uname = $uu['username'];
                } catch (Exception $e) { /* ignore */ }

                $note = $uname ? ('Criado por ' . $uname) : ('Criado por user_id ' . $movementUserId);
                $note = ensure_utf8_local($note);

                // Insert movement using explicit created_at timestamp
                $ins = $pdo->prepare('INSERT INTO lead_movements (lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([
                    (int)$leadId,
                    $movementUserId,
                    null,
                    ($resolvedStageId ?? null),
                    null,
                    ($resolvedStatus ?? null),
                    $movementUserId,
                    $note,
                    0,
                    $movementCreatedAt
                ]);
            }
        } catch (Exception $e) { /* swallow errors - movement logging is best-effort */ }

        echo json_encode(['ok' => true, 'id' => $leadId]);
        exit;
    }

    if ($action === 'update') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }

        // TEST LOG - immediate to confirm logging works
        file_put_contents(__DIR__ . '/../logs/test_update.log', '[' . date('Y-m-d H:i:s') . '] UPDATE started - ID: ' . ($data['id'] ?? 'NONE') . ' - data_inicio: ' . ($data['data_inicio'] ?? 'NONE') . "\n", FILE_APPEND);

        // parse forma_pagamento from incoming data (ensure defined)
        $formaPagamento = null;
        if (!empty($data['forma_pagamento'])) {
            $formaPagamento = trim($data['forma_pagamento']);
        }
        // Log raw request body for debugging. For multipart/form-data php://input may be empty,
        // so fall back to logging $_POST keys and $_FILES keys to avoid confusing empty logs.
        try {
            $rawInput = @file_get_contents('php://input');
            $postKeysArr = array_keys($_POST);
            $filesKeysArr = array_keys($_FILES);
            // Log only when there is raw input, or when both $_POST and $_FILES are empty
            if (is_string($rawInput) && trim($rawInput) !== '') {
                _leads_api_log('UPDATE raw_input: ' . substr($rawInput, 0, 4096));
            } elseif (empty($postKeysArr) && empty($filesKeysArr)) {
                // real empty POST (possible post_max_size/upload_max_filesize issue)
                $postKeys = @json_encode($postKeysArr, JSON_UNESCAPED_UNICODE);
                $filesKeys = @json_encode($filesKeysArr, JSON_UNESCAPED_UNICODE);
                _leads_api_log('UPDATE raw_input: [empty] $_POST keys: ' . $postKeys . ' $_FILES keys: ' . $filesKeys);
            } else {
                // typical multipart/form-data case: $_POST populated but php://input empty — do not spam logs
            }
        } catch (Exception $e) {}

        // Fetch previous state to detect changes
        $pre = $pdo->prepare('SELECT id, status, stage_id, notes FROM leads WHERE id = ? LIMIT 1');
        $pre->execute([$data['id']]);
        $prev = $pre->fetch(PDO::FETCH_ASSOC);
        $fromStatus = $prev['status'] ?? null;
        $fromStageId = isset($prev['stage_id']) ? (int)$prev['stage_id'] : null;

        // Status should be stage name (text), stage_id should be numeric
        // JavaScript now sends both status (name) and stage_id (id)
        $incomingStatus = isset($data['status']) && trim($data['status']) !== '' ? trim($data['status']) : ($fromStatus ?? '');
        $incomingStageId = isset($data['stage_id']) && trim($data['stage_id']) !== '' ? trim($data['stage_id']) : '';
        
        // If stage_id is provided, use it directly along with status name
        if (!empty($incomingStageId) && is_numeric($incomingStageId)) {
            $resolvedStageId = (int)$incomingStageId;
            $resolvedStatus = $incomingStatus; // Keep the name
        } 
        // If status is numeric (legacy behavior), use it as stage_id
        elseif (is_numeric($incomingStatus)) {
            $resolvedStageId = (int)$incomingStatus;
            // Try to get the stage name from funil_stages
            $stageQuery = $pdo->prepare("SELECT stage_name FROM funil_stages WHERE id = ? LIMIT 1");
            $stageQuery->execute([$resolvedStageId]);
            $stageRow = $stageQuery->fetch(PDO::FETCH_ASSOC);
            $resolvedStatus = $stageRow ? $stageRow['stage_name'] : $incomingStatus;
        } 
        // If status is a text name, look up the stage_id from funil_stages
        elseif (!empty($incomingStatus)) {
            $stageQuery = $pdo->prepare("SELECT id, stage_name FROM funil_stages WHERE stage_name = ? LIMIT 1");
            $stageQuery->execute([$incomingStatus]);
            $stageRow = $stageQuery->fetch(PDO::FETCH_ASSOC);
            
            if ($stageRow) {
                $resolvedStageId = (int)$stageRow['id'];
                $resolvedStatus = $stageRow['stage_name'];
            } else {
                // Stage name not found - keep previous or default to 0
                $resolvedStageId = $fromStageId ?? 0;
                $resolvedStatus = $incomingStatus;
            }
        }
        // Keep previous values if nothing provided
        else {
            $resolvedStageId = $fromStageId ?? 0;
            $resolvedStatus = $fromStatus ?? '';
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

        $ultimoContato = null;
        if (!empty($data['ultimo_contato'])) {
            $u = trim($data['ultimo_contato']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $u)) { $u = $u . ' 00:00:00'; }
            elseif (strpos($u, 'T') !== false) { $u = str_replace('T', ' ', $u); if (strlen($u) == 16) $u .= ':00'; }
            $ultimoContato = $u;
        }

        $dataInicio = null;
        if (!empty($data['data_inicio'])) {
            $d = trim($data['data_inicio']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $dataInicio = $d; }
            elseif (strpos($d, 'T') !== false) { $dataInicio = substr($d, 0, 10); }
        }

        // Log data_inicio for debugging
        try {
            _leads_api_log('UPDATE data_inicio (raw): ' . var_export($data['data_inicio'] ?? null, true));
            _leads_api_log('UPDATE data_inicio (parsed): ' . var_export($dataInicio, true));
        } catch (Exception $e) { /* ignore */ }

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
            $envio,
            $ultimoContato,
            $formaPagamento,
            $dataInicio,
            $userId /* user_id_update: user who made this update */
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

        // Append id for WHERE clause
        $params[] = $data['id'];

        // Include stage_id column in the update SQL. Allow updates regardless of original user_id
        // Set user_id_update to track who last edited the lead
        $stmt = $pdo->prepare('UPDATE leads SET name=?, cidade=?, email=?, phone=?, cpf_cnpj=?, source=?, status=?, stage_id=?, notes=?, consumo_cliente=?, estimativa_projeto_kwh=?, orcamento_value=?, envio_proposta=?, ultimo_contato=?, forma_pagamento=?, data_inicio=?, user_id_update=?, updated_at=NOW()' . $updateAnexos . ' WHERE id=?');
        try {
            $stmt->execute($params);
        } catch (Exception $e) {
            _leads_api_log('update:leads failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during update']);
            exit;
        }

        // If status or stage changed, log movement
        try {
            if ($fromStatus !== $resolvedStatus || $fromStageId !== $resolvedStageId) {
                $changedBy = $_SESSION['user_id'] ?? null;
                _log_lead_movement($pdo, (int)$data['id'], $userId, $fromStageId, $resolvedStageId, $fromStatus, $resolvedStatus, $changedBy, 'Atualização via edit', 0);
            }
        } catch (Exception $e) { /* swallow */ }

        // If notes changed, record an immutable movement entry for history
        try {
            if (array_key_exists('notes', $data)) {
                $oldNotes = isset($prev['notes']) ? $prev['notes'] : '';
                $newNotes = $data['notes'] ?? '';
                if (trim($oldNotes) !== trim($newNotes)) {
                    $changedBy = $_SESSION['user_id'] ?? null;
                    $noteLog = 'Notas atualizadas';
                    // include short diffs for traceability
                    $snippetOld = mb_substr((string)$oldNotes, 0, 1000);
                    $snippetNew = mb_substr((string)$newNotes, 0, 1000);
                    $noteLog .= ' | Antes: ' . $snippetOld . ' | Depois: ' . $snippetNew;
                    // For notes-only changes we avoid recording stage/status values to keep the movement text focused
                    _log_lead_movement($pdo, (int)$data['id'], $userId, null, null, null, null, $changedBy, $noteLog, 0);
                }
            }
        } catch (Exception $e) { /* swallow */ }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }

        // Allow admins to move any lead to trash. Also allow roles with the
        // granular delete permission to move any lead to trash. Otherwise,
        // restrict to leads owned by the current user.
        if ($isAdmin || hasPermission('delete_leads_permanent')) {
            $stmt = $pdo->prepare('UPDATE leads SET deleted=1, deleted_at=NOW() WHERE id=?');
            $stmt->execute([$data['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE leads SET deleted=1, deleted_at=NOW() WHERE id=? AND user_id=?');
            $stmt->execute([$data['id'], $userId]);
        }

        if ($stmt->rowCount() === 0) {
            throw new Exception('Lead not found or insufficient permissions to delete');
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'restore') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }
        if ($isAdmin) {
            $stmt = $pdo->prepare('UPDATE leads SET deleted=0, deleted_at=NULL WHERE id=?');
            $stmt->execute([$data['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE leads SET deleted=0, deleted_at=NULL WHERE id=? AND user_id=?');
            $stmt->execute([$data['id'], $userId]);
        }
        if ($stmt->rowCount() === 0) {
            throw new Exception('Lead not found or insufficient permissions to restore');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_permanent') {
        if (empty($data['id'])) { throw new Exception('Missing id'); }
        // Only admins or roles explicitly granted this permission may permanently delete leads
        if (!hasPermission('delete_leads_permanent') && !$isAdmin) {
            throw new Exception('Lead not found or insufficient permissions to delete permanently');
        }

        // If user is admin or has the granular permission, allow deleting any trashed lead
        if ($isAdmin || hasPermission('delete_leads_permanent')) {
            $stmt = $pdo->prepare('DELETE FROM leads WHERE id=? AND deleted=1');
            $stmt->execute([$data['id']]);
        } else {
            // Fallback: restrict to own leads
            $stmt = $pdo->prepare('DELETE FROM leads WHERE id=? AND user_id=? AND deleted=1');
            $stmt->execute([$data['id'], $userId]);
        }

        if ($stmt->rowCount() === 0) {
            throw new Exception('Lead not found or insufficient permissions to delete permanently');
        }
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

        // Audit log: record who requested the deletion and which file
        try {
            _leads_api_log('DELETE_ATTACHMENT requested: user=' . ($userId ?? 'unknown') . ' lead=' . ($leadId ?? 'unknown') . ' file=' . ($fileId ?? 'unknown') . ' filename=' . ($row['filename'] ?? '')); 
        } catch (Exception $e) { /* ignore logging errors */ }

        // Delete the attachment
        try {
            $del = $pdo->prepare('DELETE FROM leads_attachments WHERE id = ?');
            $del->execute([$fileId]);
            // Log success for audit
            try { _leads_api_log('DELETE_ATTACHMENT success: user=' . ($userId ?? 'unknown') . ' lead=' . ($leadId ?? 'unknown') . ' file=' . ($fileId ?? 'unknown') . ' filename=' . ($row['filename'] ?? '')); } catch (Exception $e) {}
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
        // Fetch lead info to build a synthetic initial movement from created_at/user_id/initial_stage_id
        $leadStmt = $pdo->prepare('SELECT id, user_id, created_at, stage_id, initial_stage_id, status FROM leads WHERE id = ? LIMIT 1');
        $leadStmt->execute([(int)$leadId]);
        $leadRow = $leadStmt->fetch(PDO::FETCH_ASSOC);

        $m = $pdo->prepare('SELECT id, lead_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE lead_id = ? ORDER BY created_at ASC');
        $m->execute([$leadId]);
        $rows = $m->fetchAll(PDO::FETCH_ASSOC);

        // If lead exists and has a created_at, consider prepending a synthetic "created" movement
        if ($leadRow && !empty($leadRow['created_at'])) {
            $leadCreatedAt = $leadRow['created_at'];
            $leadUserId = isset($leadRow['user_id']) ? (int)$leadRow['user_id'] : null;
            // Prefer initial_stage_id when available to preserve the stage where the lead started
            // Only use initial_stage_id for the synthetic created movement.
            // If initial_stage_id is empty, we will not set a stage name (to avoid showing current stage).
            $initialStageId = null;
            if (isset($leadRow['initial_stage_id']) && $leadRow['initial_stage_id'] !== null && $leadRow['initial_stage_id'] !== '') {
                $initialStageId = (int)$leadRow['initial_stage_id'];
            }
            $leadStatus = $leadRow['status'] ?? null;

            $prepend = false;
            if (empty($rows)) {
                $prepend = true;
            } else {
                // compare earliest movement timestamp
                $first = $rows[0];
                if (!empty($first['created_at'])) {
                    // If earliest movement is after lead.created_at, prepend
                    if (strtotime($first['created_at']) > strtotime($leadCreatedAt)) {
                        $prepend = true;
                    } else {
                        // If earliest movement equals created_at but by a different user, still do not duplicate
                        // No action needed
                    }
                } else {
                    $prepend = true;
                }
            }

                if ($prepend) {
                // try to resolve username for note
                $uname = null;
                if ($leadUserId) {
                    try {
                        $u = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                        $u->execute([$leadUserId]);
                        $uu = $u->fetch(PDO::FETCH_ASSOC);
                        if ($uu && !empty($uu['username'])) $uname = $uu['username'];
                    } catch (Exception $e) { /* ignore */ }
                }

                $note = $uname ? ('Criado por ' . $uname) : ('Criado por user_id ' . ($leadUserId ?? 'N/A'));

                // Resolve stage name only when initial_stage_id is present
                $stageName = null;
                if ($initialStageId) {
                    try {
                        $s = $pdo->prepare('SELECT stage_name FROM funil_stages WHERE id = ? LIMIT 1');
                        $s->execute([$initialStageId]);
                        $sr = $s->fetch(PDO::FETCH_ASSOC);
                        if ($sr && !empty($sr['stage_name'])) $stageName = $sr['stage_name'];
                    } catch (Exception $e) { /* ignore */ }
                }

                $synthetic = [
                    'id' => null,
                    'lead_id' => (int)$leadId,
                    'from_stage_id' => null,
                    'to_stage_id' => $initialStageId !== null ? (int)$initialStageId : null,
                    'from_status' => null,
                    // Only include to_status when initial_stage_id is present – otherwise keep it null
                    'to_status' => ($initialStageId !== null ? $leadStatus : null),
                    'changed_by' => $leadUserId,
                    'note' => $note,
                    // include to_stage_name only when we resolved it from initial_stage_id
                    'to_stage_name' => $stageName,
                    'is_alert' => 0,
                    'created_at' => $leadCreatedAt
                ];

                array_unshift($rows, $synthetic);
            }
        }

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
            $preferred = ['data_criacao'];
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

    // Get current user ID
    if ($action === 'get_user_id') {
        echo json_encode(['user_id' => $userId]);
        exit;
    }

    // Get list of users
    if ($action === 'get_users') {
        $stmt = $pdo->prepare('SELECT id, username, email FROM users ORDER BY username');
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

