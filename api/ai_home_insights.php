<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

function ai_home_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ai_home_has(array $cols, string $col): bool
{
    return in_array($col, $cols, true);
}

function ai_home_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ai_home_value(PDO $pdo, string $sql, array $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$leadCols = ai_home_columns($pdo, 'leads');
if (empty($leadCols)) {
    echo json_encode([
        'success' => true,
        'insights' => [],
        'quick_prompts' => [],
        'message' => 'Tabela de leads indisponivel',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasDeleted = ai_home_has($leadCols, 'deleted');
$hasDataInicio = ai_home_has($leadCols, 'data_inicio');
$hasCreatedAt = ai_home_has($leadCols, 'created_at');
$hasFirstContact = ai_home_has($leadCols, 'first_contact_at');
$hasUltimoContato = ai_home_has($leadCols, 'ultimo_contato');
$hasStatus = ai_home_has($leadCols, 'status');
$hasStageId = ai_home_has($leadCols, 'stage_id');
$hasOrcamento = ai_home_has($leadCols, 'orcamento_value');
$hasIsSql = ai_home_has($leadCols, 'is_sql');

$dateCol = $hasDataInicio ? 'data_inicio' : ($hasCreatedAt ? 'created_at' : 'id');
$delCond = $hasDeleted ? 'COALESCE(deleted, 0) = 0' : '1=1';
$activeCond = $hasStatus
    ? " AND LOWER(COALESCE(status,'')) NOT LIKE '%perdido%' AND LOWER(COALESCE(status,'')) NOT LIKE '%descartado%' AND LOWER(COALESCE(status,'')) NOT LIKE '%convertido%' AND LOWER(COALESCE(status,'')) NOT LIKE '%ganho%'"
    : '';

$threshold24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
$threshold7d = date('Y-m-d H:i:s', strtotime('-7 days'));
$insights = [];

if ($hasFirstContact) {
    $noContact24h = ai_home_count($pdo, "SELECT COUNT(*) FROM leads WHERE {$delCond}{$activeCond} AND first_contact_at IS NULL AND {$dateCol} < ?", [$threshold24h]);
} elseif ($hasUltimoContato) {
    $noContact24h = ai_home_count($pdo, "SELECT COUNT(*) FROM leads WHERE {$delCond}{$activeCond} AND (ultimo_contato IS NULL OR ultimo_contato < ?) AND {$dateCol} < ?", [$threshold24h, $threshold24h]);
} else {
    $noContact24h = 0;
}

if ($noContact24h > 0) {
    $insights[] = [
        'icon' => 'fa-user-clock',
        'tone' => 'danger',
        'title' => $noContact24h . ' lead' . ($noContact24h === 1 ? '' : 's') . ' sem contato há mais de 24h.',
        'text' => 'Priorize esses atendimentos para reduzir perda por demora no primeiro retorno.',
        'action' => 'Mostre os leads sem contato há mais de 24h e sugira a próxima ação para cada um.',
        'label' => 'Ver lista de leads',
    ];
}

$staleLeads = 0;
$movementCols = ai_home_columns($pdo, 'lead_movements');
if ($hasStageId && ai_home_has($movementCols, 'lead_id') && ai_home_has($movementCols, 'created_at')) {
    $staleLeads = ai_home_count($pdo, "
        SELECT COUNT(*) FROM (
            SELECT l.id
            FROM leads l
            LEFT JOIN lead_movements lm ON lm.lead_id = l.id
            WHERE {$delCond}{$activeCond} AND l.{$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY l.id
            HAVING MAX(lm.created_at) IS NULL OR MAX(lm.created_at) < ?
        ) x
    ", [$threshold7d]);
} elseif ($hasUltimoContato) {
    $staleLeads = ai_home_count($pdo, "SELECT COUNT(*) FROM leads WHERE {$delCond}{$activeCond} AND (ultimo_contato IS NULL OR ultimo_contato < ?) AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)", [$threshold7d]);
}

if ($staleLeads > 0) {
    $insights[] = [
        'icon' => 'fa-hourglass-half',
        'tone' => 'warning',
        'title' => $staleLeads . ' lead' . ($staleLeads === 1 ? '' : 's') . ' parado' . ($staleLeads === 1 ? '' : 's') . ' há mais de 7 dias.',
        'text' => 'Revise a etapa atual e defina um follow-up objetivo para retomar a negociação.',
        'action' => 'Quais leads estão parados há mais de 7 dias e o que devo fazer com eles?',
        'label' => 'Ver leads parados',
    ];
}

$speedToLeadAvg = null;
if ($hasFirstContact) {
    $avg = ai_home_value($pdo, "SELECT AVG(TIMESTAMPDIFF(HOUR, {$dateCol}, first_contact_at)) FROM leads WHERE {$delCond} AND first_contact_at IS NOT NULL AND {$dateCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if ($avg !== null && $avg !== false) {
        $speedToLeadAvg = round((float)$avg, 1);
    }
}

if ($speedToLeadAvg !== null) {
    $good = $speedToLeadAvg <= 1;
    $insights[] = [
        'icon' => $good ? 'fa-circle-check' : 'fa-bolt',
        'tone' => $good ? 'success' : 'warning',
        'title' => 'Speed-to-lead médio: ' . $speedToLeadAvg . 'h nos últimos 30 dias.',
        'text' => $good ? 'O primeiro contato está em bom ritmo. Continue monitorando novos leads.' : 'Tente reduzir o tempo até o primeiro contato, principalmente em leads novos.',
        'action' => 'Qual é o speed-to-lead atual e quais ações ajudam a melhorar?',
        'label' => $good ? 'Ver indicador' : 'Ver dicas',
    ];
}

$newLeadsToday = ai_home_count($pdo, "SELECT COUNT(*) FROM leads WHERE {$delCond} AND DATE({$dateCol}) = CURDATE()");
if ($newLeadsToday > 0) {
    $insights[] = [
        'icon' => 'fa-user-plus',
        'tone' => 'success',
        'title' => $newLeadsToday . ' lead' . ($newLeadsToday === 1 ? '' : 's') . ' novo' . ($newLeadsToday === 1 ? '' : 's') . ' hoje.',
        'text' => 'Confira origem, etapa e próximo contato para manter o funil atualizado.',
        'action' => 'Faça um resumo dos leads novos de hoje e indique prioridades.',
        'label' => 'Resumo de hoje',
    ];
}

if (empty($insights)) {
    $insights[] = [
        'icon' => 'fa-circle-check',
        'tone' => 'success',
        'title' => 'Nenhum alerta crítico encontrado agora.',
        'text' => 'Use as perguntas rápidas para analisar funil, leads e desempenho da equipe.',
        'action' => 'Faça um resumo comercial do CRM e destaque oportunidades de melhoria.',
        'label' => 'Analisar CRM',
    ];
}

$quickPrompts = [
    'Quais leads têm maior chance de conversão?',
    $noContact24h > 0 ? 'Mostre os leads sem contato >24h' : 'Quais leads novos precisam de prioridade?',
    'Resumo do desempenho da equipe',
    $staleLeads > 0 ? 'Quais leads estão parados há mais de 7 dias?' : 'Sugira ações para melhorar meus resultados',
];

echo json_encode([
    'success' => true,
    'insights' => array_slice($insights, 0, 4),
    'quick_prompts' => $quickPrompts,
    'checked_at' => date('d/m/Y H:i'),
], JSON_UNESCAPED_UNICODE);
