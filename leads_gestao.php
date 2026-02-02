<?php
// leads_gestao.php - Gestão de Leads moderna (Kanban, painel lateral, filtros)
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

checkAccessOrRedirect('leads_gestao');

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
                    <select id="filterCidade" class="form-select form-select-sm">
                        <option value="">Todas cidades</option>
                    </select>
                    <select id="filterScore" class="form-select form-select-sm">
                        <option value="">Todos scores</option>
                        <option value="hot">🔥 Quente (80+)</option>
                        <option value="warm">⚡ Morno (50-79)</option>
                        <option value="cold">❄️ Frio (0-49)</option>
                    </select>
                    <button id="newLeadBtn" class="btn btn-primary btn-sm" style="min-width:160px;">Novo lead</button>
                    <a href="import_leads.php" class="btn btn-sm btn-outline-secondary" title="Importar leads via CSV">Importar CSV</a>
                    <button id="funilConfigBtn" class="btn btn-sm btn-outline-primary" title="Personalizar estágios do funil" onclick="location.href='funil_config.php'">Personalizar Funil</button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-3 mb-3" id="kpiRow">
                <div class="col-md-2">
                    <div class="card p-3">
                        <div class="small text-muted">Leads Anúncios</div>
                        <div class="h4"><span id="anunciosKpiCount">0</span></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3">
                        <div class="small text-muted">Leads ativos</div>
                        <div id="kpiActive" class="h4">0</div>
                    </div>
                </div>
                <div class="col-md-2"><div class="card p-3"><div class="small text-muted">Taxa de conversão</div><div id="kpiConv" class="h4">0%</div></div></div>
                <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Valor no pipeline</div><div id="kpiValue" class="h4">R$ 0,00</div></div></div>
                <div class="col-md-2"><div class="card p-3"><div class="small text-muted">Leads quentes</div><div id="kpiHot" class="h4">0</div></div></div>
            </div>

            <!-- Kanban (loaded from funil_stages table) -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div id="pipelineSummary" class="small text-muted">Pipeline total: <strong id="pipelineTotal">R$ 0,00</strong></div>
                <div class="d-flex gap-2 align-items-center">
                    <button id="bulkDeleteBtn" class="btn btn-sm btn-outline-danger d-none" title="Excluir selecionados"><i class="fa fa-trash"></i></button>
                    <button id="bulkUncheckBtn" class="btn btn-sm btn-outline-secondary d-none" title="Desmarcar todos"><i class="fa fa-times"></i></button>
                    <button id="stalledToggle" class="btn btn-sm btn-outline-secondary">Leads parados</button>
                    <button id="toggleSemStatusBtn" class="btn btn-sm btn-outline-secondary" title="Mostrar/Ocultar coluna Sem Status">Sem Status</button>
                    <button id="toggleAnunciosBtn" class="btn btn-sm btn-outline-secondary" title="Mostrar/Ocultar coluna Anúncios">Anúncios</button>
                    <button id="bulkActionsBtn" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bulkModal">Ações em massa</button>
                    <button id="printSelectedBtn" class="btn btn-sm btn-outline-secondary d-none" title="Imprimir leads selecionados"><i class="fa fa-print"></i> Imprimir Selecionados</button>
                    <button id="toggleViewBtn" class="btn btn-sm btn-outline-secondary" title="Alternar visualização Kanban / Grade"><i class="fa fa-columns"></i></button>
                    <button id="kanbanCompactBtn" class="btn btn-sm btn-outline-secondary" title="Compactar Kanban" style="width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;margin-right:6px;">
                        <i class="fa fa-compress" id="kanbanCompactIcon" aria-hidden="true"></i>
                    </button>
                    <button id="kanbanOnlyBtn" class="btn btn-sm btn-outline-secondary" title="Mostrar somente Kanban" style="width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;margin-left:6px;">
                        <i class="fa fa-expand-arrows-alt" id="kanbanOnlyIcon" aria-hidden="true"></i>
                    </button>
                    
                </div>
            </div>
            <div id="kanbanWrap" class="kanban-wrap">
                <div id="kanbanLoading" class="p-4 text-muted">Carregando etapas do funil...</div>
            </div>

            <!-- Fixed hidden column for Anúncios removed (floating panel not needed) -->

            <!-- Modal: Leads Anúncios -->
            <div id="anunciosModal" class="modal fade" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Leads de Anúncios</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div id="anunciosList" class="list-group">
                                <div class="text-muted small">Carregando...</div>
                            </div>
                            <div class="small text-muted mt-2">Você pode arrastar um lead daqui para uma coluna do Kanban, ou usar o botão "Adicionar ao Kanban".</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
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
                    <button id="expandLeadPanelBtn" class="btn btn-sm btn-light me-2 expand-panel" title="Expandir painel" aria-pressed="false">⇔</button>
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
                            <div class="mb-2"><label class="form-check"><input id="bulkDeleteCheck" class="form-check-input" type="checkbox"> <span class="form-check-label">Excluir selecionados</span></label></div>
                            <div class="small text-muted">Selecione os cards marcando a caixa ao lado deles.</div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button id="bulkApply" type="button" class="btn btn-primary">Aplicar</button></div>
                    </div>
                </div>
            </div>

        </div>
    </main>
    </div>
    <!-- Modal: Manage Statuses -->
    <div id="statusModal" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Gerenciar Status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3 d-flex gap-2">
                        <input id="newStatusName" class="form-control" placeholder="Novo status">
                        <button id="addStatusBtn" class="btn btn-primary">Adicionar</button>
                    </div>
                    <div id="statusList" class="list-group"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
            </div>
        </div>
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
                        <div class="container-fluid">
                            <div class="row">
                                <div class="col-lg-7">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Nome</label>
                                                <input id="lead-name" class="form-control form-control-lg" required placeholder="Nome completo ou empresa">
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Email</label>
                                                    <input id="lead-email" class="form-control" type="email" placeholder="exemplo@dominio.com">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Telefone</label>
                                                    <input id="lead-phone" class="form-control" type="tel" placeholder="(00) 90000-0000">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">CPF / CNPJ</label>
                                                    <input id="lead-cpf-cnpj" class="form-control" placeholder="000.000.000-00 ou 00.000.000/0000-00">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Cidade</label>
                                                    <input id="lead-city" class="form-control" placeholder="Cidade">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Anexar Arquivos</label>
                                                <input id="lead-anexos" class="form-control" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                                <div class="d-flex align-items-center gap-2 mt-2">
                                                    <div class="flex-grow-1"><div class="form-text">PDF, DOC, JPG, PNG (max 10MB cada)</div></div>
                                                    <div><button id="upload-anexos-now" type="button" class="btn btn-sm btn-outline-primary"><i class="fa fa-upload"></i> Enviar</button></div>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Notas</label>
                                                <textarea id="lead-notes" class="form-control" rows="4" placeholder="Observações sobre o lead..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-5">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Status</label>
                                                <select id="lead-status" class="form-select"></select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Último Contato</label>
                                                <input id="lead-ultimo-contato" class="form-control" type="date">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Data de Entrada</label>
                                                <input id="lead-created-at" class="form-control" type="date" placeholder="Data de entrada">
                                                <div class="form-text small">Pode ser ajustada no cadastro inicial; não editável depois.</div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Consumo (R$)</label>
                                                    <input id="lead-consumo" class="form-control" type="number" step="0.01" placeholder="0,00">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Estimativa (kWh)</label>
                                                    <input id="lead-estimativa-kwh" class="form-control" type="number" step="0.01" placeholder="0,00">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Valor de Orçamento</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">R$</span>
                                                    <input id="lead-orcamento" class="form-control currency-mask" type="text" placeholder="0,00">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Forma de Pagamento</label>
                                                <select id="lead-forma-pagamento" class="form-select"><option value="">-- selecione --</option></select>
                                                <div class="form-text">Selecione a forma de pagamento principal do cliente.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Fonte</label>
                                                <input id="lead-source" class="form-control" placeholder="Ex: Facebook, Indicação">
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Anexos movidos para a coluna esquerda para melhor visibilidade -->
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
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="saveAsTemplateCheckbox">
                                <label class="form-check-label small" for="saveAsTemplateCheckbox">Salvar este lembrete como modelo</label>
                            </div>
                            <div class="mt-2" id="saveTemplateNameWrap" style="display:none;">
                                <label class="form-label small">Nome do modelo (opcional)</label>
                                <input id="saveTemplateName" class="form-control form-control-sm" placeholder="Ex: Ligar em 3 dias">
                            </div>
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
                // set current date for ultimo_contato
                const now = new Date().toISOString().slice(0,10);
                document.getElementById('lead-ultimo-contato').value = now;
                    // set current date for created_at (Data de Entrada) and make it editable for new leads
                    const createdEl = document.getElementById('lead-created-at');
                    if (createdEl) { createdEl.value = now; createdEl.disabled = false; createdEl.readOnly = false; }
                leadModal.show();
            });
            document.getElementById('save-lead').addEventListener('click', (e)=>{
                const form = document.getElementById('leadForm');
                // Prefer requestSubmit when available (triggers form submit handlers)
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }
    });
    </script>

    <script>
    // Currency mask for inputs with class 'currency-mask'
    function applyCurrencyMask(input) {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = (value / 100).toFixed(2);
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            e.target.value = value;
        });
        input.addEventListener('focus', function(e) {
            if (e.target.value === '0,00') e.target.value = '';
        });
        input.addEventListener('blur', function(e) {
            if (e.target.value === '') e.target.value = '0,00';
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.currency-mask').forEach(applyCurrencyMask);
    });
    </script>

    <script src="assets/js/leads_gestao.js"></script>
    <?php include 'includes/footer.php'; ?>

