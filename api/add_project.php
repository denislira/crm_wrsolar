<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('projetos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$client_name = trim($_POST['client_name'] ?? '');
$address = trim($_POST['address'] ?? '');
$proposal_value = isset($_POST['proposal_value']) ? str_replace([',',' '], ['.',''], $_POST['proposal_value']) : 0;
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$lead_id = isset($_POST['lead_id']) && $_POST['lead_id'] !== '' ? intval($_POST['lead_id']) : null;
$closed_date = $_POST['closed_date'] ?? null;
$contract = isset($_POST['contract']) ? trim($_POST['contract']) : null;
$contract = $contract === '' ? null : $contract;
$client_status = $_POST['client_status'] ?? null; // 'Assinante' or 'Ex-Cliente'
$payment_type = $_POST['payment_type'] ?? null;
$payment_type = $payment_type === '' ? null : $payment_type;
$payment_status = $_POST['payment_status'] ?? null;
$payment_status = $payment_status === '' ? null : $payment_status;

$logistics_tracking_code = isset($_POST['logistics_tracking_code']) ? trim($_POST['logistics_tracking_code']) : null;
$logistics_tracking_code = $logistics_tracking_code === '' ? null : $logistics_tracking_code;
$logistics_delivery_date = $_POST['logistics_delivery_date'] ?? null;
$inspection_photos = isset($_POST['inspection_photos']) ? trim($_POST['inspection_photos']) : null;
$inspection_photos = $inspection_photos === '' ? null : $inspection_photos;
$projeto = isset($_POST['projeto']) ? trim($_POST['projeto']) : null;
$projeto = $projeto === '' ? null : $projeto;
$due_days = isset($_POST['due_days']) ? intval($_POST['due_days']) : 30;
if (!in_array($due_days, [30, 60, 90], true)) {
    $due_days = 30;
}
$technical_checklist = isset($_POST['technical_checklist']) ? trim($_POST['technical_checklist']) : null;
$technical_checklist = $technical_checklist === '' ? null : $technical_checklist;
$docs_checklist = isset($_POST['docs_checklist']) ? trim($_POST['docs_checklist']) : null;
$docs_checklist = $docs_checklist === '' ? null : $docs_checklist;

// Debug log
error_log("add_project.php - Recebido lead_id: " . print_r($_POST['lead_id'] ?? 'NOT SET', true));
error_log("add_project.php - Processado lead_id: " . ($lead_id ?? 'NULL'));

if (empty($client_name)) {
    echo json_encode(['success' => false, 'message' => 'Nome do cliente obrigatório']);
    exit;
}

try {
    // ensure columns exist (safe migration)
    try {
        $columnsToCheck = [
            'client_status' => "VARCHAR(50) DEFAULT 'Assinante'",
            'payment_type' => "VARCHAR(50) DEFAULT NULL",
            'payment_status' => "VARCHAR(50) DEFAULT NULL",
            'contract' => 'TEXT DEFAULT NULL',
            'logistics_tracking_code' => 'VARCHAR(255) DEFAULT NULL',
            'logistics_delivery_date' => 'DATE DEFAULT NULL',
            'inspection_photos' => 'TEXT DEFAULT NULL',
            'technical_checklist' => 'TEXT DEFAULT NULL',
            'docs_checklist' => 'TEXT DEFAULT NULL',
            'doc_attachments' => 'TEXT DEFAULT NULL',
            'projeto' => 'VARCHAR(255) DEFAULT NULL',
            'due_days' => 'INT DEFAULT 30'
        ];

        foreach ($columnsToCheck as $colName => $definition) {
            $col = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = ?");
            $col->execute([$colName]);
            if (!$col->fetchColumn()) {
                $pdo->exec("ALTER TABLE projetos ADD COLUMN {$colName} {$definition}");
            }
        }
    } catch (Exception $e) { /* ignore migration errors */ }

    // Resolve default project stage when status is not provided.
    if ($status === '') {
        try {
            $tbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_stages'");
            $tbl->execute();
            if ((int)$tbl->fetchColumn() > 0) {
                $colsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_stages'");
                $colsStmt->execute();
                $stageCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

                $nameCol = in_array('name', $stageCols, true) ? 'name' : (in_array('stage_name', $stageCols, true) ? 'stage_name' : 'name');
                $orderCol = in_array('position', $stageCols, true) ? 'position' : 'id';
                $hasInitial = in_array('is_initial', $stageCols, true);

                if ($hasInitial) {
                    $initialStmt = $pdo->prepare("SELECT {$nameCol} FROM projeto_stages WHERE is_initial = 1 ORDER BY {$orderCol} ASC, id ASC LIMIT 1");
                    $initialStmt->execute();
                    $status = (string)($initialStmt->fetchColumn() ?: '');
                }

                if ($status === '') {
                    $firstStmt = $pdo->prepare("SELECT {$nameCol} FROM projeto_stages ORDER BY {$orderCol} ASC, id ASC LIMIT 1");
                    $firstStmt->execute();
                    $status = (string)($firstStmt->fetchColumn() ?: '');
                }
            }
        } catch (Exception $e) {
            // Keep fallback below.
        }
    }

    if ($status === '') {
        $status = 'Documentação';
    }

    if ($lead_id !== null) {
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM projetos WHERE lead_id = ?');
        $checkStmt->execute([$lead_id]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Já existe um projeto vinculado a este lead.']);
            exit;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO projetos (user_id, client_name, address, proposal_value, status, lead_id, closed_date, contract, projeto, due_days, client_status, payment_type, payment_status, logistics_tracking_code, logistics_delivery_date, inspection_photos, technical_checklist, docs_checklist, doc_attachments, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $_SESSION['user_id'],
        $client_name,
        $address,
        $proposal_value,
        $status,
        $lead_id,
        $closed_date ?: null,
        $contract,
        $projeto,
        $due_days,
        $client_status,
        $payment_type,
        $payment_status,
        $logistics_tracking_code,
        $logistics_delivery_date ?: null,
        $inspection_photos,
        $technical_checklist,
        $docs_checklist,
        null
    ]);
    echo json_encode(['success' => true, 'message' => 'Projeto criado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao criar projeto: ' . $e->getMessage()]);
}
?>
