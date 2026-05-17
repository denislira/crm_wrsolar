<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';
require_once '../includes/project_post_sale_automation.php';

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

$allowed = ['client_name','address','proposal_value','projeto','status','contract','closed_date','due_days','client_status','payment_method_id','payment_type','payment_status','logistics_tracking_code','logistics_delivery_date','inspection_photos','technical_checklist','docs_checklist','doc_attachments'];
$sets = [];
$params = [];
$removedAttachmentPaths = [];
if (isset($_POST['doc_attachments'])) {
    $existingStmt = $pdo->prepare('SELECT doc_attachments FROM projetos WHERE id = ? LIMIT 1');
    $existingStmt->execute([$id]);
    $existingJson = $existingStmt->fetchColumn();
    $oldAttachments = [];
    if ($existingJson) {
        $decoded = json_decode($existingJson, true);
        if (is_array($decoded)) {
            $oldAttachments = $decoded;
        }
    }

    $newAttachments = [];
    if ($_POST['doc_attachments'] !== '') {
        $decoded = json_decode($_POST['doc_attachments'], true);
        if (is_array($decoded)) {
            $newAttachments = $decoded;
        }
    }

    $oldPaths = array_column(array_filter($oldAttachments, function ($item) {
        return is_array($item) && isset($item['path']);
    }), 'path');
    $newPaths = array_column(array_filter($newAttachments, function ($item) {
        return is_array($item) && isset($item['path']);
    }), 'path');

    $removedAttachmentPaths = array_values(array_diff($oldPaths, $newPaths));
}

foreach ($allowed as $f) {
    if (isset($_POST[$f])) {
        $sets[] = "$f = ?";
        $val = $_POST[$f];
        // normalize numeric
        if ($f === 'proposal_value') $val = str_replace([',',' '], ['.',''], $val);
        $params[] = ($val === '' ? null : $val);
    }
}

if (isset($_POST['payment_method_id'])) {
    $paymentMethodId = intval($_POST['payment_method_id']);
    if ($paymentMethodId > 0) {
        $pmStmt = $pdo->prepare('SELECT name FROM payment_methods WHERE id = ? AND code = 2 LIMIT 1');
        $pmStmt->execute([$paymentMethodId]);
        $resolvedPaymentType = $pmStmt->fetchColumn();
        $sets[] = 'payment_type = ?';
        $params[] = ($resolvedPaymentType !== false) ? (string)$resolvedPaymentType : null;
    } else {
        $sets[] = 'payment_type = ?';
        $params[] = null;
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
        'status_changed_at' => 'DATETIME DEFAULT NULL',
        'moved_to_post_sale' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'payment_method_id' => 'INT DEFAULT NULL',
        'payment_type' => "VARCHAR(50) DEFAULT NULL",
        'payment_status' => "VARCHAR(50) DEFAULT NULL",
        'contract' => 'TEXT DEFAULT NULL',
        'projeto' => 'VARCHAR(255) DEFAULT NULL',
        'due_days' => 'INT DEFAULT 30',
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
    $leadId = null;
    $statusChanged = false;
    if (isset($_POST['status'])) {
        $prevStatusStmt = $pdo->prepare('SELECT status FROM projetos WHERE id = ? LIMIT 1');
        $prevStatusStmt->execute([$id]);
        $prevStatus = $prevStatusStmt->fetchColumn();
        $statusChanged = trim((string)$prevStatus) !== trim((string)($_POST['status'] ?? ''));
    }

    if ($statusChanged) {
        $sets[] = 'status_changed_at = ?';
        $params[] = date('Y-m-d H:i:s');
    }

    $leadStmt = $pdo->prepare('SELECT lead_id FROM projetos WHERE id = ? LIMIT 1');
    $leadStmt->execute([$id]);
    $leadId = $leadStmt->fetchColumn();

    $sql = 'UPDATE projetos SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if (!empty($removedAttachmentPaths)) {
        $projectFolder = realpath(__DIR__ . '/../uploads/project_docs/' . $id);
        if ($projectFolder !== false) {
            foreach ($removedAttachmentPaths as $path) {
                $candidate = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
                $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
                if (strpos($candidate, $projectFolder) !== 0) {
                    continue;
                }
                if (is_file($candidate)) {
                    @unlink($candidate);
                }
            }
        }
    }

    // If project is linked to a lead, keep lead budget/kWh in sync when edited here.
    if (!empty($leadId)) {
        $leadSets = [];
        $leadParams = [];

        if (isset($_POST['proposal_value'])) {
            $leadSets[] = 'orcamento_value = ?';
            $leadVal = str_replace([',',' '], ['.',''], (string)$_POST['proposal_value']);
            $leadParams[] = ($leadVal === '' ? null : $leadVal);
        }

        if (isset($_POST['projeto'])) {
            $leadSets[] = 'estimativa_projeto_kwh = ?';
            $leadKwh = str_replace([',',' '], ['.',''], (string)$_POST['projeto']);
            $leadParams[] = ($leadKwh === '' ? null : $leadKwh);
        }

        if (!empty($leadSets)) {
            $leadSql = 'UPDATE leads SET ' . implode(', ', $leadSets) . ', updated_at = NOW() WHERE id = ?';
            $leadParams[] = (int)$leadId;
            $leadUpd = $pdo->prepare($leadSql);
            $leadUpd->execute($leadParams);
        }
    }

    // Re-run stage-based post-sale automation after updates (including drag/drop status moves).
    // This keeps migration timing consistent without relying on a page reload.
    runProjectPostSaleAutomation($pdo, (int) $_SESSION['user_id']);

    echo json_encode(['success' => true, 'message' => 'Projeto atualizado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar projeto: ' . $e->getMessage()]);
}
?>
