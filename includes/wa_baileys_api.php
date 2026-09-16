<?php
require_once __DIR__ . '/config.php';
if (is_readable(__DIR__ . '/../.env')) {
    foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim(trim($value), "\"'");
        if (str_starts_with($key, 'BAILEYS_') && $value !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}
function wa_baileys_config() {
    global $pdo;
    $defaults = [
        'url' => rtrim((string)(getenv('BAILEYS_API_URL') ?: 'http://127.0.0.1:3001'), '/'),
        'secret' => (string)(getenv('BAILEYS_INTERNAL_SECRET') ?: ''),
        'client_key' => (string)(getenv('BAILEYS_CLIENT_KEY') ?: ''),
        'empresa_id' => (int)(getenv('BAILEYS_EMPRESA_ID') ?: 9999),
        'empresa_token' => (string)(getenv('BAILEYS_EMPRESA_TOKEN') ?: 'wrcrm-9999'),
        'auto_create_leads' => 1,
        'lead_capture_mode' => 'new_only',
        'reopen_after_days' => 30,
    ];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_integracao_config (id TINYINT UNSIGNED PRIMARY KEY, api_url VARCHAR(500) NOT NULL, internal_secret TEXT NOT NULL, empresa_id INT NOT NULL, empresa_token TEXT NOT NULL, auto_create_leads TINYINT(1) NOT NULL DEFAULT 1, lead_capture_mode VARCHAR(40) NOT NULL DEFAULT 'new_only', reopen_after_days INT NOT NULL DEFAULT 30, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        try { $pdo->exec("ALTER TABLE whatsapp_integracao_config ADD COLUMN auto_create_leads TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $ignored) {}
        try { $pdo->exec("ALTER TABLE whatsapp_integracao_config ADD COLUMN lead_capture_mode VARCHAR(40) NOT NULL DEFAULT 'new_only'"); } catch (Throwable $ignored) {}
        try { $pdo->exec("ALTER TABLE whatsapp_integracao_config ADD COLUMN reopen_after_days INT NOT NULL DEFAULT 30"); } catch (Throwable $ignored) {}
        $seed = $pdo->prepare('INSERT IGNORE INTO whatsapp_integracao_config (id, api_url, internal_secret, empresa_id, empresa_token, auto_create_leads, lead_capture_mode, reopen_after_days) VALUES (1, ?, ?, ?, ?, 1, ?, ?)');
        $seed->execute([$defaults['url'], $defaults['secret'], $defaults['empresa_id'], $defaults['empresa_token'], $defaults['lead_capture_mode'], $defaults['reopen_after_days']]);
        $row = $pdo->query('SELECT api_url, internal_secret, empresa_id, empresa_token, auto_create_leads, lead_capture_mode, reopen_after_days FROM whatsapp_integracao_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['url'=>rtrim($row['api_url'], '/'), 'secret'=>$row['internal_secret'], 'client_key'=>$defaults['client_key'], 'empresa_id'=>(int)$row['empresa_id'], 'empresa_token'=>$row['empresa_token'], 'auto_create_leads'=>(int)($row['auto_create_leads'] ?? 1), 'lead_capture_mode'=>$row['lead_capture_mode'] ?? 'new_only', 'reopen_after_days'=>(int)($row['reopen_after_days'] ?? 30)];
    } catch (Throwable $e) { /* usa defaults */ }
    return $defaults;
}
function wa_baileys_request($method, $path, $payload = null) {
    $cfg = wa_baileys_config(); $url = $cfg['url'] . '/' . ltrim($path, '/');
    if ($method === 'GET' && $payload) {
        // Tokens não devem aparecer na URL, pois podem ser gravados em logs/proxies.
        $query = $payload;
        unset($query['empresa_token']);
        if ($query) $url .= '?' . http_build_query($query);
    }
    $ch = curl_init($url); $headers = [
        'Accept: application/json',
        'X-Internal-Secret: ' . $cfg['secret'],
        'X-Empresa-Token: ' . $cfg['empresa_token'],
        'X-Client-Key: ' . $cfg['client_key'],
    ];
    if ($method !== 'GET') { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>120]);
    $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($raw === false || $error || !is_array($data)) return ['ok'=>false, 'error'=>'baileys_api_indisponivel', 'detail'=>$error ?: 'resposta_invalida', '_http'=>$status];
    $data['_http'] = $status; return $data;
}
