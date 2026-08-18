<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/ai_settings.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$current = wrcrm_get_ai_settings(true);
$apiKey = trim((string)($_POST['api_key'] ?? ''));
$baseUrl = trim((string)($_POST['base_url'] ?? ''));
$model = trim((string)($_POST['model'] ?? ''));
$modelsRaw = trim((string)($_POST['models'] ?? ''));
$prompt = trim((string)($_POST['prompt'] ?? ''));
$temperature = isset($_POST['temperature']) ? (float)$_POST['temperature'] : 0.2;
$maxTokens = isset($_POST['max_tokens']) ? (int)$_POST['max_tokens'] : 900;
$proactiveInterval = isset($_POST['proactive_interval_minutes']) ? (int)$_POST['proactive_interval_minutes'] : 30;
$proactivePrompts = trim((string)($_POST['proactive_prompts'] ?? ''));
$draggableLauncherEnabled = isset($_POST['draggable_launcher_enabled']) && in_array((string)$_POST['draggable_launcher_enabled'], ['1', 'true', 'on'], true) ? 1 : 0;
$provider = trim((string)($_POST['provider'] ?? 'openai_compatible'));
if ($provider === '') {
    $provider = 'openai_compatible';
}

$defaultBaseUrl = $provider === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta' : 'https://api.openai.com/v1';
$defaultModel = $provider === 'gemini' ? 'gemini-3.1-flash-lite' : 'gpt-4o-mini';

$ai = [
    'enabled' => isset($_POST['enabled']) && in_array((string)$_POST['enabled'], ['1', 'true', 'on'], true) ? 1 : 0,
    'provider' => $provider,
    'base_url' => $baseUrl !== '' ? rtrim($baseUrl, '/') : $defaultBaseUrl,
    'model' => $model !== '' ? $model : $defaultModel,
    'models' => [],
    'temperature' => max(0, min(2, $temperature)),
    'max_tokens' => max(100, min(4000, $maxTokens)),
    'proactive_enabled' => isset($_POST['proactive_enabled']) && in_array((string)$_POST['proactive_enabled'], ['1', 'true', 'on'], true) ? 1 : 0,
    'proactive_interval_minutes' => max(1, min(1440, $proactiveInterval)),
    'proactive_prompts' => $proactivePrompts,
    'draggable_launcher_enabled' => $draggableLauncherEnabled,
    'prompt' => $prompt !== '' ? $prompt : wrcrm_default_ai_settings()['prompt'],
];

if ($modelsRaw !== '') {
    $models = preg_split('/[\r\n,]+/', $modelsRaw) ?: [];
    $models = array_values(array_filter(array_map('trim', $models), static fn($item) => $item !== ''));
    $ai['models'] = array_values(array_unique($models));
}

if ($apiKey !== '') {
    $ai['api_key'] = $apiKey;
} elseif (!empty($current['api_key'])) {
    $ai['api_key'] = $current['api_key'];
}

if (wrcrm_save_ai_settings($ai)) {
    echo json_encode(['success' => true, 'message' => 'Configuração de IA salva com sucesso', 'ai' => wrcrm_get_ai_settings(false)]);
} else {
    echo json_encode(['success' => false, 'message' => 'Falha ao salvar configuração de IA']);
}
