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

$allowed = ['client_name','address','proposal_value','projeto','status','contract','closed_date','due_days','client_status','payment_method_id','payment_type','payment_status','logistics_tracking_code','logistics_delivery_date','inspection_photos','technical_checklist','docs_checklist','doc_attachments'];
$sets = [];
$params = [];
$incomingStatus = isset($_POST['status']) ? trim((string)$_POST['status']) : null;
$incomingStatusLower = $incomingStatus !== null ? mb_strtolower($incomingStatus, 'UTF-8') : null;
$isPostVendaTriggerStatus = in_array($incomingStatusLower, ['homologado', 'concluido', 'concluído', 'fechado', 'finalizado'], true);
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

// When a project reaches homologation/closure stages, ensure we stamp closed_date.
if ($isPostVendaTriggerStatus && !isset($_POST['closed_date'])) {
    $sets[] = 'closed_date = ?';
    $params[] = date('Y-m-d');
}

if (empty($sets)) {
    echo json_encode(['success' => false, 'message' => 'Nenhum campo para atualizar']);
    exit;
}

// ensure columns exist when needed (safe migration)
try {
    $columnsToCheck = [
        'client_status' => "VARCHAR(50) DEFAULT 'Assinante'",
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

    // Auto-enroll project into post-sales when status reaches homologation/closure.
    if ($isPostVendaTriggerStatus) {
        $projectStmt = $pdo->prepare('SELECT id, user_id, client_name, closed_date FROM projetos WHERE id = ? LIMIT 1');
        $projectStmt->execute([$id]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

        if ($project && (int)$project['user_id'] === (int)$_SESSION['user_id']) {
            $installationDate = null;
            if (!empty($_POST['closed_date'])) {
                $installationDate = substr((string)$_POST['closed_date'], 0, 10);
            } elseif (!empty($project['closed_date'])) {
                $installationDate = substr((string)$project['closed_date'], 0, 10);
            }
            if (!$installationDate) {
                $installationDate = date('Y-m-d');
            }

            $nextMaintenance = date('Y-m-d', strtotime($installationDate . ' +6 months'));

            $pvStmt = $pdo->prepare('SELECT id, installation_date, next_maintenance FROM pos_venda WHERE project_id = ? AND user_id = ? LIMIT 1');
            $pvStmt->execute([(int)$project['id'], (int)$_SESSION['user_id']]);
            $pv = $pvStmt->fetch(PDO::FETCH_ASSOC);

            if ($pv) {
                $updPv = $pdo->prepare('UPDATE pos_venda SET client_name = ?, installation_date = COALESCE(installation_date, ?), next_maintenance = COALESCE(next_maintenance, ?), updated_at = NOW() WHERE id = ?');
                $updPv->execute([
                    (string)$project['client_name'],
                    $installationDate,
                    $nextMaintenance,
                    (int)$pv['id'],
                ]);
            } else {
                $insPv = $pdo->prepare('INSERT INTO pos_venda (user_id, project_id, client_name, installation_date, next_maintenance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                $insPv->execute([
                    (int)$_SESSION['user_id'],
                    (int)$project['id'],
                    (string)$project['client_name'],
                    $installationDate,
                    $nextMaintenance,
                ]);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Projeto atualizado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar projeto: ' . $e->getMessage()]);
}
?>
