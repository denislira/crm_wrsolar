<?php
/**
 * Sincroniza vendas ganhas do CRM para a caixa de entrada do Financeiro.
 * Esta camada cria apenas pre-cadastros; parcelas e baixas sao responsabilidade
 * do modulo financeiro quando a venda for conferida.
 */

if (!function_exists('financeiro_crm_table_exists')) {
    function financeiro_crm_table_exists(PDO $pdo): bool
    {
        static $exists = null;
        if ($exists !== null) return $exists;

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'financeiro_vendas'");
            $stmt->execute();
            $exists = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }

    function financeiro_crm_stage_is_won(PDO $pdo, ?int $stageId): bool
    {
        if (!$stageId) return false;

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM funil_stages WHERE id = ? AND (COALESCE(is_conversion, 0) = 1 OR COALESCE(final_type, 'none') = 'won')");
            $stmt->execute([$stageId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    function financeiro_crm_status(?array $project): string
    {
        if (!$project) return 'aguardando_projeto';

        $hasValue = (float) ($project['proposal_value'] ?? 0) > 0;
        $hasPayment = trim((string) ($project['payment_type'] ?? '')) !== ''
            || !empty($project['payment_method_id']);

        return ($hasValue && $hasPayment) ? 'aguardando_conferencia' : 'dados_incompletos';
    }

    function financeiro_sincronizar_lead_ganho(PDO $pdo, int $leadId): void
    {
        if ($leadId <= 0 || !financeiro_crm_table_exists($pdo)) return;

        try {
            $stmt = $pdo->prepare(
                'SELECT l.id, l.user_id, l.name, l.cpf_cnpj, l.phone, l.email, l.cidade, l.vendedor,
                        l.orcamento_value, l.forma_pagamento, l.data_inicio, l.created_at, l.notes,
                        l.stage_id, p.id AS project_id, p.proposal_value, p.payment_method_id,
                        p.payment_type, p.closed_date, p.contract
                 FROM leads l
                 LEFT JOIN projetos p ON p.lead_id = l.id
                 WHERE l.id = ? LIMIT 1'
            );
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) return;

            $isWon = financeiro_crm_stage_is_won($pdo, isset($lead['stage_id']) ? (int) $lead['stage_id'] : null);
            if (!$isWon) {
                $review = $pdo->prepare("UPDATE financeiro_vendas SET status = 'em_revisao', updated_at = NOW() WHERE lead_id = ? AND origem = 'crm' AND status IN ('aguardando_projeto', 'dados_incompletos', 'aguardando_conferencia')");
                $review->execute([$leadId]);
                return;
            }

            $project = !empty($lead['project_id']) ? $lead : null;
            $status = financeiro_crm_status($project);
            $valor = $project && (float) $lead['proposal_value'] > 0
                ? (float) $lead['proposal_value']
                : ((float) ($lead['orcamento_value'] ?? 0) ?: null);
            $formaPagamento = $project && trim((string) ($lead['payment_type'] ?? '')) !== ''
                ? $lead['payment_type']
                : ($lead['forma_pagamento'] ?? null);
            $dataVenda = $project && !empty($lead['closed_date'])
                ? $lead['closed_date']
                : (!empty($lead['data_inicio']) ? $lead['data_inicio'] : ($lead['created_at'] ?? null));
            $observacoes = $project && trim((string) ($lead['contract'] ?? '')) !== ''
                ? $lead['contract']
                : ($lead['notes'] ?? null);

            $upsert = $pdo->prepare(
                "INSERT INTO financeiro_vendas
                    (lead_id, project_id, crm_stage_id, owner_user_id, origem, status, client_name, cpf_cnpj, phone, email, cidade, vendedor, valor_negociado, forma_pagamento, data_venda, observacoes)
                 VALUES (?, ?, ?, ?, 'crm', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    project_id = COALESCE(VALUES(project_id), project_id),
                    crm_stage_id = VALUES(crm_stage_id),
                    owner_user_id = VALUES(owner_user_id),
                    client_name = IF(status = 'aceito', client_name, VALUES(client_name)),
                    cpf_cnpj = IF(status = 'aceito', cpf_cnpj, VALUES(cpf_cnpj)),
                    phone = IF(status = 'aceito', phone, VALUES(phone)),
                    email = IF(status = 'aceito', email, VALUES(email)),
                    cidade = IF(status = 'aceito', cidade, VALUES(cidade)),
                    vendedor = IF(status = 'aceito', vendedor, VALUES(vendedor)),
                    valor_negociado = IF(status = 'aceito', valor_negociado, VALUES(valor_negociado)),
                    forma_pagamento = IF(status = 'aceito', forma_pagamento, VALUES(forma_pagamento)),
                    data_venda = IF(status = 'aceito', data_venda, VALUES(data_venda)),
                    observacoes = IF(status = 'aceito', observacoes, VALUES(observacoes)),
                    status = CASE
                        WHEN status IN ('aguardando_projeto', 'dados_incompletos', 'aguardando_conferencia', 'em_revisao') THEN VALUES(status)
                        ELSE status
                    END,
                    updated_at = NOW()"
            );
            $upsert->execute([
                $leadId,
                $project['project_id'] ?? null,
                $lead['stage_id'] ?? null,
                $lead['user_id'] ?? null,
                $status,
                trim((string) ($lead['name'] ?? 'Cliente sem nome')),
                $lead['cpf_cnpj'] ?: null,
                $lead['phone'] ?: null,
                $lead['email'] ?: null,
                $lead['cidade'] ?: null,
                $lead['vendedor'] ?: null,
                $valor,
                $formaPagamento ?: null,
                $dataVenda ?: null,
                $observacoes ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('Financeiro CRM sync: ' . $e->getMessage());
        }
    }

    function financeiro_sincronizar_projeto(PDO $pdo, int $projectId): void
    {
        if ($projectId <= 0 || !financeiro_crm_table_exists($pdo)) return;

        try {
            $stmt = $pdo->prepare('SELECT lead_id FROM projetos WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);
            $leadId = (int) $stmt->fetchColumn();
            if ($leadId > 0) {
                financeiro_sincronizar_lead_ganho($pdo, $leadId);
            }
        } catch (Throwable $e) {
            error_log('Financeiro projeto sync: ' . $e->getMessage());
        }
    }
}
