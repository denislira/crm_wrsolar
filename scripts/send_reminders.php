<?php
// Script para enviar lembretes por e-mail quando atingir remind_at
// Execute via Task Scheduler (Windows) ou cron a cada minuto.
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../includes/config.php';

// Load SMTP settings from storage/settings.json
$settingsPath = __DIR__ . '/../storage/settings.json';
$smtp = [];
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $all = $raw ? json_decode($raw, true) : [];
    if (isset($all['smtp']) && is_array($all['smtp'])) $smtp = $all['smtp'];
}

function send_via_mail($to, $subject, $body, $fromName = null, $fromEmail = null) {
    $headers = [];
    if ($fromName && $fromEmail) {
        $headers[] = 'From: ' . mb_encode_mimeheader($fromName) . " <{$fromEmail}>";
    } elseif ($fromEmail) {
        $headers[] = 'From: ' . $fromEmail;
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

// Basic SMTP send using fsockopen and SMTP commands (supports TLS)
function send_via_smtp($smtpConfig, $to, $subject, $body, $fromName = null, $fromEmail = null) {
    $host = $smtpConfig['host'] ?? '';
    $port = isset($smtpConfig['port']) ? (int)$smtpConfig['port'] : 25;
    $secure = $smtpConfig['secure'] ?? '';
    $user = $smtpConfig['user'] ?? '';
    $pass = $smtpConfig['pass'] ?? '';
    $timeout = 15;
    $crypto = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @stream_socket_client($crypto . $host . ':' . $port, $errno, $errstr, $timeout);
    if (!$fp) return false;
    stream_set_timeout($fp, $timeout);
    $res = fgets($fp, 512);
    $smtp_send = function($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
        return fgets($fp, 512);
    };
    $ehlo = $smtp_send('EHLO ' . gethostname());
    if ($secure === 'tls') {
        $smtp_send('STARTTLS');
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $ehlo = $smtp_send('EHLO ' . gethostname());
    }
    if ($user) {
        $smtp_send('AUTH LOGIN');
        $smtp_send(base64_encode($user));
        $smtp_send(base64_encode($pass));
    }
    $from = $fromEmail ?: ($smtpConfig['user'] ?? 'no-reply@localhost');
    $smtp_send('MAIL FROM:<' . $from . '>');
    $smtp_send('RCPT TO:<' . $to . '>');
    $smtp_send('DATA');
    $headers = [];
    $headers[] = 'From: ' . ($fromName ? $fromName . ' <' . $from . '>' : $from);
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $smtp_send($data);
    $smtp_send('QUIT');
    fclose($fp);
    return true;
}

try {
    // fetch pending reminders due now or earlier
    $stmt = $pdo->prepare("SELECT r.*, u.email AS creator_email, u.username AS creator_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = 'pending' AND r.remind_at <= NOW() LIMIT 100");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $to = $r['creator_email'] ?: null;
        if (!$to) {
            // mark failed to avoid retries
            $u2 = $pdo->prepare('UPDATE reminders SET status = ?, executed_at = NOW() WHERE id = ?');
            $u2->execute(['failed', $r['id']]);
            continue;
        }
        $subject = 'Lembrete: ' . ($r['lead_id'] ? 'Lead #' . $r['lead_id'] : 'Lembrete') . ' — ' . substr($r['message'],0,60);
        $body = '<p>Olá ' . htmlspecialchars($r['creator_name'] ?? '') . ',</p><p>Este é um lembrete agendado para <strong>' . $r['remind_at'] . '</strong>.</p><div style="padding:12px;background:#f8fafc;border-radius:6px;margin:10px 0;">' . nl2br(htmlspecialchars($r['message'])) . '</div><p>Atenciosamente,<br>Equipe</p>';
        $sent = false;
        if (!empty($smtp['host'])) {
            $sent = send_via_smtp($smtp, $to, $subject, $body, $r['creator_name'] ?? null, $smtp['user'] ?? null);
        }
        if (!$sent) {
            // fallback to mail()
            $sent = send_via_mail($to, $subject, $body, $r['creator_name'] ?? null, $smtp['user'] ?? null);
        }
        if ($sent) {
            $u2 = $pdo->prepare('UPDATE reminders SET status = ?, executed_at = NOW() WHERE id = ?');
            $u2->execute(['sent', $r['id']]);
        } else {
            $u2 = $pdo->prepare('UPDATE reminders SET status = ?, executed_at = NOW() WHERE id = ?');
            $u2->execute(['failed', $r['id']]);
        }
    }
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/send_reminders.log', date('c') . ' error: ' . $e->getMessage() . "\n", FILE_APPEND);
}

?>
