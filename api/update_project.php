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

// Allow partial updates: only fields provided will be updated
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$allowed = ['client_name','address','proposal_value','status','contract','closed_date','client_status','payment_type','logistics_tracking_code','logistics_delivery_date','inspection_photos','technical_checklist','docs_checklist','doc_attachments'];
$sets = [];
$params = [];
foreach ($allowed as $f) {
    if (isset($_POST[$f])) {
        $sets[] = "$f = ?";
        $val = $_POST[$f];
        // normalize numeric
        if ($f === 'proposal_value') $val = str_replace([',',' '], ['.',''], $val);
        $params[] = ($val === '' ? null : $val);
    }
}

if (empty($sets)) {
    echo json_encode(['success' => false, 'message' => 'Nenhum campo para atualizar']);
    exit;
}

// ensure columns exist when needed (safe migration)
try {
    $columnsToCheck = [
        'client_status' => "VARCHAR(50) DEFAULT 'Assinante'",
        'payment_type' => "VARCHAR(50) DEFAULT NULL",
        'contract' => 'TEXT DEFAULT NULL',
        'logistics_tracking_code' => 'VARCHAR(255) DEFAULT NULL',
        'logistics_delivery_date' => 'DATE DEFAULT NULL',
        'inspection_photos' => 'TEXT DEFAULT NULL',
        'technical_checklist' => 'TEXT DEFAULT NULL',
        'docs_checklist' => 'TEXT DEFAULT NULL',
        'doc_attachments' => 'TEXT DEFAULT NULL'
    ];
    foreach ($columnsToCheck as $colName => $definition) {
        $col = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = ?");
        $col->execute([$colName]);
        if (!$col->fetchColumn()) {
            $pdo->exec("ALTER TABLE projetos ADD COLUMN {$colName} {$definition}");
        }
    }
} catch (Exception $e) { /* ignore */ }

try {
    $sql = 'UPDATE projetos SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND user_id = ?';
    $params[] = $id; $params[] = $_SESSION['user_id'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'message' => 'Projeto atualizado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar projeto: ' . $e->getMessage()]);
}
?>
