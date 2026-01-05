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
                    <button id="funilConfigBtn" class="btn btn-sm btn-outline-primary" title="Personalizar estágios do funil" onclick="location.href='funil_config.php'">Personalizar Funil</button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-3 mb-3" id="kpiRow">
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Leads ativos</div><div id="kpiActive" class="h4">0</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Taxa de conversão</div><div id="kpiConv" class="h4">0%</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Valor no pipeline</div><div id="kpiValue" class="h4">R$ 0,00</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Leads quentes</div><div id="kpiHot" class="h4">0</div></div></div>
            </div>

            <!-- Kanban (loaded from funil_stages table) -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div id="pipelineSummary" class="small text-muted">Pipeline total: <strong id="pipelineTotal">R$ 0,00</strong></div>
                <div class="d-flex gap-2 align-items-center">
                    <button id="stalledToggle" class="btn btn-sm btn-outline-secondary">Leads parados</button>
                    <button id="bulkActionsBtn" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bulkModal">Ações em massa</button>
                    <button id="toggleViewBtn" class="btn btn-sm btn-outline-secondary" title="Alternar visualização Kanban / Grade"><i class="fa fa-columns"></i></button>
                </div>
            </div>
            <div id="kanbanWrap" class="kanban-wrap">
                <div id="kanbanLoading" class="p-4 text-muted">Carregando etapas do funil...</div>
            </div>

            <!-- Lista / Grade alternativa -->
            <div id="listWrap" class="list-wrap d-none p-2">
                <div id="listLoading" class="p-4 text-muted d-none">Carregando lista...</div>
                <div id="leadsTableContainer"></div>
            </div>

            <!-- Detalhes do Lead (painel lateral) -->
            <aside id="leadDetailsPanel" class="lead-panel hidden">
                <div class="lead-panel-inner">
                    <button id="closeLeadPanel" class="btn btn-sm btn-light close-panel" title="Fechar">✕</button>
                    <div id="leadDetailContent" class="p-3"></div>
                </div>
            </aside>

            <!-- Modal: Ações em massa -->
            <div id="bulkModal" class="modal fade" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Ações em massa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-2"><label class="form-label">Mover selecionados para</label>
                                <select id="bulkTargetStage" class="form-select">
                                    <option value="">Escolher etapa</option>
                                </select>
                            </div>
                            <div class="mb-2"><label class="form-label">Atribuir responsável (opcional)</label><input id="bulkAssign" class="form-control" placeholder="Nome do responsável"></div>
                            <div class="small text-muted">Selecione os cards marcando a caixa ao lado deles.</div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button id="bulkApply" type="button" class="btn btn-primary">Aplicar</button></div>
                    </div>
                </div>
            </div>

        </div>
    </main>
    </div>

    <!-- Modals: lead + reminder (placed outside main content to avoid nesting issues) -->
    <div class="modal fade" id="leadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="leadModalTitle">
                        <i class="fa-regular fa-user-plus text-primary"></i> <span>Novo Lead</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form id="leadForm" enctype="multipart/form-data">
                        <input type="hidden" id="lead-id">
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Nome <i class="fa fa-user text-muted"></i></label>
                                        <input id="lead-name" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select id="lead-status" class="form-select">
                                            <option value="">Novo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <label class="form-label">Email <i class="fa fa-envelope text-muted"></i></label>
                                        <input id="lead-email" class="form-control" type="email">
                                        <label class="form-label mt-2">Telefone <i class="fa fa-phone text-muted"></i></label>
                                        <input id="lead-phone" class="form-control" type="tel">
                                        <label class="form-label mt-2">CPF / CNPJ <i class="fa fa-id-card text-muted"></i></label>
                                        <input id="lead-cpf-cnpj" class="form-control" placeholder="000.000.000-00 ou 00.000.000/0000-00">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <label class="form-label">Consumo do Cliente (R$) <i class="fa fa-bolt text-warning"></i></label>
                                        <input id="lead-consumo" class="form-control" type="number" step="0.01" placeholder="0,00">
                                        <label class="form-label mt-2">Estimativa do Projeto (kWh) <i class="fa fa-solar-panel text-info"></i></label>
                                        <input id="lead-estimativa-kwh" class="form-control" type="number" step="0.01" placeholder="0,00">
                                        <label class="form-label mt-2">Fonte <i class="fa fa-globe text-muted"></i></label>
                                        <input id="lead-source" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <label class="form-label">Anexar Arquivos <i class="fa fa-paperclip text-muted"></i></label>
                                        <input id="lead-anexos" class="form-control" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                        <div class="form-text">Formatos aceitos: PDF, DOC, DOCX, JPG, PNG</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3 h-100">
                                    <div class="card-body d-flex flex-column h-100">
                                        <label class="form-label">Notas de Observação <i class="fa fa-sticky-note text-muted"></i></label>
                                        <textarea id="lead-notes" class="form-control" rows="4" placeholder="Digite suas observações sobre este lead..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button id="save-lead" type="submit" form="leadForm" class="btn btn-primary"><i class="fa fa-save"></i> Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reminderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Novo Lembrete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <form id="reminderForm">
                    <input type="hidden" id="reminderLeadId" name="lead_id">
                    <input type="hidden" id="reminderId" name="reminder_id">
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">Modelos</label>
                            <select id="reminderTemplateSelect" class="form-select mb-2"><option value="">-- nenhum --</option></select>
                            <label class="form-label">Mensagem</label>
                            <textarea id="reminderMessage" name="message" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-7">
                                <label class="form-label">Data</label>
                                <input id="reminderDate" name="date" type="date" class="form-control" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label">Hora</label>
                                <input id="reminderTime" name="time" type="time" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Adicionar lembrete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // wire lead modal open/save
    document.addEventListener('DOMContentLoaded', () => {
        const addBtn = document.getElementById('newLeadBtn');
        const leadModalEl = document.getElementById('leadModal');
        if(addBtn && leadModalEl){
            const leadModal = new bootstrap.Modal(leadModalEl);
            addBtn.addEventListener('click', ()=>{
                document.getElementById('leadForm').reset();
                document.getElementById('lead-id').value = '';
                document.getElementById('leadModalTitle').textContent = 'Novo Lead';
                leadModal.show();
            });
            document.getElementById('save-lead').addEventListener('click', async ()=>{
                const id = document.getElementById('lead-id').value;
                const formData = new FormData(document.getElementById('leadForm'));
                if (id) formData.append('id', id);
                const action = id ? 'update' : 'add';
                try {
                    const res = await fetch('includes/leads_api.php?action='+action, { method: 'POST', body: formData });
                    if (res.ok) { leadModal.hide(); location.reload(); } else { alert('Erro ao salvar lead'); }
                } catch (err) { console.error(err); alert('Erro ao salvar lead'); }
            });
        }
    });
    </script>

    <script src="assets/js/leads_gestao.js"></script>
    <?php include 'includes/footer.php'; ?>
                    </form>

                </div>

