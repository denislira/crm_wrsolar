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

$roleName = $_SESSION['role_name'] ?? null;
if (!$roleName && !empty($_SESSION['role_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['role_id']]);
    $roleName = $stmt->fetchColumn();
}

$canAccessDemandQueue = function_exists('isDirector') && isDirector() ? true : hasPermission('fila_demandas');
if (strtolower((string)$roleName) === 'consultor_externo' || !$canAccessDemandQueue) {
    echo 'Acesso negado.';
    exit;
}

ce_ensure_stage_tables($pdo);
$consultorRows = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, COUNT(c.id) AS total_items
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
          LEFT JOIN consultoria_externa_itens c
            ON c.user_id = u.id AND COALESCE(c.deleted, 0) = 0
         WHERE LOWER(COALESCE(r.name, '')) = 'consultor_externo'
         GROUP BY u.id, u.username
         ORDER BY u.username ASC
    ");
    $stmt->execute();
    $consultorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $consultorRows = [];
}
$pageTitle = 'Fila de Demandas';
include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 main-content-scroll">
        <style>
            .dm-shell {
                height: calc(100vh - 56px);
                min-height: 620px;
                background: #f6f7f9;
                display: grid;
                grid-template-rows: 57px 1fr;
            }
            .dm-header {
                background: #fff;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 1.4rem;
                gap: 1rem;
            }
            .dm-title {
                display: flex;
                align-items: center;
                gap: .8rem;
                min-width: 0;
            }
            .dm-title h1 {
                margin: 0;
                color: #0b253f;
                font-size: 1.05rem;
                font-weight: 900;
                letter-spacing: .02em;
                text-transform: uppercase;
            }
            .dm-count-pill {
                background: #dbeafe;
                color: #1d4ed8;
                border-radius: 999px;
                padding: .3rem .65rem;
                font-size: .72rem;
                font-weight: 900;
                white-space: nowrap;
            }
            .dm-header-actions {
                display: flex;
                align-items: center;
                gap: 1rem;
            }
            .dm-bell {
                position: relative;
                width: 36px;
                height: 36px;
                border: 0;
                border-radius: 10px;
                background: transparent;
                color: #64748b;
            }
            .dm-bell-count {
                position: absolute;
                right: 2px;
                top: 2px;
                min-width: 18px;
                height: 18px;
                border-radius: 999px;
                background: #ef4444;
                color: #fff;
                font-size: .68rem;
                font-weight: 900;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0 .25rem;
            }
            .dm-layout {
                min-height: 0;
                display: grid;
                grid-template-columns: minmax(360px, 532px) minmax(420px, 1fr);
            }
            .dm-left {
                min-width: 0;
                border-right: 1px solid #dbe2ea;
                padding: 1.5rem 1.35rem;
                overflow-y: auto;
            }
            .dm-tabs {
                display: flex;
                gap: 1rem;
                margin-bottom: 1.45rem;
                flex-wrap: wrap;
            }
            .dm-tab {
                border: 1px solid #dbe4ef;
                background: #fff;
                color: #475569;
                border-radius: 8px;
                min-width: 122px;
                height: 38px;
                padding: 0 1rem;
                font-weight: 900;
                font-size: .82rem;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            }
            .dm-tab.active {
                background: #ff6b18;
                border-color: #ff6b18;
                color: #fff;
                box-shadow: 0 8px 18px rgba(255, 107, 24, .25);
            }
            .dm-consultor-list {
                display: grid;
                gap: .75rem;
            }
            .dm-consultor-card {
                width: 100%;
                border: 1px solid #edf1f6;
                background: #fff;
                border-radius: 12px;
                padding: 1rem 1.1rem;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 1rem;
                text-align: left;
            }
            .dm-consultor-card:hover {
                border-color: #bfdbfe;
                box-shadow: 0 12px 24px rgba(15, 23, 42, .08);
                transform: translateY(-1px);
            }
            .dm-consultor-name {
                font-weight: 900;
                color: #06172d;
                margin-bottom: .25rem;
            }
            .dm-consultor-meta {
                color: #64748b;
                font-size: .82rem;
            }
            .dm-consultor-badge {
                border-radius: 999px;
                padding: .35rem .6rem;
                background: #eff6ff;
                color: #1d4ed8;
                font-size: .72rem;
                font-weight: 900;
                white-space: nowrap;
            }
            .dm-list {
                display: grid;
                gap: 1rem;
            }
            .dm-card {
                width: 100%;
                text-align: left;
                border: 1px solid #edf1f6;
                background: #fff;
                border-radius: 12px;
                padding: 1.25rem 1.1rem 1rem;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
            }
            .dm-card:hover,
            .dm-card.active {
                border-color: #bfdbfe;
                box-shadow: 0 12px 24px rgba(15, 23, 42, .08);
                transform: translateY(-1px);
            }
            .dm-card-top {
                display: flex;
                justify-content: space-between;
                gap: .75rem;
                align-items: flex-start;
            }
            .dm-id {
                color: #94a3b8;
                font-size: .72rem;
                font-weight: 900;
                margin-bottom: .5rem;
            }
            .dm-card-title {
                color: #06172d;
                font-weight: 900;
                font-size: 1rem;
                margin: 0 0 1rem;
            }
            .dm-priority {
                border-radius: 5px;
                padding: .38rem .55rem;
                font-size: .62rem;
                font-weight: 900;
                text-transform: uppercase;
                background: #dbeafe;
                color: #1d4ed8;
            }
            .dm-priority.high {
                background: #ffe4e6;
                color: #e11d48;
            }
            .dm-card-meta {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: .55rem 1rem;
                color: #52627a;
                font-size: .82rem;
            }
            .dm-card-meta span {
                display: inline-flex;
                align-items: center;
                gap: .45rem;
                min-width: 0;
            }
            .dm-card-meta i {
                color: #ff6b18;
                width: 14px;
                text-align: center;
            }
            .dm-card-meta strong {
                color: #10233f;
            }
            .dm-right {
                background: #fff;
                min-width: 0;
                overflow-y: auto;
                padding: 2rem;
                display: flex;
            }
            .dm-empty-detail {
                margin: auto;
                text-align: center;
                color: #98a2b3;
                max-width: 360px;
                font-weight: 700;
                line-height: 1.5;
            }
            .dm-empty-detail i {
                width: 44px;
                height: 44px;
                border: 4px solid #e5e7eb;
                border-radius: 999px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.4rem;
                color: #d1d5db;
                margin-bottom: 1rem;
            }
            .dm-detail {
                width: 100%;
                max-width: 820px;
                margin: 0 auto;
            }
            .dm-detail-head {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: flex-start;
                border-bottom: 1px solid #e5e7eb;
                padding-bottom: 1rem;
                margin-bottom: 1rem;
            }
            .dm-detail-id {
                color: #94a3b8;
                font-weight: 900;
                font-size: .75rem;
                margin-bottom: .35rem;
            }
            .dm-detail-title {
                margin: 0;
                font-size: 1.6rem;
                font-weight: 900;
                color: #06172d;
            }
            .dm-status {
                border-radius: 999px;
                padding: .4rem .7rem;
                font-size: .72rem;
                font-weight: 900;
                text-transform: uppercase;
                white-space: nowrap;
            }
            .dm-status.pending { background: #fff7ed; color: #c2410c; }
            .dm-status.accepted { background: #eff6ff; color: #1d4ed8; }
            .dm-status.done { background: #ecfdf5; color: #047857; }
            .dm-detail-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .9rem;
                margin: 1rem 0;
            }
            .dm-field {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: .8rem .9rem;
                background: #fafbfc;
            }
            .dm-field-label {
                color: #94a3b8;
                font-size: .72rem;
                font-weight: 900;
                text-transform: uppercase;
                margin-bottom: .25rem;
            }
            .dm-field-value {
                color: #10233f;
                font-weight: 800;
                word-break: break-word;
            }
            .dm-notes {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 1rem;
                background: #fff;
                color: #475569;
                min-height: 120px;
            }
            .dm-detail-actions {
                display: flex;
                gap: .65rem;
                flex-wrap: wrap;
                margin-top: 1rem;
            }
            .dm-attachments {
                margin-top: 1rem;
                border-top: 1px solid #e5e7eb;
                padding-top: 1rem;
                display: grid;
                gap: .75rem;
            }
            .dm-attachment-upload {
                display: flex;
                gap: .65rem;
                align-items: center;
                flex-wrap: wrap;
            }
            .dm-attachment-upload input[type="file"] {
                max-width: 100%;
            }
            .dm-attachments-list {
                display: grid;
                gap: .5rem;
            }
            .dm-attachment-item {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: .7rem .85rem;
                background: #fafbfc;
                display: flex;
                justify-content: space-between;
                gap: .75rem;
                align-items: center;
            }
            .dm-attachment-meta {
                min-width: 0;
            }
            .dm-attachment-name {
                font-weight: 800;
                color: #10233f;
                word-break: break-word;
            }
            .dm-attachment-sub {
                font-size: .75rem;
                color: #64748b;
                margin-top: .15rem;
            }
            .dm-attachment-link {
                white-space: nowrap;
            }
            .dm-attachment-delete {
                border: 0;
                background: #fee2e2;
                color: #b91c1c;
                border-radius: 8px;
                padding: .45rem .6rem;
            }
            .dm-attachment-delete:hover {
                background: #fecaca;
            }
            .dm-empty-list {
                border: 1px dashed #cbd5e1;
                background: #fff;
                color: #64748b;
                border-radius: 12px;
                padding: 1.5rem;
                text-align: center;
                font-weight: 700;
            }
            body.theme-dark .dm-shell,
            body.dark-mode .dm-shell {
                background: #071427;
            }
            body.theme-dark .dm-header,
            body.dark-mode .dm-header {
                background: #0b1220;
                border-bottom-color: rgba(255,255,255,0.06);
            }
            body.theme-dark .dm-title h1,
            body.dark-mode .dm-title h1,
            body.theme-dark .dm-detail-title,
            body.dark-mode .dm-detail-title,
            body.theme-dark .dm-card-title,
            body.dark-mode .dm-card-title,
            body.theme-dark .dm-consultor-name,
            body.dark-mode .dm-consultor-name,
            body.theme-dark .dm-field-value,
            body.dark-mode .dm-field-value {
                color: #e6eef8;
            }
            body.theme-dark .dm-count-pill,
            body.dark-mode .dm-count-pill {
                background: rgba(59,130,246,0.16);
                color: #bfdbfe;
            }
            body.theme-dark .dm-bell,
            body.dark-mode .dm-bell {
                color: #c3d5ea;
            }
            body.theme-dark .dm-left,
            body.dark-mode .dm-left {
                border-right-color: rgba(255,255,255,0.06);
            }
            body.theme-dark .dm-tab,
            body.dark-mode .dm-tab,
            body.theme-dark .dm-card,
            body.dark-mode .dm-card,
            body.theme-dark .dm-consultor-card,
            body.dark-mode .dm-consultor-card,
            body.theme-dark .dm-field,
            body.dark-mode .dm-field,
            body.theme-dark .dm-notes,
            body.dark-mode .dm-notes,
            body.theme-dark .dm-empty-list,
            body.dark-mode .dm-empty-list {
                background: #0f1724;
                border-color: rgba(255,255,255,0.06);
                color: #c3d5ea;
            }
            body.theme-dark .dm-tab,
            body.dark-mode .dm-tab {
                box-shadow: none;
            }
            body.theme-dark .dm-tab,
            body.dark-mode .dm-tab,
            body.theme-dark .dm-consultor-meta,
            body.dark-mode .dm-consultor-meta,
            body.theme-dark .dm-id,
            body.dark-mode .dm-id,
            body.theme-dark .dm-detail-id,
            body.dark-mode .dm-detail-id,
            body.theme-dark .dm-field-label,
            body.dark-mode .dm-field-label,
            body.theme-dark .dm-empty-detail,
            body.dark-mode .dm-empty-detail {
                color: #94a3b8;
            }
            body.theme-dark .dm-tab.active,
            body.dark-mode .dm-tab.active {
                background: #ff6b18;
                border-color: #ff6b18;
                color: #fff;
            }
            body.theme-dark .dm-card:hover,
            body.dark-mode .dm-card:hover,
            body.theme-dark .dm-card.active,
            body.dark-mode .dm-card.active,
            body.theme-dark .dm-consultor-card:hover,
            body.dark-mode .dm-consultor-card:hover {
                border-color: rgba(191,219,254,0.36);
                box-shadow: 0 12px 24px rgba(0,0,0,.28);
            }
            body.theme-dark .dm-right,
            body.dark-mode .dm-right {
                background: #0b1220;
            }
            body.theme-dark .dm-detail-head,
            body.dark-mode .dm-detail-head {
                border-bottom-color: rgba(255,255,255,0.08);
            }
            body.theme-dark .dm-empty-detail,
            body.dark-mode .dm-empty-detail {
                color: #8aa0bb;
            }
            body.theme-dark .dm-empty-detail i,
            body.dark-mode .dm-empty-detail i {
                border-color: rgba(255,255,255,0.12);
                color: #c3d5ea;
                background: rgba(255,255,255,0.02);
            }
            body.theme-dark .dm-status.pending,
            body.dark-mode .dm-status.pending {
                background: rgba(249,115,22,0.14);
                color: #fdba74;
            }
            body.theme-dark .dm-status.accepted,
            body.dark-mode .dm-status.accepted {
                background: rgba(59,130,246,0.14);
                color: #93c5fd;
            }
            body.theme-dark .dm-status.done,
            body.dark-mode .dm-status.done {
                background: rgba(16,185,129,0.14);
                color: #6ee7b7;
            }
            @media (max-width: 980px) {
                .dm-shell { height: auto; min-height: calc(100vh - 56px); }
                .dm-layout { grid-template-columns: 1fr; }
                .dm-left { border-right: 0; border-bottom: 1px solid #dbe2ea; }
                .dm-right { min-height: 460px; }
            }
            body.theme-dark .dm-left,
            body.dark-mode .dm-left {
                border-bottom-color: rgba(255,255,255,0.06);
            }
            @media (max-width: 560px) {
                .dm-header { align-items: flex-start; padding: .9rem 1rem; }
                .dm-title { align-items: flex-start; flex-direction: column; gap: .35rem; }
                .dm-tabs { gap: .55rem; }
                .dm-tab { min-width: 0; flex: 1; }
                .dm-card-meta,
                .dm-detail-grid { grid-template-columns: 1fr; }
                .dm-consultor-card { align-items: flex-start; flex-direction: column; }
            }
        </style>

        <div class="dm-shell">
            <header class="dm-header">
                <div class="dm-title">
                    <h1>Gerenciamento de Demandas</h1>
                    <span id="dmQueueCount" class="dm-count-pill">Fila: 0 pendentes</span>
                </div>
                <div class="dm-header-actions">
                    <button type="button" class="dm-bell" title="Pendentes">
                        <i class="fa-regular fa-bell"></i>
                        <span id="dmBellCount" class="dm-bell-count">0</span>
                    </button>
                </div>
            </header>

            <section class="dm-layout">
                <aside class="dm-left">
                    <div class="dm-tabs" role="group" aria-label="Filtrar demandas">
                        <button id="dmPendingTab" class="dm-tab active" type="button" data-view="pending">Aguardando (0)</button>
                        <button id="dmSentTab" class="dm-tab" type="button" data-view="sent">Enviados (0)</button>
                        <button id="dmConsultorTab" class="dm-tab" type="button" data-view="consultors">Consultores Externo</button>
                    </div>
                    <div id="dmList" class="dm-list">
                        <div class="dm-empty-list">Carregando demandas...</div>
                    </div>
                </aside>

                <section id="dmDetailPane" class="dm-right">
                    <div class="dm-empty-detail">
                        <i class="fa-solid fa-exclamation"></i>
                        <div>Selecione uma demanda na fila para ver os detalhes e iniciar o atendimento.</div>
                    </div>
                </section>
            </section>
        </div>

        <script>
            (function () {
                const api = 'includes/consultoria_interna_demandas_api.php';
                const listEl = document.getElementById('dmList');
                const detailEl = document.getElementById('dmDetailPane');
                const queueCountEl = document.getElementById('dmQueueCount');
                const bellCountEl = document.getElementById('dmBellCount');
                const tabs = Array.from(document.querySelectorAll('.dm-tab[data-view]'));
                let rows = [];
                let currentTab = 'pending';
                const consultantRows = <?php echo json_encode($consultorRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                let selectedId = null;

                function escapeHtml(value) {
                    return String(value || '').replace(/[&<>"']/g, (char) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[char]));
                }

                function money(value) {
                    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                }

                function dateLabel(value) {
                    if (!value) return '-';
                    const date = new Date(String(value).replace(' ', 'T'));
                    if (Number.isNaN(date.getTime())) return '-';
                    return date.toLocaleDateString('pt-BR') + ', ' + date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                }

                function statusLabel(status) {
                    if (status === 'accepted') return 'Assumida';
                    if (status === 'done') return 'Concluida';
                    return 'Pendente';
                }

                function visibleRows() {
                    if (currentTab === 'pending') {
                        return rows.filter((row) => row.demand_status === 'pending');
                    }
                    if (currentTab === 'sent') {
                        return rows.filter((row) => row.demand_status !== 'pending');
                    }
                    if (currentTab === 'consultors') {
                        return [];
                    }
                    return rows.filter((row) => row.demand_status !== 'pending');
                }

                function priorityClass(row) {
                    const value = Number(row.value || 0);
                    return value >= 50000 ? 'high' : '';
                }

                function priorityLabel(row) {
                    const value = Number(row.value || 0);
                    return value >= 50000 ? 'Alta' : 'Normal';
                }

                function updateTabs() {
                    const pending = rows.filter((row) => row.demand_status === 'pending').length;
                    const sent = rows.length - pending;
                    document.getElementById('dmPendingTab').textContent = `Aguardando (${pending})`;
                    document.getElementById('dmSentTab').textContent = `Enviados (${sent})`;
                    queueCountEl.textContent = `Fila: ${pending} pendentes`;
                    bellCountEl.textContent = String(pending);
                }

                function renderList() {
                    if (currentTab === 'consultors') {
                        listEl.innerHTML = consultantRows.length ? consultantRows.map((row) => `
                            <a class="dm-consultor-card text-decoration-none" href="consultoria_externa.php?consultor_id=${encodeURIComponent(row.id)}">
                                <div>
                                    <div class="dm-consultor-name">${escapeHtml(row.username || 'Consultor externo')}</div>
                                    <div class="dm-consultor-meta">${Number(row.total_items || 0)} registro(s) no kanban</div>
                                </div>
                                <span class="dm-consultor-badge">Abrir kanban</span>
                            </a>
                        `).join('') : '<div class="dm-empty-list">Nenhum consultor externo encontrado.</div>';
                        return;
                    }

                    const list = visibleRows();
                    updateTabs();
                    if (!list.length) {
                        listEl.innerHTML = '<div class="dm-empty-list">Nenhuma demanda encontrada.</div>';
                        return;
                    }

                    listEl.innerHTML = list.map((row) => `
                        <button
                            type="button"
                            class="dm-card ${String(row.demand_id) === String(selectedId) ? 'active' : ''}"
                            data-select-demand="${escapeHtml(row.demand_id)}"
                        >
                            <div class="dm-card-top">
                                <div>
                                    <div class="dm-id">ID #${escapeHtml(row.external_item_id)}</div>
                                    <h2 class="dm-card-title">${escapeHtml(row.external_consultor || 'Consultor externo')}</h2>
                                </div>
                                <span class="dm-priority ${priorityClass(row)}">${priorityLabel(row)}</span>
                            </div>
                            <div class="dm-card-meta">
                                <span><i class="fa-solid fa-user-plus"></i>${escapeHtml(row.client_name || 'Registro sem nome')}</span>
                                <span><i class="fa-regular fa-clock"></i>${dateLabel(row.queued_at)}</span>
                                <span><i class="fa-solid fa-chart-simple"></i><strong>${money(row.value)}</strong></span>
                                <span><i class="fa-solid fa-layer-group"></i>${escapeHtml(row.stage_name || 'Sem coluna')}</span>
                            </div>
                        </button>
                    `).join('');
                }

                function actionButtons(row) {
                    const id = escapeHtml(row.demand_id);
                    if (row.demand_status === 'done') {
                        return `<button class="btn btn-outline-secondary" data-action="reopen" data-id="${id}">Reabrir demanda</button>`;
                    }
                    if (row.demand_status === 'accepted') {
                        return `
                            <button class="btn btn-success" data-action="complete" data-id="${id}">Concluir</button>
                            <button class="btn btn-outline-secondary" data-action="reopen" data-id="${id}">Voltar para fila</button>
                        `;
                    }
                    return `<button class="btn btn-primary" data-action="accept" data-id="${id}">Assumir demanda</button>`;
                }

                function renderEmptyDetail() {
                    detailEl.innerHTML = `
                        <div class="dm-empty-detail">
                            <i class="fa-solid fa-exclamation"></i>
                            <div>Selecione uma demanda na fila para ver os detalhes e iniciar o atendimento.</div>
                        </div>
                    `;
                }

                function renderDetail(row) {
                    if (!row) {
                        renderEmptyDetail();
                        return;
                    }
                    detailEl.innerHTML = `
                        <div class="dm-detail">
                            <div class="dm-detail-head">
                                <div>
                                    <div class="dm-detail-id">ID #${escapeHtml(row.external_item_id)}</div>
                                    <h2 class="dm-detail-title">${escapeHtml(row.external_consultor || 'Consultor externo')}</h2>
                                </div>
                                <span class="dm-status ${escapeHtml(row.demand_status || 'pending')}">${statusLabel(row.demand_status)}</span>
                            </div>

                            <div class="dm-detail-grid">
                                <div class="dm-field">
                                    <div class="dm-field-label">Cliente</div>
                                    <div class="dm-field-value">${escapeHtml(row.client_name || 'Registro sem nome')}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Coluna de origem</div>
                                    <div class="dm-field-value">${escapeHtml(row.stage_name || 'Sem coluna')}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Telefone</div>
                                    <div class="dm-field-value">${escapeHtml(row.phone || '-')}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Cidade</div>
                                    <div class="dm-field-value">${escapeHtml(row.cidade || '-')}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Origem</div>
                                    <div class="dm-field-value">${escapeHtml(row.source || '-')}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Valor</div>
                                    <div class="dm-field-value">${money(row.value)}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Entrada na fila</div>
                                    <div class="dm-field-value">${dateLabel(row.queued_at)}</div>
                                </div>
                                <div class="dm-field">
                                    <div class="dm-field-label">Responsavel interno</div>
                                    <div class="dm-field-value">${escapeHtml(row.accepted_by_name || '-')}</div>
                                </div>
                            </div>

                            <div class="dm-field-label">Observacoes</div>
                            <div class="dm-notes">${escapeHtml(row.notes || 'Sem observacoes.')}</div>
                            <div class="dm-attachments">
                                <div class="dm-field-label">Anexos do card</div>
                                <div class="dm-attachment-upload">
                                    <input type="file" class="form-control form-control-sm" data-demand-attachment-input>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-demand-attachment-upload>Enviar arquivo</button>
                                </div>
                                <div class="dm-attachments-list" data-demand-attachments-list>
                                    <div class="text-muted small">Carregando anexos...</div>
                                </div>
                            </div>
                            <div class="dm-detail-actions">${actionButtons(row)}</div>
                        </div>
                    `;
                    loadAttachments(row.demand_id);
                }

                function attachmentRow(att) {
                    const sizeLabel = att.file_size ? `${(Number(att.file_size) / 1024).toFixed(1)} KB` : '';
                    return `
                        <div class="dm-attachment-item">
                            <div class="dm-attachment-meta">
                                <div class="dm-attachment-name">${escapeHtml(att.filename || 'Arquivo')}</div>
                                <div class="dm-attachment-sub">${escapeHtml(dateLabel(att.created_at))}${sizeLabel ? ' â€¢ ' + escapeHtml(sizeLabel) : ''}</div>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <a class="btn btn-sm btn-outline-secondary dm-attachment-link" href="${api}?action=download_attachment&attachment_id=${encodeURIComponent(att.id)}" target="_blank" rel="noopener">Baixar</a>
                                <button type="button" class="dm-attachment-delete" data-demand-attachment-delete="${escapeHtml(att.id)}" title="Excluir anexo" aria-label="Excluir anexo"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    `;
                }

                async function loadAttachments(demandId) {
                    const list = detailEl.querySelector('[data-demand-attachments-list]');
                    if (!list || !demandId) return;
                    list.innerHTML = '<div class="text-muted small">Carregando anexos...</div>';
                    const res = await fetch(`${api}?action=attachments&demand_id=${encodeURIComponent(demandId)}`);
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.ok) {
                        list.innerHTML = `<div class="text-danger small">${escapeHtml(data.error || 'Falha ao carregar anexos')}</div>`;
                        return;
                    }
                    const items = Array.isArray(data.attachments) ? data.attachments : [];
                    if (!items.length) {
                        list.innerHTML = '<div class="text-muted small">Nenhum arquivo anexado.</div>';
                        return;
                    }
                    list.innerHTML = items.map((att) => `
                        <div class="dm-attachment-item">
                            <div class="dm-attachment-meta">
                                <div class="dm-attachment-name">${escapeHtml(att.filename || 'Arquivo')}</div>
                                <div class="dm-attachment-sub">${escapeHtml(dateLabel(att.created_at))}${att.file_size ? ' • ' + escapeHtml((Number(att.file_size) / 1024).toFixed(1) + ' KB') : ''}</div>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <a class="btn btn-sm btn-outline-secondary dm-attachment-link" href="${api}?action=download_attachment&attachment_id=${encodeURIComponent(att.id)}" target="_blank" rel="noopener">Baixar</a>
                                <button type="button" class="dm-attachment-delete" data-demand-attachment-delete="${escapeHtml(att.id)}" title="Excluir anexo" aria-label="Excluir anexo"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    `).join('');
                }

                async function deleteAttachment(attachmentId) {
                    const payload = new URLSearchParams();
                    payload.set('attachment_id', String(attachmentId));
                    const res = await fetch(`${api}?action=delete_attachment`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: payload.toString()
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || 'Falha ao excluir anexo');
                    }
                    await loadAttachments(selectedId);
                }

                async function uploadAttachment() {
                    if (!selectedId) return;
                    const input = detailEl.querySelector('[data-demand-attachment-input]');
                    const file = input && input.files ? input.files[0] : null;
                    if (!file) {
                        alert('Selecione um arquivo');
                        return;
                    }
                    const formData = new FormData();
                    formData.append('demand_id', String(selectedId));
                    formData.append('attachment', file);
                    const res = await fetch(`${api}?action=upload_attachment`, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || 'Falha ao enviar arquivo');
                    }
                    input.value = '';
                    await loadAttachments(selectedId);
                }

                async function load() {
                    listEl.innerHTML = '<div class="dm-empty-list">Carregando demandas...</div>';
                    const res = await fetch(`${api}?action=list`);
                    const data = await res.json().catch(() => []);
                    if (!res.ok) {
                        throw new Error(data.error || 'Falha ao carregar demandas');
                    }
                    rows = Array.isArray(data) ? data : [];
                    if (selectedId && !rows.some((row) => String(row.demand_id) === String(selectedId))) {
                        selectedId = null;
                    }
                    renderList();
                    renderDetail(rows.find((row) => String(row.demand_id) === String(selectedId)));
                }

                async function postAction(action, id) {
                    const payload = new URLSearchParams();
                    payload.set('id', id);
                    const res = await fetch(`${api}?action=${encodeURIComponent(action)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: payload.toString()
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.error) {
                        throw new Error(data.error || 'Falha ao atualizar demanda');
                    }
                    await load();
                }

                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => {
                        tabs.forEach((item) => item.classList.remove('active'));
                        tab.classList.add('active');
                        currentTab = tab.dataset.view || 'pending';
                        selectedId = null;
                        renderList();
                        renderEmptyDetail();
                    });
                });

                listEl.addEventListener('click', (event) => {
                    const card = event.target.closest('[data-select-demand]');
                    if (!card) return;
                    selectedId = card.dataset.selectDemand;
                    renderList();
                    renderDetail(rows.find((row) => String(row.demand_id) === String(selectedId)));
                });

                detailEl.addEventListener('click', async (event) => {
                    const deleteBtn = event.target.closest('[data-demand-attachment-delete]');
                    if (deleteBtn) {
                        if (!window.confirm('Excluir este anexo?')) return;
                        deleteBtn.disabled = true;
                        try {
                            await deleteAttachment(deleteBtn.dataset.demandAttachmentDelete);
                        } catch (error) {
                            alert(error.message || 'Falha ao excluir anexo');
                        } finally {
                            deleteBtn.disabled = false;
                        }
                        return;
                    }
                    const uploadBtn = event.target.closest('[data-demand-attachment-upload]');
                    if (uploadBtn) {
                        uploadBtn.disabled = true;
                        try {
                            await uploadAttachment();
                        } catch (error) {
                            alert(error.message || 'Falha ao enviar arquivo');
                        } finally {
                            uploadBtn.disabled = false;
                        }
                        return;
                    }
                    const button = event.target.closest('[data-action][data-id]');
                    if (!button) return;
                    button.disabled = true;
                    try {
                        await postAction(button.dataset.action, button.dataset.id);
                    } catch (error) {
                        alert(error.message || 'Falha ao atualizar demanda');
                        button.disabled = false;
                    }
                });

                load().catch((error) => {
                    listEl.innerHTML = `<div class="dm-empty-list">${escapeHtml(error.message)}</div>`;
                    renderEmptyDetail();
                });
            })();
        </script>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
