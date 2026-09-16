<?php
require_once __DIR__ . '/../includes/wa_baileys_api.php';
require_once __DIR__ . '/../includes/email_notifications.php';

header('Content-Type: application/json; charset=utf-8');

function wa_incoming_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function wa_incoming_digits($value): string {
    return preg_replace('/\D+/', '', (string)$value);
}

function wa_incoming_phone_variants(string $digits): array {
    $digits = wa_incoming_digits($digits);
    $variants = [$digits];
    if (str_starts_with($digits, '55') && strlen($digits) > 11) {
        $variants[] = substr($digits, 2);
    } elseif (strlen($digits) >= 10 && strlen($digits) <= 11) {
        $variants[] = '55' . $digits;
    }
    return array_values(array_unique(array_filter($variants)));
}

function wa_incoming_find_latest_lead_by_phone(PDO $pdo, string $digits): ?array {
    $variants = wa_incoming_phone_variants($digits);
    $matches = [];
    $stmt = $pdo->query("SELECT id, user_id, phone, status, source, stage_id, updated_at, created_at FROM leads WHERE phone IS NOT NULL AND phone <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored = wa_incoming_digits($row['phone'] ?? '');
        if (!$stored) continue;
        $storedVariants = wa_incoming_phone_variants($stored);
        if (array_intersect($variants, $storedVariants)) $matches[] = $row;
    }
    if (!$matches) return null;
    usort($matches, function($a, $b) {
        return strtotime($b['updated_at'] ?? $b['created_at'] ?? '1970-01-01') <=> strtotime($a['updated_at'] ?? $a['created_at'] ?? '1970-01-01');
    });
    return $matches[0];
}

function wa_incoming_is_closed_lead(array $lead): bool {
    $label = mb_strtolower(trim((string)($lead['status'] ?? '')), 'UTF-8');
    return (bool)preg_match('/conclu|encerr|finaliz|ganh|perdid|vendid|fechad/u', $label);
}

function wa_incoming_days_since_lead(array $lead): int {
    $raw = $lead['updated_at'] ?? $lead['created_at'] ?? null;
    $ts = $raw ? strtotime((string)$raw) : false;
    if (!$ts) return 0;
    return max(0, (int)floor((time() - $ts) / 86400));
}

$cfg = wa_baileys_config();
$secret = $cfg['secret'] ?? '';
if ($secret !== '' && ($_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '') !== $secret) {
    wa_incoming_json(['success' => false, 'message' => 'unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wa_incoming_json(['success' => false, 'message' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    wa_incoming_json(['success' => false, 'message' => 'invalid_json'], 400);
}

// Test mode: ignore CRM capture settings and create leads for every inbound WhatsApp message.

$phone = wa_incoming_digits($payload['telefone'] ?? $payload['phone'] ?? $payload['from'] ?? '');
$text = trim((string)($payload['mensagem'] ?? $payload['text'] ?? $payload['message'] ?? ''));
$pushName = trim((string)($payload['push_name'] ?? $payload['name'] ?? ''));

if (strlen($phone) < 10 || strlen($phone) > 15) {
    wa_incoming_json(['success' => false, 'message' => 'invalid_phone'], 422);
}

try {
    // Test mode: create a WhatsApp lead for every received message, including duplicate numbers.
    $existing = null;

    $userId = 0;
    if (!empty($existing['user_id'])) $userId = (int)$existing['user_id'];
    try {
        if ($userId <= 0) {
            $userStmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
            $firstUser = $userStmt->fetchColumn();
            if ($firstUser) $userId = (int)$firstUser;
        }
    } catch (Throwable $ignored) {}
    if ($userId <= 0) $userId = 1;

    $name = $pushName !== '' ? $pushName : ('WhatsApp ' . $phone);
    $notes = [];
    $notes[] = 'Lead criado automaticamente a partir de conversa recebida no WhatsApp.';
    if (!empty($existing['id'])) $notes[] = 'Novo atendimento criado por retorno no WhatsApp. Lead anterior: #' . (int)$existing['id'];
    if ($text !== '') $notes[] = 'Primeira mensagem: ' . mb_substr($text, 0, 1000);
    if (!empty($payload['message_id'])) $notes[] = 'Message ID: ' . $payload['message_id'];
    $notes[] = 'Recebido em: ' . date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, phone, source, status, stage_id, initial_stage_id, notes, created_at, updated_at) VALUES (?, ?, ?, 'WhatsApp', 'WhatsApp', NULL, NULL, ?, NOW(), NOW())");
    $stmt->execute([$userId, $name, $phone, implode("\n", $notes)]);
    $leadId = (int)$pdo->lastInsertId();

    try {
        $move = $pdo->prepare('INSERT INTO lead_movements (lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at) VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, 0, NOW())');
        $move->execute([$leadId, $userId, 'WhatsApp', $userId, 'Criado automaticamente por mensagem recebida no WhatsApp']);
    } catch (Throwable $ignored) {}

    try { wrcrm_notify_lead_created($pdo, $leadId, $userId); } catch (Throwable $ignored) {}

    wa_incoming_json(['success' => true, 'created' => true, 'lead_id' => $leadId]);
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    wa_incoming_json(['success' => false, 'message' => 'server_error'], 500);
}
