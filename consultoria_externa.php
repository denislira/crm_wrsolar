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
$loggedUserId = (int) $_SESSION['user_id'];
$requestedConsultorId = isset($_GET['consultor_id']) ? (int) $_GET['consultor_id'] : 0;
$canOpenConsultoriaExterna = $isConsultorExterno || $isDirector || hasPermission('consultoria_externa');
$canManageConsultoriaStages = !$isConsultorExterno;
$showStagesButton = $canManageConsultoriaStages || $requestedConsultorId > 0;
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
        'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ç' => 'c', 'ç' => 'c'
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
        if (!$isConsultorExterno) {
            $userId = (int) $requestedConsultor['id'];
        }
        $displayName = trim((string) ($requestedConsultor['username'] ?? $displayName));
    }
}

$apiBase = 'includes/consultoria_externa_api.php';
$stagesApiBase = 'includes/consultoria_externa_stages_api.php';
$apiConsultorId = $userId;

ce_ensure_stage_tables($pdo);
$stageRows = ce_list_stages($pdo);

try {
    $itemColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultoria_externa_itens'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $itemColumns = [];
}

$hasItemDeleted = in_array('deleted', $itemColumns, true);
$itemWhere = $hasItemDeleted ? 'COALESCE(c.deleted, 0) = 0' : '1 = 1';

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
    ];
}

$groupedCards = [];
foreach (array_keys($stageMeta) as $stageKey) {
    $groupedCards[$stageKey] = [];
}

foreach ($consultorRows as $item) {
    $stageKey = ce_resolve_global_stage_id($pdo, $item['stage_id'] ?? null);
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
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                align-items: center;
            }
            .ce-search {
                min-width: 240px;
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
            .ce-board {
                display: flex;
                gap: 1rem;
                align-items: flex-start;
                overflow-x: auto;
                overflow-y: hidden;
                padding: .25rem 0 .75rem;
                scrollbar-gutter: stable;
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
            body.theme-dark .ce-shell {
                background: linear-gradient(180deg, rgba(15,23,42,0.96) 0%, rgba(15,23,42,0.88) 100%);
            }
            body.theme-dark .ce-kpi,
            body.theme-dark .ce-card,
            body.theme-dark .ce-filters-panel {
                background: rgba(15, 23, 42, 0.82);
                border-color: rgba(148, 163, 184, 0.18);
                color: #e2e8f0;
            }
            body.theme-dark .ce-card {
                background: rgba(15, 23, 42, 0.88) !important;
            }
            body.theme-dark .ce-column {
                background: rgba(30, 41, 59, 0.68);
                border-color: rgba(148, 163, 184, 0.22);
            }
            body.theme-dark .ce-toolbar h1,
            body.theme-dark .ce-card-title,
            body.theme-dark .ce-kpi-value,
            body.theme-dark .ce-column-title {
                color: #f8fafc;
            }
            body.theme-dark .ce-toolbar-subtitle,
            body.theme-dark .ce-meta-row,
            body.theme-dark .ce-kpi-label,
            body.theme-dark .ce-empty,
            body.theme-dark .ce-card-link,
            body.theme-dark .ce-card-link:hover {
                color: #94a3b8;
            }
            body.theme-dark .ce-card-link:hover {
                color: #cbd5e1;
            }
            body.theme-dark .ce-card-footer {
                border-top-color: rgba(148, 163, 184, 0.12);
            }
            body.theme-dark .ce-pill-type {
                background: rgba(59, 130, 246, 0.18);
                color: #93c5fd;
            }
            body.theme-dark .ce-pill-status {
                background: rgba(148, 163, 184, 0.14);
                color: #e2e8f0;
            }
            body.theme-dark .ce-value {
                color: #4ade80;
            }
            body.theme-dark .ce-card {
                box-shadow: 0 14px 36px rgba(0, 0, 0, 0.28);
                border-top-color: rgba(96, 165, 250, 0.85);
            }
            body.theme-dark .ce-empty {
                background: rgba(15, 23, 42, 0.52);
                border-color: rgba(148, 163, 184, 0.26);
            }
            @media (max-width: 1200px) {
                .ce-kpis,
                .ce-board {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
            @media (max-width: 767px) {
                .ce-shell {
                    border-radius: 18px;
                    padding: 1rem;
                }
                .ce-kpis,
                .ce-board {
                    grid-template-columns: 1fr;
                }
                .ce-board {
                    display: flex;
                    overflow-x: auto;
                }
                .ce-column {
                    flex-basis: 280px;
                    min-width: 280px;
                }
                .ce-actions {
                    width: 100%;
                }
                .ce-search,
                .ce-create-btn,
                .ce-filter-btn {
                    width: 100%;
                }
            }
        </style>

        <div class="ce-shell">
            <div class="ce-toolbar">
                <div>
                    <h1>Painel de Consultores Externos</h1>
                    <div class="ce-toolbar-subtitle">Visão rápida das visitas, orçamentos, financiamentos e contratos de <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>.</div>
                </div>
                <div class="ce-actions">
                    <input id="ceSearchInput" type="search" class="form-control ce-search" placeholder="Buscar cliente...">
                    <button id="ceToggleFilters" type="button" class="btn btn-light ce-filter-btn">
                        <i class="fa-solid fa-filter me-2"></i>Filtros
                    </button>
                    <?php if ($showStagesButton): ?>
                        <button id="ceOpenStagesModal" type="button" class="btn btn-light ce-filter-btn" <?php echo $canManageConsultoriaStages ? '' : 'title="Apenas o diretor pode configurar as colunas"'; ?>>
                            <i class="fa-solid fa-sliders me-2"></i>Configurar colunas
                        </button>
                    <?php endif; ?>
                    <button id="ceOpenLeadModal" type="button" class="btn btn-primary ce-create-btn">
                        <i class="fa-solid fa-circle-plus me-2"></i>Cadastrar Visita / Lead
                    </button>
                </div>
            </div>

            <div id="ceFiltersPanel" class="ce-filters-panel">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="ceTypeFilter" class="form-label small text-muted">Tipo</label>
                        <select id="ceTypeFilter" class="form-select">
                            <option value="">Todos</option>
                            <option value="lead">Lead</option>
                            <option value="projeto">Projeto</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="ceStageFilter" class="form-label small text-muted">Coluna</label>
                        <select id="ceStageFilter" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($stageMeta as $stageKey => $meta): ?>
                                <option value="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="ceCityFilter" class="form-label small text-muted">Cidade</label>
                        <input id="ceCityFilter" type="text" class="form-control" placeholder="Filtrar por cidade">
                    </div>
                </div>
            </div>

            <div class="ce-kpis">
                <?php foreach ($stageMeta as $stageKey => $meta): ?>
                    <div class="ce-kpi" style="--accent: <?php echo htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <div class="ce-kpi-label"><?php echo htmlspecialchars($meta['summary'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="ce-kpi-value" data-summary-stage="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo str_pad((string) $summaryCounts[$stageKey], 2, '0', STR_PAD_LEFT); ?></div>
                    </div>
                <?php endforeach; ?>
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
                                                <a class="ce-card-link" href="<?php echo htmlspecialchars($card['link'], ENT_QUOTES, 'UTF-8'); ?>" title="Abrir módulo relacionado">
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
        </div>

        <div class="modal fade" id="ceLeadModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0" id="ceLeadModalTitle">Cadastrar Registro</h5>
                            <div class="small text-muted">Registro rápido para o painel de consultores externos.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <form id="ceLeadForm">
                        <input type="hidden" id="ceLeadId" name="id" value="">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="ceLeadName" class="form-label">Nome</label>
                                    <input id="ceLeadName" name="name" type="text" class="form-control" placeholder="Nome do cliente ou empresa" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="ceLeadPhone" class="form-label">Telefone</label>
                                    <input id="ceLeadPhone" name="phone" type="text" class="form-control" placeholder="(00) 00000-0000">
                                </div>
                                <div class="col-md-6">
                                    <label for="ceLeadCity" class="form-label">Cidade</label>
                                    <input id="ceLeadCity" name="cidade" type="text" class="form-control" placeholder="Cidade - UF">
                                </div>
                                <div class="col-md-6">
                                    <label for="ceLeadSource" class="form-label">Origem</label>
                                    <input id="ceLeadSource" name="source" type="text" class="form-control" placeholder="Ex: Visita, Indicação, WhatsApp">
                                </div>
                                <div class="col-md-6">
                                    <label for="ceLeadStageId" class="form-label">Coluna do Kanban</label>
                                    <select id="ceLeadStageId" name="stage_id" class="form-select">
                                        <?php foreach ($stageMeta as $stageKey => $meta): ?>
                                            <option value="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="ceLeadStatus" class="form-label">Status</label>
                                    <select id="ceLeadStatus" name="status" class="form-select">
                                        <option value="">Sem status</option>
                                        <option value="Em captação técnica">Em captação técnica</option>
                                        <option value="Aguardando orçamento">Aguardando orçamento</option>
                                        <option value="Processo bancário">Processo bancário</option>
                                        <option value="Contrato gerado">Contrato gerado</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="ceLeadValue" class="form-label">Valor</label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input id="ceLeadValue" name="orcamento_value" type="text" class="form-control" placeholder="0,00">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="ceLeadNotes" class="form-label">Observações</label>
                                    <textarea id="ceLeadNotes" name="notes" class="form-control" rows="4" placeholder="Detalhes da visita, negociação ou próximos passos"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                            <button id="ceLeadSaveBtn" type="submit" class="btn btn-primary">Salvar</button>
                        </div>
                    </form>
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
                const stagesApiQuery = '';
                let stages = <?php echo json_encode(array_values($stageMeta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const searchInput = document.getElementById('ceSearchInput');
                const typeFilter = document.getElementById('ceTypeFilter');
                const stageFilter = document.getElementById('ceStageFilter');
                const cityFilter = document.getElementById('ceCityFilter');
                const toggleFilters = document.getElementById('ceToggleFilters');
                const filtersPanel = document.getElementById('ceFiltersPanel');
                const cards = Array.from(document.querySelectorAll('[data-card]'));
                const countBadges = Array.from(document.querySelectorAll('[data-count-for]'));
                const summaryValues = Array.from(document.querySelectorAll('[data-summary-stage]'));
                const board = document.getElementById('ceBoard');
                const openLeadModalBtn = document.getElementById('ceOpenLeadModal');
                const openStagesModalBtn = document.getElementById('ceOpenStagesModal');
                const leadModalEl = document.getElementById('ceLeadModal');
                const stagesModalEl = document.getElementById('ceStagesModal');
                const leadForm = document.getElementById('ceLeadForm');
                const leadSaveBtn = document.getElementById('ceLeadSaveBtn');
                const leadModalTitle = document.getElementById('ceLeadModalTitle');
                const leadIdInput = document.getElementById('ceLeadId');
                const leadNameInput = document.getElementById('ceLeadName');
                const leadPhoneInput = document.getElementById('ceLeadPhone');
                const leadCityInput = document.getElementById('ceLeadCity');
                const leadSourceInput = document.getElementById('ceLeadSource');
                const leadStageInput = document.getElementById('ceLeadStageId');
                const leadStatusInput = document.getElementById('ceLeadStatus');
                const leadValueInput = document.getElementById('ceLeadValue');
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
                const stageDeleteBtn = document.getElementById('ceStageDeleteBtn');
                const newStageBtn = document.getElementById('ceNewStageBtn');
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
                }

                if (board) {
                    board.addEventListener('wheel', (event) => {
                        if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
                            board.scrollLeft += event.deltaY;
                            event.preventDefault();
                        }
                    }, { passive: false });
                }

                function formatMoneyInput(value) {
                    const digits = String(value || '').replace(/\D/g, '');
                    if (!digits) return '';
                    const number = (Number(digits) / 100).toFixed(2);
                    const parts = number.split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    return parts.join(',');
                }

                function parseMoneyInput(value) {
                    const normalized = String(value || '').replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '');
                    const parsed = parseFloat(normalized);
                    return Number.isFinite(parsed) ? parsed : 0;
                }

                function resetLeadModal() {
                    if (!leadForm) return;
                    leadForm.reset();
                    leadIdInput.value = '';
                    leadModalTitle.textContent = 'Cadastrar Registro';
                    leadSaveBtn.textContent = 'Salvar';
                    const initialStage = stages.find((stage) => Number(stage.is_initial) === 1) || stages[0];
                    if (leadStageInput && initialStage) {
                        leadStageInput.value = String(initialStage.id);
                    }
                    leadStatusInput.value = '';
                    leadValueInput.value = '';
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
                        leadPhoneInput.value = card.phone || '';
                        leadCityInput.value = card.cidade || '';
                        leadSourceInput.value = card.source || '';
                        if (leadStageInput) leadStageInput.value = String(card.stage_id || '');
                        leadStatusInput.value = card.status || '';
                        leadValueInput.value = card.orcamento_value ? formatMoneyInput(card.orcamento_value) : '';
                        leadNotesInput.value = card.notes || '';
                    }
                    leadModal.show();
                }

                async function loadLeadForEdit(id) {
                    const res = await fetch(`${apiBase}?action=get&id=${encodeURIComponent(id)}${apiConsultorQuery}`);
                    if (!res.ok) {
                        throw new Error('Não foi possível carregar o registro');
                    }
                    return await res.json();
                }

                async function saveLeadForm(event) {
                    event.preventDefault();
                    const id = String(leadIdInput.value || '').trim();
                    const payload = new URLSearchParams();
                    payload.set('name', leadNameInput.value.trim());
                    payload.set('phone', leadPhoneInput.value.trim());
                    payload.set('cidade', leadCityInput.value.trim());
                    payload.set('source', leadSourceInput.value.trim());
                    if (leadStageInput) payload.set('stage_id', leadStageInput.value.trim());
                    payload.set('status', leadStatusInput.value.trim());
                    payload.set('orcamento_value', String(parseMoneyInput(leadValueInput.value)));
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
                    const res = await fetch(`${stagesApiBase}?action=list${stagesApiQuery}`);
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
                        export_to_internal_queue: stage.export_to_internal_queue
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
                    if (id) payload.set('id', id);

                    const action = id ? 'update' : 'add';
                    const res = await fetch(`${stagesApiBase}?action=${action}${stagesApiQuery}`, {
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
                    const res = await fetch(`${stagesApiBase}?action=delete${stagesApiQuery}`, {
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
                    const res = await fetch(`${stagesApiBase}?action=reorder${stagesApiQuery}`, {
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

                    updateCounts();
                }

                if (toggleFilters && filtersPanel) {
                    toggleFilters.addEventListener('click', function () {
                        filtersPanel.classList.toggle('is-open');
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
                        if (!<?php echo $canManageConsultoriaStages ? 'true' : 'false'; ?>) {
                            alert('Apenas o diretor pode configurar as colunas deste kanban.');
                            return;
                        }
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

                updateCounts();
            })();
        </script>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
