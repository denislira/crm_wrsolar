<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

// The AI call can take several seconds. Release PHP's per-session lock so it
// cannot block opening/closing another lead while the insight is generated.
if (function_exists('session_write_close')) {
    session_write_close();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_settings.php';

function ai_lead_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ai_lead_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ai_lead_has(array $cols, string $col): bool
{
    return in_array($col, $cols, true);
}

$leadId = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : (int)($_POST['lead_id'] ?? 0);
if ($leadId <= 0) {
    ai_lead_json(['success' => false, 'message' => 'Lead invalido']);
}

$aiSettings = wrcrm_get_ai_settings(true);
if (empty($aiSettings['enabled']) || empty($aiSettings['api_key'])) {
    ai_lead_json(['success' => false, 'message' => 'IA nao configurada']);
}

$leadCols = ai_lead_columns($pdo, 'leads');
if (empty($leadCols)) {
    ai_lead_json(['success' => false, 'message' => 'Tabela de leads indisponivel']);
}

$leadSelect = [
    'id',
    ai_lead_has($leadCols, 'name') ? 'name' : "'' AS name",
    ai_lead_has($leadCols, 'cidade') ? 'cidade' : "'' AS cidade",
    ai_lead_has($leadCols, 'email') ? 'email' : "'' AS email",
    ai_lead_has($leadCols, 'phone') ? 'phone' : "'' AS phone",
    ai_lead_has($leadCols, 'source') ? 'source' : "'' AS source",
    ai_lead_has($leadCols, 'status') ? 'status' : "'' AS status",
    ai_lead_has($leadCols, 'notes') ? 'notes' : "'' AS notes",
    ai_lead_has($leadCols, 'orcamento_value') ? 'orcamento_value' : '0 AS orcamento_value',
    ai_lead_has($leadCols, 'consumo_cliente') ? 'consumo_cliente' : "'' AS consumo_cliente",
    ai_lead_has($leadCols, 'estimativa_projeto_kwh') ? 'estimativa_projeto_kwh' : "'' AS estimativa_projeto_kwh",
    ai_lead_has($leadCols, 'data_inicio') ? 'data_inicio' : "NULL AS data_inicio",
    ai_lead_has($leadCols, 'created_at') ? 'created_at' : "NULL AS created_at",
    ai_lead_has($leadCols, 'updated_at') ? 'updated_at' : "NULL AS updated_at",
    ai_lead_has($leadCols, 'ultimo_contato') ? 'ultimo_contato' : "NULL AS ultimo_contato",
    ai_lead_has($leadCols, 'first_contact_at') ? 'first_contact_at' : "NULL AS first_contact_at",
];
$deletedCond = ai_lead_has($leadCols, 'deleted') ? ' AND COALESCE(deleted, 0) = 0' : '';

$stmt = $pdo->prepare('SELECT ' . implode(', ', $leadSelect) . " FROM leads WHERE id = ?{$deletedCond} LIMIT 1");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    ai_lead_json(['success' => false, 'message' => 'Lead nao encontrado']);
}

$movements = [];
$movementCols = ai_lead_columns($pdo, 'lead_movements');
if (ai_lead_has($movementCols, 'lead_id') && ai_lead_has($movementCols, 'created_at')) {
    try {
        $noteExpr = ai_lead_has($movementCols, 'note') ? 'note' : "'' AS note";
        $fromExpr = ai_lead_has($movementCols, 'from_status') ? 'from_status' : "'' AS from_status";
        $toExpr = ai_lead_has($movementCols, 'to_status') ? 'to_status' : "'' AS to_status";
        $moveStmt = $pdo->prepare("SELECT {$fromExpr}, {$toExpr}, {$noteExpr}, created_at FROM lead_movements WHERE lead_id = ? ORDER BY created_at DESC LIMIT 8");
        $moveStmt->execute([$leadId]);
        $movements = $moveStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $movements = [];
    }
}

$reminders = [];
$reminderCols = ai_lead_columns($pdo, 'reminders');
if (ai_lead_has($reminderCols, 'lead_id')) {
    try {
        $remindAt = ai_lead_has($reminderCols, 'remind_at') ? 'remind_at' : "NULL AS remind_at";
        $message = ai_lead_has($reminderCols, 'message') ? 'message' : "'' AS message";
        $status = ai_lead_has($reminderCols, 'status') ? 'status' : "'' AS status";
        $remStmt = $pdo->prepare("SELECT {$message}, {$remindAt}, {$status} FROM reminders WHERE lead_id = ? ORDER BY remind_at ASC LIMIT 5");
        $remStmt->execute([$leadId]);
        $reminders = $remStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $reminders = [];
    }
}

$context = [
    'lead' => $lead,
    'movimentacoes_recentes' => $movements,
    'lembretes' => $reminders,
    'gerado_em' => date('Y-m-d H:i:s'),
];

$result = wrcrm_call_ai_chat([
    ['role' => 'system', 'content' =>
        "Voce e um analista comercial do WRCRM.\n" .
        "A resposta sera exibida dentro do detalhe de um lead para um usuario interno vendedor/gestor.\n" .
        "Nao fale como se o lead fosse o usuario. O lead e um cliente em analise.\n" .
        "Use somente os dados fornecidos. Nao invente valores, datas, nomes ou motivos.\n" .
        "Retorne um resumo curto em portugues do Brasil, com no maximo 3 bullets.\n" .
        "Inclua: situacao do lead, principal risco/oportunidade e proxima acao sugerida.\n" .
        "Se faltar dado importante, diga objetivamente qual dado revisar."
    ],
    ['role' => 'user', 'content' => "Dados do lead:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
]);

if (empty($result['success'])) {
    ai_lead_json(['success' => false, 'message' => $result['message'] ?? 'Falha ao gerar insight']);
}

ai_lead_json([
    'success' => true,
    'insight' => trim((string)$result['content']),
    'checked_at' => date('d/m/Y H:i:s'),
]);
