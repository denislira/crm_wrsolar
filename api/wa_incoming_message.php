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

function wa_incoming_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $ignored) {
        return false;
    }
}

function wa_incoming_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $ignored) {
        return false;
    }
}

function wa_incoming_find_latest_lead_by_phone(PDO $pdo, string $digits): ?array {
    $variants = wa_incoming_phone_variants($digits);
    $matches = [];
    $select = "id, user_id, phone, status, source, stage_id, updated_at, created_at";
    if (wa_incoming_column_exists($pdo, 'leads', 'deleted')) $select .= ", deleted";
    if (wa_incoming_column_exists($pdo, 'leads', 'deleted_at')) $select .= ", deleted_at";
    if (wa_incoming_column_exists($pdo, 'leads', 'importado')) $select .= ", importado";
    $stmt = $pdo->query("SELECT {$select} FROM leads WHERE phone IS NOT NULL AND phone <> ''");
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
    if ((int)($lead['deleted'] ?? 0) === 1) return true;
    if ((int)($lead['importado'] ?? 0) === 1) return true;
    return (bool)preg_match('/conclu|encerr|finaliz|ganh|perdid|vendid|fechad|convertid/u', $label);
}

function wa_incoming_is_final_stage(PDO $pdo, array $lead): bool {
    $stageId = (int)($lead['stage_id'] ?? 0);
    if ($stageId <= 0 || !wa_incoming_table_exists($pdo, 'funil_stages')) return false;

    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM funil_stages') as $row) {
            $cols[] = (string)$row['Field'];
        }
        $checks = [];
        if (in_array('is_final', $cols, true)) $checks[] = 'COALESCE(is_final, 0) = 1';
        if (in_array('final_type', $cols, true)) $checks[] = "COALESCE(final_type, 'none') <> 'none'";
        if (in_array('is_conversion', $cols, true)) $checks[] = 'COALESCE(is_conversion, 0) = 1';
        if (!$checks) return false;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM funil_stages WHERE id = ? AND (' . implode(' OR ', $checks) . ')');
        $stmt->execute([$stageId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $ignored) {
        return false;
    }
}

function wa_incoming_lead_closed_at(PDO $pdo, array $lead): ?string {
    $leadId = (int)($lead['id'] ?? 0);
    if ($leadId <= 0) return null;

    if ((int)($lead['deleted'] ?? 0) === 1 && !empty($lead['deleted_at'])) {
        return (string)$lead['deleted_at'];
    }

    if (wa_incoming_is_final_stage($pdo, $lead)) {
        try {
            $stmt = $pdo->prepare('SELECT created_at FROM lead_movements WHERE lead_id = ? AND to_stage_id = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$leadId, (int)$lead['stage_id']]);
            $stageClosedAt = $stmt->fetchColumn();
            if ($stageClosedAt) return (string)$stageClosedAt;
        } catch (Throwable $ignored) {}
    }

    if (wa_incoming_table_exists($pdo, 'funil_stages')) {
        try {
            $stmt = $pdo->prepare("
                SELECT lm.created_at
                FROM lead_movements lm
                INNER JOIN funil_stages fs ON fs.id = lm.to_stage_id
                WHERE lm.lead_id = ?
                  AND (
                    COALESCE(fs.is_final, 0) = 1
                    OR COALESCE(fs.final_type, 'none') <> 'none'
                    OR COALESCE(fs.is_conversion, 0) = 1
                  )
                ORDER BY lm.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$leadId]);
            $stageMovementAt = $stmt->fetchColumn();
            if ($stageMovementAt) return (string)$stageMovementAt;
        } catch (Throwable $ignored) {}
    }

    try {
        $stmt = $pdo->prepare("SELECT created_at FROM lead_movements WHERE lead_id = ? AND LOWER(COALESCE(to_status, '')) REGEXP 'conclu|encerr|finaliz|ganh|perdid|vendid|fechad|convertid' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$leadId]);
        $closedAt = $stmt->fetchColumn();
        if ($closedAt) return (string)$closedAt;
    } catch (Throwable $ignored) {}

    if (wa_incoming_table_exists($pdo, 'projetos')) {
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(closed_date, created_at, updated_at) FROM projetos WHERE lead_id = ? ORDER BY COALESCE(closed_date, created_at, updated_at) DESC LIMIT 1');
            $stmt->execute([$leadId]);
            $projectAt = $stmt->fetchColumn();
            if ($projectAt) return (string)$projectAt;
        } catch (Throwable $ignored) {}
    }

    if (wa_incoming_table_exists($pdo, 'pos_venda') && wa_incoming_table_exists($pdo, 'projetos')) {
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(pv.created_at, pv.updated_at) FROM pos_venda pv INNER JOIN projetos p ON p.id = pv.project_id WHERE p.lead_id = ? ORDER BY COALESCE(pv.created_at, pv.updated_at) DESC LIMIT 1');
            $stmt->execute([$leadId]);
            $postSaleAt = $stmt->fetchColumn();
            if ($postSaleAt) return (string)$postSaleAt;
        } catch (Throwable $ignored) {}
    }

    if ((int)($lead['importado'] ?? 0) === 1) {
        return (string)($lead['updated_at'] ?? $lead['created_at'] ?? '');
    }

    return (wa_incoming_is_final_stage($pdo, $lead) || wa_incoming_is_closed_lead($lead)) ? (string)($lead['updated_at'] ?? $lead['created_at'] ?? '') : null;
}

function wa_incoming_days_since_date($raw): int {
    $ts = $raw ? strtotime((string)$raw) : false;
    if (!$ts) return 0;
    return max(0, (int)floor((time() - $ts) / 86400));
}

function wa_incoming_ensure_profile_image_column(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE leads ADD COLUMN whatsapp_profile_image VARCHAR(500) DEFAULT NULL");
    } catch (Throwable $ignored) {}
}

function wa_incoming_download_profile_image(string $url, int $leadId, int $empresaId): string {
    if ($url === '' || !preg_match('#^https://#i', $url)) return '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'WRCRM WhatsApp Lead Importer',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error || $status < 200 || $status >= 300) return '';
    if (strlen($body) <= 0 || strlen($body) > 2 * 1024 * 1024) return '';

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->buffer($body));
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime]) && !isset($allowed[$contentType])) return '';
    $ext = $allowed[$mime] ?? $allowed[$contentType];

    $relativeDir = 'uploads/whatsapp/empresa_' . max(0, $empresaId) . '/leads/lead_' . $leadId;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) return '';

    $relativePath = $relativeDir . '/profile.' . $ext;
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    if (@file_put_contents($absolutePath, $body, LOCK_EX) === false) return '';

    return $relativePath;
}

function wa_incoming_debug_notify(PDO $pdo, array $payload): void {
    $phone = wa_incoming_digits($payload['telefone'] ?? $payload['phone'] ?? $payload['from'] ?? '');
    $name = trim((string)($payload['push_name'] ?? $payload['nome'] ?? $payload['name'] ?? ''));
    $message = trim((string)($payload['mensagem'] ?? $payload['text'] ?? $payload['message'] ?? ''));
    $remoteJid = trim((string)($payload['remote_jid'] ?? ''));
    $senderJid = trim((string)($payload['sender_jid'] ?? ''));
    $summary = [
        'phone' => $phone,
        'name' => $name,
        'remote_jid' => $remoteJid,
        'sender_jid' => $senderJid,
        'message' => mb_substr($message, 0, 160),
    ];

    @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] received_before_lead ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            project_id INT NULL,
            type VARCHAR(50) DEFAULT 'notification',
            message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $users = [];
        try {
            $stmt = $pdo->query('SELECT id FROM users WHERE COALESCE(role_level, 0) <= 1 ORDER BY id ASC LIMIT 10');
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $ignored) {}
        if (!$users) {
            $stmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $label = $name !== '' ? $name : ($phone !== '' ? $phone : ($remoteJid !== '' ? $remoteJid : 'sem numero'));
        $alertMessage = 'Teste WhatsApp: mensagem recebida antes de criar lead - ' . $label;
        if ($message !== '') $alertMessage .= ' | ' . mb_substr($message, 0, 120);

        $ins = $pdo->prepare('INSERT INTO alerts (user_id, project_id, type, message) VALUES (?, NULL, ?, ?)');
        foreach ($users as $userId) {
            $ins->execute([(int)$userId, 'whatsapp_test', $alertMessage]);
        }
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] debug_notify_error ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}

$cfg = wa_baileys_config();
$secret = $cfg['secret'] ?? '';
if ($secret !== '' && ($_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '') !== $secret) {
    wa_incoming_json(['success' => false, 'message' => 'unauthorized'], 401);
}

if (empty($cfg['enabled'])) {
    wa_incoming_json(['success' => true, 'created' => false, 'reason' => 'whatsapp_disabled']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wa_incoming_json(['success' => false, 'message' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    wa_incoming_json(['success' => false, 'message' => 'invalid_json'], 400);
}

wa_incoming_debug_notify($pdo, $payload);

if (empty($cfg['auto_create_leads'])) {
    wa_incoming_json(['success' => true, 'created' => false, 'reason' => 'auto_create_disabled']);
}

$phone = wa_incoming_digits($payload['telefone'] ?? $payload['phone'] ?? $payload['from'] ?? '');
$text = trim((string)($payload['mensagem'] ?? $payload['text'] ?? $payload['message'] ?? ''));
$pushName = trim((string)($payload['push_name'] ?? $payload['nome'] ?? $payload['name'] ?? ''));
$profilePictureUrl = trim((string)($payload['profile_picture_url'] ?? $payload['photo'] ?? $payload['picture'] ?? ''));
$remoteJid = trim((string)($payload['remote_jid'] ?? ''));
$remoteJidAlt = trim((string)($payload['remote_jid_alt'] ?? ''));
$senderJid = trim((string)($payload['sender_jid'] ?? ''));
$participantAlt = trim((string)($payload['participant_alt'] ?? ''));
$idType = trim((string)($payload['id_tipo'] ?? $payload['id_type'] ?? ''));

if (strlen($phone) < 10 || strlen($phone) > 15) {
    @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] no_real_phone ' . json_encode(['remote_jid' => $remoteJid, 'remote_jid_alt' => $remoteJidAlt, 'sender_jid' => $senderJid, 'participant_alt' => $participantAlt, 'id_tipo' => $idType, 'nome' => $pushName], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    wa_incoming_json(['success' => true, 'created' => false, 'reason' => 'no_real_phone', 'remote_jid' => $remoteJid, 'remote_jid_alt' => $remoteJidAlt, 'sender_jid' => $senderJid, 'participant_alt' => $participantAlt, 'id_tipo' => $idType]);
}

try {
    wa_incoming_ensure_profile_image_column($pdo);

    $mode = (string)($cfg['lead_capture_mode'] ?? 'new_only');
    $existing = null;
    if ($mode !== 'always_create') {
        $existing = wa_incoming_find_latest_lead_by_phone($pdo, $phone);
    }
    if ($existing) {
        $closedAt = wa_incoming_lead_closed_at($pdo, $existing);
        $days = wa_incoming_days_since_date($closedAt);
        $minDays = max(1, (int)($cfg['reopen_after_days'] ?? 30));
        $canCreateReturn = $mode === 'closed_after_days' && $closedAt !== null && $days >= $minDays;
        if (!$canCreateReturn) {
            wa_incoming_json(['success' => true, 'created' => false, 'reason' => 'phone_exists', 'lead_id' => (int)$existing['id'], 'closed_at' => $closedAt, 'days_since_close' => $days]);
        }
    }

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
    if ($profilePictureUrl !== '') $notes[] = 'Foto WhatsApp: ' . mb_substr($profilePictureUrl, 0, 500);
    if ($remoteJid !== '') $notes[] = 'Remote JID: ' . mb_substr($remoteJid, 0, 120);
    if ($remoteJidAlt !== '') $notes[] = 'Remote JID Alt: ' . mb_substr($remoteJidAlt, 0, 120);
    if ($senderJid !== '') $notes[] = 'Sender JID: ' . mb_substr($senderJid, 0, 120);
    if ($participantAlt !== '') $notes[] = 'Participant Alt: ' . mb_substr($participantAlt, 0, 120);
    if ($idType !== '') $notes[] = 'Tipo ID WhatsApp: ' . mb_substr($idType, 0, 40);
    if (!empty($payload['message_id'])) $notes[] = 'Message ID: ' . $payload['message_id'];
    $notes[] = 'Recebido em: ' . date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, phone, source, status, stage_id, initial_stage_id, notes, created_at, updated_at) VALUES (?, ?, ?, 'WhatsApp', 'WhatsApp', NULL, NULL, ?, NOW(), NOW())");
    $stmt->execute([$userId, $name, $phone, implode("\n", $notes)]);
    $leadId = (int)$pdo->lastInsertId();
    $localProfileImage = '';

    if ($profilePictureUrl !== '') {
        try {
            $localProfileImage = wa_incoming_download_profile_image($profilePictureUrl, $leadId, (int)($cfg['empresa_id'] ?? 0));
            if ($localProfileImage !== '') {
                $photoStmt = $pdo->prepare('UPDATE leads SET whatsapp_profile_image = ? WHERE id = ?');
                $photoStmt->execute([$localProfileImage, $leadId]);
            }
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] profile_image_error lead=' . $leadId . ' ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    try {
        $move = $pdo->prepare('INSERT INTO lead_movements (lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at) VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, 0, NOW())');
        $move->execute([$leadId, $userId, 'WhatsApp', $userId, 'Criado automaticamente por mensagem recebida no WhatsApp']);
    } catch (Throwable $ignored) {}

    try { wrcrm_notify_lead_created($pdo, $leadId, $userId); } catch (Throwable $ignored) {}

    wa_incoming_json(['success' => true, 'created' => true, 'lead_id' => $leadId, 'whatsapp_profile_image' => $localProfileImage]);
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/../logs/wa_incoming_message.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    wa_incoming_json(['success' => false, 'message' => 'server_error'], 500);
}
