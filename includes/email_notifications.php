<?php
if (!function_exists('wrcrm_settings_path')) {
    function wrcrm_settings_path() {
        return __DIR__ . '/../storage/settings.json';
    }
}

if (!function_exists('wrcrm_mime_header_encode')) {
    function wrcrm_mime_header_encode($value, $charset = 'UTF-8') {
        $value = (string)$value;
        if ($value === '') return '';
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, $charset);
        }
        if (function_exists('iconv_mime_encode')) {
            $encoded = @iconv_mime_encode('X', $value, [
                'scheme' => 'B',
                'input-charset' => $charset,
                'output-charset' => $charset,
            ]);
            if (is_string($encoded) && strpos($encoded, 'X: ') === 0) {
                return substr($encoded, 3);
            }
        }
        return $value;
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
                'lead_stage_changed' => 0,
                'login_success' => 0,
                'login_failed_3' => 0
            ],
            'recipients' => [
                'reminder_created' => ['creator', 'responsible'],
                'task_created' => ['creator', 'responsible'],
                'lead_created' => [],
                'lead_sale_completed' => [],
                'lead_stage_changed' => [],
                'login_success' => [],
                'login_failed_3' => []
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
        $merged = $defaults;

        foreach ($saved as $key => $value) {
            if ($key === 'events' && is_array($value)) {
                $merged['events'] = array_replace($merged['events'], $value);
                continue;
            }

            if ($key === 'recipients' && is_array($value)) {
                foreach ($value as $event => $eventRecipients) {
                    if (is_array($eventRecipients)) {
                        $merged['recipients'][$event] = $eventRecipients;
                    }
                }
                continue;
            }

            if ($key === 'sale_stage_names') {
                $merged['sale_stage_names'] = is_array($value) ? array_values($value) : [];
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }
}

if (!function_exists('wrcrm_mail_simple')) {
    function wrcrm_mail_simple($to, $subject, $html, $fromName = null, $fromEmail = null) {
        $headers = [];
        if ($fromName && $fromEmail) {
            $headers[] = 'From: ' . wrcrm_mime_header_encode($fromName) . " <{$fromEmail}>";
        } elseif ($fromEmail) {
            $headers[] = 'From: ' . $fromEmail;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return @mail($to, $subject, $html, implode("\r\n", $headers));
    }
}

if (!function_exists('wrcrm_send_email')) {
    if (!function_exists('wrcrm_smtp_crypto_methods')) {
        function wrcrm_smtp_crypto_methods() {
            $methods = [];
            foreach ([
                'STREAM_CRYPTO_METHOD_TLS_CLIENT',
                'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
                'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
                'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT',
                'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT'
            ] as $const) {
                if (defined($const)) $methods[] = constant($const);
            }
            return $methods;
        }
    }

    function wrcrm_smtp_last_error($message = null) {
        static $lastError = '';
        if ($message !== null) $lastError = (string)$message;
        return $lastError;
    }

    function wrcrm_smtp_last_debug($debug = null) {
        static $lastDebug = [];
        if ($debug !== null) $lastDebug = is_array($debug) ? $debug : [];
        return $lastDebug;
    }

    function wrcrm_smtp_mask($value) {
        $value = (string)$value;
        if ($value === '') return '';
        if (strpos($value, '@') !== false) {
            [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');
            return substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
        }
        return substr($value, 0, 2) . str_repeat('*', max(1, strlen($value) - 2));
    }

    function wrcrm_smtp_log_debug(array $debug) {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $path = $dir . '/smtp_debug.log';
        $lines = [];
        $lines[] = '================ SMTP TEST ' . ($debug['id'] ?? '-') . ' ================';
        $lines[] = 'time=' . ($debug['time'] ?? date('c'));
        $lines[] = 'result=' . (!empty($debug['sent']) ? 'success' : 'failure');
        $lines[] = 'error=' . ($debug['error'] ?? '');
        $lines[] = 'host=' . ($debug['host'] ?? '') . ' port=' . ($debug['port'] ?? '') . ' secure=' . ($debug['secure'] ?? '') . ' auth=' . ($debug['auth'] ?? '');
        $lines[] = 'target=' . ($debug['target'] ?? '') . ' local_host=' . ($debug['local_host'] ?? '') . ' php=' . PHP_VERSION . ' os=' . PHP_OS;
        $lines[] = 'dns_ips=' . (!empty($debug['dns_ips']) && is_array($debug['dns_ips']) ? implode(',', $debug['dns_ips']) : '');
        $lines[] = 'from=' . ($debug['from'] ?? '') . ' to=' . ($debug['to'] ?? '') . ' user=' . ($debug['user'] ?? '');
        $lines[] = 'transcript:';
        foreach (($debug['transcript'] ?? []) as $entry) {
            $lines[] = '  ' . $entry;
        }
        $lines[] = '';
        @file_put_contents($path, implode(PHP_EOL, $lines), FILE_APPEND | LOCK_EX);
        return $path;
    }

    function wrcrm_send_email($to, $subject, $html, $toName = null) {
        $settings = wrcrm_read_settings();
        $smtp = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];
        $fromEmail = trim($smtp['from_email'] ?? '') ?: trim($smtp['user'] ?? '') ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = trim($smtp['from_name'] ?? '') ?: 'WRCRM';
        $sent = false;
        $lastError = '';
        wrcrm_smtp_last_error('');
        $debug = [
            'id' => date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'time' => date('c'),
            'sent' => false,
            'error' => '',
            'host' => trim((string)($smtp['host'] ?? '')),
            'port' => isset($smtp['port']) ? (int)$smtp['port'] : '',
            'secure' => strtolower($smtp['secure'] ?? ''),
            'auth' => !empty($smtp['auth']) ? '1' : '0',
            'target' => '',
            'local_host' => gethostname() ?: 'localhost',
            'dns_ips' => [],
            'from' => wrcrm_smtp_mask($fromEmail),
            'to' => wrcrm_smtp_mask($to),
            'user' => wrcrm_smtp_mask($smtp['user'] ?? ''),
            'transcript' => []
        ];
        wrcrm_smtp_last_debug($debug);

        if (!empty($smtp['host'])) {
            try {
                $host = trim((string)$smtp['host']);
                $port = isset($smtp['port']) ? (int)$smtp['port'] : 25;
                $secure = strtolower($smtp['secure'] ?? '');
                $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
                $debug['target'] = $target;
                $debug['dns_ips'] = @gethostbynamel($host) ?: [];
                $debug['transcript'][] = 'CONNECT ' . $target . ' timeout=20';
                $context = stream_context_create([
                    'ssl' => [
                        'peer_name' => $host,
                        'SNI_enabled' => true,
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false
                    ]
                ]);
                $fp = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
                if (!$fp) {
                    $lastError = 'Conexao recusada por ' . $host . ':' . $port . ' (' . $errno . ') ' . $errstr;
                    $debug['transcript'][] = 'CONNECT_ERROR errno=' . $errno . ' errstr=' . $errstr;
                } else {
                    $debug['transcript'][] = 'CONNECTED';
                    stream_set_timeout($fp, 15);
                    $read = function($label = 'S') use ($fp, &$debug) {
                        $response = '';
                        while (($line = fgets($fp, 2048)) !== false) {
                            $response .= $line;
                            if (strlen($line) < 4 || $line[3] !== '-') break;
                        }
                        $response = trim($response);
                        $debug['transcript'][] = $label . ': ' . ($response ?: 'sem resposta');
                        return $response;
                    };
                    $send = function($cmd) use ($fp, $read, &$debug) {
                        $logCmd = preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $cmd) ? '[base64 omitido]' : $cmd;
                        $debug['transcript'][] = 'C: ' . $logCmd;
                        if (fwrite($fp, $cmd . "\r\n") === false) return '';
                        return $read();
                    };
                    $expect = function($response, array $codes, $step) {
                        $code = (int)substr((string)$response, 0, 3);
                        if (!in_array($code, $codes, true)) {
                            throw new RuntimeException($step . ': ' . ($response ?: 'servidor nao respondeu'));
                        }
                    };
                    $banner = $read('S banner');
                    $expect($banner, [220], 'Conexao SMTP');
                    $ehlo = $send('EHLO ' . (gethostname() ?: 'localhost'));
                    $expect($ehlo, [250], 'EHLO');
                    if ($secure === 'tls') {
                        $starttls = $send('STARTTLS');
                        $expect($starttls, [220], 'STARTTLS');
                        $cryptoMethods = wrcrm_smtp_crypto_methods();
                        $enabled = false;
                        $cryptoErrors = [];
                        foreach ($cryptoMethods as $method) {
                            $enabled = @stream_socket_enable_crypto($fp, true, $method);
                            if ($enabled) break;
                            $cryptoErrors[] = 'method ' . $method;
                        }
                        if (!$enabled) {
                            throw new RuntimeException('Falha ao iniciar a criptografia TLS');
                        } else {
                            $debug['transcript'][] = 'TLS_ENABLED method=' . $method;
                            $ehlo = $send('EHLO ' . (gethostname() ?: 'localhost'));
                            $expect($ehlo, [250], 'EHLO apos STARTTLS');
                        }
                    }
                    $smtpUser = trim($smtp['user'] ?? '');
                    $smtpPass = (string)($smtp['pass'] ?? '');
                    $shouldAuth = !empty($smtp['auth']) || ($smtpUser !== '' && $smtpPass !== '');
                    if ($shouldAuth && $smtpUser !== '') {
                        $authResp = $send('AUTH LOGIN');
                        $expect($authResp, [334], 'Inicio da autenticacao');
                        $userResp = $send(base64_encode($smtpUser));
                        $expect($userResp, [334], 'Usuario SMTP');
                        $passResp = $send(base64_encode($smtpPass));
                        $expect($passResp, [235], 'Senha SMTP');
                    }
                    $encodedSubject = wrcrm_mime_header_encode($subject, 'UTF-8');
                    $headers = [
                        'From: ' . wrcrm_mime_header_encode($fromName, 'UTF-8') . " <{$fromEmail}>",
                        'To: ' . ($toName ? wrcrm_mime_header_encode($toName, 'UTF-8') . " <{$to}>" : $to),
                        'Subject: ' . $encodedSubject,
                        'MIME-Version: 1.0',
                        'Content-Type: text/html; charset=UTF-8'
                    ];
                    $mailResp = $send('MAIL FROM:<' . $fromEmail . '>');
                    $expect($mailResp, [250], 'Remetente');
                    $rcptResp = $send('RCPT TO:<' . $to . '>');
                    $expect($rcptResp, [250, 251], 'Destinatario');
                    $dataStartResp = $send('DATA');
                    $expect($dataStartResp, [354], 'Inicio da mensagem');
                    $safeHtml = preg_replace('/(?m)^\./', '..', (string)$html);
                    if (fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $safeHtml . "\r\n.\r\n") === false) {
                        throw new RuntimeException('Falha ao transmitir o conteudo do email');
                    }
                    $dataResp = $read();
                    $expect($dataResp, [250], 'Entrega da mensagem');
                    $send('QUIT');
                    fclose($fp);
                    $sent = true;
                    $debug['sent'] = true;
                }
            } catch (Throwable $e) {
                $sent = false;
                $lastError = $e->getMessage();
                $debug['transcript'][] = 'EXCEPTION ' . $lastError;
                if (isset($fp) && is_resource($fp)) fclose($fp);
            }
        }

        if (!$sent && empty($smtp['host'])) {
            $sent = wrcrm_mail_simple($to, $subject, $html, $fromName, $fromEmail);
            if (!$sent) $lastError = 'SMTP nao configurado e a funcao mail() do servidor falhou';
            $debug['sent'] = (bool)$sent;
            $debug['transcript'][] = 'MAIL_FUNCTION fallback=' . ($sent ? 'success' : 'failure');
        } elseif (!$sent && !$lastError) {
            $lastError = 'Falha ao enviar via SMTP sem resposta detalhada';
        }
        $debug['sent'] = (bool)$sent;
        $debug['error'] = $lastError;
        $logPath = wrcrm_smtp_log_debug($debug);
        $debug['log_path'] = $logPath;
        wrcrm_smtp_last_debug($debug);
        if ($lastError) {
            wrcrm_smtp_last_error($lastError);
            error_log('[WRCRM SMTP] ' . $lastError . ' host=' . ($smtp['host'] ?? '') . ' port=' . ($smtp['port'] ?? '') . ' secure=' . ($smtp['secure'] ?? '') . ' auth=' . (!empty($smtp['auth']) ? '1' : '0'));
        }
        return $sent;
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

if (!function_exists('wrcrm_team_user_ids')) {
    function wrcrm_team_user_ids(PDO $pdo, $teamId) {
        $teamId = (int)$teamId;
        if ($teamId <= 0) return [];
        $stmt = $pdo->prepare('SELECT id FROM users WHERE team_id = ? AND email IS NOT NULL AND email <> ""');
        $stmt->execute([$teamId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('wrcrm_send_team_email_notification')) {
    function wrcrm_send_team_email_notification(PDO $pdo, $teamId, $subject, $html, $fromUserId = null) {
        $users = wrcrm_user_emails($pdo, wrcrm_team_user_ids($pdo, $teamId));
        $sentAny = false;
        $seen = [];
        $fromUserId = $fromUserId !== null ? (int)$fromUserId : null;
        foreach ($users as $user) {
            $email = strtolower(trim($user['email']));
            if (!$email || isset($seen[$email])) continue;
            if ($fromUserId !== null && (int)$user['id'] === $fromUserId) continue;
            $seen[$email] = true;
            $name = $user['nome_completo'] ?: $user['username'];
            $sentAny = wrcrm_send_email($user['email'], $subject, $html, $name) || $sentAny;
        }
        return $sentAny;
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
            $stmt = $pdo->prepare('SELECT r.*, l.name AS lead_name, tm.name AS team_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id LEFT JOIN teams tm ON tm.id = r.team_id WHERE r.id = ? LIMIT 1');
            $stmt->execute([(int)$reminderId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return false;
            $subject = 'Novo lembrete criado';
            $html = '<p>Um novo lembrete foi criado no CRM.</p>'
                . '<p><strong>Lead:</strong> ' . htmlspecialchars($r['lead_name'] ?: ('#' . $r['lead_id'])) . '<br>'
                . '<strong>Quando:</strong> ' . htmlspecialchars($r['remind_at']) . '<br>'
                . '<strong>Equipe:</strong> ' . htmlspecialchars($r['team_name'] ?? '') . '</p>'
                . '<div style="padding:12px;background:#f8fafc;border-radius:6px">' . nl2br(htmlspecialchars($r['message'])) . '</div>';
            $sent = wrcrm_send_event_notification($pdo, 'reminder_created', $subject, $html, [
                'creator' => $r['created_by'] ?? null,
                'responsible' => $r['responsavel_id'] ?? null
            ]);
            if (!empty($r['team_id'])) {
                $sent = wrcrm_send_team_email_notification($pdo, $r['team_id'], $subject, $html, $r['created_by'] ?? null) || $sent;
            }
            return $sent;
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

if (!function_exists('wrcrm_notify_login_security')) {
    function wrcrm_notify_login_security(array $user, $event, $ipAddress = null) {
        if (!wrcrm_notification_enabled($event)) return false;

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') return false;

        $name = trim((string)($user['nome_completo'] ?? '')) ?: (string)($user['username'] ?? '');
        $isSuccess = $event === 'login_success';
        $subject = $isSuccess ? 'Novo login no WRCRM' : 'Alerta: 3 tentativas de senha incorreta';
        $when = date('d/m/Y H:i:s');

        $html = $isSuccess
            ? '<p>Um login foi realizado com sucesso na sua conta do WRCRM.</p>'
            : '<p>Detectamos 3 tentativas seguidas de senha incorreta na sua conta do WRCRM.</p>';

        $html .= '<p><strong>Usuário:</strong> ' . htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Data/hora:</strong> ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>IP:</strong> ' . htmlspecialchars((string)($ipAddress ?: 'Não identificado'), ENT_QUOTES, 'UTF-8') . '</p>';

        if (!$isSuccess) {
            $html .= '<p>Se não foi você, recomenda-se alterar sua senha e avisar o administrador do sistema.</p>';
        }

        return wrcrm_send_email($email, $subject, $html, $name);
    }
}

if (!function_exists('wrcrm_queue_login_security_notification')) {
    function wrcrm_queue_login_security_notification(array $user, $event, $ipAddress = null) {
        if (!wrcrm_notification_enabled($event)) return false;
        if (empty($user['id']) || empty($user['email'])) return false;
        if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) return false;

        if (!isset($_SESSION['pending_login_notifications']) || !is_array($_SESSION['pending_login_notifications'])) {
            $_SESSION['pending_login_notifications'] = [];
        }

        $_SESSION['pending_login_notifications'][] = [
            'user_id' => (int)$user['id'],
            'event' => (string)$event,
            'ip' => (string)($ipAddress ?: ''),
            'queued_at' => time()
        ];

        return true;
    }
}

if (!function_exists('wrcrm_process_queued_login_security_notifications')) {
    function wrcrm_process_queued_login_security_notifications(PDO $pdo) {
        if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) return 0;
        $queue = isset($_SESSION['pending_login_notifications']) && is_array($_SESSION['pending_login_notifications'])
            ? $_SESSION['pending_login_notifications']
            : [];
        unset($_SESSION['pending_login_notifications']);
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        if (!$queue) return 0;

        $sent = 0;
        foreach ($queue as $item) {
            $event = (string)($item['event'] ?? '');
            if (!in_array($event, ['login_success', 'login_failed_3'], true)) continue;
            $userId = (int)($item['user_id'] ?? 0);
            if ($userId <= 0) continue;
            try {
                $stmt = $pdo->prepare('SELECT id, username, nome_completo, email FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && wrcrm_notify_login_security($user, $event, $item['ip'] ?? null)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                error_log('[WRCRM notifications] login security background failed: ' . $e->getMessage());
            }
        }

        return $sent;
    }
}
