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
    <main class="flex-grow-1 p-4 main-content-scroll">
        <style>
            .fd-shell {
                min-height: calc(100vh - 92px);
                background: #f5f7fb;
                border-radius: 18px;
                padding: 1.25rem;
            }
            .fd-toolbar {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                flex-wrap: wrap;
                align-items: center;
                margin-bottom: 1rem;
            }
            .fd-title h1 {
                margin: 0;
                font-size: 1.55rem;
                font-weight: 800;
                color: #16324f;
            }
            .fd-title p {
                margin: .25rem 0 0;
                color: #64748b;
            }
            .fd-filters {
                display: flex;
                gap: .6rem;
                flex-wrap: wrap;
                align-items: center;
            }
            .fd-filter {
                border: 1px solid #dbe4ef;
                background: #fff;
                border-radius: 10px;
                padding: .55rem .9rem;
                font-weight: 700;
                color: #475569;
            }
            .fd-filter.active {
                background: #0b6ac1;
                color: #fff;
                border-color: #0b6ac1;
            }
            .fd-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
                gap: 1rem;
            }
            .fd-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-top: 4px solid var(--stage-color, #0b6ac1);
                border-radius: 12px;
                padding: 1rem;
                box-shadow: 0 10px 28px rgba(15, 23, 42, .07);
            }
            .fd-card-head {
                display: flex;
                justify-content: space-between;
                gap: .75rem;
                align-items: flex-start;
                margin-bottom: .75rem;
            }
            .fd-client {
                font-size: 1.05rem;
                font-weight: 800;
                color: #0f172a;
                margin: 0;
            }
            .fd-badge {
                border-radius: 999px;
                padding: .25rem .55rem;
                font-size: .68rem;
                font-weight: 800;
                text-transform: uppercase;
                white-space: nowrap;
            }
            .fd-badge.pending { background: #fff7ed; color: #c2410c; }
            .fd-badge.accepted { background: #eff6ff; color: #1d4ed8; }
            .fd-badge.done { background: #ecfdf5; color: #047857; }
            .fd-meta {
                display: grid;
                gap: .45rem;
                color: #64748b;
                font-size: .85rem;
                margin-bottom: .8rem;
            }
            .fd-meta div {
                display: flex;
                gap: .5rem;
                align-items: center;
            }
            .fd-value {
                font-weight: 800;
                color: #16a34a;
                margin-bottom: .75rem;
            }
            .fd-notes {
                border-top: 1px solid #e2e8f0;
                padding-top: .75rem;
                color: #475569;
                font-size: .86rem;
                min-height: 44px;
            }
            .fd-actions {
                display: flex;
                gap: .5rem;
                flex-wrap: wrap;
                margin-top: .9rem;
            }
            .fd-empty {
                background: #fff;
                border: 1px dashed #cbd5e1;
                border-radius: 12px;
                padding: 2rem;
                text-align: center;
                color: #64748b;
                grid-column: 1 / -1;
            }
            body.theme-dark .fd-shell { background: #071427; }
            body.theme-dark .fd-card,
            body.theme-dark .fd-empty { background: #0f1e35; border-color: rgba(230,238,248,.08); }
            body.theme-dark .fd-client,
            body.theme-dark .fd-title h1 { color: #f8fafc; }
            body.theme-dark .fd-title p,
            body.theme-dark .fd-meta,
            body.theme-dark .fd-notes { color: #94a3b8; }
        </style>

        <div class="fd-shell">
            <div class="fd-toolbar">
                <div class="fd-title">
                    <h1>Fila de Demandas</h1>
                    <p>Registros enviados pelos consultores externos para atendimento interno.</p>
                </div>
                <div class="fd-filters" role="group" aria-label="Filtrar demandas">
                    <button class="fd-filter active" type="button" data-status="">Todas</button>
                    <button class="fd-filter" type="button" data-status="pending">Pendentes</button>
                    <button class="fd-filter" type="button" data-status="accepted">Assumidas</button>
                    <button class="fd-filter" type="button" data-status="done">Concluidas</button>
                </div>
            </div>

            <div id="fdGrid" class="fd-grid">
                <div class="fd-empty">Carregando demandas...</div>
            </div>
        </div>

        <script>
            (function () {
                const api = 'includes/consultoria_interna_demandas_api.php';
                const grid = document.getElementById('fdGrid');
                const filterButtons = Array.from(document.querySelectorAll('[data-status]'));
                let currentStatus = '';

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
                    const amount = Number(value || 0);
                    return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                }

                function dateLabel(value) {
                    if (!value) return '-';
                    const date = new Date(String(value).replace(' ', 'T'));
                    if (Number.isNaN(date.getTime())) return '-';
                    return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                }

                function statusLabel(status) {
                    if (status === 'accepted') return 'Assumida';
                    if (status === 'done') return 'Concluida';
                    return 'Pendente';
                }

                function actions(row) {
                    const id = escapeHtml(row.demand_id);
                    if (row.demand_status === 'done') {
                        return `<button class="btn btn-sm btn-outline-secondary" data-action="reopen" data-id="${id}">Reabrir</button>`;
                    }
                    if (row.demand_status === 'accepted') {
                        return `
                            <button class="btn btn-sm btn-success" data-action="complete" data-id="${id}">Concluir</button>
                            <button class="btn btn-sm btn-outline-secondary" data-action="reopen" data-id="${id}">Voltar para fila</button>
                        `;
                    }
                    return `<button class="btn btn-sm btn-primary" data-action="accept" data-id="${id}">Assumir demanda</button>`;
                }

                function render(rows) {
                    if (!Array.isArray(rows) || rows.length === 0) {
                        grid.innerHTML = '<div class="fd-empty">Nenhuma demanda encontrada.</div>';
                        return;
                    }

                    grid.innerHTML = rows.map((row) => `
                        <article class="fd-card" style="--stage-color:${escapeHtml(row.stage_color || '#0b6ac1')}">
                            <div class="fd-card-head">
                                <h2 class="fd-client">${escapeHtml(row.client_name || 'Registro sem nome')}</h2>
                                <span class="fd-badge ${escapeHtml(row.demand_status || 'pending')}">${statusLabel(row.demand_status)}</span>
                            </div>
                            <div class="fd-meta">
                                <div><i class="fa-solid fa-layer-group"></i><span>${escapeHtml(row.stage_name || 'Sem coluna')}</span></div>
                                <div><i class="fa-solid fa-user-tie"></i><span>Externo: ${escapeHtml(row.external_consultor || '-')}</span></div>
                                ${row.accepted_by_name ? `<div><i class="fa-solid fa-user-check"></i><span>Assumida por: ${escapeHtml(row.accepted_by_name)}</span></div>` : ''}
                                ${row.phone ? `<div><i class="fa-solid fa-phone"></i><span>${escapeHtml(row.phone)}</span></div>` : ''}
                                ${row.cidade ? `<div><i class="fa-solid fa-location-dot"></i><span>${escapeHtml(row.cidade)}</span></div>` : ''}
                                <div><i class="fa-regular fa-clock"></i><span>Entrada: ${dateLabel(row.queued_at)}</span></div>
                            </div>
                            <div class="fd-value">${money(row.value)}</div>
                            <div class="fd-notes">${escapeHtml(row.notes || 'Sem observacoes.')}</div>
                            <div class="fd-actions">${actions(row)}</div>
                        </article>
                    `).join('');
                }

                async function load() {
                    grid.innerHTML = '<div class="fd-empty">Carregando demandas...</div>';
                    const url = currentStatus ? `${api}?action=list&status=${encodeURIComponent(currentStatus)}` : `${api}?action=list`;
                    const res = await fetch(url);
                    const data = await res.json().catch(() => []);
                    if (!res.ok) {
                        throw new Error(data.error || 'Falha ao carregar demandas');
                    }
                    render(data);
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

                filterButtons.forEach((button) => {
                    button.addEventListener('click', async () => {
                        filterButtons.forEach((item) => item.classList.remove('active'));
                        button.classList.add('active');
                        currentStatus = button.dataset.status || '';
                        try {
                            await load();
                        } catch (error) {
                            grid.innerHTML = `<div class="fd-empty">${escapeHtml(error.message)}</div>`;
                        }
                    });
                });

                grid.addEventListener('click', async (event) => {
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
                    grid.innerHTML = `<div class="fd-empty">${escapeHtml(error.message)}</div>`;
                });
            })();
        </script>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
