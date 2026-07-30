<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email_notifications.php';

@ignore_user_abort(true);

$sent = wrcrm_process_queued_login_security_notifications($pdo);

echo json_encode(['success' => true, 'sent' => $sent]);
