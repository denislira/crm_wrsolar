<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Não autenticado']);
    exit;
}

try {
    // Load users and for each user get recent tasks (limit 6) and last activity timestamp
    $usersStmt = $pdo->query('SELECT id, username, avatar FROM users ORDER BY username');
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($users as $u) {
        $uid = $u['id'];
        $uname = $u['username'];
        $tasksStmt = $pdo->prepare('SELECT id,titulo,status,data_vencimento,criado_em,responsavel FROM team_tasks WHERE user_id = ? OR responsavel = ? ORDER BY criado_em DESC LIMIT 6');
        $tasksStmt->execute([$uid, $uname]);
        $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
        $lastActivity = null;
        if (!empty($tasks) && !empty($tasks[0]['criado_em'])) $lastActivity = $tasks[0]['criado_em'];
        $isOnline = false;
        if ($lastActivity) {
            $ts = strtotime($lastActivity);
            if ($ts !== false && ($ts > time() - 15*60)) $isOnline = true;
        }
        $result[] = [
            'id' => $uid,
            'username' => $uname,
            'avatar' => $u['avatar'] ?? '',
            'tasks' => $tasks,
            'last_activity' => $lastActivity,
            'online' => $isOnline,
        ];
    }
    echo json_encode(['success'=>true,'integrations'=>$result], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}

?>
