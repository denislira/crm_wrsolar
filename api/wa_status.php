<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$storage_dir = __DIR__ . '/../storage';
header('Content-Type: application/json');
// Allow unauthenticated access for local testing if the state file exists.
// In production, you may want to enforce session checks here.
if (!isset($_SESSION['user_id'])) {
    // only allow read if state file exists (so the service produced a QR)
    $state_file_tmp = $storage_dir . '/wa_state.json';
    if (!file_exists($state_file_tmp)) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
}
$state_file = $storage_dir . '/wa_state.json';
$state = ['connected'=>false];
if (file_exists($state_file)) {
    $raw = file_get_contents($state_file);
    $s = json_decode($raw, true);
    if (is_array($s)) $state = $s;
}
$result = [
    'success' => true,
    'connected' => !empty($state['connected']),
    'info' => $state['info'] ?? null
];
if (empty($state['connected']) && !empty($state['qr_data']) && strpos((string)$state['qr_data'], 'whatsapp-qr:') === 0) {
    unset($state['qr_data']);
    $state['info'] = 'QR de teste removido. Inicie o wa-service para gerar um QR real.';
    @file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));
    $result['info'] = $state['info'];
}
if (empty($state['connected']) && !empty($state['qr_data'])) {
    $qr_text = $state['qr_data'];
    $chart = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode($qr_text) . '&chld=L|1';
    $result['qr'] = $chart;
    $result['qr_generated_at'] = $state['qr_generated_at'] ?? null;
    // Try to fetch the chart image server-side and return as data URI to avoid
    // browser tracking-protection blocking external image loads.
    // Attempt to fetch the QR image server-side. Some PHP installs disallow
    // remote fopen; try file_get_contents with a browser-like UA first and
    // fall back to cURL. Log diagnostic info to logs/wa_status_fetch.log.
    $logPath = __DIR__ . '/../logs/wa_status_fetch.log';
    $log = [];
    $img = false;
    $log[] = date('c') . " - attempting fetch: $chart";
    $allow = ini_get('allow_url_fopen') ? '1' : '0';
    $log[] = "allow_url_fopen: $allow";

    // try file_get_contents with UA
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
                'timeout' => 5,
            ]
        ]);
        try {
            $img = @file_get_contents($chart, false, $ctx);
            $log[] = 'file_get_contents result: ' . ($img === false ? 'false' : 'success, ' . strlen($img) . ' bytes');
        } catch (Exception $e) {
            $img = false;
            $log[] = 'file_get_contents exception: ' . $e->getMessage();
        }
    } else {
        $log[] = 'skipped file_get_contents (allow_url_fopen disabled)';
    }

    // fallback to cURL
    if ($img === false) {
        if (function_exists('curl_version')) {
            $ch = curl_init($chart);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $img = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            $log[] = 'curl httpCode: ' . intval($httpCode) . ', err: ' . $curlErr . ', bytes: ' . ($img === false ? 'false' : strlen($img));
            if ($img === false || intval($httpCode) !== 200) {
                $img = false;
            }
        } else {
            $log[] = 'curl not available';
        }
    }

    if ($img !== false) {
        $b64 = base64_encode($img);
        $result['qr_data_uri'] = 'data:image/png;base64,' . $b64;
        $log[] = 'generated data uri, bytes: ' . strlen($b64);
    } else {
        $log[] = 'failed to fetch image server-side';
    }

    // write log
    try {
        $d = dirname($logPath);
        if (!is_dir($d)) @mkdir($d, 0755, true);
        file_put_contents($logPath, implode("\n", $log) . "\n\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { /* ignore logging errors */ }
}

if (empty($state['connected']) && !empty($state['qr_data'])) {
    // provide same-origin URL to fetch QR image (server-side proxy)
    $result['qr_image_url'] = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/wa_qr_image.php';
}
echo json_encode($result);
