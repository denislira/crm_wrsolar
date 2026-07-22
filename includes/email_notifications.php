<?php
if (!function_exists('wrcrm_settings_path')) {
    function wrcrm_settings_path() {
        return __DIR__ . '/../storage/settings.json';
    }
}

if (!function_exists('wrcrm_read_settings')) {
    function wrcrm_read_settings() {
        $path = wrcrm_settings_path();
        if (!file_exists($path)) return [];
        $raw = @file_get_contents($path);
        $settings = $raw ? json_decode($raw, true) : [];
        return is_array($settings) ? $settings : [];
    }
}

if (!function_exists('wrcrm_write_settings')) {
    function wrcrm_write_settings(array $settings) {
        $dir = dirname(wrcrm_settings_path());
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return @file_put_contents(wrcrm_settings_path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }
}

if (!function_exists('wrcrm_default_notification_settings')) {
    function wrcrm_default_notification_settings() {
        return [
            'events' => [
                'reminder_created' => 1,
                'task_created' => 1,
                'lead_created' => 0,
                'lead_sale_completed' => 0,
                'lead_stage_changed' => 0
            ],
            'recipients' => [
                'reminder_created' => ['creator', 'responsible'],
                'task_created' => ['creator', 'responsible'],
                'lead_created' => [],
                'lead_sale_completed' => [],
                'lead_stage_changed' => []
            ],
            'sale_stage_names' => ['Venda concluída', 'Venda concluida', 'Concluído', 'Concluido', 'Ganho', 'Fechado']
        ];
    }
}

if (!function_exists('wrcrm_get_notification_settings')) {
    function wrcrm_get_notification_settings() {
        $settings = wrcrm_read_settings();
        $defaults = wrcrm_default_notification_settings();
        $saved = isset($settings['notifications']) && is_array($settings['notifications']) ? $settings['notifications'] : [];
        return array_replace_recursive($defaults, $saved);
    }
}

if (!function_exists('wrcrm_mail_simple')) {
    function wrcrm_mail_simple($to, $subject, $html, $fromName = null, $fromEmail = null) {
        $headers = [];
        if ($fromName && $fromEmail) {
            $headers[] = 'From: ' . mb_encode_mimeheader($fromName) . " <{$fromEmail}>";
        } elseif ($fromEmail) {
            $headers[] = 'From: ' . $fromEmail;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return @mail($to, $subject, $html, implode("\r\n", $headers));
    }
}

if (!function_exists('wrcrm_send_email')) {
    function wrcrm_send_email($to, $subject, $html, $toName = null) {
        $settings = wrcrm_read_settings();
        $smtp = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];
        $fromEmail = trim($smtp['from_email'] ?? '') ?: trim($smtp['user'] ?? '') ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = trim($smtp['from_name'] ?? '') ?: 'WRCRM';
        $sent = false;

        if (!empty($smtp['host'])) {
            try {
                $host = $smtp['host'];
                $port = isset($smtp['port']) ? (int)$smtp['port'] : 25;
                $secure = strtolower($smtp['secure'] ?? '');
                $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
                $fp = @stream_socket_client($target, $errno, $errstr, 15);
                if ($fp) {
                    stream_set_timeout($fp, 15);
                    $read = function() use ($fp) { return fgets($fp, 1024); };
                    $send = function($cmd) use ($fp, $read) { fwrite($fp, $cmd . "\r\n"); return $read(); };
                    $read();
                    $send('EHLO ' . (gethostname() ?: 'localhost'));
                    if ($secure === 'tls') {
                        $send('STARTTLS');
                        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        $send('EHLO ' . (gethostname() ?: 'localhost'));
                    }
                    $smtpUser = trim($smtp['user'] ?? '');
                    $smtpPass = (string)($smtp['pass'] ?? '');
                    $shouldAuth = !empty($smtp['auth']) || ($smtpUser !== '' && $smtpPass !== '');
                    if ($shouldAuth && $smtpUser !== '') {
                        $send('AUTH LOGIN');
                        $send(base64_encode($smtpUser));
                        $send(base64_encode($smtpPass));
                    }
                    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
                    $headers = [
                        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromEmail}>",
                        'To: ' . ($toName ? mb_encode_mimeheader($toName, 'UTF-8') . " <{$to}>" : $to),
                        'Subject: ' . $encodedSubject,
                        'MIME-Version: 1.0',
                        'Content-Type: text/html; charset=UTF-8'
                    ];
                    $send('MAIL FROM:<' . $fromEmail . '>');
                    $send('RCPT TO:<' . $to . '>');
                    $send('DATA');
                    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.\r\n");
                    $read();
                    $send('QUIT');
                    fclose($fp);
                    $sent = true;
                }
            } catch (Exception $e) {
                $sent = false;
            }
        }

        return $sent ?: wrcrm_mail_simple($to, $subject, $html, $fromName, $fromEmail);
    }
}

if (!function_exists('wrcrm_notification_enabled')) {
    function wrcrm_notification_enabled($event) {
        $settings = wrcrm_get_notification_settings();
        return !empty($settings['events'][$event]);
    }
}

if (!function_exists('wrcrm_user_emails')) {
    function wrcrm_user_emails(PDO $pdo, array $userIds) {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return [];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, username, nome_completo, email FROM users WHERE id IN ($placeholders) AND email IS NOT NULL AND email <> ''");
        $stmt->execute($userIds);
        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $users[(int)$row['id']] = $row;
        }
        return $users;
    }
}

if (!function_exists('wrcrm_send_event_notification')) {
    function wrcrm_send_event_notification(PDO $pdo, $event, $subject, $html, array $roleUserIds) {
        if (!wrcrm_notification_enabled($event)) return false;
        $settings = wrcrm_get_notification_settings();
        $roles = $settings['recipients'][$event] ?? [];
        $ids = [];
        foreach ($roles as $role) {
            if (isset($roleUserIds[$role]) && $roleUserIds[$role]) $ids[] = (int)$roleUserIds[$role];
        }
        $users = wrcrm_user_emails($pdo, $ids);
        $sentAny = false;
        $seen = [];
        foreach ($users as $user) {
            $email = strtolower(trim($user['email']));
            if (!$email || isset($seen[$email])) continue;
            $seen[$email] = true;
            $name = $user['nome_completo'] ?: $user['username'];
            $sentAny = wrcrm_send_email($user['email'], $subject, $html, $name) || $sentAny;
        }
        return $sentAny;
    }
}

if (!function_exists('wrcrm_notify_reminder_created')) {
    function wrcrm_notify_reminder_created(PDO $pdo, $reminderId) {
        try {
            $stmt = $pdo->prepare('SELECT r.*, l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id WHERE r.id = ? LIMIT 1');
            $stmt->execute([(int)$reminderId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return false;
            $subject = 'Novo lembrete criado';
            $html = '<p>Um novo lembrete foi criado no CRM.</p>'
                . '<p><strong>Lead:</strong> ' . htmlspecialchars($r['lead_name'] ?: ('#' . $r['lead_id'])) . '<br>'
                . '<strong>Quando:</strong> ' . htmlspecialchars($r['remind_at']) . '</p>'
                . '<div style="padding:12px;background:#f8fafc;border-radius:6px">' . nl2br(htmlspecialchars($r['message'])) . '</div>';
            return wrcrm_send_event_notification($pdo, 'reminder_created', $subject, $html, [
                'creator' => $r['created_by'] ?? null,
                'responsible' => $r['responsavel_id'] ?? null
            ]);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('wrcrm_notify_lead_created')) {
    function wrcrm_notify_lead_created(PDO $pdo, $leadId, $createdBy) {
        try {
            $stmt = $pdo->prepare('SELECT id, name, email, phone, source, status, user_id FROM leads WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$leadId]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$l) return false;
            $subject = 'Novo lead criado: ' . ($l['name'] ?: ('#' . $l['id']));
            $html = '<p>Um novo lead foi criado no CRM.</p>'
                . '<p><strong>Lead:</strong> ' . htmlspecialchars($l['name'] ?: ('#' . $l['id'])) . '<br>'
                . '<strong>Status:</strong> ' . htmlspecialchars($l['status'] ?? '') . '<br>'
                . '<strong>Origem:</strong> ' . htmlspecialchars($l['source'] ?? '') . '<br>'
                . '<strong>Contato:</strong> ' . htmlspecialchars(trim(($l['email'] ?? '') . ' ' . ($l['phone'] ?? ''))) . '</p>';
            return wrcrm_send_event_notification($pdo, 'lead_created', $subject, $html, [
                'creator' => $createdBy,
                'responsible' => $l['user_id'] ?? null
            ]);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('wrcrm_notify_task_created')) {
    function wrcrm_notify_task_created(PDO $pdo, $taskId, $createdBy) {
        try {
            $hasResponsavelId = false;
            try {
                $stmtCol = $pdo->prepare("SHOW COLUMNS FROM team_tasks LIKE 'responsavel_id'");
                $stmtCol->execute();
                $hasResponsavelId = (bool)$stmtCol->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $hasResponsavelId = false;
            }

            $select = 'SELECT t.*, u.nome_completo AS creator_name, u.username AS creator_username';
            if ($hasResponsavelId) {
                $select .= ', ru.nome_completo AS responsible_name, ru.username AS responsible_username';
            }
            $select .= ' FROM team_tasks t LEFT JOIN users u ON u.id = t.user_id';
            if ($hasResponsavelId) {
                $select .= ' LEFT JOIN users ru ON ru.id = t.responsavel_id';
            }
            $select .= ' WHERE t.id = ? LIMIT 1';

            $stmt = $pdo->prepare($select);
            $stmt->execute([(int)$taskId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) return false;

            $taskTitle = $t['titulo'] ?: ('Tarefa #' . $t['id']);
            $subject = 'Nova tarefa criada: ' . $taskTitle;
            $html = '<p>Uma nova tarefa foi criada no módulo de Integrações de Equipes.</p>'
                . '<p><strong>Tarefa:</strong> ' . htmlspecialchars($taskTitle) . '<br>'
                . '<strong>Equipe:</strong> ' . htmlspecialchars($t['equipe'] ?? '') . '<br>'
                . '<strong>Status:</strong> ' . htmlspecialchars($t['status'] ?? '') . '<br>'
                . '<strong>Responsável:</strong> ' . htmlspecialchars($t['responsavel'] ?? '') . '</p>';

            return wrcrm_send_event_notification($pdo, 'task_created', $subject, $html, [
                'creator' => $createdBy,
                'responsible' => $t['responsavel_id'] ?? null
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('wrcrm_is_sale_completed_stage')) {
    function wrcrm_is_sale_completed_stage($status) {
        $settings = wrcrm_get_notification_settings();
        $needle = mb_strtolower(trim((string)$status), 'UTF-8');
        foreach (($settings['sale_stage_names'] ?? []) as $name) {
            if ($needle !== '' && $needle === mb_strtolower(trim((string)$name), 'UTF-8')) return true;
        }
        return false;
    }
}

if (!function_exists('wrcrm_notify_lead_sale_completed')) {
    function wrcrm_notify_lead_sale_completed(PDO $pdo, $leadId, $changedBy, $status) {
        if (!wrcrm_is_sale_completed_stage($status)) return false;
        try {
            $stmt = $pdo->prepare('SELECT id, name, email, phone, source, status, user_id FROM leads WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$leadId]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$l) return false;
            $subject = 'Venda concluída: ' . ($l['name'] ?: ('Lead #' . $l['id']));
            $html = '<p>Um lead foi marcado como venda concluída.</p>'
                . '<p><strong>Lead:</strong> ' . htmlspecialchars($l['name'] ?: ('#' . $l['id'])) . '<br>'
                . '<strong>Status:</strong> ' . htmlspecialchars($status) . '<br>'
                . '<strong>Contato:</strong> ' . htmlspecialchars(trim(($l['email'] ?? '') . ' ' . ($l['phone'] ?? ''))) . '</p>';
            return wrcrm_send_event_notification($pdo, 'lead_sale_completed', $subject, $html, [
                'creator' => $changedBy,
                'responsible' => $l['user_id'] ?? null
            ]);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('wrcrm_notify_lead_stage_changed')) {
    function wrcrm_notify_lead_stage_changed(PDO $pdo, $leadId, $changedBy, $fromStatus, $toStatus) {
        try {
            $stmt = $pdo->prepare('SELECT id, name, user_id FROM leads WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$leadId]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$l) return false;
            $subject = 'Lead movido no funil: ' . ($l['name'] ?: ('Lead #' . $l['id']));
            $html = '<p>Um lead mudou de etapa no funil.</p>'
                . '<p><strong>Lead:</strong> ' . htmlspecialchars($l['name'] ?: ('#' . $l['id'])) . '<br>'
                . '<strong>De:</strong> ' . htmlspecialchars($fromStatus ?? '') . '<br>'
                . '<strong>Para:</strong> ' . htmlspecialchars($toStatus ?? '') . '</p>';
            return wrcrm_send_event_notification($pdo, 'lead_stage_changed', $subject, $html, [
                'creator' => $changedBy,
                'responsible' => $l['user_id'] ?? null
            ]);
        } catch (Exception $e) { return false; }
    }
}
