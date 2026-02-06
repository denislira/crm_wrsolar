<?php
// API para CRUD de tarefas de equipe
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Não autenticado']);
    exit;
}
$userId = $_SESSION['user_id'];
header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

function strEqualsIgnoreCase($a, $b) {
    return mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
}

function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

$hasResponsavelId = columnExists($pdo, 'team_tasks', 'responsavel_id');

switch ($action) {
    case 'list':
        // Return tasks visible to everyone. Optional filters by responsavel/equipe/status are supported.
        $username = $_SESSION['username'] ?? '';
        $w = [];
        $params = [];
        if (!empty($_GET['responsavel'])) {
            $w[] = 'responsavel = ?';
            $params[] = $_GET['responsavel'];
        }
        if (!empty($_GET['equipe'])) {
            $w[] = 'equipe = ?';
            $params[] = $_GET['equipe'];
        }
        if (!empty($_GET['status'])) {
            $w[] = 'status = ?';
            $params[] = $_GET['status'];
        }
        $sql = 'SELECT * FROM team_tasks';
        if (!empty($w)) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY data_vencimento ASC, criado_em DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
    case 'add':
        $data = json_decode(file_get_contents('php://input'), true);
        // force responsavel to current session username for security
        $responsavel = $_SESSION['username'] ?? '';
        $responsavel_id = $userId; // Set responsavel_id to current user

        // If responsavel_id is provided in the request, validate and use it
        if (isset($data['responsavel_id']) && $data['responsavel_id'] !== '') {
            if (is_numeric($data['responsavel_id'])) {
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $uStmt->execute([$data['responsavel_id']]);
                if ($uStmt->fetch(PDO::FETCH_ASSOC)) {
                    $responsavel_id = $data['responsavel_id'];
                }
            }
        } elseif (isset($data['responsavel_id']) && $data['responsavel_id'] === '') {
            $responsavel_id = null;
        }

        $data_venc = (isset($data['data_vencimento']) && trim($data['data_vencimento']) !== '') ? $data['data_vencimento'] : null;
        try {
            if ($hasResponsavelId) {
                $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, responsavel_id, data_vencimento) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $responsavel_id,
                    $data_venc
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $data_venc
                ]);
            }
            echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        break;
    case 'update':
        $id = $_GET['id'] ?? 0;
        $data = json_decode(file_get_contents('php://input'), true);
        // Fetch existing row and check permissions (owner or assigned responsavel)
        $curSql = $hasResponsavelId ? "SELECT user_id, responsavel, responsavel_id FROM team_tasks WHERE id = ?" : "SELECT user_id, responsavel FROM team_tasks WHERE id = ?";
        $cur = $pdo->prepare($curSql);
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }
        $username = $_SESSION['username'] ?? '';
        $isOwner = ((int)$row['user_id'] === (int)$userId);
        $isResponsavelName = strEqualsIgnoreCase($row['responsavel'] ?? '', $username);
        $isResponsavelId = ($hasResponsavelId && isset($row['responsavel_id']) && (int)$row['responsavel_id'] === (int)$userId);
        $isDirector = function_exists('isDirector') ? isDirector() : false;
        if (!($isOwner || $isResponsavelName || $isResponsavelId || $isDirector)) { echo json_encode(['success'=>false,'error'=>'No permission']); break; }
        // Allow changing responsavel when provided (owner or current responsavel already checked above)
        $responsavel = $row['responsavel'];
        $responsavel_id = $hasResponsavelId ? ($row['responsavel_id'] ?? null) : null;
        if (isset($data['responsavel']) && trim($data['responsavel']) !== '') {
            $responsavel = $data['responsavel'];
        }
        if ($hasResponsavelId && isset($data['responsavel_id'])) {
            if ($data['responsavel_id'] === '') {
                $responsavel_id = null;
            } elseif (is_numeric($data['responsavel_id'])) {
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $uStmt->execute([$data['responsavel_id']]);
                if ($uStmt->fetch(PDO::FETCH_ASSOC)) {
                    $responsavel_id = $data['responsavel_id'];
                }
            }
        }
        $data_venc = (isset($data['data_vencimento']) && trim($data['data_vencimento']) !== '') ? $data['data_vencimento'] : null;
        try {
            if ($hasResponsavelId) {
                $stmt = $pdo->prepare("UPDATE team_tasks SET equipe=?, titulo=?, descricao=?, status=?, responsavel=?, responsavel_id=?, data_vencimento=? WHERE id=?");
                $stmt->execute([
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $responsavel_id,
                    $data_venc,
                    $id
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE team_tasks SET equipe=?, titulo=?, descricao=?, status=?, responsavel=?, data_vencimento=? WHERE id=?");
                $stmt->execute([
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $data_venc,
                    $id
                ]);
            }
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        break;
    case 'recent_activities':
        $username = $_SESSION['username'] ?? '';
        $sql = "SELECT id, equipe, titulo, responsavel, criado_em, atualizado_em FROM team_tasks WHERE (user_id = ? OR responsavel = ?) AND (criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR atualizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY GREATEST(criado_em, atualizado_em) DESC LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $username]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $activities = [];
        foreach ($tasks as $t) {
            $isNew = $t['criado_em'] == $t['atualizado_em'];
            $timestamp = $isNew ? $t['criado_em'] : $t['atualizado_em'];
            $activities[] = [
                'type' => $isNew ? 'created' : 'updated',
                'titulo' => $t['titulo'],
                'equipe' => $t['equipe'],
                'responsavel' => $t['responsavel'],
                'timestamp' => $timestamp
            ];
        }
        echo json_encode($activities);
        break;
    case 'delete':
        $id = $_GET['id'] ?? 0;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        // Check permission: owner or assigned responsavel
        $curSql = $hasResponsavelId ? "SELECT user_id, responsavel, responsavel_id FROM team_tasks WHERE id = ?" : "SELECT user_id, responsavel FROM team_tasks WHERE id = ?";
        $cur = $pdo->prepare($curSql);
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Not found']); break; }
        $username = $_SESSION['username'] ?? '';
        $isOwner = ((int)$row['user_id'] === (int)$userId);
        $isResponsavelName = strEqualsIgnoreCase($row['responsavel'] ?? '', $username);
        $isResponsavelId = ($hasResponsavelId && isset($row['responsavel_id']) && (int)$row['responsavel_id'] === (int)$userId);
        $isDirector = function_exists('isDirector') ? isDirector() : false;
        
        if (!($isOwner || $isResponsavelName || $isResponsavelId || $isDirector)) { 
            echo json_encode(['success' => false, 'error' => "No permission (own=$isOwner, resp_name=$isResponsavelName, resp_id=$isResponsavelId, dir=$isDirector)"]); 
            break; 
        }
        $stmt = $pdo->prepare("DELETE FROM team_tasks WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Delete failed']);
        }
        break;
}
