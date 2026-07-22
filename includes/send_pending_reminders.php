<?php
// Envia lembretes pendentes (integração interna)
// Limitar número por execução para não travar carregamento de página
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_notifications.php';

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
    try {
        $stmt = $pdo->prepare("SELECT r.*, u.email AS creator_email, u.username AS creator_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = 'pending' AND r.remind_at <= NOW() ORDER BY r.remind_at ASC LIMIT ?");
        $stmt->execute([(int)$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $recipients = wrcrm_user_emails($pdo, [$r['created_by'] ?? null, $r['responsavel_id'] ?? null]);
            if (empty($recipients)) {
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
            $seen = [];
            foreach ($recipients as $recipient) {
                $email = strtolower(trim($recipient['email'] ?? ''));
                if (!$email || isset($seen[$email])) continue;
                $seen[$email] = true;
                $name = $recipient['nome_completo'] ?? ($recipient['username'] ?? null);
                $sent = wrcrm_send_email($recipient['email'], $subject, $body, $name) || $sent;
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
