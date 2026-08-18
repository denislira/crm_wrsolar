<?php

function wrcrm_ai_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

function wrcrm_ai_has_col(PDO $pdo, string $table, string $col): bool
{
    return in_array($col, wrcrm_ai_table_columns($pdo, $table), true);
}

function wrcrm_ai_date_range(string $question): array
{
    $q = mb_strtolower($question, 'UTF-8');
    $end = new DateTime();
    $start = clone $end;
    if (strpos($q, 'hoje') !== false) {
        $start = new DateTime(date('Y-m-d') . ' 00:00:00');
    } elseif (strpos($q, 'ontem') !== false) {
        $start = new DateTime(date('Y-m-d 00:00:00', strtotime('-1 day')));
        $end = new DateTime(date('Y-m-d 23:59:59', strtotime('-1 day')));
    } elseif (strpos($q, 'semana') !== false) {
        $start->modify('-7 days');
    } elseif (strpos($q, 'mês') !== false || strpos($q, 'mes') !== false) {
        $start = new DateTime(date('Y-m-01') . ' 00:00:00');
    } elseif (preg_match('/(\d+)\s*dias?/u', $q, $m)) {
        $start->modify('-' . max(1, min(365, (int)$m[1])) . ' days');
    } else {
        $start->modify('-30 days');
    }
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function wrcrm_ai_lead_date_col(PDO $pdo): string
{
    if (wrcrm_ai_has_col($pdo, 'leads', 'data_inicio')) return 'data_inicio';
    if (wrcrm_ai_has_col($pdo, 'leads', 'created_at')) return 'created_at';
    return 'id';
}

function wrcrm_ai_deleted_cond(PDO $pdo, string $alias = ''): string
{
    if (!wrcrm_ai_has_col($pdo, 'leads', 'deleted')) return '';
    return ' AND ' . ($alias ? $alias . '.' : '') . 'deleted = 0';
}

function wrcrm_ai_first_col(PDO $pdo, string $table, array $cols): ?string
{
    foreach ($cols as $col) {
        if (wrcrm_ai_has_col($pdo, $table, $col)) return $col;
    }
    return null;
}

function wrcrm_ai_generic_date_col(PDO $pdo, string $table): string
{
    return wrcrm_ai_first_col($pdo, $table, ['created_at', 'criado_em', 'updated_at', 'atualizado_em', 'data_inicio']) ?: 'id';
}

function wrcrm_ai_count_period(PDO $pdo, string $table, string $dateCol, string $start, string $end): int
{
    if (empty(wrcrm_ai_table_columns($pdo, $table))) return 0;
    if ($dateCol === 'id') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$dateCol} >= ? AND {$dateCol} <= ?");
        $stmt->execute([$start, $end]);
    }
    return (int)$stmt->fetchColumn();
}

function wrcrm_ai_group_count(PDO $pdo, string $table, string $groupCol, ?string $dateCol = null, ?string $start = null, ?string $end = null): array
{
    if (empty(wrcrm_ai_table_columns($pdo, $table)) || !wrcrm_ai_has_col($pdo, $table, $groupCol)) return [];
    $where = '';
    $params = [];
    if ($dateCol && $dateCol !== 'id' && $start !== null && $end !== null) {
        $where = " WHERE {$dateCol} >= ? AND {$dateCol} <= ?";
        $params = [$start, $end];
    }
    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF({$groupCol},''),'Sem informacao') AS nome, COUNT(*) AS total FROM {$table}{$where} GROUP BY nome ORDER BY total DESC, nome ASC LIMIT 20");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wrcrm_ai_recent_user_context(PDO $pdo, ?int $userId = null): array
{
    $context = [];

    if (!empty(wrcrm_ai_table_columns($pdo, 'activity_log')) && wrcrm_ai_has_col($pdo, 'activity_log', 'created_at')) {
        $messageExpr = wrcrm_ai_has_col($pdo, 'activity_log', 'message')
            ? "a.message"
            : (wrcrm_ai_has_col($pdo, 'activity_log', 'details') ? "a.details" : "''");
        $actionExpr = wrcrm_ai_has_col($pdo, 'activity_log', 'action') ? "a.action" : "''";
        $userJoin = wrcrm_ai_has_col($pdo, 'activity_log', 'user_id') && !empty(wrcrm_ai_table_columns($pdo, 'users'))
            ? "LEFT JOIN users u ON u.id = a.user_id"
            : "";
        $userExpr = $userJoin ? "COALESCE(u.username, '')" : "''";
        $where = [];
        $params = [];

        if ($userId && wrcrm_ai_has_col($pdo, 'activity_log', 'user_id')) {
            $where[] = "a.user_id = ?";
            $params[] = $userId;
        }

        $sql = "SELECT a.id, {$actionExpr} AS acao, LEFT(COALESCE({$messageExpr}, ''), 900) AS detalhes, {$userExpr} AS usuario, a.created_at AS data
                FROM activity_log a
                {$userJoin}";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY a.created_at DESC, a.id DESC LIMIT 12";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $context['atividades_recentes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty(wrcrm_ai_table_columns($pdo, 'lead_movements'))) {
        $movementUserJoin = !empty(wrcrm_ai_table_columns($pdo, 'users')) && wrcrm_ai_has_col($pdo, 'lead_movements', 'user_id')
            ? "LEFT JOIN users u ON u.id = m.user_id"
            : "";
        $leadJoin = !empty(wrcrm_ai_table_columns($pdo, 'leads')) ? "LEFT JOIN leads l ON l.id = m.lead_id" : "";
        $leadName = $leadJoin && wrcrm_ai_has_col($pdo, 'leads', 'name') ? "COALESCE(l.name, '')" : "''";
        $movementUser = $movementUserJoin ? "COALESCE(u.username, '')" : "''";
        $movementNote = wrcrm_ai_has_col($pdo, 'lead_movements', 'note') ? 'LEFT(COALESCE(m.note, \'\'), 900)' : "''";
        $movementFrom = wrcrm_ai_has_col($pdo, 'lead_movements', 'from_status') ? 'm.from_status' : "''";
        $movementTo = wrcrm_ai_has_col($pdo, 'lead_movements', 'to_status') ? 'm.to_status' : "''";
        $movementDate = wrcrm_ai_has_col($pdo, 'lead_movements', 'created_at') ? 'm.created_at' : 'm.id';
        $where = [];
        $params = [];

        if ($userId && wrcrm_ai_has_col($pdo, 'lead_movements', 'user_id')) {
            $where[] = "m.user_id = ?";
            $params[] = $userId;
        }

        $sql = "SELECT m.id, m.lead_id, {$leadName} AS lead_nome, {$movementFrom} AS status_anterior, {$movementTo} AS status_novo, {$movementNote} AS anotacao, {$movementUser} AS usuario, {$movementDate} AS data
                FROM lead_movements m
                {$leadJoin}
                {$movementUserJoin}";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY {$movementDate} DESC, m.id DESC LIMIT 12";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $context['movimentacoes_leads_recentes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty(wrcrm_ai_table_columns($pdo, 'reminders')) && $userId && wrcrm_ai_has_col($pdo, 'reminders', 'created_by')) {
        $message = wrcrm_ai_has_col($pdo, 'reminders', 'message') ? 'r.message' : "''";
        $status = wrcrm_ai_has_col($pdo, 'reminders', 'status') ? 'r.status' : "''";
        $remindAt = wrcrm_ai_has_col($pdo, 'reminders', 'remind_at') ? 'r.remind_at' : 'NULL';
        $leadJoin = !empty(wrcrm_ai_table_columns($pdo, 'leads')) ? "LEFT JOIN leads l ON l.id = r.lead_id" : "";
        $leadName = $leadJoin && wrcrm_ai_has_col($pdo, 'leads', 'name') ? "COALESCE(l.name, '')" : "''";
        $stmt = $pdo->prepare("SELECT r.id, r.lead_id, {$leadName} AS lead_nome, LEFT(COALESCE({$message}, ''), 700) AS mensagem, {$status} AS status, {$remindAt} AS lembrar_em, r.created_at AS criado_em FROM reminders r {$leadJoin} WHERE r.created_by = ? ORDER BY r.created_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        $context['lembretes_recentes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($context)) {
        $context['instrucao'] = 'Use estes eventos recentes para entender o que o usuario acabou de fazer no CRM, oferecer ajuda contextual e sugerir proximos passos sem inventar dados.';
    }

    return $context;
}

function wrcrm_ai_collect_context(PDO $pdo, string $question, ?int $userId = null): array
{
    $q = mb_strtolower($question, 'UTF-8');
    [$start, $end] = wrcrm_ai_date_range($question);
    $dateCol = wrcrm_ai_lead_date_col($pdo);
    $deleted = wrcrm_ai_deleted_cond($pdo);
    $context = ['periodo' => ['inicio' => $start, 'fim' => $end], 'consultas' => []];
    $recentUserContext = wrcrm_ai_recent_user_context($pdo, $userId);
    if (!empty($recentUserContext)) {
        $context['usuario_contexto_recente'] = $recentUserContext;
    }

    if (preg_match('/usu[aÃ¡]rio|usuarios|usu[aÃ¡]rios|user|users|equipe|equipes|time|times|perfil|perfis|cargo|cargos|pap[eÃ©]is|roles/u', $q)) {
        if (!empty(wrcrm_ai_table_columns($pdo, 'users'))) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $context['consultas']['total_usuarios'] = (int)$stmt->fetchColumn();

            if (wrcrm_ai_has_col($pdo, 'users', 'team_id') && !empty(wrcrm_ai_table_columns($pdo, 'teams'))) {
                $stmt = $pdo->query("
                    SELECT COALESCE(NULLIF(t.name,''),'Sem equipe') AS equipe, COUNT(*) AS total
                    FROM users u
                    LEFT JOIN teams t ON t.id = u.team_id
                    GROUP BY equipe
                    ORDER BY total DESC, equipe ASC
                    LIMIT 20
                ");
                $context['consultas']['usuarios_por_equipe'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (wrcrm_ai_has_col($pdo, 'users', 'role_id') && !empty(wrcrm_ai_table_columns($pdo, 'roles'))) {
                $stmt = $pdo->query("
                    SELECT COALESCE(NULLIF(r.name,''),'Sem perfil') AS perfil, COUNT(*) AS total
                    FROM users u
                    LEFT JOIN roles r ON r.id = u.role_id
                    GROUP BY perfil
                    ORDER BY total DESC, perfil ASC
                    LIMIT 20
                ");
                $context['consultas']['usuarios_por_perfil'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (preg_match('/listar|lista|quais|nomes?/u', $q)) {
                $nameExpr = wrcrm_ai_has_col($pdo, 'users', 'nome_completo')
                    ? "COALESCE(NULLIF(u.nome_completo,''), u.username)"
                    : "u.username";
                $emailExpr = wrcrm_ai_has_col($pdo, 'users', 'email') ? "u.email" : "''";
                $teamJoin = !empty(wrcrm_ai_table_columns($pdo, 'teams')) && wrcrm_ai_has_col($pdo, 'users', 'team_id') ? "LEFT JOIN teams t ON t.id = u.team_id" : "";
                $teamExpr = $teamJoin ? "COALESCE(NULLIF(t.name,''),'Sem equipe')" : "''";
                $roleJoin = !empty(wrcrm_ai_table_columns($pdo, 'roles')) && wrcrm_ai_has_col($pdo, 'users', 'role_id') ? "LEFT JOIN roles r ON r.id = u.role_id" : "";
                $roleExpr = $roleJoin ? "COALESCE(NULLIF(r.name,''),'Sem perfil')" : "''";
                $stmt = $pdo->query("
                    SELECT u.id, {$nameExpr} AS nome, {$emailExpr} AS email, {$teamExpr} AS equipe, {$roleExpr} AS perfil
                    FROM users u
                    {$teamJoin}
                    {$roleJoin}
                    ORDER BY nome ASC
                    LIMIT 50
                ");
                $context['consultas']['usuarios_amostra'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        if (!empty(wrcrm_ai_table_columns($pdo, 'teams'))) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM teams");
            $context['consultas']['total_equipes'] = (int)$stmt->fetchColumn();
        }

        if (!empty(wrcrm_ai_table_columns($pdo, 'roles'))) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM roles");
            $context['consultas']['total_perfis'] = (int)$stmt->fetchColumn();
        }
    }

    if (preg_match('/integracao|integra[cç][aã]o|tarefa|tarefas|equipe|equipes|pendente|prazo|vencid|respons[aá]vel|responsavel/u', $q)) {
        $table = 'team_tasks';
        if (!empty(wrcrm_ai_table_columns($pdo, $table))) {
            $taskDate = wrcrm_ai_generic_date_col($pdo, $table);
            $context['consultas']['total_tarefas_periodo'] = wrcrm_ai_count_period($pdo, $table, $taskDate, $start, $end);
            if (wrcrm_ai_has_col($pdo, $table, 'status')) {
                $context['consultas']['tarefas_por_status'] = wrcrm_ai_group_count($pdo, $table, 'status', null, null, null);
            }
            if (wrcrm_ai_has_col($pdo, $table, 'equipe')) {
                $context['consultas']['tarefas_por_equipe'] = wrcrm_ai_group_count($pdo, $table, 'equipe', null, null, null);
            }
            if (wrcrm_ai_has_col($pdo, $table, 'responsavel')) {
                $context['consultas']['tarefas_por_responsavel'] = wrcrm_ai_group_count($pdo, $table, 'responsavel', null, null, null);
            }
            if (wrcrm_ai_has_col($pdo, $table, 'data_vencimento')) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM team_tasks WHERE data_vencimento IS NOT NULL AND data_vencimento < CURDATE() AND COALESCE(status,'') NOT IN ('Concluida','Concluída','Finalizada','Finalizado')");
                $context['consultas']['tarefas_vencidas_abertas'] = (int)$stmt->fetchColumn();
            }
            if (preg_match('/listar|lista|quais|atrasad|vencid|pendente/u', $q)) {
                $titleCol = wrcrm_ai_has_col($pdo, $table, 'titulo') ? 'titulo' : 'id';
                $statusCol = wrcrm_ai_has_col($pdo, $table, 'status') ? 'status' : "''";
                $respCol = wrcrm_ai_has_col($pdo, $table, 'responsavel') ? 'responsavel' : "''";
                $dueCol = wrcrm_ai_has_col($pdo, $table, 'data_vencimento') ? 'data_vencimento' : "NULL";
                $stmt = $pdo->query("SELECT id, {$titleCol} AS titulo, {$statusCol} AS status, {$respCol} AS responsavel, {$dueCol} AS vencimento FROM team_tasks ORDER BY id DESC LIMIT 30");
                $context['consultas']['tarefas_amostra'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    if (preg_match('/fila|demanda|demandas|consultoria interna|consultoria externa|externa|interna/u', $q)) {
        $table = 'consultoria_interna_demandas';
        if (!empty(wrcrm_ai_table_columns($pdo, $table))) {
            $demandDate = wrcrm_ai_generic_date_col($pdo, $table);
            $context['consultas']['total_demandas_periodo'] = wrcrm_ai_count_period($pdo, $table, $demandDate, $start, $end);
            if (wrcrm_ai_has_col($pdo, $table, 'status')) {
                $context['consultas']['demandas_por_status'] = wrcrm_ai_group_count($pdo, $table, 'status', null, null, null);
            }
            if (wrcrm_ai_has_col($pdo, $table, 'accepted_by')) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM consultoria_interna_demandas WHERE accepted_by IS NULL");
                $context['consultas']['demandas_sem_responsavel'] = (int)$stmt->fetchColumn();
            }
            if (preg_match('/listar|lista|quais|pendente|abert/u', $q)) {
                $clientCol = wrcrm_ai_has_col($pdo, $table, 'client_name') ? 'client_name' : 'id';
                $statusCol = wrcrm_ai_has_col($pdo, $table, 'status') ? 'status' : "''";
                $createdCol = wrcrm_ai_has_col($pdo, $table, 'created_at') ? 'created_at' : 'id';
                $stmt = $pdo->query("SELECT id, {$clientCol} AS cliente, {$statusCol} AS status, {$createdCol} AS criado_em FROM consultoria_interna_demandas ORDER BY id DESC LIMIT 30");
                $context['consultas']['demandas_amostra'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $externalTable = 'consultoria_externa_itens';
        if (!empty(wrcrm_ai_table_columns($pdo, $externalTable))) {
            $externalDate = wrcrm_ai_generic_date_col($pdo, $externalTable);
            $context['consultas']['total_consultoria_externa_periodo'] = wrcrm_ai_count_period($pdo, $externalTable, $externalDate, $start, $end);
            if (wrcrm_ai_has_col($pdo, $externalTable, 'stage_key')) {
                $context['consultas']['consultoria_externa_por_etapa'] = wrcrm_ai_group_count($pdo, $externalTable, 'stage_key', null, null, null);
            }
            if (wrcrm_ai_has_col($pdo, $externalTable, 'exported_to_internal_queue')) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM consultoria_externa_itens WHERE exported_to_internal_queue = 0");
                $context['consultas']['consultoria_externa_nao_exportada'] = (int)$stmt->fetchColumn();
            }
        }
    }

    $wantsFollowupDraft = preg_match('/redig|mensagem|whatsapp|email|roteiro/u', $q)
        && preg_match('/lead/u', $q)
        && preg_match('/ultimo|ult\x{00ED}mo|recent|ultima.*contato|ult\x{00ED}ma.*contato|ultimo.*contato|ult\x{00ED}mo.*contato/u', $q);

    if ($wantsFollowupDraft) {
        $leadName = wrcrm_ai_has_col($pdo, 'leads', 'name') ? 'l.name' : "''";
        $email = wrcrm_ai_has_col($pdo, 'leads', 'email') ? 'l.email' : "''";
        $phone = wrcrm_ai_has_col($pdo, 'leads', 'phone') ? 'l.phone' : "''";
        $city = wrcrm_ai_has_col($pdo, 'leads', 'cidade') ? 'l.cidade' : "''";
        $source = wrcrm_ai_has_col($pdo, 'leads', 'source') ? 'l.source' : "''";
        $status = wrcrm_ai_has_col($pdo, 'leads', 'status') ? 'l.status' : "''";
        $notes = wrcrm_ai_has_col($pdo, 'leads', 'notes') ? 'LEFT(COALESCE(l.notes, \'\'), 6000)' : "''";
        $lastContact = wrcrm_ai_has_col($pdo, 'leads', 'ultimo_contato') ? 'l.ultimo_contato' : 'NULL';
        $createdAt = $dateCol === 'id' ? 'NULL' : "l.{$dateCol}";
        $orderBy = $dateCol === 'id' ? 'l.id DESC' : "l.{$dateCol} DESC, l.id DESC";

        $stageJoin = '';
        $stageSelect = "''";
        if (wrcrm_ai_has_col($pdo, 'leads', 'stage_id') && !empty(wrcrm_ai_table_columns($pdo, 'funil_stages'))) {
            $stageName = wrcrm_ai_first_col($pdo, 'funil_stages', ['name', 'stage_name']);
            if ($stageName) {
                $stageJoin = ' LEFT JOIN funil_stages fs ON fs.id = l.stage_id';
                $stageSelect = "COALESCE(fs.{$stageName}, '')";
            }
        }

        $stmt = $pdo->query("SELECT l.id, {$leadName} AS nome, {$email} AS email, {$phone} AS telefone, {$city} AS cidade, {$source} AS origem, {$status} AS status, {$stageSelect} AS etapa, {$lastContact} AS ultimo_contato, {$createdAt} AS criado_em, {$notes} AS anotacoes_atuais FROM leads l{$stageJoin} WHERE 1=1" . wrcrm_ai_deleted_cond($pdo, 'l') . " ORDER BY {$orderBy} LIMIT 1");
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lead) {
            $history = [];
            if (!empty(wrcrm_ai_table_columns($pdo, 'lead_movements'))) {
                $movementUserJoin = wrcrm_ai_has_col($pdo, 'lead_movements', 'changed_by')
                    ? 'LEFT JOIN users u ON u.id = m.changed_by'
                    : (wrcrm_ai_has_col($pdo, 'lead_movements', 'user_id') ? 'LEFT JOIN users u ON u.id = m.user_id' : '');
                $movementUser = $movementUserJoin ? "COALESCE(u.username, '')" : "''";
                $movementNote = wrcrm_ai_has_col($pdo, 'lead_movements', 'note') ? 'LEFT(COALESCE(m.note, \'\'), 2000)' : "''";
                $movementFrom = wrcrm_ai_has_col($pdo, 'lead_movements', 'from_status') ? 'm.from_status' : "''";
                $movementTo = wrcrm_ai_has_col($pdo, 'lead_movements', 'to_status') ? 'm.to_status' : "''";
                $movementDate = wrcrm_ai_has_col($pdo, 'lead_movements', 'created_at') ? 'm.created_at' : 'm.id';
                $stmt = $pdo->prepare("SELECT {$movementDate} AS data, {$movementFrom} AS status_anterior, {$movementTo} AS status_novo, {$movementNote} AS anotacao, {$movementUser} AS usuario FROM lead_movements m {$movementUserJoin} WHERE m.lead_id = ? ORDER BY {$movementDate} DESC LIMIT 10");
                $stmt->execute([(int)$lead['id']]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $context['consultas']['lead_recente_para_mensagem'] = [
                'lead' => $lead,
                'historico_movimentacoes' => $history,
                'instrucao' => 'Use somente estes dados para redigir uma mensagem pronta para copiar. Nao invente fatos, promessas, consumo, preco ou datas.',
            ];
        } else {
            $context['consultas']['lead_recente_para_mensagem'] = ['lead' => null, 'instrucao' => 'Nenhum lead ativo encontrado.'];
        }
    }

    $wantsLeads = preg_match('/lead|leads|origem|fonte|funil|convers|venda|parad|sem contato|atividade|proposta|consultor/u', $q);
    if ($wantsLeads) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE {$dateCol} >= ? AND {$dateCol} <= ?{$deleted}");
        $stmt->execute([$start, $end]);
        $context['consultas']['total_leads_periodo'] = (int)$stmt->fetchColumn();

        if (wrcrm_ai_has_col($pdo, 'leads', 'status')) {
            $context['consultas']['leads_por_status'] = wrcrm_ai_group_count($pdo, 'leads', 'status', $dateCol, $start, $end);
        }

        if (wrcrm_ai_has_col($pdo, 'leads', 'user_id')) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(u.username,'Sem usuario') AS usuario, COUNT(*) AS total
                FROM leads l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?" . wrcrm_ai_deleted_cond($pdo, 'l') . "
                GROUP BY usuario
                ORDER BY total DESC, usuario ASC
                LIMIT 20
            ");
            $stmt->execute([$start, $end]);
            $context['consultas']['leads_por_usuario'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (wrcrm_ai_has_col($pdo, 'leads', 'orcamento_value')) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(orcamento_value),0) FROM leads WHERE {$dateCol} >= ? AND {$dateCol} <= ?{$deleted}");
            $stmt->execute([$start, $end]);
            $context['consultas']['valor_orcamentos_leads_periodo'] = (float)$stmt->fetchColumn();
        }
    }

    if (preg_match('/origem|fonte|canal/u', $q) && wrcrm_ai_has_col($pdo, 'leads', 'source')) {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(source,''),'Sem origem') AS origem, COUNT(*) AS total FROM leads WHERE {$dateCol} >= ? AND {$dateCol} <= ?{$deleted} GROUP BY origem ORDER BY total DESC LIMIT 10");
        $stmt->execute([$start, $end]);
        $context['consultas']['leads_por_origem'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (preg_match('/funil|etapa|status|convers|venda/u', $q) && wrcrm_ai_has_col($pdo, 'leads', 'stage_id')) {
        $stageName = wrcrm_ai_has_col($pdo, 'funil_stages', 'name') ? 'name' : (wrcrm_ai_has_col($pdo, 'funil_stages', 'stage_name') ? 'stage_name' : 'id');
        $sql = "SELECT COALESCE(NULLIF(fs.{$stageName},''),'Sem etapa') AS etapa, COUNT(*) AS total
                FROM leads l LEFT JOIN funil_stages fs ON fs.id = l.stage_id
                WHERE l.{$dateCol} >= ? AND l.{$dateCol} <= ?" . wrcrm_ai_deleted_cond($pdo, 'l') . "
                GROUP BY etapa ORDER BY total DESC LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        $context['consultas']['leads_por_etapa'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (preg_match('/atividade|atividades|consultor|equipe|usu[aá]rio|follow|proposta/u', $q)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE created_at >= ? AND created_at <= ?");
        $stmt->execute([$start, $end]);
        $context['consultas']['total_atividades_periodo'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(u.username,'desconhecido') AS usuario, COUNT(*) AS total FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.created_at >= ? AND a.created_at <= ? GROUP BY a.user_id, usuario ORDER BY total DESC LIMIT 10");
        $stmt->execute([$start, $end]);
        $context['consultas']['atividades_por_usuario'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (preg_match('/parad|sem contato|atrasad/u', $q)) {
        $nameCol = wrcrm_ai_has_col($pdo, 'leads', 'name') ? 'name' : 'id';
        $sourceSelect = wrcrm_ai_has_col($pdo, 'leads', 'source') ? "COALESCE(NULLIF(source,''),'Sem origem') AS source" : "'' AS source";
        $statusSelect = wrcrm_ai_has_col($pdo, 'leads', 'status') ? "COALESCE(NULLIF(status,''),'Sem status') AS status" : "'' AS status";
        $stmt = $pdo->prepare("SELECT id, {$nameCol} AS nome, {$sourceSelect}, {$statusSelect}, {$dateCol} AS entrada FROM leads WHERE {$dateCol} <= DATE_SUB(NOW(), INTERVAL 7 DAY){$deleted} ORDER BY {$dateCol} ASC LIMIT 20");
        $stmt->execute();
        $context['consultas']['leads_parados_7_dias_amostra'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (preg_match('/projeto|projetos/u', $q)) {
        $cols = wrcrm_ai_table_columns($pdo, 'projetos');
        if (!empty($cols)) {
            $date = in_array('created_at', $cols, true) ? 'created_at' : 'id';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE {$date} >= ? AND {$date} <= ?");
            $stmt->execute([$start, $end]);
            $context['consultas']['total_projetos_periodo'] = (int)$stmt->fetchColumn();
        }
    }

    if (preg_match('/p[oó]s|pos-venda|p[oó]s-venda|garantia/u', $q)) {
        $cols = wrcrm_ai_table_columns($pdo, 'pos_venda');
        if (!empty($cols)) {
            $date = in_array('created_at', $cols, true) ? 'created_at' : 'id';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_venda WHERE {$date} >= ? AND {$date} <= ?");
            $stmt->execute([$start, $end]);
            $context['consultas']['total_pos_venda_periodo'] = (int)$stmt->fetchColumn();
        }
    }

    if (preg_match('/projeto|projetos|relatorio|relat[oó]rio|dashboard|indicador|kpi/u', $q)) {
        $cols = wrcrm_ai_table_columns($pdo, 'projetos');
        if (!empty($cols)) {
            if (in_array('status', $cols, true)) {
                $context['consultas']['projetos_por_status'] = wrcrm_ai_group_count($pdo, 'projetos', 'status', null, null, null);
            }
            if (in_array('client_status', $cols, true)) {
                $context['consultas']['projetos_por_status_cliente'] = wrcrm_ai_group_count($pdo, 'projetos', 'client_status', null, null, null);
            }
            if (in_array('payment_status', $cols, true)) {
                $context['consultas']['projetos_por_status_pagamento'] = wrcrm_ai_group_count($pdo, 'projetos', 'payment_status', null, null, null);
            }
            if (in_array('proposal_value', $cols, true)) {
                $date = in_array('created_at', $cols, true) ? 'created_at' : 'id';
                if ($date === 'id') {
                    $stmt = $pdo->query("SELECT COALESCE(SUM(proposal_value),0), COALESCE(AVG(NULLIF(proposal_value,0)),0) FROM projetos");
                } else {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(proposal_value),0), COALESCE(AVG(NULLIF(proposal_value,0)),0) FROM projetos WHERE {$date} >= ? AND {$date} <= ?");
                    $stmt->execute([$start, $end]);
                }
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $context['consultas']['valor_projetos_periodo'] = ['total' => (float)($row[0] ?? 0), 'ticket_medio' => (float)($row[1] ?? 0)];
            }
            if (preg_match('/listar|lista|quais|abert|andamento|atrasad/u', $q)) {
                $clientCol = in_array('client_name', $cols, true) ? 'client_name' : 'id';
                $statusCol = in_array('status', $cols, true) ? 'status' : "''";
                $valueCol = in_array('proposal_value', $cols, true) ? 'proposal_value' : '0';
                $createdCol = in_array('created_at', $cols, true) ? 'created_at' : 'id';
                $stmt = $pdo->query("SELECT id, {$clientCol} AS cliente, {$statusCol} AS status, {$valueCol} AS valor, {$createdCol} AS criado_em FROM projetos ORDER BY id DESC LIMIT 30");
                $context['consultas']['projetos_amostra'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    if (preg_match('/relatorio|relat[oó]rio|dashboard|indicador|kpi|pos-venda|garantia/u', $q)) {
        $cols = wrcrm_ai_table_columns($pdo, 'pos_venda');
        if (!empty($cols)) {
            if (in_array('stage', $cols, true)) {
                $context['consultas']['pos_venda_por_etapa'] = wrcrm_ai_group_count($pdo, 'pos_venda', 'stage', null, null, null);
            }
            if (in_array('status', $cols, true)) {
                $context['consultas']['pos_venda_por_status'] = wrcrm_ai_group_count($pdo, 'pos_venda', 'status', null, null, null);
            }
        }
    }

    if (empty($context['consultas'])) {
        $context['observacao'] = 'Nenhuma ferramenta especifica foi acionada. Responda pedindo uma pergunta sobre leads, funil, atividades, projetos, integracoes de equipe, fila de demandas, usuarios, relatorios ou pos-venda.';
    }

    return $context;
}
