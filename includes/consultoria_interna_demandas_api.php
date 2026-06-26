<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/consultoria_externa_stages.php';

$userId = (int) $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';
$roleName = $_SESSION['role_name'] ?? null;

if (!$roleName && !empty($_SESSION['role_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['role_id']]);
    $roleName = $stmt->fetchColumn();
}

$canAccessDemandQueue = function_exists('isDirector') && isDirector() ? true : hasPermission('fila_demandas');
if (strtolower((string)$roleName) === 'consultor_externo' || !$canAccessDemandQueue) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

try {
    ce_ensure_stage_tables($pdo);

    if ($action === 'list') {
        $status = trim((string)($_GET['status'] ?? ''));
        $where = ['COALESCE(i.deleted, 0) = 0'];
        $params = [];
        if ($status !== '') {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }

        $sql = "
            SELECT
                d.id AS demand_id,
                d.external_item_id,
                d.external_stage_id,
                d.external_user_id,
                d.status AS demand_status,
                d.accepted_by,
                d.accepted_at,
                d.created_at AS queued_at,
                d.updated_at AS demand_updated_at,
                i.client_name,
                i.phone,
                i.cidade,
                i.source,
                i.value,
                i.notes,
                i.status AS external_status,
                i.created_at AS external_created_at,
                s.name AS stage_name,
                s.color AS stage_color,
                u.username AS external_consultor,
                au.username AS accepted_by_name
            FROM consultoria_interna_demandas d
            INNER JOIN consultoria_externa_itens i ON i.id = d.external_item_id
            LEFT JOIN consultoria_externa_stages s ON s.id = d.external_stage_id
            LEFT JOIN users u ON u.id = d.external_user_id
            LEFT JOIN users au ON au.id = d.accepted_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE d.status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
                d.created_at DESC,
                d.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'accept') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'accepted', accepted_by = ?, accepted_at = COALESCE(accepted_at, NOW()), updated_at = NOW() WHERE id = ? AND status IN ('pending', 'accepted')");
        $stmt->execute([$userId, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'complete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'done', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reopen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID invalido');
        }
        $stmt = $pdo->prepare("UPDATE consultoria_interna_demandas SET status = 'pending', accepted_by = NULL, accepted_at = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new Exception('Acao invalida');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
