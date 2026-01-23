<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Simple server-side QR image proxy/generator using external public QR API
// This avoids browser blocked cross-origin image loads. No Composer required.
$storage_dir = __DIR__ . '/../storage';
$state_file = $storage_dir . '/wa_state.json';
if (!file_exists($state_file)) {
    // return a lightweight SVG placeholder so the frontend can show a friendly image
    header('Content-Type: image/svg+xml');
    echo "<svg xmlns='http://www.w3.org/2000/svg' width='300' height='300' viewBox='0 0 300 300'>";
    echo "<rect width='100%' height='100%' fill='#f8f9fa'/>";
    echo "<text x='50%' y='50%' dominant-baseline='middle' text-anchor='middle' fill='#6c757d' font-family='Arial,Helvetica,sans-serif' font-size='16'>No QR state</text>";
    echo "</svg>";
    exit;
}
$raw = file_get_contents($state_file);
$s = json_decode($raw, true);
$qr_text = '';
if (!empty($s['qr_data'])) $qr_text = $s['qr_data'];
if (empty($qr_text)) {
    // return a friendly SVG explaining there's no QR yet
    header('Content-Type: image/svg+xml');
    echo "<svg xmlns='http://www.w3.org/2000/svg' width='300' height='300' viewBox='0 0 300 300'>";
    echo "<rect width='100%' height='100%' fill='#fff3cd'/>";
    echo "<text x='50%' y='45%' dominant-baseline='middle' text-anchor='middle' fill='#856404' font-family='Arial,Helvetica,sans-serif' font-size='14'>Nenhum QRCODE</text>";
    echo "<text x='50%' y='60%' dominant-baseline='middle' text-anchor='middle' fill='#856404' font-family='Arial,Helvetica,sans-serif' font-size='12'>Clique em Gerar ou cole o QR manualmente</text>";
    echo "</svg>";
    exit;
}
// use api.qrserver.com to create QR PNG (server-side fetch)
$api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_text);
$img = false;
// try file_get_contents first
if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http'=>['timeout'=>5], 'https'=>['timeout'=>5]]);
    $img = @file_get_contents($api, false, $ctx);
}
// fallback to cURL
if ($img === false && function_exists('curl_version')) {
    $ch = curl_init($api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (PHP)');
    $img = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) $img = false;
}
if ($img === false) {
    // log the failure for debugging
    $logdir = __DIR__ . '/../logs';
    if (!is_dir($logdir)) @mkdir($logdir, 0755, true);
    $msg = date('c') . " - failed to fetch external QR for " . substr($qr_text, 0, 80) . "\n";
    @file_put_contents($logdir . '/wa_qr_image_fetch.log', $msg, FILE_APPEND | LOCK_EX);
    // return an SVG placeholder so the frontend always receives an image
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "<svg xmlns='http://www.w3.org/2000/svg' width='300' height='300' viewBox='0 0 300 300'>";
    echo "<rect width='100%' height='100%' fill='#f8f9fa'/>";
    echo "<text x='50%' y='45%' dominant-baseline='middle' text-anchor='middle' fill='#6c757d' font-family='Arial,Helvetica,sans-serif' font-size='14'>QR indisponível</text>";
    echo "<text x='50%' y='60%' dominant-baseline='middle' text-anchor='middle' fill='#6c757d' font-family='Arial,Helvetica,sans-serif' font-size='11'>Tente Gerar ou colar manualmente</text>";
    echo "</svg>";
    exit;
}
// output PNG
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $img;
