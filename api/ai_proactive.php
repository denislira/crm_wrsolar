<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_settings.php';
require_once __DIR__ . '/../includes/ai_crm_tools.php';

$ai = wrcrm_get_ai_settings(true);
if (empty($ai['enabled']) || empty($ai['api_key']) || empty($ai['proactive_enabled'])) {
    echo json_encode(['success' => false, 'message' => 'IA proativa desativada']);
    exit;
}

$lines = preg_split('/\r\n|\r|\n/', (string)($ai['proactive_prompts'] ?? ''));
$prompts = array_values(array_filter(array_map('trim', $lines)));
if (empty($prompts)) {
    echo json_encode(['success' => false, 'message' => 'Nenhuma análise automática configurada']);
    exit;
}

$index = isset($_GET['i']) ? (int)$_GET['i'] : 0;
$prompt = $prompts[$index % count($prompts)];
$userId = (int)$_SESSION['user_id'];
$context = wrcrm_ai_collect_context($pdo, $prompt, $userId);
$userNameCol = wrcrm_ai_has_col($pdo, 'users', 'nome_completo') ? 'nome_completo' : "'' AS nome_completo";
$userEmailCol = wrcrm_ai_has_col($pdo, 'users', 'email') ? 'email' : "'' AS email";
$userStmt = $pdo->prepare("SELECT id, username, {$userNameCol}, {$userEmailCol} FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$loggedUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];
$context['usuario_logado'] = [
    'id' => (int)($loggedUser['id'] ?? $userId),
    'nome' => trim((string)($loggedUser['nome_completo'] ?? '')) ?: (string)($loggedUser['username'] ?? ''),
    'username' => (string)($loggedUser['username'] ?? ''),
    'email' => (string)($loggedUser['email'] ?? ''),
    'perfil_no_chat' => 'usuario interno do CRM (vendedor/gestor)',
];

$result = wrcrm_call_ai_chat([
    ['role' => 'system', 'content' =>
        "Voce e a IA proativa do WRCRM.\n" .
        "Seu interlocutor e sempre o usuario interno do CRM descrito em usuario_logado, normalmente um vendedor ou gestor.\n" .
        "Leads, clientes, projetos e contatos presentes no contexto sao terceiros analisados pelo usuario; nunca os confunda com o usuario logado.\n" .
        "Nunca fale como se estivesse atendendo diretamente um lead ou cliente e nunca dirija a mensagem ao nome de um lead/cliente.\n" .
        "Ao mencionar um cliente, fale sobre ele em terceira pessoa e oriente o vendedor sobre a proxima acao.\n" .
        "Se cumprimentar, use apenas 'Ola!' sem nome. Prefira iniciar diretamente pelo alerta ou recomendacao.\n" .
        "Responda em portugues do Brasil.\n" .
        "Use somente os dados do contexto.\n" .
        "Nao invente numeros.\n" .
        "Retorne uma mensagem curta, com no maximo 420 caracteres, como um balao de alerta util.\n" .
        "Se nao houver dados suficientes, retorne uma sugestao curta de pergunta para o usuario fazer."
    ],
    ['role' => 'user', 'content' =>
        "Analise configurada:\n{$prompt}\n\n" .
        "Contexto consultado em " . date('Y-m-d H:i:s') . ":\n" .
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ],
]);

if (empty($result['success'])) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Falha na IA']);
    exit;
}

$message = trim((string)$result['content']);
$saved = false;
try {
    $chatContext = $context;
    $chatContext['proactive'] = [
        'prompt' => $prompt,
        'checked_at' => date('Y-m-d H:i:s'),
    ];
    $stmt = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, role, message, data_context) VALUES (?, 'assistant', ?, ?)");
    $stmt->execute([$userId, $message, json_encode($chatContext, JSON_UNESCAPED_UNICODE)]);
    $saved = true;
} catch (Throwable $e) {
    $saved = false;
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'prompt' => $prompt,
    'next_index' => ($index + 1) % count($prompts),
    'interval_minutes' => max(1, min(1440, (int)($ai['proactive_interval_minutes'] ?? 30))),
    'checked_at' => date('d/m/Y H:i:s'),
    'saved_to_chat' => $saved,
], JSON_UNESCAPED_UNICODE);
