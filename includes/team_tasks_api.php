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

/**
 * Ensure activities table exists (silent on failure).
 */
function ensureActivityTableExists($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_tasks_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(150) DEFAULT NULL,
            details TEXT,
            equipe VARCHAR(150) DEFAULT NULL,
            titulo VARCHAR(255) DEFAULT NULL,
            responsavel VARCHAR(150) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // ignore
    }
}

function logTaskActivity($pdo, $data) {
    try {
        ensureActivityTableExists($pdo);
        $stmt = $pdo->prepare("INSERT INTO team_tasks_activities (task_id, action, user_id, username, details, equipe, titulo, responsavel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['task_id'] ?? null,
            $data['action'] ?? null,
            $data['user_id'] ?? null,
            $data['username'] ?? null,
            $data['details'] ?? null,
            $data['equipe'] ?? null,
            $data['titulo'] ?? null,
            $data['responsavel'] ?? null,
        ]);
    } catch (Exception $e) {
        // ignore logging failures
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
                $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, responsavel_id, data_vencimento, lead_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $responsavel_id,
                    $data_venc,
                    $data['lead_id'] ?? null
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento, lead_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $data['equipe'] ?? null,
                    $data['titulo'] ?? null,
                    $data['descricao'] ?? '',
                    $data['status'] ?? 'Pendente',
                    $responsavel,
                    $data_venc,
                    $data['lead_id'] ?? null
                ]);
            }
            $newId = $pdo->lastInsertId();
            // log creation activity (best-effort)
            logTaskActivity($pdo, [
                'task_id' => $newId,
                'action' => 'created',
                'user_id' => $userId,
                'username' => $_SESSION['username'] ?? null,
                'details' => json_encode(['titulo'=>$data['titulo'] ?? null,'equipe'=>$data['equipe'] ?? null, 'lead_id'=>$data['lead_id'] ?? null], JSON_UNESCAPED_UNICODE),
                'equipe' => $data['equipe'] ?? null,
                'titulo' => $data['titulo'] ?? null,
                'responsavel' => $responsavel
            ]);
            echo json_encode(['success'=>true, 'id'=>$newId]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        break;
    case 'update':
        $id = $_GET['id'] ?? 0;
        $data = json_decode(file_get_contents('php://input'), true);
        // Fetch existing row
        $curSql = "SELECT * FROM team_tasks WHERE id = ?";
        $cur = $pdo->prepare($curSql);
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }
        // Allow changing responsavel when provided (owner or current responsavel already checked above)
        $responsavel = $row['responsavel'] ?? null;
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
        // prepare snapshot before/after for logging
        $keys = ['titulo','equipe','descricao','status','responsavel','data_vencimento'];
        $before = [];
        foreach ($keys as $k) { $before[$k] = $row[$k] ?? null; }
        $after = [];
        foreach ($keys as $k) { $after[$k] = $data[$k] ?? ($row[$k] ?? null); }

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
            // log update activity (best-effort)
            logTaskActivity($pdo, [
                'task_id' => $id,
                'action' => 'updated',
                'user_id' => $userId,
                'username' => $_SESSION['username'] ?? null,
                'details' => json_encode(['before'=>$before,'after'=>$after], JSON_UNESCAPED_UNICODE),
                'equipe' => $after['equipe'] ?? null,
                'titulo' => $after['titulo'] ?? null,
                'responsavel' => $after['responsavel'] ?? null
            ]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        break;
    case 'recent_activities':
        $username = $_SESSION['username'] ?? '';
        $combined = [];
        // 1) read explicit activities from team_tasks_activities (if present)
        try {
            ensureActivityTableExists($pdo);
            $stmt = $pdo->prepare("SELECT task_id, action, user_id, username, details, equipe, titulo, responsavel, created_at FROM team_tasks_activities WHERE (user_id = ? OR username = ?) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 50");
            $stmt->execute([$userId, $username]);
            $acts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($acts) {
                foreach ($acts as $a) {
                    $combined[] = [
                        'type' => $a['action'],
                        'titulo' => $a['titulo'] ?? null,
                        'equipe' => $a['equipe'] ?? null,
                        'responsavel' => $a['responsavel'] ?? null,
                        'timestamp' => $a['created_at'],
                        'details' => $a['details'] ?? null
                    ];
                }
            }
        } catch (Exception $e) {
            // ignore and fall back to tasks-only
        }
        // 2) also include inferred activities from team_tasks (preserve old behavior)
        $sql = "SELECT id, equipe, titulo, responsavel, criado_em, atualizado_em FROM team_tasks WHERE (user_id = ? OR responsavel = ?) AND (criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR atualizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY GREATEST(criado_em, atualizado_em) DESC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $username]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as $t) {
            $isNew = $t['criado_em'] == $t['atualizado_em'];
            $timestamp = $isNew ? $t['criado_em'] : $t['atualizado_em'];
            $combined[] = [
                'type' => $isNew ? 'created' : 'updated',
                'titulo' => $t['titulo'],
                'equipe' => $t['equipe'],
                'responsavel' => $t['responsavel'],
                'timestamp' => $timestamp
            ];
        }
        // deduplicate combined activities by type|titulo|equipe|timestamp
        $unique = [];
        $deduped = [];
        foreach ($combined as $c) {
            $key = ($c['type'] ?? '') . '|' . ($c['titulo'] ?? '') . '|' . ($c['equipe'] ?? '') . '|' . ($c['timestamp'] ?? '');
            $hash = md5($key);
            if (isset($unique[$hash])) continue;
            $unique[$hash] = true;
            $deduped[] = $c;
        }
        usort($deduped, function($a,$b){
            $ta = strtotime($a['timestamp'] ?? '1970-01-01');
            $tb = strtotime($b['timestamp'] ?? '1970-01-01');
            return $tb <=> $ta;
        });
        $combined = array_slice($deduped, 0, 10);
        echo json_encode($combined, JSON_UNESCAPED_UNICODE);
        break;
    case 'delete':
        $id = $_GET['id'] ?? 0;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        // Check if task exists
        $curSql = "SELECT * FROM team_tasks WHERE id = ?";
        $cur = $pdo->prepare($curSql);
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Not found']); break; }
        // preserve snapshot for activity
        $taskSnapshot = [
            'titulo' => $row['titulo'] ?? null,
            'equipe' => $row['equipe'] ?? null,
            'responsavel' => $row['responsavel'] ?? null,
        ];
        $stmt = $pdo->prepare("DELETE FROM team_tasks WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            // log deletion (best-effort)
            logTaskActivity($pdo, [
                'task_id' => $id,
                'action' => 'deleted',
                'user_id' => $userId,
                'username' => $_SESSION['username'] ?? null,
                'details' => json_encode($taskSnapshot, JSON_UNESCAPED_UNICODE),
                'equipe' => $taskSnapshot['equipe'] ?? null,
                'titulo' => $taskSnapshot['titulo'] ?? null,
                'responsavel' => $taskSnapshot['responsavel'] ?? null
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Delete failed']);
        }
        break;
}
