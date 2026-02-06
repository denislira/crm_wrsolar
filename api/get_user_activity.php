<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';

// Accept id param; if absent use current session user
$currentUserId = $_SESSION['user_id'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;
if (!$currentUserId) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autenticado']); exit; }

// If requesting another user's data, require admin/config permission
if ($id !== (int)$currentUserId && !hasPermission('configuracoes')) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Acesso negado']); exit;
}

try {
    // Fetch user basic info: select only columns that exist in this schema
    $cols = ['id','username','email','name','role_id','team_id','created_at'];
    $available = [];
    try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $colStmt->execute();
        $allCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) if (in_array($c, $allCols)) $available[] = $c;
    } catch (Exception $e) {
        // fallback to safe defaults
        $available = ['id','username','email','created_at'];
    }
    if (empty($available)) $available = ['id','username','email','created_at'];
    $select = implode(', ', array_map(function($c){ return $c; }, $available));
    $stmt = $pdo->prepare("SELECT $select FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) { echo json_encode(['success'=>false,'message'=>'Usuário não encontrado']); exit; }

    // ensure we have a friendly 'name' key for downstream code
    if (!isset($user['name'])) {
        $user['name'] = $user['username'] ?? ($user['email'] ?? '');
    }

    // Fetch team tasks (owned or assigned)
    $tasksStmt = $pdo->prepare('SELECT * FROM team_tasks WHERE user_id = ? OR responsavel = ? ORDER BY data_vencimento ASC, criado_em DESC');
    $tasksStmt->execute([$id, $user['username']]);
    $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch leads owned by user
    $leadsStmt = $pdo->prepare('SELECT id, user_id, name, email, phone, status, source, created_at FROM leads WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
    $leadsStmt->execute([$id]);
    $leads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch projects owned by user (if table exists)
    $projects = [];
    try {
        $projStmt = $pdo->prepare('SELECT id, user_id, client_name, proposal_value, status, created_at FROM projetos WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
        $projStmt->execute([$id]);
        $projects = $projStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore if projetos table not present
        $projects = [];
    }

    // Fetch lead movements (audit trail) where this user participated
    $movesStmt = $pdo->prepare('SELECT id, lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE user_id = ? OR changed_by = ? ORDER BY created_at DESC LIMIT 1000');
    $movesStmt->execute([$id, $user['username']]);
    $movements = $movesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch reminders created by this user
    $remindersStmt = $pdo->prepare('SELECT r.id, r.lead_id, r.message, r.remind_at, r.status, r.created_at, l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id WHERE r.created_by = ? ORDER BY r.created_at DESC LIMIT 500');
    $remindersStmt->execute([$id]);
    $reminders = $remindersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user' => $user,
        'tasks' => $tasks,
        'leads' => $leads,
        'projects' => $projects,
        'movements' => $movements,
        'reminders' => $reminders,
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
    exit;
}

?>
