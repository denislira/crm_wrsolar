<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/consultoria_externa_stages.php';

// Ensure only users with role 'consultor_externo' can access this page.
$roleName = $_SESSION['role_name'] ?? null;
if (!$roleName && !empty($_SESSION['role_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
    $stmt->execute([$_SESSION['role_id']]);
    $roleName = $stmt->fetchColumn();
}

$isConsultorExterno = strtolower((string)$roleName) === 'consultor_externo';
$isDirector = function_exists('isDirector') && isDirector();
$canOpenConsultoriaExterna = $isConsultorExterno || $isDirector || hasPermission('consultoria_externa');
$canManageConsultoriaStages = !$isConsultorExterno;
if (!$canOpenConsultoriaExterna) {
    header('Location: index.php');
    exit;
}

function ce_safe_query_all($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function ce_normalize($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $map = [
        'Ã' => 'a', 'Ã€' => 'a', 'Ãƒ' => 'a', 'Ã‚' => 'a', 'Ã„' => 'a',
        'Ã¡' => 'a', 'Ã ' => 'a', 'Ã£' => 'a', 'Ã¢' => 'a', 'Ã¤' => 'a',
        'Ã‰' => 'e', 'Ãˆ' => 'e', 'ÃŠ' => 'e', 'Ã‹' => 'e',
        'Ã©' => 'e', 'Ã¨' => 'e', 'Ãª' => 'e', 'Ã«' => 'e',
        'Ã' => 'i', 'ÃŒ' => 'i', 'ÃŽ' => 'i', 'Ã' => 'i',
        'Ã­' => 'i', 'Ã¬' => 'i', 'Ã®' => 'i', 'Ã¯' => 'i',
        'Ã“' => 'o', 'Ã’' => 'o', 'Ã•' => 'o', 'Ã”' => 'o', 'Ã–' => 'o',
        'Ã³' => 'o', 'Ã²' => 'o', 'Ãµ' => 'o', 'Ã´' => 'o', 'Ã¶' => 'o',
        'Ãš' => 'u', 'Ã™' => 'u', 'Ã›' => 'u', 'Ãœ' => 'u',
        'Ãº' => 'u', 'Ã¹' => 'u', 'Ã»' => 'u', 'Ã¼' => 'u',
        'Ã‡' => 'c', 'Ã§' => 'c'
    ];

    return strtolower(strtr($value, $map));
}

function ce_money($value) {
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function ce_date($value) {
    if (empty($value)) {
        return '--';
    }

    $ts = strtotime((string) $value);
    if ($ts === false) {
        return '--';
    }

    return date('d/m', $ts);
}

$loggedUserId = (int) $_SESSION['user_id'];
$requestedConsultorId = isset($_GET['consultor_id']) ? (int) $_GET['consultor_id'] : 0;
$userId = $loggedUserId;
$displayName = trim((string) ($_SESSION['username'] ?? 'Consultor Externo'));

if ($requestedConsultorId > 0) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND LOWER(COALESCE(r.name, '')) = 'consultor_externo'
         LIMIT 1
    ");
    $stmt->execute([$requestedConsultorId]);
    $requestedConsultor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($requestedConsultor) {
        $userId = (int) $requestedConsultor['id'];
        $displayName = trim((string) ($requestedConsultor['username'] ?? $displayName));
    }
}

$apiBase = 'includes/consultoria_externa_api.php';
$stagesApiBase = 'includes/consultoria_externa_stages_api.php';
$apiConsultorId = $userId;

ce_ensure_stage_tables($pdo);
$stageRows = ce_list_stages($pdo, $userId);

try {
    $itemColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultoria_externa_itens'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $itemColumns = [];
}

$hasItemDeleted = in_array('deleted', $itemColumns, true);
$itemWhere = $hasItemDeleted ? 'c.deleted = 0' : '1 = 1';

$consultorRows = ce_safe_query_all(
    $pdo,
    "SELECT
        c.id,
        c.client_name,
        c.phone,
        c.cidade,
        c.source,
        c.status,
        c.value,
        c.notes,
        c.stage_key,
        c.stage_id,
        c.exported_to_internal_queue,
        c.created_at
     FROM consultoria_externa_itens c
     WHERE c.user_id = ? AND {$itemWhere}
     ORDER BY c.created_at DESC, c.id DESC",
    [$userId]
);

$stageMeta = [];
foreach ($stageRows as $stage) {
    $stageId = (int) $stage['id'];
    $stageMeta[$stageId] = [
        'id' => $stageId,
        'label' => (string) $stage['name'],
        'summary' => (string) $stage['name'],
        'icon' => (string) ($stage['icon'] ?: 'fa-layer-group'),
        'accent' => (string) ($stage['color'] ?: '#6c757d'),
        'card_color' => (string) ($stage['card_color'] ?: '#ffffff'),
        'is_initial' => (int) ($stage['is_initial'] ?? 0),
        'export_to_internal_queue' => (int) ($stage['export_to_internal_queue'] ?? 0),
        'next_stage_id' => isset($stage['next_stage_id']) ? (int) $stage['next_stage_id'] : 0,
    ];
}

$groupedCards = [];
foreach (array_keys($stageMeta) as $stageKey) {
    $groupedCards[$stageKey] = [];
}

foreach ($consultorRows as $item) {
    $stageKey = ce_resolve_stage_id($pdo, $userId, $item['stage_id'] ?? null, $item['stage_key'] ?? null);
    if (!$stageKey || !isset($groupedCards[$stageKey])) {
        $stageKey = array_key_first($stageMeta);
    }
    $groupedCards[$stageKey][] = [
        'type' => 'consultoria',
        'id' => (int) $item['id'],
        'title' => (string) ($item['client_name'] ?? 'Registro sem nome'),
        'status' => (string) ($item['status'] ?? 'Sem status'),
        'value' => (float) ($item['value'] ?? 0),
        'phone' => (string) ($item['phone'] ?? ''),
        'cidade' => (string) ($item['cidade'] ?? ''),
        'source' => (string) ($item['source'] ?? 'Consultoria externa'),
        'created_at' => (string) ($item['created_at'] ?? ''),
        'owner' => $displayName,
        'notes' => (string) ($item['notes'] ?? ''),
        'exported' => (int) ($item['exported_to_internal_queue'] ?? 0),
        'link' => '#',
    ];
}

foreach ($groupedCards as &$cards) {
    usort($cards, function ($left, $right) {
        return strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''));
    });
}
unset($cards);

$summaryCounts = [];
foreach ($stageMeta as $stageKey => $meta) {
    $summaryCounts[$stageKey] = count($groupedCards[$stageKey]);
}

$totalConsultoriaItems = count($consultorRows);
$totalConsultoriaValue = array_reduce($consultorRows, function ($carry, $item) {
    return $carry + (float) ($item['value'] ?? 0);
}, 0.0);
$exportedConsultoriaItems = count(array_filter($consultorRows, function ($item) {
    return (int) ($item['exported_to_internal_queue'] ?? 0) === 1;
}));
$closedStatusTerms = ['fechado', 'fechada', 'contrato', 'ganho', 'ganha', 'aprovado', 'aprovada', 'finalizado', 'finalizada'];
$lostStatusTerms = ['perdido', 'perdida', 'cancelado', 'cancelada'];
$closedConsultoriaItems = 0;
$inactiveConsultoriaItems = 0;
foreach ($consultorRows as $item) {
    $normalizedStatus = ce_normalize($item['status'] ?? '');
    foreach ($closedStatusTerms as $term) {
        if ($normalizedStatus !== '' && strpos($normalizedStatus, $term) !== false) {
            $closedConsultoriaItems++;
            $inactiveConsultoriaItems++;
            continue 2;
        }
    }
    foreach ($lostStatusTerms as $term) {
        if ($normalizedStatus !== '' && strpos($normalizedStatus, $term) !== false) {
            $inactiveConsultoriaItems++;
            continue 2;
        }
    }
}
$activeConsultoriaItems = max(0, $totalConsultoriaItems - $inactiveConsultoriaItems);
$consultoriaConversionRate = $totalConsultoriaItems > 0 ? ($closedConsultoriaItems / $totalConsultoriaItems) * 100 : 0;

$defaultStatusOptions = ['Novo', 'Em atendimento', 'Orçamento enviado', 'Negociação', 'Fechado', 'Perdido'];
$statusOptions = $defaultStatusOptions;
$statusRows = ce_safe_query_all($pdo, "SELECT name FROM lead_statuses WHERE COALESCE(name, '') <> '' ORDER BY position ASC, id ASC");
foreach ($statusRows as $row) {
    $statusOptions[] = (string) ($row['name'] ?? '');
}
foreach ($consultorRows as $item) {
    $statusOptions[] = (string) ($item['status'] ?? '');
}
$statusOptions = array_values(array_unique(array_filter(array_map('trim', $statusOptions))));

$paymentMethodOptions = [];
try {
    $paymentTable = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods'")->fetchColumn();
    if ($paymentTable) {
        $paymentCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods'")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('code', $paymentCols, true)) {
            $paymentMethodOptions = ce_safe_query_all($pdo, "SELECT id, name FROM payment_methods WHERE code = 1 ORDER BY name ASC");
        } elseif (in_array('scope', $paymentCols, true)) {
            $paymentMethodOptions = ce_safe_query_all($pdo, "SELECT id, name FROM payment_methods WHERE scope = 'leads' OR scope IS NULL OR scope = '' ORDER BY name ASC");
        } else {
            $paymentMethodOptions = ce_safe_query_all($pdo, "SELECT id, name FROM payment_methods ORDER BY name ASC");
        }
    }
} catch (Exception $e) {
    $paymentMethodOptions = [];
}

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4 main-content-scroll">
        <style>
            .ce-shell {
                min-height: calc(100vh - 92px);
                background: linear-gradient(180deg, #f5f7fb 0%, #edf2f7 100%);
                border-radius: 24px;
                padding: 1.25rem;
            }
            .ce-page-header {
                display: grid;
                grid-template-columns: minmax(220px, auto) minmax(0, 1fr);
                align-items: center;
                gap: 1rem;
                margin-bottom: 1rem;
            }
            .ce-page-title {
                font-size: 1.35rem;
                font-weight: 700;
                color: #16324f;
                margin: 0;
            }
            .ce-toolbar {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                gap: 1rem;
                margin-bottom: 1.25rem;
            }
            .ce-toolbar h1 {
                font-size: 1.65rem;
                font-weight: 700;
                color: #16324f;
                margin: 0;
            }
            .ce-toolbar-subtitle {
                color: #64748b;
                margin-top: .35rem;
                font-size: .94rem;
            }
            .ce-actions {
                display: grid;
                grid-template-columns: minmax(280px, 1fr) auto auto auto;
                gap: .75rem;
                align-items: center;
                justify-content: stretch;
                min-width: 0;
            }
            .ce-search {
                width: 100%;
                min-width: 0;
                border-radius: 12px;
                border: 1px solid #d7dfeb;
                padding: .8rem .95rem;
                background: #fff;
            }
            .ce-filter-btn,
            .ce-create-btn {
                border-radius: 12px;
                padding: .78rem 1rem;
                font-weight: 600;
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
                white-space: nowrap;
            }
            .ce-create-btn {
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                border-color: #1d4ed8;
            }
            .ce-filters-panel {
                display: none;
                margin-bottom: 1rem;
                background: rgba(255,255,255,0.88);
                border: 1px solid #d7dfeb;
                border-radius: 18px;
                padding: 1rem;
                backdrop-filter: blur(10px);
            }
            .ce-filters-panel.is-open {
                display: block;
            }
            .ce-filters-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                margin-bottom: .85rem;
            }
            .ce-filters-title {
                font-size: .86rem;
                font-weight: 700;
                color: #16324f;
            }
            .ce-filters-subtitle {
                font-size: .78rem;
                color: #64748b;
            }
            .ce-kpis {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
            .ce-kpi {
                background: #fff;
                border-radius: 16px;
                padding: 1rem 1.1rem;
                box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
                border-top: 3px solid var(--accent, #cbd5e1);
            }
            .ce-kpi-general {
                border-top-width: 4px;
            }
            .ce-kpi-stage {
                background: rgba(255, 255, 255, 0.78);
            }
            .ce-kpi-label {
                font-size: .76rem;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: .04em;
                margin-bottom: .35rem;
            }
            .ce-kpi-value {
                font-size: 1.7rem;
                font-weight: 700;
                color: #0f172a;
                line-height: 1;
            }
            .ce-kpi-money {
                font-size: 1.32rem;
                line-height: 1.15;
                overflow-wrap: anywhere;
            }
            .ce-board {
                display: flex;
                gap: 1rem;
                align-items: flex-start;
                overflow-x: auto;
                overflow-y: hidden;
                padding: .25rem 0 .75rem;
                scrollbar-gutter: stable;
                cursor: grab;
            }
            .ce-board::-webkit-scrollbar {
                height: 12px;
            }
            .ce-board::-webkit-scrollbar-track {
                background: #e9eef5;
                border-radius: 999px;
            }
            .ce-board::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 999px;
            }
            .ce-board::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
            .ce-kanban-top-scrollbar {
                overflow-x: auto;
                overflow-y: hidden;
                height: 12px;
                margin: 0 2px 4px 2px;
            }
            .ce-kanban-top-scrollbar::-webkit-scrollbar {
                height: 12px;
            }
            .ce-kanban-top-scrollbar::-webkit-scrollbar-track {
                background: #e9eef5;
                border-radius: 6px;
            }
            .ce-kanban-top-scrollbar::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 6px;
            }
            .ce-kanban-top-scrollbar::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
            #ceTopScrollbarContent {
                height: 1px;
            }
            .ce-board.is-dragging {
                cursor: grabbing;
                user-select: none;
            }
            .ce-view-toolbar {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: .5rem;
                flex-wrap: wrap;
                margin-bottom: .75rem;
            }
            .ce-icon-btn {
                width: 34px;
                height: 34px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
            }
            .ce-list-wrap {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
                overflow: hidden;
            }
            .ce-list-wrap .table {
                margin-bottom: 0;
            }
            .ce-list-wrap th {
                font-size: .74rem;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: #64748b;
                white-space: nowrap;
                position: relative;
            }
            .ce-list-wrap td {
                vertical-align: middle;
            }
            .ce-th-inner {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
            }
            .ce-table-filter-btn {
                width: 22px;
                height: 22px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 0;
                border-radius: 6px;
                background: transparent;
                color: #94a3b8;
                padding: 0;
            }
            .ce-table-filter-btn:hover,
            .ce-table-filter-btn.active {
                background: rgba(37, 99, 235, 0.08);
                color: #2563eb;
            }
            .ce-table-filter-btn i {
                font-size: .7rem;
            }
            .ce-table-filter-popup {
                position: fixed;
                z-index: 1085;
                width: min(280px, calc(100vw - 24px));
                background: #fff;
                border: 1px solid #d7dfeb;
                border-radius: 12px;
                box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
                padding: .75rem;
                text-transform: none;
                letter-spacing: 0;
            }
            .ce-table-filter-popup-title {
                font-size: .78rem;
                font-weight: 700;
                color: #16324f;
                margin-bottom: .5rem;
            }
            .ce-table-filter-options {
                max-height: 190px;
                overflow: auto;
                display: grid;
                gap: .35rem;
                padding-right: .15rem;
            }
            .ce-table-filter-option {
                display: flex;
                align-items: center;
                gap: .45rem;
                font-size: .82rem;
                color: #334155;
            }
            .ce-table-filter-actions {
                display: flex;
                justify-content: flex-end;
                gap: .4rem;
                margin-top: .75rem;
            }
            .ce-column {
                flex: 0 0 320px;
                min-width: 320px;
                background: #d1d5db;
                border-radius: 18px;
                padding: .9rem;
                min-height: 420px;
            }
            .ce-column-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                padding: .15rem .2rem .9rem;
            }
            .ce-column-title {
                display: flex;
                align-items: center;
                gap: .6rem;
                font-size: .92rem;
                font-weight: 700;
                color: #16324f;
                text-transform: uppercase;
            }
            .ce-column-title i {
                color: var(--accent, #64748b);
            }
            .ce-column-count {
                min-width: 26px;
                height: 24px;
                padding: 0 .5rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                background: rgba(148, 163, 184, 0.26);
                color: #475569;
                font-size: .78rem;
                font-weight: 700;
            }
            .ce-card-list {
                display: flex;
                flex-direction: column;
                gap: .9rem;
            }
            .ce-card {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
                border: 1px solid #e2e8f0;
                border-top: 3px solid var(--accent, #cbd5e1);
                padding: 1rem;
                cursor: grab;
                transition: opacity .15s ease, transform .15s ease, box-shadow .15s ease;
            }
            .ce-card:active {
                cursor: grabbing;
            }
            .ce-column.is-drop-target {
                outline: 2px dashed var(--accent, #64748b);
                outline-offset: -6px;
            }
            .ce-card-top {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: .75rem;
                margin-bottom: .85rem;
            }
            .ce-card-title {
                font-size: 1.02rem;
                font-weight: 700;
                color: #0f172a;
                margin: 0;
            }
            .ce-card-link {
                color: #64748b;
                text-decoration: none;
            }
            .ce-card-link:hover {
                color: #1d4ed8;
            }
            .ce-meta {
                display: grid;
                gap: .4rem;
                margin-bottom: .8rem;
            }
            .ce-meta-row {
                display: flex;
                align-items: center;
                gap: .45rem;
                color: #64748b;
                font-size: .82rem;
            }
            .ce-value {
                color: #16a34a;
                font-size: 1rem;
                font-weight: 700;
                margin-bottom: .65rem;
            }
            .ce-card-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: .65rem;
                border-top: 1px solid #e2e8f0;
                padding-top: .75rem;
                flex-wrap: wrap;
            }
            .ce-pill {
                border-radius: 999px;
                padding: .25rem .55rem;
                font-size: .68rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .ce-pill-type {
                background: #eff6ff;
                color: #1d4ed8;
            }
            .ce-pill-status {
                background: #f8fafc;
                color: #475569;
            }
            .ce-empty {
                background: rgba(255,255,255,0.62);
                border: 1px dashed #cbd5e1;
                border-radius: 16px;
                padding: 1rem;
                color: #64748b;
                text-align: center;
                font-size: .92rem;
            }
            .ce-stage-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                padding: .75rem;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #f8fafc;
                margin-bottom: .65rem;
            }
            .ce-stage-row-main {
                display: flex;
                align-items: center;
                gap: .65rem;
                min-width: 0;
            }
            .ce-stage-dot {
                width: 12px;
                height: 12px;
                border-radius: 999px;
                flex-shrink: 0;
            }
            .ce-stage-name {
                font-weight: 700;
                color: #0f172a;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .ce-stage-badges {
                display: flex;
                gap: .35rem;
                flex-wrap: wrap;
                margin-top: .2rem;
            }
            .ce-lead-modal-content {
                border-radius: 14px;
                overflow: hidden;
                box-shadow: 0 24px 60px rgba(11, 26, 49, 0.12);
            }
            .ce-lead-modal-content .modal-header {
                background: transparent;
                padding: 18px 22px;
                border-bottom: none;
                font-weight: 700;
            }
            .ce-lead-modal-content .modal-body {
                padding: 20px;
                background: transparent;
            }
            .ce-lead-modal-content .modal-footer {
                padding: 14px 22px;
                border-top: none;
            }
            .ce-lead-modal-content .form-control,
            .ce-lead-modal-content .form-select {
                border-radius: 10px;
                padding: 10px 12px;
                border: 1px solid rgba(11, 26, 49, 0.08);
                box-shadow: none;
            }
            .ce-lead-modal-content label {
                font-weight: 600;
                font-size: .9rem;
            }
            body.theme-dark .ce-shell {
                background: linear-gradient(180deg, rgba(15,23,42,0.96) 0%, rgba(15,23,42,0.88) 100%);
            }
            body.theme-dark .ce-kpi,
            body.theme-dark .ce-card,
            body.theme-dark .ce-filters-panel,
            body.theme-dark .ce-list-wrap {
                background: rgba(15, 23, 42, 0.82);
                border-color: rgba(148, 163, 184, 0.18);
                color: #e2e8f0;
            }
            body.theme-dark .ce-column {
                background: rgba(30, 41, 59, 0.68);
                border-color: rgba(148, 163, 184, 0.22);
            }
            body.theme-dark .ce-card {
                background: rgba(15, 23, 42, 0.90) !important;
                border-color: rgba(148, 163, 184, 0.18) !important;
                border-top-color: rgba(96, 165, 250, 0.90) !important;
                box-shadow: 0 14px 36px rgba(0, 0, 0, 0.28);
            }
            body.theme-dark .ce-toolbar h1,
            body.theme-dark .ce-page-title,
            body.theme-dark .ce-filters-title,
            body.theme-dark .ce-card-title,
            body.theme-dark .ce-kpi-value,
            body.theme-dark .ce-column-title {
                color: #f8fafc;
            }
            body.theme-dark .ce-toolbar-subtitle,
            body.theme-dark .ce-filters-subtitle,
            body.theme-dark .ce-meta-row,
            body.theme-dark .ce-kpi-label,
            body.theme-dark .ce-empty {
                color: #94a3b8;
            }
            body.theme-dark .ce-card-footer {
                border-top-color: rgba(148, 163, 184, 0.12);
            }
            body.theme-dark .ce-table-filter-popup {
                background: #0f172a;
                border-color: rgba(148, 163, 184, 0.22);
                color: #e2e8f0;
            }
            body.theme-dark .ce-table-filter-popup-title,
            body.theme-dark .ce-table-filter-option {
                color: #e2e8f0;
            }
            body.ce-kanban-only .ce-page-header,
            body.ce-kanban-only .ce-toolbar,
            body.ce-kanban-only .ce-filters-panel,
            body.ce-kanban-only .ce-kpis,
            body.ce-kanban-only .ce-list-wrap {
                display: none !important;
            }
            body.ce-kanban-only .main-content-scroll {
                padding: .5rem !important;
            }
            body.ce-kanban-only .ce-shell {
                min-height: calc(100vh - 1rem);
                border-radius: 14px;
                padding: .75rem;
            }
            body.ce-kanban-only .ce-board {
                min-height: calc(100vh - 105px);
            }
            body.ce-kanban-compact .ce-board {
                gap: .5rem;
            }
            body.ce-kanban-compact .ce-column {
                flex-basis: 240px;
                min-width: 220px;
                padding: .55rem;
                border-radius: 12px;
            }
            body.ce-kanban-compact .ce-card {
                padding: .7rem;
                border-radius: 10px;
            }
            body.ce-kanban-compact .ce-card-title {
                font-size: .9rem;
            }
            body.ce-kanban-compact .ce-meta-row,
            body.ce-kanban-compact .ce-card-footer {
                font-size: .74rem;
            }
            @media (max-width: 1200px) {
                .ce-page-header {
                    grid-template-columns: 1fr;
                    align-items: stretch;
                }
                .ce-actions {
                    grid-template-columns: minmax(260px, 1fr) repeat(3, auto);
                }
                .ce-kpis,
                .ce-board {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
            @media (max-width: 991px) {
                .ce-actions {
                    grid-template-columns: 1fr 1fr;
                }
                .ce-search {
                    grid-column: 1 / -1;
                }
                .ce-create-btn {
                    grid-column: span 1;
                }
            }
            @media (max-width: 767px) {
                .ce-shell {
                    min-height: calc(100vh - 64px);
                    border-radius: 14px;
                    padding: .75rem;
                }
                .ce-page-header {
                    gap: .75rem;
                }
                .ce-page-title {
                    font-size: 1.18rem;
                }
                .ce-toolbar {
                    gap: .75rem;
                    margin-bottom: .9rem;
                }
                .ce-toolbar h1 {
                    font-size: 1.22rem;
                    line-height: 1.2;
                }
                .ce-toolbar-subtitle {
                    font-size: .82rem;
                    line-height: 1.35;
                }
                .ce-kpis,
                .ce-board {
                    grid-template-columns: 1fr;
                }
                .ce-kpis {
                    display: flex;
                    overflow-x: auto;
                    gap: .7rem;
                    margin-bottom: 1rem;
                    padding-bottom: .35rem;
                }
                .ce-kpi {
                    flex: 0 0 155px;
                    border-radius: 12px;
                    padding: .85rem;
                }
                .ce-kpi-value {
                    font-size: 1.28rem;
                    overflow-wrap: anywhere;
                }
                .ce-board {
                    display: flex;
                    overflow-x: auto;
                    gap: .75rem;
                    padding-bottom: .85rem;
                    scroll-snap-type: x proximity;
                }
                .ce-column {
                    flex: 0 0 min(86vw, 310px);
                    min-width: min(86vw, 310px);
                    min-height: 360px;
                    border-radius: 14px;
                    padding: .75rem;
                    scroll-snap-align: start;
                }
                .ce-column-header {
                    gap: .5rem;
                    padding-bottom: .65rem;
                }
                .ce-column-title {
                    font-size: .82rem;
                    min-width: 0;
                }
                .ce-column-title span {
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .ce-card-list {
                    gap: .7rem;
                }
                .ce-card {
                    border-radius: 12px;
                    padding: .85rem;
                }
                .ce-card-title {
                    font-size: .96rem;
                    overflow-wrap: anywhere;
                }
                .ce-actions {
                    width: 100%;
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: .5rem;
                }
                .ce-search {
                    width: 100%;
                    min-width: 0;
                    padding: .65rem .8rem;
                }
                .ce-create-btn,
                .ce-filter-btn {
                    width: 100%;
                    min-width: 0;
                    padding: .65rem .7rem;
                    white-space: normal;
                    line-height: 1.15;
                }
                .ce-filters-panel {
                    border-radius: 14px;
                    padding: .75rem;
                }
                .ce-stage-row {
                    align-items: flex-start;
                    flex-direction: column;
                }
                .ce-lead-modal-content .modal-header,
                .ce-lead-modal-content .modal-body,
                .ce-lead-modal-content .modal-footer {
                    padding-left: 14px;
                    padding-right: 14px;
                }
            }
        </style>

        <div class="ce-shell">
            <div class="ce-page-header">
                <h1 class="ce-page-title">Consultoria Externa</h1>
                <div class="ce-actions">
                    <input id="ceSearchInput" type="search" class="form-control form-control-sm ce-search" placeholder="Buscar por cliente, telefone, cidade ou fonte...">
                    <button id="ceToggleFilters" type="button" class="btn btn-sm btn-outline-primary ce-filter-btn" aria-expanded="false" aria-controls="ceFiltersPanel">
                        Filtros
                    </button>
                    <?php if ($canManageConsultoriaStages): ?>
                        <button id="ceOpenStagesModal" type="button" class="btn btn-sm btn-outline-primary ce-filter-btn">
                            <i class="fa-solid fa-sliders me-2"></i>Configurar colunas 
                        </button>
                    <?php endif; ?>
                    <button id="ceOpenLeadModal" type="button" class="btn btn-sm btn-primary ce-create-btn">
                        <i class="fa-solid fa-circle-plus me-2"></i>Cadastrar Visita / Lead
                    </button>
                </div>
            </div>

            <div id="ceFiltersPanel" class="ce-filters-panel">
                <div class="ce-filters-header">
                    <div>
                        <div class="ce-filters-title">Filtros avançados</div>
                        <div class="ce-filters-subtitle">Refine os registros de consultoria sem sair do painel.</div>
                    </div>
                    <button id="ceClearFilters" class="btn btn-sm btn-light" type="button">Limpar</button>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="ceTypeFilter" class="form-label small text-muted">Tipo</label>
                        <select id="ceTypeFilter" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <option value="lead">Lead</option>
                            <option value="projeto">Projeto</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="ceStageFilter" class="form-label small text-muted">Coluna</label>
                        <select id="ceStageFilter" class="form-select form-select-sm">
                            <option value="">Todas</option>
                            <?php foreach ($stageMeta as $stageKey => $meta): ?>
                                <option value="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="ceCityFilter" class="form-label small text-muted">Cidade</label>
                        <input id="ceCityFilter" type="text" class="form-control form-control-sm" placeholder="Filtrar por cidade">
                    </div>
                </div>
            </div>

            <div class="ce-toolbar">
                <div>
                    <h1>Painel de Consultores Externos</h1>
                    <div class="ce-toolbar-subtitle">Visão rápida das visitas, orçamentos, financiamentos e contratos de <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>.</div>
                </div>
            </div>

            <div class="ce-kpis">
                <div class="ce-kpi ce-kpi-general" style="--accent: #2563eb;">
                    <div class="ce-kpi-label">Registros totais</div>
                    <div class="ce-kpi-value" id="ceKpiTotal"><?php echo str_pad((string) $totalConsultoriaItems, 2, '0', STR_PAD_LEFT); ?></div>
                </div>
                <div class="ce-kpi ce-kpi-general" style="--accent: #16a34a;">
                    <div class="ce-kpi-label">Valor no pipeline</div>
                    <div class="ce-kpi-value ce-kpi-money" id="ceKpiValue"><?php echo ce_money($totalConsultoriaValue); ?></div>
                </div>
                <div class="ce-kpi ce-kpi-general" style="--accent: #f59e0b;">
                    <div class="ce-kpi-label">Registros ativos</div>
                    <div class="ce-kpi-value" id="ceKpiActive"><?php echo str_pad((string) $activeConsultoriaItems, 2, '0', STR_PAD_LEFT); ?></div>
                </div>
                <div class="ce-kpi ce-kpi-general" style="--accent: #7c3aed;">
                    <div class="ce-kpi-label">Taxa de fechamento</div>
                    <div class="ce-kpi-value" id="ceKpiConversion"><?php echo number_format($consultoriaConversionRate, 1, ',', '.'); ?>%</div>
                </div>
                <div class="ce-kpi ce-kpi-general" style="--accent: #0f766e;">
                    <div class="ce-kpi-label">Enviados para fila</div>
                    <div class="ce-kpi-value" id="ceKpiExported"><?php echo str_pad((string) $exportedConsultoriaItems, 2, '0', STR_PAD_LEFT); ?></div>
                </div>
            </div>

            <div class="ce-view-toolbar">
                <button id="ceToggleViewBtn" class="btn btn-sm btn-outline-secondary" type="button" title="Alternar visualização Kanban / Tabela"><i class="fa fa-columns"></i></button>
                <button id="ceKanbanCompactBtn" class="btn btn-sm btn-outline-secondary ce-icon-btn" type="button" title="Compactar Kanban"><i class="fa fa-compress" id="ceKanbanCompactIcon" aria-hidden="true"></i></button>
                <button id="ceKanbanOnlyBtn" class="btn btn-sm btn-outline-secondary ce-icon-btn" type="button" title="Mostrar somente Kanban"><i class="fa fa-expand-arrows-alt" id="ceKanbanOnlyIcon" aria-hidden="true"></i></button>
            </div>

            <div id="ceTopScrollbar" class="ce-kanban-top-scrollbar">
                <div id="ceTopScrollbarContent"></div>
            </div>

            <div class="ce-board" id="ceBoard">
                <?php foreach ($stageMeta as $stageKey => $meta): ?>
                    <section class="ce-column" style="--accent: <?php echo htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8'); ?>;" data-stage-column="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="ce-column-header">
                            <div class="ce-column-title">
                                <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                <span><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="ce-column-count" data-count-for="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo count($groupedCards[$stageKey]); ?></span>
                        </div>
                        <?php if (!empty($meta['export_to_internal_queue'])): ?>
                            <div class="mb-2"><span class="badge bg-success">Exporta para fila interna</span></div>
                        <?php endif; ?>
                        <div class="ce-card-list" data-card-list="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (empty($groupedCards[$stageKey])): ?>
                                <div class="ce-empty">Nenhum item nesta etapa.</div>
                            <?php else: ?>
                                <?php foreach ($groupedCards[$stageKey] as $card): ?>
                                    <?php
                                        $searchBlob = ce_normalize(implode(' ', [
                                            $card['title'],
                                            $card['status'],
                                            $card['cidade'],
                                            $card['source'],
                                            $card['owner'],
                                            $card['phone'],
                                        ]));
                                    ?>
                                    <article
                                        class="ce-card"
                                        data-card
                                        draggable="true"
                                        data-id="<?php echo (int) $card['id']; ?>"
                                        data-stage="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-type="<?php echo htmlspecialchars($card['type'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-city="<?php echo htmlspecialchars(ce_normalize($card['cidade']), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-title="<?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-status-label="<?php echo htmlspecialchars($card['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-city-label="<?php echo htmlspecialchars($card['cidade'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-phone="<?php echo htmlspecialchars($card['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-source="<?php echo htmlspecialchars($card['source'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-owner="<?php echo htmlspecialchars($card['owner'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-value="<?php echo htmlspecialchars((string) $card['value'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-created="<?php echo htmlspecialchars($card['created_at'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-stage-label="<?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-exported="<?php echo (int) $card['exported']; ?>"
                                        style="--accent: <?php echo htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8'); ?>; background: <?php echo htmlspecialchars($meta['card_color'], ENT_QUOTES, 'UTF-8'); ?>;"
                                    >
                                        <div class="ce-card-top">
                                            <h2 class="ce-card-title"><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                            <div class="d-flex gap-2 align-items-center">
                                                <?php if ($card['type'] === 'consultoria'): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-link p-0 ce-card-link"
                                                        data-ce-edit-item="<?php echo (int) $card['id']; ?>"
                                                        title="Editar registro"
                                                    >
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a class="ce-card-link" href="<?php echo htmlspecialchars($card['link'], ENT_QUOTES, 'UTF-8'); ?>" title="Abrir mÃ³dulo relacionado">
                                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="ce-meta">
                                            <div class="ce-meta-row"><i class="fa-regular fa-clock"></i><span>Criado em: <?php echo htmlspecialchars(ce_date($card['created_at']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                                            <?php if (!empty($card['phone'])): ?>
                                                <div class="ce-meta-row"><i class="fa-solid fa-phone"></i><span><?php echo htmlspecialchars($card['phone'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                            <?php endif; ?>
                                            <div class="ce-meta-row"><i class="fa-solid fa-user-tie"></i><span>Consultor: <?php echo htmlspecialchars($card['owner'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                        </div>
                                        <div class="ce-value"><?php echo ce_money($card['value']); ?></div>
                                        <div class="ce-card-footer">
                                            <span class="ce-pill ce-pill-type">consultoria</span>
                                            <span class="ce-pill ce-pill-status"><?php echo htmlspecialchars($card['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($card['exported'])): ?>
                                                <span class="badge bg-success">Enviado</span>
                                            <?php endif; ?>
                                            <span class="small text-muted"><?php echo htmlspecialchars($card['source'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div id="ceListWrap" class="ce-list-wrap d-none">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th><span class="ce-th-inner">Cliente <button type="button" class="ce-table-filter-btn" data-ce-table-filter="title" title="Filtrar Cliente"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Coluna <button type="button" class="ce-table-filter-btn" data-ce-table-filter="stageLabel" title="Filtrar Coluna"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Status <button type="button" class="ce-table-filter-btn" data-ce-table-filter="statusLabel" title="Filtrar Status"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Cidade <button type="button" class="ce-table-filter-btn" data-ce-table-filter="cityLabel" title="Filtrar Cidade"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Telefone <button type="button" class="ce-table-filter-btn" data-ce-table-filter="phone" title="Filtrar Telefone"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Valor <button type="button" class="ce-table-filter-btn" data-ce-table-filter="value" title="Filtrar Valor"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Fonte <button type="button" class="ce-table-filter-btn" data-ce-table-filter="source" title="Filtrar Fonte"><i class="fa fa-filter"></i></button></span></th>
                                <th><span class="ce-th-inner">Criado <button type="button" class="ce-table-filter-btn" data-ce-table-filter="created" title="Filtrar Criado"><i class="fa fa-filter"></i></button></span></th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="ceTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="modal fade" id="ceLeadModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-light border-bottom">
                        <h5 class="modal-title d-flex align-items-center gap-2" id="ceLeadModalTitle">
                            <i class="fa-regular fa-user-plus text-primary"></i> <span>Cadastrar Registro</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <form id="ceLeadForm" enctype="multipart/form-data">
                            <input type="hidden" id="ceLeadId">
                            <div class="container-fluid">
                                <div class="row">
                                    <div class="col-lg-7">
                                        <div class="card mb-3">
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Nome</label>
                                                    <input id="ceLeadName" class="form-control form-control-lg" required placeholder="Nome completo ou empresa">
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Email</label>
                                                        <input id="ceLeadEmail" class="form-control" type="email" placeholder="exemplo@dominio.com">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Telefone</label>
                                                        <input id="ceLeadPhone" class="form-control" type="tel" placeholder="(00) 90000-0000">
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">CPF / CNPJ</label>
                                                        <input id="ceLeadCpfCnpj" class="form-control" placeholder="000.000.000-00 ou 00.000.000/0000-00">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Cidade</label>
                                                        <div class="position-relative">
                                                            <input id="ceLeadCity" class="form-control" placeholder="Cidade">
                                                            <div id="ceLeadCitySuggestions" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index:1080; max-height: 240px; overflow-y: auto;"></div>
                                                        </div>
                                                        <div class="form-text d-flex justify-content-between align-items-center">
                                                            <span>Digite a cidade para ver sugestões com UF.</span>
                                                            <span id="ceLeadCityState" class="badge bg-light text-dark border">UF</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Anexar Arquivos</label>
                                                    <div id="ceAnexosDropzone" style="border:2px dashed #adb5bd;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;" onclick="document.getElementById('ceLeadAnexos').click()">
                                                        <i class="fa fa-cloud-upload fa-2x text-muted mb-2"></i>
                                                        <div class="text-muted small">Arraste e solte arquivos aqui ou <span style="color:#0d6efd;text-decoration:underline;">clique para selecionar</span></div>
                                                        <div class="form-text mt-1">PDF, DOC, DOCX, CSV, XLS, XLSX, XML, TXT, RTF, ODT, PPTX, JPG, JPEG, PNG, GIF, BMP, WEBP, JFIF (max 10MB cada)</div>
                                                        <div id="ceAnexosFileNames" class="mt-2 small text-start"></div>
                                                    </div>
                                                    <input id="ceLeadAnexos" type="file" multiple accept=".pdf,.doc,.docx,.csv,.xls,.xlsx,.xml,.txt,.rtf,.odt,.pptx,.jpg,.jpeg,.png,.gif,.bmp,.webp,.jfif" style="display:none">
                                                    <div class="d-flex justify-content-end mt-2">
                                                        <button id="ceUploadAnexosNow" type="button" class="btn btn-sm btn-outline-primary"><i class="fa fa-upload"></i> Enviar</button>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label class="form-label fw-semibold mb-2">Anexos já enviados pelos consultores internos</label>
                                                        <div id="ceLeadAttachments" class="vstack gap-2"></div>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Notas</label>
                                                    <textarea id="ceLeadNotes" class="form-control" rows="4" placeholder="Observações sobre o registro..."></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-5">
                                            <div class="card mb-3">
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Coluna do Kanban</label>
                                                    <select id="ceLeadStageId" class="form-select">
                                                        <?php foreach ($stageMeta as $stageKey => $meta): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Status</label>
                                                    <select id="ceLeadStatus" class="form-select">
                                                        <option value="">-- selecione --</option>
                                                        <?php foreach ($statusOptions as $statusOption): ?>
                                                            <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Último Contato</label>
                                                    <input id="ceLeadUltimoContato" class="form-control" type="date">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Data de Entrada</label>
                                                    <input id="ceLeadCreatedAt" class="form-control" type="date" placeholder="Data de entrada">
                                                    <div class="form-text small">Data de início do registro, pode ser editada.</div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Consumo (R$)</label>
                                                        <input id="ceLeadConsumo" class="form-control" type="number" step="0.01" placeholder="0,00">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Estimativa (kWh)</label>
                                                        <input id="ceLeadEstimativaKwh" class="form-control" type="number" step="0.01" placeholder="0,00">
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Valor de Orçamento</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">R$</span>
                                                        <input id="ceLeadOrcamento" class="form-control currency-mask" type="text" placeholder="0,00">
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Forma de Pagamento</label>
                                                    <select id="ceLeadFormaPagamento" class="form-select">
                                                        <option value="">-- selecione --</option>
                                                        <?php foreach ($paymentMethodOptions as $paymentMethod): ?>
                                                            <option value="<?php echo (int) $paymentMethod['id']; ?>"><?php echo htmlspecialchars((string) $paymentMethod['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text">Selecione a forma de pagamento principal do cliente.</div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Fonte</label>
                                                    <input id="ceLeadSource" class="form-control" value="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                                    <div class="form-text">Preenchido automaticamente com o consultor logado.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button id="ceLeadSaveBtn" type="submit" form="ceLeadForm" class="btn btn-primary"><i class="fa fa-save"></i> Salvar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="ceStagesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0">Configurar colunas</h5>
                            <div class="small text-muted">Defina as etapas do Kanban e quais delas enviam para a futura fila interna.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-5">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <strong>Colunas cadastradas</strong>
                                    <button id="ceNewStageBtn" type="button" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>Nova</button>
                                </div>
                                <div id="ceStagesList"></div>
                            </div>
                            <div class="col-lg-7">
                                <form id="ceStageForm">
                                    <input type="hidden" id="ceStageId" value="">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label for="ceStageName" class="form-label">Nome da coluna</label>
                                            <input id="ceStageName" type="text" class="form-control" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="ceStageIcon" class="form-label">Icone FontAwesome</label>
                                            <input id="ceStageIcon" type="text" class="form-control" placeholder="fa-layer-group">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="ceStageColor" class="form-label">Cor da coluna</label>
                                            <input id="ceStageColor" type="color" class="form-control form-control-color w-100" value="#6c757d">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="ceStageCardColor" class="form-label">Cor dos cards</label>
                                            <input id="ceStageCardColor" type="color" class="form-control form-control-color w-100" value="#ffffff">
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check form-switch mb-2">
                                                <input id="ceStageInitial" class="form-check-input" type="checkbox">
                                                <label for="ceStageInitial" class="form-check-label">Coluna inicial para novos registros</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input id="ceStageExport" class="form-check-input" type="checkbox">
                                                <label for="ceStageExport" class="form-check-label">Enviar para Fila de Demandas Internas ao entrar nesta coluna</label>
                                            </div>
                                            <div class="mt-3">
                                                <label for="ceStageNextStage" class="form-label">Ao concluir a parte interna, mover para</label>
                                                <select id="ceStageNextStage" class="form-select">
                                                    <option value="">NÃ£o mover</option>
                                                    <?php foreach ($stageMeta as $stageKey => $meta): ?>
                                                        <option value="<?php echo htmlspecialchars((string) $stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">Escolha apenas uma coluna de destino.</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 mt-4">
                                        <button id="ceStageSaveBtn" type="submit" class="btn btn-primary">Salvar coluna</button>
                                        <button id="ceStageDeleteBtn" type="button" class="btn btn-outline-danger">Excluir</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const apiBase = <?php echo json_encode($apiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const stagesApiBase = <?php echo json_encode($stagesApiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const apiConsultorId = <?php echo (int) $apiConsultorId; ?>;
                const apiConsultorQuery = apiConsultorId ? `&consultor_id=${encodeURIComponent(apiConsultorId)}` : '';
                let stages = <?php echo json_encode(array_values($stageMeta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const searchInput = document.getElementById('ceSearchInput');
                const typeFilter = document.getElementById('ceTypeFilter');
                const stageFilter = document.getElementById('ceStageFilter');
                const cityFilter = document.getElementById('ceCityFilter');
                const toggleFilters = document.getElementById('ceToggleFilters');
                const filtersPanel = document.getElementById('ceFiltersPanel');
                const clearFiltersBtn = document.getElementById('ceClearFilters');
                const cards = Array.from(document.querySelectorAll('[data-card]'));
                const countBadges = Array.from(document.querySelectorAll('[data-count-for]'));
                const summaryValues = Array.from(document.querySelectorAll('[data-summary-stage]'));
                const kpiTotal = document.getElementById('ceKpiTotal');
                const kpiValue = document.getElementById('ceKpiValue');
                const kpiActive = document.getElementById('ceKpiActive');
                const kpiConversion = document.getElementById('ceKpiConversion');
                const kpiExported = document.getElementById('ceKpiExported');
                const board = document.getElementById('ceBoard');
                const topScrollbar = document.getElementById('ceTopScrollbar');
                const topScrollbarContent = document.getElementById('ceTopScrollbarContent');
                const listWrap = document.getElementById('ceListWrap');
                const tableBody = document.getElementById('ceTableBody');
                const toggleViewBtn = document.getElementById('ceToggleViewBtn');
                const kanbanCompactBtn = document.getElementById('ceKanbanCompactBtn');
                const kanbanCompactIcon = document.getElementById('ceKanbanCompactIcon');
                const kanbanOnlyBtn = document.getElementById('ceKanbanOnlyBtn');
                const kanbanOnlyIcon = document.getElementById('ceKanbanOnlyIcon');
                const tableFilterButtons = Array.from(document.querySelectorAll('[data-ce-table-filter]'));
                const openLeadModalBtn = document.getElementById('ceOpenLeadModal');
                const openStagesModalBtn = document.getElementById('ceOpenStagesModal');
                const leadModalEl = document.getElementById('ceLeadModal');
                const stagesModalEl = document.getElementById('ceStagesModal');
                const leadForm = document.getElementById('ceLeadForm');
                const leadSaveBtn = document.getElementById('ceLeadSaveBtn');
                const leadModalTitle = document.getElementById('ceLeadModalTitle');
                const leadIdInput = document.getElementById('ceLeadId');
                const leadNameInput = document.getElementById('ceLeadName');
                const leadEmailInput = document.getElementById('ceLeadEmail');
                const leadPhoneInput = document.getElementById('ceLeadPhone');
                const leadCpfInput = document.getElementById('ceLeadCpfCnpj');
                const leadCityInput = document.getElementById('ceLeadCity');
                const leadCityState = document.getElementById('ceLeadCityState');
                const leadAnexosInput = document.getElementById('ceLeadAnexos');
                const leadAnexosNames = document.getElementById('ceAnexosFileNames');
                const leadAttachmentsContainer = document.getElementById('ceLeadAttachments');
                const leadSourceInput = document.getElementById('ceLeadSource');
                const leadSourceDisplay = <?php echo json_encode($displayName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const leadStageInput = document.getElementById('ceLeadStageId');
                const leadStatusInput = document.getElementById('ceLeadStatus');
                const leadUltimoContatoInput = document.getElementById('ceLeadUltimoContato');
                const leadCreatedAtInput = document.getElementById('ceLeadCreatedAt');
                const leadConsumoInput = document.getElementById('ceLeadConsumo');
                const leadEstimativaInput = document.getElementById('ceLeadEstimativaKwh');
                const leadValueInput = document.getElementById('ceLeadOrcamento');
                const leadFormaPagamentoInput = document.getElementById('ceLeadFormaPagamento');
                const leadNotesInput = document.getElementById('ceLeadNotes');
                const stagesList = document.getElementById('ceStagesList');
                const stageForm = document.getElementById('ceStageForm');
                const stageIdInput = document.getElementById('ceStageId');
                const stageNameInput = document.getElementById('ceStageName');
                const stageIconInput = document.getElementById('ceStageIcon');
                const stageColorInput = document.getElementById('ceStageColor');
                const stageCardColorInput = document.getElementById('ceStageCardColor');
                const stageInitialInput = document.getElementById('ceStageInitial');
                const stageExportInput = document.getElementById('ceStageExport');
                const stageNextStageInput = document.getElementById('ceStageNextStage');
                const stageDeleteBtn = document.getElementById('ceStageDeleteBtn');
                const newStageBtn = document.getElementById('ceNewStageBtn');
                const tableFilters = {};
                function getModalInstance(modalEl) {
                    if (!modalEl) return null;
                    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                        return window.bootstrap.Modal.getOrCreateInstance
                            ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
                            : new window.bootstrap.Modal(modalEl);
                    }
                    return {
                        show() {
                            modalEl.classList.add('show');
                            modalEl.style.display = 'block';
                            modalEl.removeAttribute('aria-hidden');
                            document.body.classList.add('modal-open');
                        },
                        hide() {
                            modalEl.classList.remove('show');
                            modalEl.style.display = 'none';
                            modalEl.setAttribute('aria-hidden', 'true');
                            document.body.classList.remove('modal-open');
                        }
                    };
                }

                function getLeadModalInstance() {
                    return getModalInstance(leadModalEl);
                }

                function normalize(value) {
                    return (value || '')
                        .toString()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .trim();
                }

                function updateCounts() {
                    countBadges.forEach((badge) => {
                        const stage = badge.dataset.countFor;
                        const visibleCount = cards.filter((card) => card.dataset.stage === stage && card.style.display !== 'none').length;
                        badge.textContent = String(visibleCount);
                    });

                    summaryValues.forEach((summary) => {
                        const stage = summary.dataset.summaryStage;
                        const visibleCount = cards.filter((card) => card.dataset.stage === stage && card.style.display !== 'none').length;
                        summary.textContent = String(visibleCount).padStart(2, '0');
                    });
                    updateGeneralKpis();
                }

                function escapeHtml(value) {
                    return String(value || '').replace(/[&<>"']/g, (char) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[char]));
                }

                function formatMoney(value) {
                    const number = Number(value || 0);
                    return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                }

                function padCount(value) {
                    return String(Number(value || 0)).padStart(2, '0');
                }

                function isClosedStatus(status) {
                    const value = normalize(status);
                    return ['fechado', 'fechada', 'contrato', 'ganho', 'ganha', 'aprovado', 'aprovada', 'finalizado', 'finalizada'].some((term) => value.includes(term));
                }

                function isLostStatus(status) {
                    const value = normalize(status);
                    return ['perdido', 'perdida', 'cancelado', 'cancelada'].some((term) => value.includes(term));
                }

                function formatDate(value) {
                    if (!value) return '--';
                    const date = new Date(String(value).replace(' ', 'T'));
                    if (Number.isNaN(date.getTime())) return '--';
                    return date.toLocaleDateString('pt-BR');
                }

                function getVisibleCards() {
                    return cards.filter((card) => card.style.display !== 'none');
                }

                function updateGeneralKpis() {
                    const visibleCards = getVisibleCards();
                    const total = visibleCards.length;
                    const value = visibleCards.reduce((sum, card) => sum + Number(card.dataset.value || 0), 0);
                    const closed = visibleCards.filter((card) => isClosedStatus(card.dataset.statusLabel || '')).length;
                    const inactive = visibleCards.filter((card) => isClosedStatus(card.dataset.statusLabel || '') || isLostStatus(card.dataset.statusLabel || '')).length;
                    const active = Math.max(0, total - inactive);
                    const exported = visibleCards.filter((card) => String(card.dataset.exported || '0') === '1').length;

                    if (kpiTotal) kpiTotal.textContent = padCount(total);
                    if (kpiValue) kpiValue.textContent = formatMoney(value);
                    if (kpiActive) kpiActive.textContent = padCount(active);
                    if (kpiConversion) {
                        const conversion = total ? (closed / total) * 100 : 0;
                        kpiConversion.textContent = `${conversion.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
                    }
                    if (kpiExported) kpiExported.textContent = padCount(exported);
                }

                function getTableFilterValue(card, key) {
                    if (key === 'created') return formatDate(card.dataset.created);
                    if (key === 'value') return formatMoney(card.dataset.value);
                    return card.dataset[key] || '';
                }

                function getTableFilteredCards() {
                    return getVisibleCards().filter((card) => {
                        return Object.keys(tableFilters).every((key) => {
                            const filter = tableFilters[key];
                            if (!filter || (Array.isArray(filter) && !filter.length)) return true;
                            const rawValue = getTableFilterValue(card, key);
                            if (Array.isArray(filter)) {
                                return filter.includes(rawValue || '--');
                            }
                            return normalize(rawValue).includes(normalize(filter));
                        });
                    });
                }

                function syncTableFilterButtons() {
                    tableFilterButtons.forEach((button) => {
                        const key = button.dataset.ceTableFilter;
                        const filter = tableFilters[key];
                        const active = Array.isArray(filter) ? filter.length > 0 : !!filter;
                        button.classList.toggle('active', active);
                    });
                }

                function closeTableFilterPopup() {
                    const popup = document.querySelector('.ce-table-filter-popup');
                    if (popup) popup.remove();
                }

                function uniqueTableValues(key) {
                    const values = getVisibleCards()
                        .map((card) => getTableFilterValue(card, key) || '--')
                        .filter((value) => String(value).trim() !== '');
                    return Array.from(new Set(values)).sort((a, b) => String(a).localeCompare(String(b), 'pt-BR'));
                }

                function openTableFilterPopup(button, key) {
                    closeTableFilterPopup();
                    const popup = document.createElement('div');
                    popup.className = 'ce-table-filter-popup';
                    const rect = button.getBoundingClientRect();
                    popup.style.top = `${rect.bottom + 8}px`;
                    popup.style.left = `${Math.min(rect.left, window.innerWidth - 292)}px`;

                    const label = button.closest('th')?.innerText.replace(/\s+/g, ' ').trim() || 'Filtro';
                    const optionKeys = ['stageLabel', 'statusLabel', 'cityLabel', 'source'];
                    if (optionKeys.includes(key)) {
                        const selected = Array.isArray(tableFilters[key]) ? tableFilters[key] : [];
                        const options = uniqueTableValues(key);
                        popup.innerHTML = `
                            <div class="ce-table-filter-popup-title">${escapeHtml(label)}</div>
                            <div class="ce-table-filter-options">
                                ${options.length ? options.map((value, index) => `
                                    <label class="ce-table-filter-option">
                                        <input type="checkbox" value="${escapeHtml(value)}" ${selected.includes(value) ? 'checked' : ''}>
                                        <span>${escapeHtml(value)}</span>
                                    </label>
                                `).join('') : '<div class="small text-muted">Sem opções.</div>'}
                            </div>
                            <div class="ce-table-filter-actions">
                                <button type="button" class="btn btn-sm btn-light" data-ce-popup-clear>Limpar</button>
                                <button type="button" class="btn btn-sm btn-primary" data-ce-popup-apply>Aplicar</button>
                            </div>
                        `;
                        popup.querySelector('[data-ce-popup-apply]')?.addEventListener('click', () => {
                            const checked = Array.from(popup.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
                            if (checked.length) tableFilters[key] = checked;
                            else delete tableFilters[key];
                            closeTableFilterPopup();
                            renderTable();
                        });
                    } else {
                        popup.innerHTML = `
                            <div class="ce-table-filter-popup-title">${escapeHtml(label)}</div>
                            <input class="form-control form-control-sm" data-ce-popup-input value="${escapeHtml(tableFilters[key] || '')}" placeholder="Digite para filtrar">
                            <div class="ce-table-filter-actions">
                                <button type="button" class="btn btn-sm btn-light" data-ce-popup-clear>Limpar</button>
                                <button type="button" class="btn btn-sm btn-primary" data-ce-popup-apply>Aplicar</button>
                            </div>
                        `;
                        const input = popup.querySelector('[data-ce-popup-input]');
                        input?.addEventListener('keydown', (event) => {
                            if (event.key === 'Enter') popup.querySelector('[data-ce-popup-apply]')?.click();
                        });
                        popup.querySelector('[data-ce-popup-apply]')?.addEventListener('click', () => {
                            const value = input ? input.value.trim() : '';
                            if (value) tableFilters[key] = value;
                            else delete tableFilters[key];
                            closeTableFilterPopup();
                            renderTable();
                        });
                        setTimeout(() => input?.focus(), 0);
                    }

                    popup.querySelector('[data-ce-popup-clear]')?.addEventListener('click', () => {
                        delete tableFilters[key];
                        closeTableFilterPopup();
                        renderTable();
                    });
                    document.body.appendChild(popup);
                }

                function renderTable() {
                    if (!tableBody) return;
                    const visibleCards = getTableFilteredCards();
                    if (!visibleCards.length) {
                        tableBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>';
                        syncTableFilterButtons();
                        return;
                    }

                    tableBody.innerHTML = visibleCards.map((card) => {
                        const id = card.dataset.id || '';
                        return `
                            <tr data-table-row="${escapeHtml(id)}">
                                <td>
                                    <div class="fw-semibold">${escapeHtml(card.dataset.title || 'Registro sem nome')}</div>
                                    <div class="small text-muted">${escapeHtml(card.dataset.owner || '')}</div>
                                </td>
                                <td>${escapeHtml(card.dataset.stageLabel || '')}</td>
                                <td><span class="badge bg-light text-dark border">${escapeHtml(card.dataset.statusLabel || 'Sem status')}</span></td>
                                <td>${escapeHtml(card.dataset.cityLabel || '--')}</td>
                                <td>${escapeHtml(card.dataset.phone || '--')}</td>
                                <td class="fw-semibold text-success">${formatMoney(card.dataset.value)}</td>
                                <td>${escapeHtml(card.dataset.source || '--')}</td>
                                <td>${formatDate(card.dataset.created)}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-ce-edit-table="${escapeHtml(id)}" title="Editar registro">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                    syncTableFilterButtons();
                }

                function setViewMode(mode) {
                    const isTable = mode === 'table';
                    if (board) board.classList.toggle('d-none', isTable);
                    if (topScrollbar) topScrollbar.classList.toggle('d-none', isTable);
                    if (listWrap) listWrap.classList.toggle('d-none', !isTable);
                    if (toggleViewBtn) {
                        toggleViewBtn.title = isTable ? 'Alternar para Kanban' : 'Alternar para Tabela';
                        toggleViewBtn.innerHTML = isTable ? '<i class="fa fa-table"></i>' : '<i class="fa fa-columns"></i>';
                    }
                    try { localStorage.setItem('ceViewMode', isTable ? 'table' : 'kanban'); } catch (e) {}
                    if (!isTable) syncTopScrollbar();
                    if (isTable) renderTable();
                }

                function syncTopScrollbar() {
                    if (!board || !topScrollbar || !topScrollbarContent) return;
                    const updateWidth = () => {
                        topScrollbarContent.style.width = `${board.scrollWidth}px`;
                    };
                    updateWidth();
                    if (topScrollbar.dataset.synced === '1') return;
                    topScrollbar.dataset.synced = '1';

                    if (typeof ResizeObserver !== 'undefined') {
                        try {
                            const observer = new ResizeObserver(updateWidth);
                            observer.observe(board);
                        } catch (e) {
                            window.addEventListener('resize', updateWidth);
                        }
                    } else {
                        window.addEventListener('resize', updateWidth);
                    }

                    let syncingTop = false;
                    let syncingBoard = false;
                    topScrollbar.addEventListener('scroll', () => {
                        if (syncingBoard) return;
                        syncingTop = true;
                        board.scrollLeft = topScrollbar.scrollLeft;
                        setTimeout(() => { syncingTop = false; }, 10);
                    });
                    board.addEventListener('scroll', () => {
                        if (syncingTop) return;
                        syncingBoard = true;
                        topScrollbar.scrollLeft = board.scrollLeft;
                        setTimeout(() => { syncingBoard = false; }, 10);
                    });
                }

                function setupHorizontalKanbanDrag() {
                    if (!board || board.dataset.dragReady === '1') return;
                    board.dataset.dragReady = '1';
                    let isDown = false;
                    let startX = 0;
                    let scrollLeft = 0;

                    board.addEventListener('mousedown', (event) => {
                        if (event.target.closest('.ce-card, input, button, select, textarea, a, .modal')) return;
                        isDown = true;
                        board.classList.add('is-dragging');
                        startX = event.pageX - board.offsetLeft;
                        scrollLeft = board.scrollLeft;
                        event.preventDefault();
                    });
                    board.addEventListener('mouseleave', () => {
                        isDown = false;
                        board.classList.remove('is-dragging');
                    });
                    board.addEventListener('mouseup', () => {
                        isDown = false;
                        board.classList.remove('is-dragging');
                    });
                    board.addEventListener('mousemove', (event) => {
                        if (!isDown) return;
                        event.preventDefault();
                        const x = event.pageX - board.offsetLeft;
                        const walk = (x - startX) * 2;
                        board.scrollLeft = scrollLeft - walk;
                    });
                }

                function setKanbanCompact(enabled) {
                    document.body.classList.toggle('ce-kanban-compact', enabled);
                    if (kanbanCompactIcon) {
                        kanbanCompactIcon.className = enabled ? 'fa fa-expand' : 'fa fa-compress';
                    }
                    try { localStorage.setItem('ceKanbanCompact', enabled ? '1' : '0'); } catch (e) {}
                }

                function setKanbanOnly(enabled) {
                    document.body.classList.toggle('ce-kanban-only', enabled);
                    if (kanbanOnlyIcon) {
                        kanbanOnlyIcon.className = enabled ? 'fa fa-compress-arrows-alt' : 'fa fa-expand-arrows-alt';
                    }
                    if (enabled) setViewMode('kanban');
                    try { localStorage.setItem('ceKanbanOnly', enabled ? '1' : '0'); } catch (e) {}
                }

                if (board) {
                    board.addEventListener('wheel', (event) => {
                        if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
                            board.scrollLeft += event.deltaY;
                            event.preventDefault();
                        }
                    }, { passive: false });
                }
                setupHorizontalKanbanDrag();

                function formatMoneyInput(value) {
                    const digits = String(value || '').replace(/\D/g, '');
                    if (!digits) return '';
                    const number = (Number(digits) / 100).toFixed(2);
                    const parts = number.split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    return parts.join(',');
                }

                function formatMoneyValueForInput(value) {
                    const number = Number(value || 0);
                    if (!Number.isFinite(number) || number <= 0) return '';
                    return number.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                function parseMoneyInput(value) {
                    const normalized = String(value || '').replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '');
                    const parsed = parseFloat(normalized);
                    return Number.isFinite(parsed) ? parsed : 0;
                }

                function ensureSelectOption(select, value) {
                    if (!select || value === null || value === undefined || String(value).trim() === '') return;
                    const wanted = String(value);
                    const exists = Array.from(select.options).some((option) => option.value === wanted);
                    if (!exists) {
                        const option = document.createElement('option');
                        option.value = wanted;
                        option.textContent = wanted;
                        select.appendChild(option);
                    }
                }

                function resetLeadModal() {
                    if (!leadForm) return;
                    leadForm.reset();
                    leadIdInput.value = '';
                    leadModalTitle.textContent = 'Cadastrar Registro';
                    leadSaveBtn.textContent = 'Salvar';
                    if (leadSourceInput) leadSourceInput.value = leadSourceDisplay;
                    const initialStage = stages.find((stage) => Number(stage.is_initial) === 1) || stages[0];
                    if (leadStageInput && initialStage) {
                        leadStageInput.value = String(initialStage.id);
                    }
                    leadStatusInput.value = '';
                    leadValueInput.value = '';
                    if (leadEmailInput) leadEmailInput.value = '';
                    if (leadCpfInput) leadCpfInput.value = '';
                    if (leadUltimoContatoInput) leadUltimoContatoInput.value = '';
                    if (leadCreatedAtInput) leadCreatedAtInput.value = '';
                    if (leadConsumoInput) leadConsumoInput.value = '';
                    if (leadEstimativaInput) leadEstimativaInput.value = '';
                    if (leadFormaPagamentoInput) leadFormaPagamentoInput.value = '';
                    if (leadAttachmentsContainer) {
                        leadAttachmentsContainer.innerHTML = '<div class="text-muted small">Nenhum anexo carregado.</div>';
                    }
                }

                function renderLeadAttachments(attachments) {
                    if (!leadAttachmentsContainer) return;
                    if (!Array.isArray(attachments) || !attachments.length) {
                        leadAttachmentsContainer.innerHTML = '<div class="text-muted small">Nenhum anexo carregado.</div>';
                        return;
                    }

                    leadAttachmentsContainer.innerHTML = '';
                    attachments.forEach((attachment) => {
                        const row = document.createElement('div');
                        row.className = 'border rounded p-2 bg-light d-flex justify-content-between align-items-center gap-3';
                        const sizeLabel = attachment.file_size ? `${(Number(attachment.file_size) / 1024).toFixed(1)} KB` : '';
                        row.innerHTML = `
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">${attachment.filename || 'Anexo'}</div>
                                <div class="small text-muted">${attachment.created_at || ''}${sizeLabel ? ` • ${sizeLabel}` : ''}</div>
                            </div>
                            <a class="btn btn-sm btn-outline-primary flex-shrink-0" href="includes/consultoria_interna_demandas_api.php?action=download_attachment&attachment_id=${encodeURIComponent(attachment.attachment_id)}" target="_blank" rel="noopener">
                                <i class="fa fa-download me-1"></i>Baixar
                            </a>
                        `;
                        leadAttachmentsContainer.appendChild(row);
                    });
                }

                function openLeadModal(card = null) {
                    const leadModal = getLeadModalInstance();
                    if (!leadModal) return;
                    resetLeadModal();
                    if (card) {
                        leadModalTitle.textContent = 'Editar Registro';
                        leadSaveBtn.textContent = 'Atualizar';
                        leadIdInput.value = String(card.id || '');
                        leadNameInput.value = card.name || card.client_name || '';
                        if (leadEmailInput) leadEmailInput.value = card.email || '';
                        leadPhoneInput.value = card.phone || '';
                        if (leadCpfInput) leadCpfInput.value = card.cpf_cnpj || '';
                        leadCityInput.value = card.cidade || '';
                        if (leadSourceInput) leadSourceInput.value = card.source || leadSourceDisplay;
                        if (leadStageInput) leadStageInput.value = String(card.stage_id || '');
                        ensureSelectOption(leadStatusInput, card.status || '');
                        leadStatusInput.value = card.status || '';
                        if (leadUltimoContatoInput) leadUltimoContatoInput.value = card.ultimo_contato ? String(card.ultimo_contato).substring(0, 10) : '';
                        if (leadCreatedAtInput) leadCreatedAtInput.value = card.created_entry_at ? String(card.created_entry_at).substring(0, 10) : '';
                        if (leadConsumoInput) leadConsumoInput.value = card.consumo || '';
                        if (leadEstimativaInput) leadEstimativaInput.value = card.estimativa_kwh || '';
                        const budgetValue = card.orcamento_value || card.value || '';
                        leadValueInput.value = budgetValue ? formatMoneyValueForInput(budgetValue) : '';
                        ensureSelectOption(leadFormaPagamentoInput, card.forma_pagamento_id || '');
                        if (leadFormaPagamentoInput) leadFormaPagamentoInput.value = card.forma_pagamento_id || '';
                        leadNotesInput.value = card.notes || '';
                        renderLeadAttachments(Array.isArray(card.attachments) ? card.attachments : []);
                    }
                    leadModal.show();
                }

                async function loadLeadForEdit(id) {
                    const res = await fetch(`${apiBase}?action=get&id=${encodeURIComponent(id)}${apiConsultorQuery}`);
                    if (!res.ok) {
                        throw new Error('NÃ£o foi possÃ­vel carregar o registro');
                    }
                    const data = await res.json();
                    return data && typeof data === 'object' ? data : {};
                }

                async function saveLeadForm(event) {
                    event.preventDefault();
                    const id = String(leadIdInput.value || '').trim();
                    const payload = new URLSearchParams();
                    payload.set('name', leadNameInput.value.trim());
                    payload.set('email', leadEmailInput ? leadEmailInput.value.trim() : '');
                    payload.set('phone', leadPhoneInput.value.trim());
                    payload.set('cpf_cnpj', leadCpfInput ? leadCpfInput.value.trim() : '');
                    payload.set('cidade', leadCityInput.value.trim());
                    payload.set('source', leadSourceDisplay);
                    if (leadStageInput) payload.set('stage_id', leadStageInput.value.trim());
                    payload.set('status', leadStatusInput.value.trim());
                    payload.set('ultimo_contato', leadUltimoContatoInput ? leadUltimoContatoInput.value.trim() : '');
                    payload.set('created_entry_at', leadCreatedAtInput ? leadCreatedAtInput.value.trim() : '');
                    payload.set('consumo', leadConsumoInput ? leadConsumoInput.value.trim() : '');
                    payload.set('estimativa_kwh', leadEstimativaInput ? leadEstimativaInput.value.trim() : '');
                    payload.set('orcamento_value', String(parseMoneyInput(leadValueInput.value)));
                    payload.set('forma_pagamento_id', leadFormaPagamentoInput ? leadFormaPagamentoInput.value.trim() : '');
                    payload.set('notes', leadNotesInput.value.trim());
                    if (id) {
                        payload.set('id', id);
                    }

                    leadSaveBtn.disabled = true;
                    const originalText = leadSaveBtn.textContent;
                    leadSaveBtn.textContent = id ? 'Atualizando...' : 'Salvando...';
                    try {
                        const action = id ? 'update' : 'add';
                        const res = await fetch(`${apiBase}?action=${action}${apiConsultorQuery}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: payload.toString()
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || data.error) {
                            throw new Error(data.error || 'Falha ao salvar registro');
                        }
                        const modalInstance = getLeadModalInstance();
                        if (modalInstance && modalInstance.hide) {
                            modalInstance.hide();
                        }
                        window.location.reload();
                    } catch (error) {
                        alert(error.message || 'Falha ao salvar registro');
                    } finally {
                        leadSaveBtn.disabled = false;
                        leadSaveBtn.textContent = originalText;
                    }
                }

                function resetStageForm() {
                    if (!stageForm) return;
                    stageForm.reset();
                    stageIdInput.value = '';
                    stageNameInput.value = '';
                    stageIconInput.value = 'fa-layer-group';
                    stageColorInput.value = '#6c757d';
                    stageCardColorInput.value = '#ffffff';
                    stageInitialInput.checked = !stages.length;
                    stageExportInput.checked = false;
                    if (stageNextStageInput) stageNextStageInput.value = '';
                    if (stageDeleteBtn) stageDeleteBtn.style.display = 'none';
                }

                function selectStage(stageId) {
                    const stage = stages.find((item) => String(item.id) === String(stageId));
                    if (!stage) return;
                    stageIdInput.value = String(stage.id);
                    stageNameInput.value = stage.label || '';
                    stageIconInput.value = stage.icon || 'fa-layer-group';
                    stageColorInput.value = stage.accent || '#6c757d';
                    stageCardColorInput.value = stage.card_color || '#ffffff';
                    stageInitialInput.checked = Number(stage.is_initial) === 1;
                    stageExportInput.checked = Number(stage.export_to_internal_queue) === 1;
                    if (stageNextStageInput) stageNextStageInput.value = stage.next_stage_id ? String(stage.next_stage_id) : '';
                    if (stageDeleteBtn) stageDeleteBtn.style.display = stages.length > 1 ? '' : 'none';
                }

                function renderStagesList() {
                    if (!stagesList) return;
                    stagesList.innerHTML = '';
                    if (!stages.length) {
                        stagesList.innerHTML = '<div class="ce-empty">Nenhuma coluna cadastrada.</div>';
                        return;
                    }

                    stages.forEach((stage) => {
                        const row = document.createElement('div');
                        row.className = 'ce-stage-row';
                        row.dataset.id = stage.id;
                        row.draggable = true;
                        row.innerHTML = `
                            <div class="ce-stage-row-main">
                                <span class="ce-stage-dot" style="background:${stage.accent || '#6c757d'}"></span>
                                <div style="min-width:0;">
                                    <div class="ce-stage-name">${stage.label || ''}</div>
                                    <div class="ce-stage-badges">
                                        <span class="badge bg-secondary">#${stage.position || '-'}</span>
                                        ${Number(stage.is_initial) === 1 ? '<span class="badge bg-primary">Inicial</span>' : ''}
                                        ${Number(stage.export_to_internal_queue) === 1 ? '<span class="badge bg-success">Exporta</span>' : ''}
                                        ${stage.next_stage_id ? '<span class="badge bg-info text-dark">Retorna ao interno</span>' : ''}
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary">Editar</button>
                        `;
                        row.querySelector('button').addEventListener('click', () => selectStage(stage.id));
                        row.addEventListener('dragstart', (event) => {
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(stage.id));
                            row.classList.add('opacity-50');
                        });
                        row.addEventListener('dragend', () => row.classList.remove('opacity-50'));
                        stagesList.appendChild(row);
                    });
                }

                async function loadStages() {
                    const res = await fetch(`${stagesApiBase}?action=list${apiConsultorQuery}`);
                    const data = await res.json().catch(() => []);
                    if (!res.ok) {
                        throw new Error(data.error || 'Falha ao carregar colunas');
                    }
                    stages = Array.isArray(data) ? data.map((stage) => ({
                        id: stage.id,
                        label: stage.name,
                        summary: stage.name,
                        icon: stage.icon || 'fa-layer-group',
                        accent: stage.color || '#6c757d',
                        card_color: stage.card_color || '#ffffff',
                        position: stage.position,
                        is_initial: stage.is_initial,
                        export_to_internal_queue: stage.export_to_internal_queue,
                        next_stage_id: stage.next_stage_id || 0
                    })) : [];
                    renderStagesList();
                    if (stages.length) selectStage(stages[0].id);
                }

                async function saveStageForm(event) {
                    event.preventDefault();
                    const id = String(stageIdInput.value || '').trim();
                    const payload = new URLSearchParams();
                    payload.set('name', stageNameInput.value.trim());
                    payload.set('icon', stageIconInput.value.trim() || 'fa-layer-group');
                    payload.set('color', stageColorInput.value || '#6c757d');
                    payload.set('card_color', stageCardColorInput.value || '#ffffff');
                    payload.set('is_initial', stageInitialInput.checked ? '1' : '0');
                    payload.set('export_to_internal_queue', stageExportInput.checked ? '1' : '0');
                    payload.set('next_stage_id', stageNextStageInput && stageNextStageInput.value ? stageNextStageInput.value : '');
                    if (id) payload.set('id', id);

                    const action = id ? 'update' : 'add';
                    const res = await fetch(`${stagesApiBase}?action=${action}${apiConsultorQuery}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: payload.toString()
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.error) {
                        throw new Error(data.error || 'Falha ao salvar coluna');
                    }
                    await loadStages();
                    window.location.reload();
                }

                async function deleteSelectedStage() {
                    const id = String(stageIdInput.value || '').trim();
                    if (!id || stages.length <= 1) return;
                    if (!confirm('Excluir esta coluna? Os registros dela serao movidos para a coluna inicial.')) return;
                    const payload = new URLSearchParams();
                    payload.set('id', id);
                    const res = await fetch(`${stagesApiBase}?action=delete${apiConsultorQuery}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: payload.toString()
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.error) {
                        throw new Error(data.error || 'Falha ao excluir coluna');
                    }
                    window.location.reload();
                }

                async function reorderStages(dragId, targetId) {
                    if (!dragId || !targetId || dragId === targetId) return;
                    const current = stages.slice();
                    const from = current.findIndex((stage) => String(stage.id) === String(dragId));
                    const to = current.findIndex((stage) => String(stage.id) === String(targetId));
                    if (from < 0 || to < 0) return;
                    const [moved] = current.splice(from, 1);
                    current.splice(to, 0, moved);
                    const positions = current.map((stage, index) => ({ id: stage.id, position: index + 1 }));
                    const res = await fetch(`${stagesApiBase}?action=reorder${apiConsultorQuery}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                        body: JSON.stringify({ positions })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.error) {
                        throw new Error(data.error || 'Falha ao reordenar colunas');
                    }
                    window.location.reload();
                }

                function syncEmptyStates() {
                    document.querySelectorAll('[data-card-list]').forEach((list) => {
                        const hasVisibleCard = Array.from(list.querySelectorAll('[data-card]')).some((card) => card.style.display !== 'none');
                        let empty = list.querySelector('.ce-empty');
                        if (!hasVisibleCard && !empty) {
                            empty = document.createElement('div');
                            empty.className = 'ce-empty';
                            empty.textContent = 'Nenhum item nesta etapa.';
                            list.appendChild(empty);
                        }
                        if (hasVisibleCard && empty) {
                            empty.remove();
                        }
                    });
                }

                async function moveCardToStage(card, targetColumn) {
                    if (!card || !targetColumn) return;
                    const cardId = card.dataset.id || '';
                    const stageId = targetColumn.dataset.stageColumn || '';
                    if (!cardId || !stageId || card.dataset.stage === stageId) return;

                    const originalStage = card.dataset.stage || '';
                    const originalList = card.closest('[data-card-list]');
                    const targetList = targetColumn.querySelector('[data-card-list]');
                    if (!targetList) return;

                    card.dataset.stage = stageId;
                    const nextStage = stages.find((item) => String(item.id) === String(stageId));
                    if (nextStage) {
                        card.dataset.stageLabel = nextStage.label || '';
                    }
                    targetList.appendChild(card);
                    syncEmptyStates();
                    applyFilters();
                    card.classList.add('opacity-50');

                    const payload = new URLSearchParams();
                    payload.set('id', cardId);
                    payload.set('stage_id', stageId);
                    try {
                        const res = await fetch(`${apiBase}?action=move_stage${apiConsultorQuery}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: payload.toString()
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || data.error) {
                            throw new Error(data.error || 'Falha ao mover card');
                        }
                    } catch (error) {
                        card.dataset.stage = originalStage;
                        const previousStage = stages.find((item) => String(item.id) === String(originalStage));
                        if (previousStage) {
                            card.dataset.stageLabel = previousStage.label || '';
                        }
                        if (originalList) {
                            originalList.appendChild(card);
                        }
                        syncEmptyStates();
                        applyFilters();
                        throw error;
                    } finally {
                        card.classList.remove('opacity-50');
                    }
                }

                function applyFilters() {
                    const search = normalize(searchInput ? searchInput.value : '');
                    const type = typeFilter ? typeFilter.value : '';
                    const stage = stageFilter ? stageFilter.value : '';
                    const city = normalize(cityFilter ? cityFilter.value : '');

                    cards.forEach((card) => {
                        const matchesSearch = !search || (card.dataset.search || '').includes(search);
                        const matchesType = !type || card.dataset.type === type;
                        const matchesStage = !stage || card.dataset.stage === stage;
                        const matchesCity = !city || (card.dataset.city || '').includes(city);
                        card.style.display = matchesSearch && matchesType && matchesStage && matchesCity ? '' : 'none';
                    });

                    syncEmptyStates();
                    updateCounts();
                    renderTable();
                    syncTopScrollbar();
                }

                if (toggleFilters && filtersPanel) {
                    toggleFilters.addEventListener('click', function () {
                        const isOpen = filtersPanel.classList.toggle('is-open');
                        toggleFilters.classList.toggle('active', isOpen);
                        toggleFilters.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    });
                }

                if (clearFiltersBtn) {
                    clearFiltersBtn.addEventListener('click', function () {
                        if (searchInput) searchInput.value = '';
                        if (typeFilter) typeFilter.value = '';
                        if (stageFilter) stageFilter.value = '';
                        if (cityFilter) cityFilter.value = '';
                        Object.keys(tableFilters).forEach((key) => delete tableFilters[key]);
                        closeTableFilterPopup();
                        applyFilters();
                    });
                }

                tableFilterButtons.forEach((button) => {
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        const key = button.dataset.ceTableFilter;
                        if (key) openTableFilterPopup(button, key);
                    });
                });

                document.addEventListener('click', function (event) {
                    if (event.target.closest('.ce-table-filter-popup') || event.target.closest('[data-ce-table-filter]')) return;
                    closeTableFilterPopup();
                });

                if (toggleViewBtn) {
                    toggleViewBtn.addEventListener('click', function () {
                        const isTable = listWrap && !listWrap.classList.contains('d-none');
                        setViewMode(isTable ? 'kanban' : 'table');
                    });
                }

                if (kanbanCompactBtn) {
                    kanbanCompactBtn.addEventListener('click', function () {
                        setKanbanCompact(!document.body.classList.contains('ce-kanban-compact'));
                    });
                }

                if (kanbanOnlyBtn) {
                    kanbanOnlyBtn.addEventListener('click', function () {
                        setKanbanOnly(!document.body.classList.contains('ce-kanban-only'));
                    });
                }

                if (tableBody) {
                    tableBody.addEventListener('click', async function (event) {
                        const button = event.target.closest('[data-ce-edit-table]');
                        if (!button) return;
                        const id = button.dataset.ceEditTable;
                        if (!id) return;
                        try {
                            const item = await loadLeadForEdit(id);
                            openLeadModal(item);
                        } catch (error) {
                            alert(error.message || 'Falha ao carregar o registro');
                        }
                    });
                }

                if (leadValueInput) {
                    leadValueInput.addEventListener('input', function () {
                        const digits = this.value.replace(/\D/g, '');
                        this.value = digits ? formatMoneyInput(digits) : '';
                    });
                }

                if (openLeadModalBtn) {
                    openLeadModalBtn.addEventListener('click', function () {
                        openLeadModal();
                    });
                }

                if (openStagesModalBtn) {
                    openStagesModalBtn.addEventListener('click', async function () {
                        try {
                            await loadStages();
                            const modal = getModalInstance(stagesModalEl);
                            if (modal) modal.show();
                        } catch (error) {
                            alert(error.message || 'Falha ao abrir configuracao de colunas');
                        }
                    });
                }

                if (newStageBtn) {
                    newStageBtn.addEventListener('click', resetStageForm);
                }

                if (stageForm) {
                    stageForm.addEventListener('submit', async function (event) {
                        try {
                            await saveStageForm(event);
                        } catch (error) {
                            alert(error.message || 'Falha ao salvar coluna');
                        }
                    });
                }

                if (stageDeleteBtn) {
                    stageDeleteBtn.addEventListener('click', async function () {
                        try {
                            await deleteSelectedStage();
                        } catch (error) {
                            alert(error.message || 'Falha ao excluir coluna');
                        }
                    });
                }

                if (stagesList) {
                    stagesList.addEventListener('dragover', (event) => event.preventDefault());
                    stagesList.addEventListener('drop', async function (event) {
                        event.preventDefault();
                        const dragId = event.dataTransfer.getData('text/plain');
                        const target = event.target.closest('.ce-stage-row');
                        if (!target) return;
                        try {
                            await reorderStages(dragId, target.dataset.id);
                        } catch (error) {
                            alert(error.message || 'Falha ao reordenar colunas');
                        }
                    });
                }

                cards.forEach((card) => {
                    card.addEventListener('dragstart', (event) => {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', card.dataset.id || '');
                        card.classList.add('opacity-50');
                    });
                    card.addEventListener('dragend', () => card.classList.remove('opacity-50'));
                });

                document.querySelectorAll('[data-stage-column]').forEach((column) => {
                    column.addEventListener('dragover', (event) => {
                        event.preventDefault();
                        column.classList.add('is-drop-target');
                    });
                    column.addEventListener('dragleave', (event) => {
                        if (!column.contains(event.relatedTarget)) {
                            column.classList.remove('is-drop-target');
                        }
                    });
                    column.addEventListener('drop', async function (event) {
                        event.preventDefault();
                        column.classList.remove('is-drop-target');
                        const cardId = event.dataTransfer.getData('text/plain');
                        const card = cards.find((item) => String(item.dataset.id || '') === String(cardId));
                        if (!card) return;
                        try {
                            await moveCardToStage(card, column);
                        } catch (error) {
                            alert(error.message || 'Falha ao mover card');
                        }
                    });
                });

                document.querySelectorAll('[data-ce-edit-item]').forEach((button) => {
                    button.addEventListener('click', async function () {
                        const id = this.dataset.ceEditItem;
                        if (!id) return;
                        try {
                            const item = await loadLeadForEdit(id);
                            openLeadModal(item);
                        } catch (error) {
                            alert(error.message || 'Falha ao carregar o registro');
                        }
                    });
                });

                if (leadForm) {
                    leadForm.addEventListener('submit', saveLeadForm);
                }

                [searchInput, typeFilter, stageFilter, cityFilter].forEach((element) => {
                    if (!element) {
                        return;
                    }
                    element.addEventListener('input', applyFilters);
                    element.addEventListener('change', applyFilters);
                });

                setKanbanCompact((() => {
                    try { return localStorage.getItem('ceKanbanCompact') === '1'; } catch (e) { return false; }
                })());
                setKanbanOnly((() => {
                    try { return localStorage.getItem('ceKanbanOnly') === '1'; } catch (e) { return false; }
                })());
                setViewMode((() => {
                    try { return localStorage.getItem('ceViewMode') === 'table' ? 'table' : 'kanban'; } catch (e) { return 'kanban'; }
                })());
                applyFilters();
            })();
        </script>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
