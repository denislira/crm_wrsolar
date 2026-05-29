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

if (!hasPermission('consultoria_externa') && !hasPermission('dashboard')) {
    echo 'Acesso negado.';
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

function ce_stage_from_status($type, $status) {
    $normalized = ce_normalize($status);

    if ($normalized === '') {
        return 'captacao_tecnica';
    }

    if (preg_match('/(fechad|contrat|assin|finaliz|ganho|aprovad)/', $normalized)) {
        return 'contrato_gerado';
    }

    if (preg_match('/(financ|banc|credito|credito|analise de credito|analise)/', $normalized)) {
        return 'processo_bancario';
    }

    if (preg_match('/(orcamento|proposta|propost|negoci)/', $normalized)) {
        return 'aguardando_orcamento';
    }

    return 'captacao_tecnica';
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

$userId = (int) $_SESSION['user_id'];
$displayName = trim((string) ($_SESSION['username'] ?? 'Consultor Externo'));

try {
    $leadColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $leadColumns = [];
}

$hasLeadDeleted = in_array('deleted', $leadColumns, true);
$leadWhere = $hasLeadDeleted ? 'l.deleted = 0' : '1 = 1';

$leadRows = ce_safe_query_all(
    $pdo,
    "SELECT
        l.id,
        l.name,
        l.phone,
        l.cidade,
        l.source,
        l.status,
        l.orcamento_value,
        l.created_at,
        u.username
     FROM leads l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.user_id = ? AND {$leadWhere}
     ORDER BY l.created_at DESC, l.id DESC",
    [$userId]
);

$projectRows = ce_safe_query_all(
    $pdo,
    "SELECT
        p.id,
        p.client_name,
        p.status,
        p.proposal_value,
        p.contract,
        p.created_at,
        p.closed_date,
        u.username,
        l.phone,
        l.cidade,
        l.source
     FROM projetos p
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN leads l ON l.id = p.lead_id
     WHERE p.user_id = ?
     ORDER BY p.created_at DESC, p.id DESC",
    [$userId]
);

$stageMeta = [
    'captacao_tecnica' => ['label' => 'CAPTAÇÃO TÉCNICA', 'summary' => 'MINHAS VISITAS', 'icon' => 'fa-house-signal', 'accent' => '#3b82f6'],
    'aguardando_orcamento' => ['label' => 'AGUARDANDO ORÇAMENTO', 'summary' => 'EM ORÇAMENTO', 'icon' => 'fa-file-invoice-dollar', 'accent' => '#f59e0b'],
    'processo_bancario' => ['label' => 'PROCESSO BANCÁRIO', 'summary' => 'FINANCIAMENTO', 'icon' => 'fa-building-columns', 'accent' => '#8b5cf6'],
    'contrato_gerado' => ['label' => 'CONTRATO GERADO', 'summary' => 'VENDAS FECHADAS', 'icon' => 'fa-file-signature', 'accent' => '#10b981'],
];

$groupedCards = [];
foreach (array_keys($stageMeta) as $stageKey) {
    $groupedCards[$stageKey] = [];
}

foreach ($leadRows as $lead) {
    $stageKey = ce_stage_from_status('lead', $lead['status'] ?? '');
    $groupedCards[$stageKey][] = [
        'type' => 'lead',
        'id' => (int) $lead['id'],
        'title' => (string) ($lead['name'] ?? 'Lead sem nome'),
        'status' => (string) ($lead['status'] ?? 'Sem status'),
        'value' => (float) ($lead['orcamento_value'] ?? 0),
        'phone' => (string) ($lead['phone'] ?? ''),
        'cidade' => (string) ($lead['cidade'] ?? ''),
        'source' => (string) ($lead['source'] ?? 'Lead'),
        'created_at' => (string) ($lead['created_at'] ?? ''),
        'owner' => (string) ($lead['username'] ?? $displayName),
        'link' => 'leads_gestao.php',
    ];
}

foreach ($projectRows as $project) {
    $stageKey = ce_stage_from_status('project', $project['status'] ?? '');
    $groupedCards[$stageKey][] = [
        'type' => 'projeto',
        'id' => (int) $project['id'],
        'title' => (string) ($project['client_name'] ?? 'Projeto sem cliente'),
        'status' => (string) ($project['status'] ?? 'Sem status'),
        'value' => (float) ($project['proposal_value'] ?? 0),
        'phone' => (string) ($project['phone'] ?? ''),
        'cidade' => (string) ($project['cidade'] ?? ''),
        'source' => (string) ($project['contract'] ? 'Contrato gerado' : ($project['source'] ?? 'Projeto')),
        'created_at' => (string) ($project['closed_date'] ?: $project['created_at']),
        'owner' => (string) ($project['username'] ?? $displayName),
        'link' => 'projetos.php',
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
                grid-template-columns: repeat(4, minmax(0, 1fr));
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
                display: grid;
                grid-template-columns: repeat(4, minmax(260px, 1fr));
                gap: 1rem;
                align-items: start;
            }
            .ce-column {
                background: rgba(226, 232, 240, 0.45);
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
                color: #94a3b8;
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
            body.theme-dark .ce-column {
                background: rgba(30, 41, 59, 0.58);
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
            body.theme-dark .ce-empty {
                color: #94a3b8;
            }
            body.theme-dark .ce-card-footer {
                border-top-color: rgba(148, 163, 184, 0.12);
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
                    <div class="ce-toolbar-subtitle">Visão rápida das visitas, orçamentos, financiamentos e contratos do consultor logado.</div>
                </div>
                <div class="ce-actions">
                    <input id="ceSearchInput" type="search" class="form-control ce-search" placeholder="Buscar cliente...">
                    <button id="ceToggleFilters" type="button" class="btn btn-light ce-filter-btn">
                        <i class="fa-solid fa-filter me-2"></i>Filtros
                    </button>
                    <a href="leads_gestao.php" class="btn btn-primary ce-create-btn">
                        <i class="fa-solid fa-circle-plus me-2"></i>Cadastrar Visita / Lead
                    </a>
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

            <div class="ce-board">
                <?php foreach ($stageMeta as $stageKey => $meta): ?>
                    <section class="ce-column" style="--accent: <?php echo htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8'); ?>;" data-stage-column="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="ce-column-header">
                            <div class="ce-column-title">
                                <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                <span><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="ce-column-count" data-count-for="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo count($groupedCards[$stageKey]); ?></span>
                        </div>
                        <div class="ce-card-list">
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
                                        data-stage="<?php echo htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-type="<?php echo htmlspecialchars($card['type'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-city="<?php echo htmlspecialchars(ce_normalize($card['cidade']), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                        style="--accent: <?php echo htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8'); ?>;"
                                    >
                                        <div class="ce-card-top">
                                            <h2 class="ce-card-title"><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                            <a class="ce-card-link" href="<?php echo htmlspecialchars($card['link'], ENT_QUOTES, 'UTF-8'); ?>" title="Abrir módulo relacionado">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </a>
                                        </div>
                                        <div class="ce-meta">
                                            <div class="ce-meta-row"><i class="fa-regular fa-clock"></i><span>Criado em: <?php echo htmlspecialchars(ce_date($card['created_at']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                                            <?php if (!empty($card['phone'])): ?>
                                                <div class="ce-meta-row"><i class="fa-solid fa-phone"></i><span><?php echo htmlspecialchars($card['phone'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                            <?php endif; ?>
                                            <div class="ce-meta-row"><i class="fa-solid fa-user-tie"></i><span><?php echo $card['type'] === 'projeto' ? 'Orçamentista' : 'Consultor'; ?>: <?php echo htmlspecialchars($card['owner'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                        </div>
                                        <div class="ce-value"><?php echo ce_money($card['value']); ?></div>
                                        <div class="ce-card-footer">
                                            <span class="ce-pill ce-pill-type"><?php echo htmlspecialchars($card['type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="ce-pill ce-pill-status"><?php echo htmlspecialchars($card['status'], ENT_QUOTES, 'UTF-8'); ?></span>
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

        <script>
            (function () {
                const searchInput = document.getElementById('ceSearchInput');
                const typeFilter = document.getElementById('ceTypeFilter');
                const stageFilter = document.getElementById('ceStageFilter');
                const cityFilter = document.getElementById('ceCityFilter');
                const toggleFilters = document.getElementById('ceToggleFilters');
                const filtersPanel = document.getElementById('ceFiltersPanel');
                const cards = Array.from(document.querySelectorAll('[data-card]'));
                const countBadges = Array.from(document.querySelectorAll('[data-count-for]'));
                const summaryValues = Array.from(document.querySelectorAll('[data-summary-stage]'));

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