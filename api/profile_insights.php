<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

try {
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
    $start = $today->modify('-29 days');
    $days = [];
    for ($i = 0; $i < 30; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $days[$day->format('Y-m-d')] = 0;
    }

    $trendStmt = $pdo->prepare("SELECT DATE(created_at) AS day, COUNT(*) AS total FROM leads WHERE user_id = ? AND deleted = 0 AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)");
    $trendStmt->execute([$userId, $start->format('Y-m-d 00:00:00'), $today->modify('+1 day')->format('Y-m-d 00:00:00')]);
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($days[$row['day']])) $days[$row['day']] = (int)$row['total'];
    }

    $stageStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(status), ''), 'Sem etapa') AS label, COUNT(*) AS total FROM leads WHERE user_id = ? AND deleted = 0 GROUP BY COALESCE(NULLIF(TRIM(status), ''), 'Sem etapa') ORDER BY total DESC, label ASC");
    $stageStmt->execute([$userId]);
    $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
    $stages = array_map(static fn($row) => ['label' => (string)$row['label'], 'total' => (int)$row['total']], $stages);

    $username = (string)($_SESSION['username'] ?? '');
    if ($username === '') {
        $nameStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $nameStmt->execute([$userId]);
        $username = (string)$nameStmt->fetchColumn();
    }
    $taskStmt = $pdo->prepare('SELECT status, data_vencimento FROM team_tasks WHERE user_id = ? OR responsavel_id = ? OR responsavel = ?');
    $taskStmt->execute([$userId, $userId, $username]);
    $tasks = ['pending' => 0, 'progress' => 0, 'completed' => 0, 'overdue' => 0];
    foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $status = mb_strtolower(trim((string)($task['status'] ?? '')), 'UTF-8');
        if (in_array($status, ['concluída', 'concluida', 'concluído', 'concluido', 'done', 'completed'], true)) {
            $tasks['completed']++;
            continue;
        }
        if (in_array($status, ['em andamento', 'andamento', 'in progress'], true)) $tasks['progress']++;
        else $tasks['pending']++;
        $due = (string)($task['data_vencimento'] ?? '');
        if ($due !== '' && substr($due, 0, 10) < $today->format('Y-m-d')) $tasks['overdue']++;
    }

    echo json_encode([
        'success' => true,
        'trend' => ['days' => array_keys($days), 'counts' => array_values($days)],
        'stages' => $stages,
        'tasks' => $tasks,
        'period_days' => 30,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[profile_insights] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Não foi possível carregar os indicadores'], JSON_UNESCAPED_UNICODE);
}
