<?php

require_once __DIR__ . '/email_notifications.php';

function wrcrm_email_ensure_schema(PDO $pdo): void
{
    // Estrutura criada exclusivamente pelas migrations manuais.
    return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        recipient_to TEXT NOT NULL,
        recipient_cc TEXT DEFAULT NULL,
        recipient_bcc TEXT DEFAULT NULL,
        reply_to VARCHAR(255) DEFAULT NULL,
        subject VARCHAR(998) NOT NULL DEFAULT '',
        body_html MEDIUMTEXT DEFAULT NULL,
        status ENUM('draft','sent','failed','trash') NOT NULL DEFAULT 'draft',
        previous_status ENUM('draft','sent','failed') DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_crm_emails_user_status (user_id, status, updated_at),
        CONSTRAINT fk_crm_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'from_email' => "ALTER TABLE crm_emails ADD COLUMN from_email VARCHAR(255) DEFAULT NULL AFTER user_id",
        'from_name' => "ALTER TABLE crm_emails ADD COLUMN from_name VARCHAR(255) DEFAULT NULL AFTER from_email",
        'smtp_scope' => "ALTER TABLE crm_emails ADD COLUMN smtp_scope ENUM('user','system') NOT NULL DEFAULT 'system' AFTER from_name"
    ] as $column => $sql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM crm_emails LIKE " . $pdo->quote($column));
            if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
        } catch (Throwable $ignored) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_email_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crm_email_attachments_email (email_id),
        CONSTRAINT fk_crm_email_attachments_email FOREIGN KEY (email_id) REFERENCES crm_emails(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function wrcrm_email_user_smtp_key(int $userId): string
{
    return 'smtp_user_' . $userId;
}

function wrcrm_email_user_scope_key(int $userId): string
{
    return 'smtp_user_scope_' . $userId;
}

function wrcrm_email_normalize_smtp(array $smtp, bool $keepPass = true): array
{
    return [
        'host' => trim((string)($smtp['host'] ?? '')),
        'port' => (int)($smtp['port'] ?? 0),
        'secure' => in_array(($smtp['secure'] ?? ''), ['ssl', 'tls'], true) ? $smtp['secure'] : '',
        'user' => trim((string)($smtp['user'] ?? '')),
        'pass' => $keepPass ? (string)($smtp['pass'] ?? '') : '',
        'from_email' => trim((string)($smtp['from_email'] ?? '')),
        'from_name' => trim((string)($smtp['from_name'] ?? '')),
        'auth' => !empty($smtp['auth']) ? 1 : 0,
    ];
}

function wrcrm_email_user_profile(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT username, email, nome_completo FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function wrcrm_email_get_system_smtp(): array
{
    $settings = wrcrm_read_settings();
    return wrcrm_email_normalize_smtp(is_array($settings['smtp'] ?? null) ? $settings['smtp'] : []);
}

function wrcrm_email_get_user_smtp(PDO $pdo, int $userId): array
{
    $row = wrcrm_db_setting_get($pdo, wrcrm_email_user_smtp_key($userId));
    return wrcrm_email_normalize_smtp(is_array($row['value'] ?? null) ? $row['value'] : []);
}

function wrcrm_email_save_user_smtp(PDO $pdo, int $userId, array $smtp): bool
{
    $current = wrcrm_email_get_user_smtp($pdo, $userId);
    $smtp = wrcrm_email_normalize_smtp($smtp);
    if ($smtp['pass'] === '') $smtp['pass'] = $current['pass'] ?? '';
    return wrcrm_db_setting_set($pdo, wrcrm_email_user_smtp_key($userId), $smtp, true, $userId);
}

function wrcrm_email_save_user_scope(PDO $pdo, int $userId, string $scope): bool
{
    return wrcrm_db_setting_set($pdo, wrcrm_email_user_scope_key($userId), in_array($scope, ['user', 'system'], true) ? $scope : 'user', false, $userId);
}

function wrcrm_email_public_account(PDO $pdo, int $userId): array
{
    $profile = wrcrm_email_user_profile($pdo, $userId);
    $userSmtp = wrcrm_email_get_user_smtp($pdo, $userId);
    $systemSmtp = wrcrm_email_get_system_smtp();
    $scopeRow = wrcrm_db_setting_get($pdo, wrcrm_email_user_scope_key($userId));
    $preferredScope = in_array(($scopeRow['value'] ?? ''), ['user', 'system'], true) ? $scopeRow['value'] : 'user';
    $userAvailable = !empty($userSmtp['host']) && filter_var(($userSmtp['from_email'] ?: $userSmtp['user']), FILTER_VALIDATE_EMAIL);
    $activeScope = $preferredScope === 'user' && $userAvailable ? 'user' : 'system';
    $active = $activeScope === 'user' ? $userSmtp : $systemSmtp;
    $userSafe = $userSmtp;
    $systemSafe = $systemSmtp;
    $userSafe['pass'] = '';
    $systemSafe['pass'] = '';

    return [
        'active_scope' => $activeScope,
        'preferred_scope' => $preferredScope,
        'active_email' => trim($active['from_email'] ?: $active['user'] ?: ($profile['email'] ?? '')),
        'active_name' => trim($active['from_name'] ?: ($profile['nome_completo'] ?? '') ?: ($profile['username'] ?? '')),
        'user_smtp' => $userSafe,
        'user_has_password' => !empty($userSmtp['pass']),
        'system_smtp' => $systemSafe,
        'system_available' => !empty($systemSmtp['host']) && filter_var(($systemSmtp['from_email'] ?: $systemSmtp['user']), FILTER_VALIDATE_EMAIL),
        'profile_email' => $profile['email'] ?? '',
    ];
}

function wrcrm_email_addresses(string $value): array
{
    $result = [];
    foreach (preg_split('/[,;\r\n]+/', $value) ?: [] as $item) {
        $item = trim($item);
        if ($item === '') continue;
        if (preg_match('/<([^>]+)>/', $item, $match)) $item = trim($match[1]);
        if (!filter_var($item, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Endereço de e-mail inválido: ' . $item);
        }
        $result[strtolower($item)] = $item;
    }
    return array_values($result);
}

function wrcrm_email_clean_html(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link)[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|data):.*?\2/i', '$1="#"', $html) ?? '';
    return trim($html);
}

function wrcrm_email_attachment_dir(int $userId, int $emailId): string
{
    return dirname(__DIR__) . '/uploads/emails/' . $userId . '/' . $emailId;
}

function wrcrm_email_store_uploads(PDO $pdo, int $userId, int $emailId, array $files): void
{
    if (empty($files['name'])) return;
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $total = 0;
    foreach ($sizes as $size) $total += (int)$size;
    if ($total > 15 * 1024 * 1024) throw new RuntimeException('Os anexos ultrapassam o limite total de 15 MB.');

    $allowed = ['pdf','png','jpg','jpeg','gif','webp','doc','docx','xls','xlsx','csv','txt','zip','rar'];
    $dir = wrcrm_email_attachment_dir($userId, $emailId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Não foi possível criar a pasta de anexos.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $i => $name) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha ao receber o anexo ' . basename((string)$name) . '.');
        $size = (int)($sizes[$i] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Cada anexo deve ter no máximo 10 MB.');
        $safeOriginal = mb_substr(basename((string)$name), 0, 255);
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) throw new RuntimeException('Tipo de anexo não permitido: ' . $safeOriginal);
        $stored = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        $target = $dir . '/' . $stored;
        if (!move_uploaded_file($tmpNames[$i], $target)) throw new RuntimeException('Não foi possível salvar o anexo ' . $safeOriginal . '.');
        $mime = $finfo->file($target) ?: 'application/octet-stream';
        $stmt = $pdo->prepare('INSERT INTO crm_email_attachments (email_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$emailId, $safeOriginal, $stored, $mime, $size]);
    }
}

function wrcrm_email_mime_message(array $mail, array $attachments, string $fromEmail, string $fromName): array
{
    $boundary = 'wrcrm_' . bin2hex(random_bytes(12));
    $to = wrcrm_email_addresses($mail['recipient_to'] ?? '');
    $cc = wrcrm_email_addresses($mail['recipient_cc'] ?? '');
    $bcc = wrcrm_email_addresses($mail['recipient_bcc'] ?? '');
    if (!$to) throw new InvalidArgumentException('Informe pelo menos um destinatário no campo Para.');
    $subject = trim((string)($mail['subject'] ?? ''));
    if ($subject === '') throw new InvalidArgumentException('Informe o assunto da mensagem.');
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . wrcrm_mime_header_encode($fromName) . ' <' . $fromEmail . '>',
        'To: ' . implode(', ', $to),
        'Subject: ' . wrcrm_mime_header_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-z0-9.-]/i', '', substr(strrchr($fromEmail, '@') ?: '@localhost', 1)) . '>',
        'MIME-Version: 1.0'
    ];
    if ($cc) $headers[] = 'Cc: ' . implode(', ', $cc);
    $replyTo = trim((string)($mail['reply_to'] ?? ''));
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    $html = wrcrm_email_clean_html((string)($mail['body_html'] ?? ''));
    if ($attachments) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html));
        foreach ($attachments as $attachment) {
            $path = $attachment['path'];
            if (!is_file($path)) continue;
            $encodedName = wrcrm_mime_header_encode($attachment['original_name']);
            $body .= '--' . $boundary . "\r\nContent-Type: " . $attachment['mime_type'] . '; name="' . $encodedName . '"';
            $body .= "\r\nContent-Disposition: attachment; filename=\"" . $encodedName . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode(file_get_contents($path))) . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($html));
    }
    return ['recipients' => array_values(array_unique(array_merge($to, $cc, $bcc))), 'headers' => $headers, 'body' => $body];
}

function wrcrm_send_composed_email(array $mail, array $attachments = [], ?array $smtpOverride = null): bool
{
    $smtp = $smtpOverride !== null ? $smtpOverride : wrcrm_email_get_system_smtp();
    $fromEmail = trim($smtp['from_email'] ?? '') ?: trim($smtp['user'] ?? '');
    $fromName = trim($smtp['from_name'] ?? '') ?: 'WRCRM';
    if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure um remetente válido na aba SMTP.');
    $message = wrcrm_email_mime_message($mail, $attachments, $fromEmail, $fromName);
    if (empty($smtp['host'])) throw new RuntimeException('Configure o servidor SMTP antes de enviar.');

    $host = trim((string)$smtp['host']);
    $port = (int)($smtp['port'] ?? 25);
    $secure = strtolower((string)($smtp['secure'] ?? ''));
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) throw new RuntimeException("Conexão SMTP recusada ({$errno}): {$errstr}");
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string { $out=''; while (($line=fgets($fp, 4096)) !== false) { $out.=$line; if (strlen($line)<4 || $line[3] !== '-') break; } return trim($out); };
    $send = static function (string $command) use ($fp, $read): string { if (fwrite($fp, $command."\r\n") === false) throw new RuntimeException('Falha ao transmitir comando SMTP.'); return $read(); };
    $expect = static function (string $response, array $codes, string $step): void { if (!in_array((int)substr($response,0,3), $codes, true)) throw new RuntimeException($step . ': ' . ($response ?: 'sem resposta')); };
    try {
        $expect($read(), [220], 'Conexão SMTP');
        $expect($send('EHLO ' . (gethostname() ?: 'localhost')), [250], 'EHLO');
        if ($secure === 'tls') {
            $expect($send('STARTTLS'), [220], 'STARTTLS');
            $enabled = false;
            foreach (wrcrm_smtp_crypto_methods() as $method) { if (@stream_socket_enable_crypto($fp, true, $method)) { $enabled = true; break; } }
            if (!$enabled) throw new RuntimeException('Falha ao ativar TLS.');
            $expect($send('EHLO ' . (gethostname() ?: 'localhost')), [250], 'EHLO após TLS');
        }
        $user = trim((string)($smtp['user'] ?? '')); $pass = (string)($smtp['pass'] ?? '');
        if (!empty($smtp['auth']) || ($user !== '' && $pass !== '')) {
            $expect($send('AUTH LOGIN'), [334], 'Autenticação');
            $expect($send(base64_encode($user)), [334], 'Usuário SMTP');
            $expect($send(base64_encode($pass)), [235], 'Senha SMTP');
        }
        $expect($send('MAIL FROM:<' . $fromEmail . '>'), [250], 'Remetente');
        foreach ($message['recipients'] as $recipient) $expect($send('RCPT TO:<' . $recipient . '>'), [250,251], 'Destinatário ' . $recipient);
        $expect($send('DATA'), [354], 'Conteúdo');
        $raw = implode("\r\n", $message['headers']) . "\r\n\r\n" . $message['body'];
        $raw = preg_replace('/(?m)^\./', '..', $raw);
        if (fwrite($fp, $raw . "\r\n.\r\n") === false) throw new RuntimeException('Falha ao transmitir a mensagem.');
        $expect($read(), [250], 'Entrega');
        $send('QUIT'); fclose($fp); return true;
    } catch (Throwable $e) { if (is_resource($fp)) fclose($fp); throw $e; }
}
