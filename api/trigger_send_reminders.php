<?php
// Endpoint seguro para acionar envio de lembretes (para uso com cron do painel)
header('Content-Type: application/json; charset=utf-8');
// token via query string: ?token=LONG_SECRET
$token = $_GET['token'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

$settingsPath = __DIR__ . '/../storage/settings.json';
$settings = [];
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
}
$expected = $settings['reminder_token'] ?? '';
if (empty($expected) || !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

// call internal sender
try {
    include_once __DIR__ . '/../includes/send_pending_reminders.php';
    if (!function_exists('send_pending_reminders')) throw new Exception('function missing');
    // run with provided limit
    send_pending_reminders($limit);
    echo json_encode(['success' => true, 'triggered' => true, 'limit' => $limit]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
