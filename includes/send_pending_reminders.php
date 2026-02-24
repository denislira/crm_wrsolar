<?php
// Envia lembretes pendentes (integração interna)
// Limitar número por execução para não travar carregamento de página
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';

function send_via_mail_simple($to, $subject, $body, $fromName = null, $fromEmail = null) {
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

function send_pending_reminders($limit = 5) {
    global $pdo;
    // Load SMTP config from storage/settings.json if available
    $settingsPath = __DIR__ . '/../storage/settings.json';
    $smtp = [];
    if (file_exists($settingsPath)) {
        $raw = @file_get_contents($settingsPath);
        $all = $raw ? json_decode($raw, true) : [];
        if (isset($all['smtp']) && is_array($all['smtp'])) $smtp = $all['smtp'];
    }

    try {
        $stmt = $pdo->prepare("SELECT r.*, u.email AS creator_email, u.username AS creator_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = 'pending' AND r.remind_at <= NOW() ORDER BY r.remind_at ASC LIMIT ?");
        $stmt->execute([(int)$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $to = $r['creator_email'] ?? null;
            if (empty($to)) {
                $u2 = $pdo->prepare('UPDATE reminders SET status = ?, executed_at = NOW() WHERE id = ?');
                $u2->execute(['failed', $r['id']]);
                continue;
            }
            $subject = 'Lembrete: ' . ($r['lead_id'] ? 'Lead #' . $r['lead_id'] : 'Lembrete');
            $body = '<p>Olá ' . htmlspecialchars($r['creator_name'] ?? '') . ',</p>';
            $body .= '<p>Este é o lembrete agendado para <strong>' . htmlspecialchars($r['remind_at']) . '</strong>:</p>';
            $body .= '<div style="padding:12px;background:#f8fafc;border-radius:6px;margin:10px 0;">' . nl2br(htmlspecialchars($r['message'])) . '</div>';
            $body .= '<p>Atenciosamente,<br>Sistema</p>';

            $sent = false;
            // try SMTP via basic fsockopen if configured
            if (!empty($smtp['host'])) {
                try {
                    $host = $smtp['host'];
                    $port = isset($smtp['port']) ? (int)$smtp['port'] : 25;
                    $secure = $smtp['secure'] ?? '';
                    $crypto = ($secure === 'ssl') ? 'ssl://' : '';
                    $fp = @stream_socket_client($crypto . $host . ':' . $port, $errno, $errstr, 10);
                    if ($fp) {
                        $send = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); return fgets($fp, 512); };
                        $send('EHLO ' . gethostname());
                        if ($secure === 'tls') { $send('STARTTLS'); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $send('EHLO ' . gethostname()); }
                        if (!empty($smtp['user'])) {
                            $send('AUTH LOGIN');
                            $send(base64_encode($smtp['user']));
                            $send(base64_encode($smtp['pass'] ?? ''));
                        }
                        $from = $smtp['user'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                        $send('MAIL FROM:<' . $from . '>');
                        $send('RCPT TO:<' . $to . '>');
                        $send('DATA');
                        $headers = [];
                        $headers[] = 'From: ' . ($r['creator_name'] ? $r['creator_name'] . ' <' . $from . '>' : $from);
                        $headers[] = 'To: ' . $to;
                        $headers[] = 'Subject: ' . $subject;
                        $headers[] = 'MIME-Version: 1.0';
                        $headers[] = 'Content-Type: text/html; charset=UTF-8';
                        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
                        $send($data);
                        $send('QUIT');
                        fclose($fp);
                        $sent = true;
                    }
                } catch (Exception $e) {
                    $sent = false;
                }
            }
            if (!$sent) {
                $sent = send_via_mail_simple($to, $subject, $body, $r['creator_name'] ?? null, $smtp['user'] ?? null);
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
        // fail silently — don't break page
    }
}

?>
