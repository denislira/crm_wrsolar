<?php
require_once __DIR__ . '/config.php';
function wa_baileys_config() {
    global $pdo;
    $defaults = [
        'url' => rtrim((string)(getenv('BAILEYS_API_URL') ?: 'http://127.0.0.1:3001'), '/'),
        'secret' => (string)(getenv('BAILEYS_INTERNAL_SECRET') ?: ''),
        'empresa_id' => (int)(getenv('BAILEYS_EMPRESA_ID') ?: 9999),
        'empresa_token' => (string)(getenv('BAILEYS_EMPRESA_TOKEN') ?: 'wrcrm-9999'),
    ];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_integracao_config (id TINYINT UNSIGNED PRIMARY KEY, api_url VARCHAR(500) NOT NULL, internal_secret TEXT NOT NULL, empresa_id INT NOT NULL, empresa_token TEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $row = $pdo->query('SELECT api_url, internal_secret, empresa_id, empresa_token FROM whatsapp_integracao_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['url'=>rtrim($row['api_url'], '/'), 'secret'=>$row['internal_secret'], 'empresa_id'=>(int)$row['empresa_id'], 'empresa_token'=>$row['empresa_token']];
    } catch (Throwable $e) { /* usa defaults */ }
    return $defaults;
}
function wa_baileys_request($method, $path, $payload = null) {
    $cfg = wa_baileys_config(); $url = $cfg['url'] . '/' . ltrim($path, '/');
    if ($method === 'GET' && $payload) $url .= '?' . http_build_query($payload);
    $ch = curl_init($url); $headers = ['Accept: application/json', 'X-Internal-Secret: ' . $cfg['secret']];
    if ($method !== 'GET') { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30]);
    $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($raw === false || $error || !is_array($data)) return ['ok'=>false, 'error'=>'baileys_api_indisponivel', 'detail'=>$error ?: 'resposta_invalida', '_http'=>$status];
    $data['_http'] = $status; return $data;
}
