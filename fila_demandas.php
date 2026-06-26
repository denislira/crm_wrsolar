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
            .dm-empty-list {
                border: 1px dashed #cbd5e1;
                background: #fff;
                color: #64748b;
                border-radius: 12px;
                padding: 1.5rem;
                text-align: center;
                font-weight: 700;
            }
            @media (max-width: 980px) {
                .dm-shell { height: auto; min-height: calc(100vh - 56px); }
                .dm-layout { grid-template-columns: 1fr; }
                .dm-left { border-right: 0; border-bottom: 1px solid #dbe2ea; }
                .dm-right { min-height: 460px; }
            }
            @media (max-width: 560px) {
                .dm-header { align-items: flex-start; padding: .9rem 1rem; }
                .dm-title { align-items: flex-start; flex-direction: column; gap: .35rem; }
                .dm-tabs { gap: .55rem; }
                .dm-tab { min-width: 0; flex: 1; }
                .dm-card-meta,
                .dm-detail-grid { grid-template-columns: 1fr; }
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
                        <button id="dmPendingTab" class="dm-tab active" type="button" data-status="pending">Aguardando (0)</button>
                        <button id="dmSentTab" class="dm-tab" type="button" data-status="sent">Enviados (0)</button>
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
                const tabs = Array.from(document.querySelectorAll('.dm-tab[data-status]'));
                let rows = [];
                let currentTab = 'pending';
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
                                    <h2 class="dm-card-title">${escapeHtml(row.client_name || 'Registro sem nome')}</h2>
                                </div>
                                <span class="dm-priority ${priorityClass(row)}">${priorityLabel(row)}</span>
                            </div>
                            <div class="dm-card-meta">
                                <span><i class="fa-solid fa-user-plus"></i>${escapeHtml(row.external_consultor || '-')}</span>
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
                                    <h2 class="dm-detail-title">${escapeHtml(row.client_name || 'Registro sem nome')}</h2>
                                </div>
                                <span class="dm-status ${escapeHtml(row.demand_status || 'pending')}">${statusLabel(row.demand_status)}</span>
                            </div>

                            <div class="dm-detail-grid">
                                <div class="dm-field">
                                    <div class="dm-field-label">Consultor externo</div>
                                    <div class="dm-field-value">${escapeHtml(row.external_consultor || '-')}</div>
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
                            <div class="dm-detail-actions">${actionButtons(row)}</div>
                        </div>
                    `;
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
                        currentTab = tab.dataset.status || 'pending';
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
