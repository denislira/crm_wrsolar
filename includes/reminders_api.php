<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'];
action:
$action = $_POST['action'] ?? $_GET['action'] ?? null;
try {
    if ($action === 'add') {
        $leadId = $_POST['lead_id'] ?? null;
        $leadIdent = trim($_POST['lead_ident'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $templateId = isset($_POST['template_id']) ? ($_POST['template_id'] ? (int)$_POST['template_id'] : null) : null;
        $datetime = $_POST['datetime'] ?? null; // expected 'YYYY-MM-DD HH:MM'
        // allow lead_id numeric; if not provided try to resolve by lead_ident, otherwise fallback to 0
        if (!$leadId && $leadIdent) {
            $s = $pdo->prepare('SELECT id FROM leads WHERE name LIKE ? LIMIT 1');
            $s->execute(["%$leadIdent%"]);
            $found = $s->fetchColumn();
            if ($found) $leadId = $found;
        }
        if (!$leadId) $leadId = 0;
        if (!$message || !$datetime) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }
        // validate datetime
        $dt = date('Y-m-d H:i:s', strtotime($datetime));
        $stmt = $pdo->prepare('INSERT INTO reminders (lead_id, message, remind_at, template_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$leadId, $message, $dt, $templateId, 'pending', $userId]);
        $id = $pdo->lastInsertId();
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }
    if ($action === 'list') {
        $status = $_GET['status'] ?? null;
        $leadId = $_GET['lead_id'] ?? null;
        $sql = 'SELECT r.id, r.lead_id, r.message, r.remind_at, r.status, r.template_id, r.created_by, r.created_at, l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id';
        $w = [];
        $params = [];
        if ($status) { $w[] = 'r.status = ?'; $params[] = $status; }
        if ($leadId) { $w[] = 'r.lead_id = ?'; $params[] = $leadId; }
        if (!empty($w)) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY r.remind_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($action === 'get') {
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $stmt = $pdo->prepare('SELECT * FROM reminders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: []);
        exit;
    }

    if ($action === 'update') {
        // update reminder
        $id = $_POST['id'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $datetime = $_POST['datetime'] ?? null;
        $statusNew = $_POST['status'] ?? null;
        $templateId = isset($_POST['template_id']) ? ($_POST['template_id'] ? (int)$_POST['template_id'] : null) : null;
        if (!$id || !$message || !$datetime) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }
        $dt = date('Y-m-d H:i:s', strtotime($datetime));
        $stmt = $pdo->prepare('UPDATE reminders SET message = ?, remind_at = ?, template_id = ?, status = ? WHERE id = ?');
        $stmt->execute([$message, $dt, $templateId, $statusNew ?: 'pending', $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'mark_sent') {
        $id = $_POST['id'] ?? ($_GET['id'] ?? null);
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $stmt = $pdo->prepare('UPDATE reminders SET status = ?, executed_at = NOW() WHERE id = ?');
        $stmt->execute(['sent', $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'delete') {
        $id = $_POST['id'] ?? ($_GET['id'] ?? null);
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $stmt = $pdo->prepare('DELETE FROM reminders WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
    http_response_code(400); echo json_encode(['error'=>'Unknown action']);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}

?>