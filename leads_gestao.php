<?php
// leads_gestao.php - Gestão de Leads moderna (Kanban, painel lateral, filtros)
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Gestão de Leads';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/leads_gestao.css">
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4 main-content-scroll">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h4 mb-0">Gestão de Leads</h1>
                <div class="d-flex gap-2 align-items-center">
                    <input id="searchInput" class="form-control form-control-sm" placeholder="Buscar por nome ou empresa..." style="min-width:280px">
                    <select id="filterScore" class="form-select form-select-sm">
                        <option value="">Todos scores</option>
                        <option value="hot">🔥 Quente (80+)</option>
                        <option value="warm">⚡ Morno (50-79)</option>
                        <option value="cold">❄️ Frio (0-49)</option>
                    </select>
                    <button id="newLeadBtn" class="btn btn-primary btn-sm">Novo lead</button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-3 mb-3" id="kpiRow">
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Leads ativos</div><div id="kpiActive" class="h4">0</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Taxa de conversão</div><div id="kpiConv" class="h4">0%</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Valor no pipeline</div><div id="kpiValue" class="h4">R$ 0,00</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Leads quentes</div><div id="kpiHot" class="h4">0</div></div></div>
            </div>

            <!-- Kanban -->
            <div id="kanbanWrap" class="kanban-wrap">
                <div class="kanban-column" data-stage="Novo">
                    <div class="kanban-header">Novo Lead <span class="badge bg-light text-muted" id="count-Novo">0</span></div>
                    <div class="column-content" id="col-Novo"></div>
                </div>
                <div class="kanban-column" data-stage="Contato Feito">
                    <div class="kanban-header">Contato Feito <span class="badge bg-light text-muted" id="count-Contato Feito">0</span></div>
                    <div class="column-content" id="col-Contato Feito"></div>
                </div>
                <div class="kanban-column" data-stage="Proposta Enviada">
                    <div class="kanban-header">Proposta Enviada <span class="badge bg-light text-muted" id="count-Proposta Enviada">0</span></div>
                    <div class="column-content" id="col-Proposta Enviada"></div>
                </div>
                <div class="kanban-column" data-stage="Negociação">
                    <div class="kanban-header">Negociação <span class="badge bg-light text-muted" id="count-Negociação">0</span></div>
                    <div class="column-content" id="col-Negociação"></div>
                </div>
                <div class="kanban-column" data-stage="Ganhou">
                    <div class="kanban-header">Ganhou <span class="badge bg-light text-muted" id="count-Ganhou">0</span></div>
                    <div class="column-content" id="col-Ganhou"></div>
                </div>
                <div class="kanban-column" data-stage="Perdeu">
                    <div class="kanban-header">Perdeu <span class="badge bg-light text-muted" id="count-Perdeu">0</span></div>
                    <div class="column-content" id="col-Perdeu"></div>
                </div>
            </div>

            <!-- Detalhes do Lead (painel lateral) -->
            <aside id="leadDetailsPanel" class="lead-panel hidden">
                <div class="lead-panel-inner">
                    <button id="closeLeadPanel" class="btn btn-sm btn-light close-panel" title="Fechar">✕</button>
                    <div id="leadDetailContent" class="p-3"></div>
                </div>
            </aside>

        </div>
    </main>
</div>

<!-- Modal: criar/editar lead -->
<div id="leadModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="leadForm">
                <div class="modal-header"><h5 id="leadModalTitle" class="modal-title">Novo Lead</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" id="leadId">
                    <div class="mb-2"><label class="form-label">Nome</label><input id="leadName" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Empresa</label><input id="leadCompany" class="form-control"></div>
                    <div class="row g-2"><div class="col"><label class="form-label">Email</label><input id="leadEmail" class="form-control" type="email"></div><div class="col"><label class="form-label">Telefone</label><input id="leadPhone" class="form-control"></div></div>
                    <div class="mb-2"><label class="form-label">Valor estimado (R$)</label><input id="leadValue" class="form-control" type="number" step="0.01"></div>
                    <div class="mb-2"><label class="form-label">Notas</label><textarea id="leadNotes" class="form-control" rows="3"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/leads_gestao.js"></script>
<?php include 'includes/footer.php'; ?>
