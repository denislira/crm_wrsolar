<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/ai_settings.php';
require_once __DIR__ . '/../includes/ai_crm_tools.php';

function wrcrm_ai_chat_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function wrcrm_ai_chat_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role ENUM('user','assistant') NOT NULL,
            message TEXT NOT NULL,
            data_context JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_chat_user_created (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'ask';
wrcrm_ai_chat_ensure_tables($pdo);

if ($action === 'history') {
    $stmt = $pdo->prepare("SELECT role, message, created_at FROM ai_chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 20");
    $stmt->execute([$userId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    wrcrm_ai_chat_json(['success' => true, 'messages' => $rows]);
}

if ($action === 'clear') {
    $stmt = $pdo->prepare("DELETE FROM ai_chat_messages WHERE user_id = ?");
    $stmt->execute([$userId]);
    wrcrm_ai_chat_json(['success' => true, 'message' => 'Histórico limpo']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    wrcrm_ai_chat_json(['success' => false, 'message' => 'Metodo nao permitido']);
}

$question = trim((string)($_POST['message'] ?? ''));
if ($question === '') {
    wrcrm_ai_chat_json(['success' => false, 'message' => 'Digite uma pergunta.']);
}
if (mb_strlen($question, 'UTF-8') > 1200) {
    wrcrm_ai_chat_json(['success' => false, 'message' => 'Pergunta muito longa.']);
}

$aiSettings = wrcrm_get_ai_settings(true);
if (empty($aiSettings['enabled']) || empty($aiSettings['api_key'])) {
    wrcrm_ai_chat_json(['success' => false, 'message' => 'IA não está configurada em Configurações > Integrações.']);
}

$context = wrcrm_ai_collect_context($pdo, $question, $userId);
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
];
$pdo->prepare("INSERT INTO ai_chat_messages (user_id, role, message, data_context) VALUES (?, 'user', ?, ?)")
    ->execute([$userId, $question, json_encode($context, JSON_UNESCAPED_UNICODE)]);

$historyStmt = $pdo->prepare("SELECT role, message FROM ai_chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 8");
$historyStmt->execute([$userId]);
$historyRows = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));
$historyText = '';
foreach ($historyRows as $row) {
    $historyText .= strtoupper((string)$row['role']) . ': ' . (string)$row['message'] . "\n";
}

$messages = [
    ['role' => 'system', 'content' =>
        "Voce e a IA assistente do WRCRM.\n" .
        "Voce esta conversando com o usuario logado informado em usuario_logado.\n" .
        "Quando cumprimentar, use apenas 'Ola' ou 'Ola!'. Nao inclua nomes na saudacao.\n" .
        "Nunca trate nome de lead, cliente, projeto ou contato como se fosse o usuario logado.\n" .
        "Voce nao fala diretamente com leads/clientes; voce ajuda o usuario do CRM a analisar, escrever mensagens e decidir proximos passos.\n" .
        "Mensagens antigas do historico podem conter uma identificacao incorreta do interlocutor; ignore esse erro e siga sempre usuario_logado.\n" .
        "Responda em portugues do Brasil.\n" .
        "Use somente os dados fornecidos no contexto JSON.\n" .
        "Nao invente numeros, IDs, nomes, percentuais nem datas.\n" .
        "Quando a pergunta pedir previsao, diga claramente que e uma estimativa baseada no periodo consultado.\n" .
        "Conduza a conversa com liberdade: se faltar um dado essencial, faca uma pergunta curta e objetiva; se houver contexto suficiente, aja direto e entregue a ajuda.\n" .
        "Observe o contexto usuario_contexto_recente para entender o que o usuario acabou de salvar, atualizar, mover ou registrar no CRM.\n" .
        "Quando fizer sentido, cite a acao recente que motivou sua sugestao e ofereca o proximo passo pratico.\n" .
        "Se a ferramenta nao trouxe dados suficientes, diga qual dado falta e sugira uma pergunta melhor.\n" .
        "Se o contexto trouxer totais, listas ou agrupamentos, responda diretamente com esses dados.\n" .
        "Se houver listas, mostre no maximo 10 itens e priorize conclusoes acionaveis.\n" .
        "Quando o contexto trouxer lead_recente_para_mensagem, identifique o lead usado e entregue uma mensagem pronta para copiar. " .
        "Se o usuario pedir email e WhatsApp, entregue os dois formatos. Use as anotacoes e movimentacoes apenas como contexto e nunca invente detalhes."
    ],
    ['role' => 'user', 'content' =>
        "Histórico recente:\n{$historyText}\n" .
        "Pergunta atual:\n{$question}\n\n" .
        "Contexto consultado no banco em " . date('Y-m-d H:i:s') . ":\n" .
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ],
];

$result = wrcrm_call_ai_chat($messages);
if (empty($result['success'])) {
    wrcrm_ai_chat_json([
        'success' => false,
        'message' => $result['message'] ?? 'Falha ao consultar IA',
        'context' => $context,
    ]);
}

$answer = trim((string)$result['content']);
$pdo->prepare("INSERT INTO ai_chat_messages (user_id, role, message, data_context) VALUES (?, 'assistant', ?, ?)")
    ->execute([$userId, $answer, json_encode($context, JSON_UNESCAPED_UNICODE)]);

wrcrm_ai_chat_json([
    'success' => true,
    'answer' => $answer,
    'context' => $context,
    'source' => 'Banco consultado + IA',
    'checked_at' => date('d/m/Y H:i:s'),
]);
