<?php

require_once __DIR__ . '/settings_storage.php';
require_once __DIR__ . '/db_settings.php';

function wrcrm_default_ai_settings(): array
{
    return [
        'enabled' => 0,
        'provider' => 'openai_compatible',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
        'models' => [],
        'api_key' => '',
        'temperature' => 0.2,
        'max_tokens' => 900,
        'proactive_enabled' => 0,
        'proactive_interval_minutes' => 30,
        'proactive_prompts' => "Analise leads parados ha mais de 7 dias e sugira prioridades.\nAnalise gargalos do funil dos ultimos 30 dias.\nAnalise fontes com alto volume e baixa conversao no mes.",
        'draggable_launcher_enabled' => 0,
        'prompt' => "Voce e um analista comercial senior do WRCRM.\nAnalise somente os dados fornecidos.\nNao invente numeros, nomes, percentuais ou causas.\nSe faltar informacao, diga que o dado nao esta disponivel.\nRetorne de 3 a 5 insights praticos, curtos e acionaveis para a gestao comercial.\nEscreva em portugues do Brasil.",
    ];
}

function wrcrm_get_ai_settings(bool $includeSecret = false): array
{
    global $pdo;
    $ai = null;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $dbSetting = wrcrm_db_setting_get($pdo, 'ai_reports');
            if ($dbSetting && is_array($dbSetting['value'])) {
                $ai = $dbSetting['value'];
            }
        } catch (Throwable $e) {
            $ai = null;
        }
    }

    if ($ai === null) {
        $settings = wrcrm_load_settings(true);
        $ai = isset($settings['ai_reports']) && is_array($settings['ai_reports']) ? $settings['ai_reports'] : [];
        if (!empty($ai) && isset($pdo) && $pdo instanceof PDO) {
            try {
                wrcrm_db_setting_set($pdo, 'ai_reports', $ai, true, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
            } catch (Throwable $e) {}
        }
    }

    $ai = array_merge(wrcrm_default_ai_settings(), $ai);

    if (!$includeSecret) {
        $ai['has_api_key'] = !empty($ai['api_key']);
        $ai['api_key'] = '';
    }

    return $ai;
}

function wrcrm_ai_model_candidates(array $ai): array
{
    $models = [];
    if (!empty($ai['model'])) {
        $models[] = trim((string)$ai['model']);
    }

    $extra = $ai['models'] ?? [];
    if (is_string($extra)) {
        $extra = preg_split('/[\r\n,]+/', $extra) ?: [];
    }
    if (is_array($extra)) {
        foreach ($extra as $model) {
            $model = trim((string)$model);
            if ($model !== '') {
                $models[] = $model;
            }
        }
    }

    $models = array_values(array_unique(array_filter($models, static fn($model) => is_string($model) && trim($model) !== '')));
    return $models;
}

function wrcrm_save_ai_settings(array $ai): bool
{
    global $pdo;
    $current = wrcrm_get_ai_settings(true);
    $merged = array_merge($current, $ai);

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            return wrcrm_db_setting_set($pdo, 'ai_reports', $merged, true, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        } catch (Throwable $e) {
            return false;
        }
    }

    $settings = wrcrm_load_settings(true);
    $settings['ai_reports'] = $merged;
    return (bool)@file_put_contents(wrcrm_settings_path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function wrcrm_call_ai_chat(array $messages, ?array $override = null): array
{
    $ai = array_merge(wrcrm_get_ai_settings(true), $override ?: []);
    if (empty($ai['enabled']) || empty($ai['api_key'])) {
        return ['success' => false, 'message' => 'IA nao configurada ou desativada.'];
    }

    $provider = (string)($ai['provider'] ?? 'openai_compatible');
    $modelCandidates = wrcrm_ai_model_candidates($ai);
    if (empty($modelCandidates)) {
        return ['success' => false, 'message' => 'Nenhum modelo de IA configurado.'];
    }

    $attempts = [];
    foreach ($modelCandidates as $model) {
        $attemptAi = $ai;
        $attemptAi['model'] = $model;
        $attempts[] = $attemptAi;
    }

    $lastResult = ['success' => false, 'message' => 'Falha ao consultar IA.'];
    foreach ($attempts as $attemptAi) {
        if ($provider === 'gemini') {
            $result = wrcrm_call_gemini_chat($messages, $attemptAi);
        } else {
            $result = wrcrm_call_openai_compatible_chat($messages, $attemptAi);
        }

        if (!empty($result['success'])) {
            if (count($modelCandidates) > 1 && ($attemptAi['model'] ?? '') !== ($ai['model'] ?? '')) {
                $result['fallback_model'] = $attemptAi['model'];
            }
            return $result;
        }

        $lastResult = $result;
    }

    return $lastResult;
}

function wrcrm_call_openai_compatible_chat(array $messages, array $ai): array
{
    $baseUrl = rtrim((string)($ai['base_url'] ?? ''), '/');
    if ($baseUrl === '') $baseUrl = 'https://api.openai.com/v1';
    $url = $baseUrl . '/chat/completions';

    $payload = [
        'model' => (string)$ai['model'],
        'messages' => $messages,
        'temperature' => max(0, min(2, (float)($ai['temperature'] ?? 0.2))),
        'max_tokens' => max(100, min(4000, (int)($ai['max_tokens'] ?? 900))),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $ai['api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 35,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err) {
        return ['success' => false, 'message' => 'Falha na chamada da IA: ' . $err];
    }

    $json = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        return ['success' => false, 'message' => 'IA retornou erro: ' . $msg];
    }

    $content = $json['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        return ['success' => false, 'message' => 'IA retornou resposta vazia.'];
    }

    return ['success' => true, 'content' => trim($content), 'raw' => $json];
}

function wrcrm_call_gemini_chat(array $messages, array $ai): array
{
    $baseUrl = rtrim((string)($ai['base_url'] ?? ''), '/');
    if ($baseUrl === '') $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    $model = preg_replace('/^models\//', '', (string)$ai['model']);
    $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode((string)$ai['api_key']);

    $systemText = '';
    $userText = '';
    foreach ($messages as $message) {
        $role = (string)($message['role'] ?? 'user');
        $content = trim((string)($message['content'] ?? ''));
        if ($content === '') continue;
        if ($role === 'system') {
            $systemText .= ($systemText !== '' ? "\n\n" : '') . $content;
        } else {
            $userText .= ($userText !== '' ? "\n\n" : '') . $content;
        }
    }

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => trim($userText !== '' ? $userText : 'Gere uma resposta curta.')]],
        ]],
        'generationConfig' => [
            'temperature' => max(0, min(2, (float)($ai['temperature'] ?? 0.2))),
            'maxOutputTokens' => max(100, min(4000, (int)($ai['max_tokens'] ?? 900))),
        ],
    ];
    if ($systemText !== '') {
        $payload['systemInstruction'] = ['parts' => [['text' => $systemText]]];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 35,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err) {
        return ['success' => false, 'message' => 'Falha na chamada da IA: ' . $err];
    }

    $json = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        return ['success' => false, 'message' => 'Gemini retornou erro: ' . $msg];
    }

    $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        return ['success' => false, 'message' => 'Gemini retornou resposta vazia.'];
    }

    return ['success' => true, 'content' => trim($content), 'raw' => $json];
}

function wrcrm_parse_ai_insights(string $content): array
{
    $content = trim($content);
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        $items = isset($decoded['insights']) && is_array($decoded['insights']) ? $decoded['insights'] : $decoded;
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) $item = $item['text'] ?? $item['insight'] ?? $item['titulo'] ?? '';
            $item = trim((string)$item);
            if ($item !== '') $out[] = $item;
        }
        return array_slice($out, 0, 6);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $out = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^\s*[-*•]|\s*\d+[\).\:-]\s*/u', '', $line));
        if ($line !== '') $out[] = $line;
    }

    if (empty($out) && $content !== '') $out[] = $content;
    return array_slice($out, 0, 6);
}
