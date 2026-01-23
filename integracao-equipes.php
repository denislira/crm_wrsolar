<?php
// Integração de Equipes - Centraliza informações e tarefas entre equipes
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'includes/config.php';
include 'includes/permissions.php';

checkAccessOrRedirect('integracao-equipes');


// Buscar equipes (preferencialmente da tabela `teams`) e responsáveis distintos para filtros
$equipes = [];
try {
    $teamsStmt = $pdo->query('SELECT name FROM teams ORDER BY name');
    $rows = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        foreach ($rows as $r) $equipes[] = $r['name'];
    }
} catch (Exception $e) {
    // tabela teams pode não existir em instalações antigas — fallback para lista estática
    $equipes = ['Marketing','Vendas','Atendimento','Técnica','Financeiro'];
}




$respStmt = $pdo->prepare('SELECT DISTINCT responsavel FROM team_tasks WHERE user_id = ? AND responsavel IS NOT NULL AND responsavel <> ""');
$respStmt->execute([$_SESSION['user_id']]);
$responsaveis = array_map(function($r){return $r['responsavel'];}, $respStmt->fetchAll(PDO::FETCH_ASSOC));

$pageTitle = 'Integração de Equipes';
include 'includes/header.php';
?>

<style>
.section-left-border { border-left: 6px solid #0b6ac1 !important; }
.section-left-border.secondary { border-left-color: #6c757d !important; }
.section-left-border.primary { border-left-color: #0d6efd !important; }
.section-left-border.success { border-left-color: #198754 !important; }
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
<!-- Modal Editar Lembrete -->
<div class="modal fade" id="modalEditarLembrete" tabindex="-1" aria-labelledby="modalEditarLembreteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title" id="modalEditarLembreteLabel"><i class="fa fa-edit text-primary"></i> Editar Lembrete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarLembrete">
                    <input type="hidden" id="editRem-id" name="id">
                    <div class="mb-2">
                        <label class="form-label">Lead</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" id="editRem-lead-ident" class="form-control form-control-sm" readonly>
                            </div>
                            <div class="col-md-3">
                                <input type="text" id="editRem-lead-id" class="form-control form-control-sm" readonly placeholder="ID">
                            </div>
                            <div class="col-md-3">
                                <input type="text" id="editRem-lead-phone" class="form-control form-control-sm" readonly placeholder="Telefone">
                            </div>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label">Data</label>
                            <input type="date" id="editRem-date" class="form-control form-control-sm">
                        </div>
                        <div class="col-5">
                            <label class="form-label">Hora</label>
                            <input type="time" id="editRem-time" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label">Modelo</label>
                        <select id="editRem-template" class="form-select form-select-sm"><option value="">(Nenhum)</option></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Mensagem</label>
                        <textarea id="editRem-message" class="form-control form-control-sm" rows="4"></textarea>
                    </div>
                </form>
                <div id="editarLembreteMsg" class="mt-2"></div>
            </div>
            <div class="modal-footer d-flex justify-content-end gap-2">
                <button id="btnExcluirLembrete" type="button" class="btn btn-danger me-auto"><i class="fa fa-trash"></i> Excluir</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button id="btnSalvarEdicaoLembrete" type="button" class="btn btn-primary"><i class="fa fa-save"></i> Salvar alterações</button>
            </div>
        </div>
    </div>
</div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h4 mb-1">Integração de Equipes</h1>
                    <div class="text-muted">Centraliza informações e tarefas entre equipes de marketing, vendas e atendimento. Melhora a comunicação interna e reduz falhas no processo.</div>
                </div>
                <div>
                </div>
            </div>

            <!-- Abas principais: Tarefas / Lembretes -->
            <div class="mb-3">
                <div class="btn-group" role="group" aria-label="view-tabs">
                    <button id="tabTarefas" type="button" class="btn btn-primary active">Tarefas de Equipe</button>
                    <button id="tabMinhasTarefas" type="button" class="btn btn-outline-primary">Minhas Tarefas</button>
                    <button id="tabLembretesBtn" type="button" class="btn btn-outline-primary">Lembretes</button>
                </div>
            </div>

            <!-- Aba: Tarefas de Equipe -->
            <div id="tarefasArea">
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card card-shadow p-3 mb-3 border-start border-3 border-secondary section-left-border secondary">
                        <h6 class="mb-3">Tarefas de Equipe</h6>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            <select id="filtroEquipe" class="form-select form-select-sm w-auto" style="display:none;">
                                <option value="">Todas equipes</option>
                                <?php foreach ($equipes as $eq): ?><option value="<?php echo $eq; ?>"><?php echo $eq; ?></option><?php endforeach; ?>
                            </select>
                            <select id="filtroResp" class="form-select form-select-sm w-auto">
                                <option value="">Todos responsáveis</option>
                                <?php foreach ($responsaveis as $r): ?><option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option><?php endforeach; ?>
                            </select>
                            <select id="filtroStatus" class="form-select form-select-sm w-auto">
                                <option value="">Todos status</option>
                                <option value="Pendente">Pendente</option>
                                <option value="Em andamento">Em andamento</option>
                                <option value="Concluída">Concluída</option>
                            </select>
                            <input type="search" id="filtroBusca" class="form-control form-control-sm w-50" placeholder="Buscar tarefa...">
                        </div>
                        <div id="tasksList"></div>
                        <!-- Modal Nova Tarefa -->
                        <div class="modal fade" id="modalNovaTarefa" tabindex="-1" aria-labelledby="modalNovaTarefaLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-light border-bottom">
                                        <h5 class="modal-title" id="modalNovaTarefaLabel"><i class="fa fa-plus text-success"></i> Nova Tarefa de Equipe</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="formModalNovaTarefa">
                                            <div class="mb-2">
                                                <label class="form-label">Título <i class="fa fa-tasks text-muted"></i></label>
                                                <input type="text" name="titulo" id="new-titulo" class="form-control" required>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">Descrição <i class="fa fa-align-left text-muted"></i></label>
                                                <textarea name="descricao" id="new-descricao" class="form-control" rows="3"></textarea>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">Responsável <i class="fa fa-user text-muted"></i></label>
                                                <div class="form-control-plaintext fw-semibold"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                                                <input type="hidden" name="responsavel" id="new-responsavel" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>">
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Data de vencimento <i class="fa fa-calendar text-muted"></i></label>
                                                    <input type="date" name="data_vencimento" id="new-data-vencimento" class="form-control">
                                                </div>
                                                <div class="col-md-6"></div>
                                            </div>
                                            <div class="mt-2">
                                                <label class="form-label">Status <i class="fa fa-flag text-muted"></i></label>
                                                <select name="status" id="new-status" class="form-select">
                                                    <option value="Pendente">Pendente</option>
                                                    <option value="Em andamento">Em andamento</option>
                                                    <option value="Concluída">Concluída</option>
                                                </select>
                                            </div>
                                            <input type="hidden" name="equipe" id="new-equipe" value="">
                                        </form>
                                        <div id="novaTarefaModalMsg" class="mt-2"></div>
                                    </div>
                                    <div class="modal-footer d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button id="btnSalvarNovaModal" type="button" class="btn btn-success"><i class="fa fa-save"></i> Salvar</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Editar Tarefa -->
                                                <div class="modal fade" id="modalEditarTarefa" tabindex="-1" aria-labelledby="modalEditarTarefaLabel" aria-hidden="true">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-light border-bottom">
                                                                <h5 class="modal-title" id="modalEditarTarefaLabel"><i class="fa fa-edit text-primary"></i> Editar Tarefa de Equipe</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <form id="formEditarTarefa">
                                                                    <input type="hidden" name="id" id="edit-id">
                                                                    <div class="mb-2">
                                                                        <label class="form-label">Título <i class="fa fa-tasks text-muted"></i></label>
                                                                        <input type="text" name="titulo" id="edit-titulo" class="form-control" required>
                                                                    </div>
                                                                    <div class="mb-2">
                                                                        <label class="form-label">Descrição <i class="fa fa-align-left text-muted"></i></label>
                                                                        <textarea name="descricao" id="edit-descricao" class="form-control" rows="3"></textarea>
                                                                    </div>
                                                                    <div class="mb-2">
                                                                        <label class="form-label">Responsável <i class="fa fa-user text-muted"></i></label>
                                                                        <div class="form-control-plaintext fw-semibold" id="edit-responsavel_display"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                                                                        <input type="hidden" name="responsavel" id="edit-responsavel" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>">
                                                                    </div>
                                                                    <div class="row g-3">
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Data de vencimento <i class="fa fa-calendar text-muted"></i></label>
                                                                            <input type="date" name="data_vencimento" id="edit-data-vencimento" class="form-control">
                                                                        </div>
                                                                        <div class="col-md-6"></div>
                                                                    </div>
                                                                    <div class="mt-2">
                                                                        <label class="form-label">Status <i class="fa fa-flag text-muted"></i></label>
                                                                        <select name="status" id="edit-status" class="form-select">
                                                                            <option value="Pendente">Pendente</option>
                                                                            <option value="Em andamento">Em andamento</option>
                                                                            <option value="Concluída">Concluída</option>
                                                                        </select>
                                                                    </div>
                                                                    <input type="hidden" name="equipe" id="edit-equipe" value="">
                                                                </form>
                                                                <div id="editarTarefaMsg" class="mt-2"></div>
                                                            </div>
                                                            <div class="modal-footer d-flex justify-content-end gap-2">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                <button id="btnSalvarEdicao" type="button" class="btn btn-primary"><i class="fa fa-save"></i> Salvar alterações</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                    </div>
                    <div class="card card-shadow p-3 border-start border-3 border-secondary section-left-border secondary">
                        <h6 class="mb-2">Atividades recentes</h6>
                        <ul id="teamTimeline" class="list-unstyled mb-0">
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card card-shadow p-3 border-start border-3 border-secondary section-left-border secondary" style="display:none;">
                        <h6 class="mb-2">Resumo de Equipes</h6>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($equipes as $eq): ?>
                                <li class="py-2 border-bottom">
                                    <strong><?php echo $eq; ?></strong>
                                    <span class="small text-muted d-block">Tarefas atribuídas: <span id="count_<?php echo strtolower($eq); ?>">...</span></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="card card-shadow p-3 mt-3 border-start border-3 border-primary section-left-border primary">
                        <div class="d-flex align-items-start mb-3">
                            <div class="me-3 display-6 text-primary"><i class="fa fa-plus-circle"></i></div>
                            <div>
                                <h6 class="mb-0">Nova Tarefa</h6>
                                <small class="text-muted">Crie uma tarefa rápida para você</small>
                            </div>
                        </div>
                        <form id="formNovaTarefa" class="mb-0">
                            <div class="mb-2">
                                <label class="form-label small">Título <span class="text-danger">*</span></label>
                                <input type="text" name="titulo" class="form-control form-control-sm" placeholder="Ex: Revisar proposta" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Descrição</label>
                                <textarea name="descricao" class="form-control form-control-sm" rows="3" placeholder="Detalhes (opcional)"></textarea>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-7">
                                    <label class="form-label small">Responsável</label>
                                    <div class="form-control-plaintext small fw-semibold"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                                    <input type="hidden" name="responsavel" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>">
                                </div>
                                <div class="col-5">
                                    <label class="form-label small">Vencimento</label>
                                    <input type="date" name="data_vencimento" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="Pendente">Pendente</option>
                                    <option value="Em andamento">Em andamento</option>
                                    <option value="Concluída">Concluída</option>
                                </select>
                            </div>
                            <input type="hidden" name="equipe" value="">
                            <button type="submit" class="btn btn-primary w-100">Salvar tarefa</button>
                        </form>
                        <div id="novaTarefaMsg" class="mt-2"></div>
                    </div>
                </div>
            </div>
            <!-- Fim Aba Tarefas -->

            <!-- Aba: Minhas Tarefas (apenas do usuário logado) -->
            <div id="minhasArea" style="display:none;">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="card card-shadow p-3 mb-3 border-start border-3 border-secondary section-left-border secondary">
                            <h6 class="mb-3">Minhas Tarefas</h6>
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <select id="myFiltroStatus" class="form-select form-select-sm w-auto">
                                    <option value="">Todos status</option>
                                    <option value="Pendente">Pendente</option>
                                    <option value="Em andamento">Em andamento</option>
                                    <option value="Concluída">Concluída</option>
                                </select>
                                <input type="search" id="myFiltroBusca" class="form-control form-control-sm w-50" placeholder="Buscar tarefa...">
                            </div>
                            <div id="myTasksList"></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card card-shadow p-3 mt-3 border-start border-3 border-primary section-left-border primary">
                            <div class="d-flex align-items-start mb-3">
                                <div class="me-3 display-6 text-primary"><i class="fa fa-plus-circle"></i></div>
                                <div>
                                    <h6 class="mb-0">Nova Tarefa</h6>
                                    <small class="text-muted">Crie uma tarefa rápida para você</small>
                                </div>
                            </div>
                            <!-- Reusa o mesmo formulário de criação rápida presente na coluna direita -->
                            <form id="formNovaTarefa_My" class="mb-0">
                                <div class="mb-2">
                                    <label class="form-label small">Título <span class="text-danger">*</span></label>
                                    <input type="text" name="titulo" class="form-control form-control-sm" placeholder="Ex: Revisar proposta" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Descrição</label>
                                    <textarea name="descricao" class="form-control form-control-sm" rows="3" placeholder="Detalhes (opcional)"></textarea>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-7">
                                        <label class="form-label small">Responsável</label>
                                        <div class="form-control-plaintext small fw-semibold"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                                        <input type="hidden" name="responsavel" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>">
                                    </div>
                                    <div class="col-5">
                                        <label class="form-label small">Vencimento</label>
                                        <input type="date" name="data_vencimento" class="form-control form-control-sm">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small">Status</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="Pendente">Pendente</option>
                                        <option value="Em andamento">Em andamento</option>
                                        <option value="Concluída">Concluída</option>
                                    </select>
                                </div>
                                <input type="hidden" name="equipe" value="">
                                <button type="submit" class="btn btn-primary w-100">Salvar tarefa</button>
                            </form>
                            <div id="novaTarefaMsg_My" class="mt-2"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Fim Aba Minhas Tarefas -->
            </div>
            <!-- Fim Aba Tarefas -->

            <!-- Aba: Lembretes -->
            <div id="lembretesArea" style="display:none;">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="form-card-modern mb-3 section-left-border primary">
                            <div class="heading-with-icon">
                                <i class="fa fa-calendar-check-o"></i>
                                <h6>Agenda para Hoje</h6>
                            </div>
                            <div id="agendaHoje" class="list-unstyled small text-muted">Carregando...</div>
                        </div>
                        <div class="form-card-modern section-left-border">
                            <div class="heading-with-icon">
                                <i class="fa fa-bell-o"></i>
                                <h6>Agendar Lembrete</h6>
                            </div>
                            <form id="formNovoLembrete">
                                <div class="mb-2 position-relative">
                                    <label class="form-label">Identificação do Lead</label>
                                    <input type="text" name="lead_ident" id="rem-lead-ident" class="form-control form-control-sm" placeholder="Digite nome, email ou telefone do lead" autocomplete="off">
                                    <div id="leadSuggestions" class="list-group position-absolute bg-white border" style="display:none; z-index:1000; max-height:200px; overflow-y:auto; width:100%;"></div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Lead ID (automático)</label>
                                    <input type="text" name="lead_id" id="rem-lead-id" class="form-control form-control-sm" readonly placeholder="Será preenchido automaticamente">
                                </div>
                                <div class="mb-2 d-flex gap-2">
                                    <div class="flex-fill">
                                        <label class="form-label">Data</label>
                                        <input type="date" name="date" id="rem-date" class="form-control form-control-sm" required>
                                    </div>
                                    <div style="width:110px;">
                                        <label class="form-label">Hora</label>
                                        <input type="time" name="time" id="rem-time" class="form-control form-control-sm" required>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Modelo</label>
                                    <select id="rem-template" name="template_id" class="form-select form-select-sm">
                                        <option value="">(Nenhum)</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Mensagem</label>
                                    <textarea name="message" id="rem-message" class="form-control form-control-sm" rows="3" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Salvar Lembrete</button>
                                <div id="remMsg" class="mt-2"></div>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="form-card-modern section-left-border">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="heading-with-icon mb-0">
                                    <i class="fa fa-calendar"></i>
                                    <h6 class="mb-0">Próximos Lembretes</h6>
                                </div>
                                <span id="totalLeadsBtn" class="badge" style="background:#5b21b6;color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;">0 LEADS</span>
                            </div>
                            <p class="text-muted small mb-3" style="font-size:13px;">Lista cronológica de atividades</p>
                            <div id="proximosLembretes">Carregando...</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Fim Aba Lembretes -->

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>

<script type="module">
import { fetchTasks, addTask, updateTask, deleteTask, fetchRecentActivities } from './assets/js/team_tasks.js';

const equipes = <?php echo json_encode($equipes); ?>;
const responsaveis = <?php echo json_encode($responsaveis); ?>;
const userId = <?php echo json_encode($_SESSION['user_id']); ?>;

function escapeHtmlGlobal(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (s) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s]; });
}

// Alias para compatibilidade
const escapeHtml = escapeHtmlGlobal;

// Funções para atualizar a lista de tarefas e a linha do tempo
async function atualizarTarefas() {
    const equipeFiltro = document.getElementById('filtroEquipe').value;
    const respFiltro = document.getElementById('filtroResp').value;
    const statusFiltro = document.getElementById('filtroStatus').value;
    const buscaFiltro = document.getElementById('filtroBusca').value;

    const list = document.getElementById('tasksList');
    list.innerHTML = '';
    if (statusFiltro === 'Lembretes') {
        // load reminders instead of tasks
        try {
            const res = await fetch('includes/reminders_api.php?action=list');
            if (!res.ok) throw new Error('Falha ao carregar lembretes');
            const reminders = await res.json();
            if (!reminders.length) { list.innerHTML = '<div class="text-muted">Nenhum lembrete encontrado.</div>'; }
            reminders.forEach(r => {
                const card = document.createElement('div');
                card.className = 'mb-2 p-2 border rounded d-flex align-items-center gap-3 bg-white';
                const avatar = document.createElement('div'); avatar.className = 'rounded-circle d-flex align-items-center justify-content-center me-2'; avatar.style.width = '38px'; avatar.style.height = '38px'; avatar.style.background = '#0b6ac1'; avatar.style.color = '#fff'; avatar.style.fontWeight = 'bold'; avatar.style.fontSize = '1.1rem'; avatar.textContent = 'L';
                card.appendChild(avatar);
                const content = document.createElement('div'); content.className = 'flex-grow-1';
                content.innerHTML = `<div class="fw-semibold">${escapeHtml(r.lead_name || ('Lead #' + r.lead_id))} <span class="badge ms-2" style="background:#0b6ac1;color:#fff;">Lembrete</span></div>
                    <div class="small text-muted">Agendado: ${r.remind_at} • Status: ${escapeHtml(r.status)}</div>
                    <div class="mt-1">${escapeHtml(r.message || '')}</div>`;
                card.appendChild(content);
                // make whole card clickable to edit
                card.style.cursor = 'pointer';
                card.addEventListener('click', () => openEditReminderModal(r.id));
                list.appendChild(card);
            });
        } catch (e) { console.error(e); list.innerHTML = '<div class="text-danger">Erro carregando lembretes</div>'; }
        return;
    }
    const tarefas = await fetchTasks({equipe: equipeFiltro, responsavel: respFiltro, status: statusFiltro});
    if (!tarefas.length) {
        list.innerHTML = '<div class="text-muted">Nenhuma tarefa encontrada.</div>';
    } else {
        tarefas.forEach(t => {
            const card = document.createElement('div');
            card.className = 'mb-2 p-2 border rounded d-flex align-items-center gap-3 bg-white';
            // Avatar
            const avatar = document.createElement('div');
            avatar.className = 'rounded-circle d-flex align-items-center justify-content-center me-2';
            avatar.style.width = '38px';
            avatar.style.height = '38px';
            avatar.style.background = equipeColor(t.equipe);
            avatar.style.color = '#fff';
            avatar.style.fontWeight = 'bold';
            avatar.style.fontSize = '1.1rem';
            avatar.textContent = t.responsavel ? initials(t.responsavel) : '?';
            card.appendChild(avatar);
            // Conteúdo
            const content = document.createElement('div');
            content.className = 'flex-grow-1';
            content.innerHTML = `<div class="fw-semibold">${escapeHtml(t.titulo)} <span class="badge ms-2" style="background:${equipeColor(t.equipe)};color:#fff;">${escapeHtml(t.equipe)}</span> <span class="badge ms-1" style="background:${statusColor(t.status)};color:#fff;">${escapeHtml(t.status)}</span></div>
                <div class="small text-muted">${t.responsavel ? 'Responsável: ' + escapeHtml(t.responsavel) : ''} ${t.data_vencimento ? ' | Vencimento: ' + t.data_vencimento : ''}</div>
                <div class="mt-1">${escapeHtml(t.descricao || '')}</div>`;
            card.appendChild(content);
            // Ações
            const actions = document.createElement('div');
            actions.className = 'd-flex flex-column gap-1';
            actions.innerHTML = `<button class="btn btn-sm btn-outline-primary" title="Editar"><i class="fa fa-edit"></i></button>
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="fa fa-trash"></i></button>`;
            // Adicionar eventos de editar/excluir
            const editBtn = actions.querySelector('.btn-outline-primary');
            const deleteBtn = actions.querySelector('.btn-outline-danger');
            editBtn.addEventListener('click', () => openEditModal(t));
            deleteBtn.addEventListener('click', () => deleteTaskConfirm(t.id));
            card.appendChild(actions);
            list.appendChild(card);
        });
    }
    // Adicionar eventos aos checkboxes
    document.querySelectorAll('.task-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    // Atualizar contadores de tarefas por equipe
    <?php foreach ($equipes as $eq): ?>
        document.getElementById('count_<?php echo strtolower($eq); ?>').innerText = tarefas.filter(t => t.equipe === '<?php echo $eq; ?>').length;
    <?php endforeach; ?>

    // Carregar atividades recentes
    loadRecentActivities();
    function initials(nome) {
        return nome.split(' ').map(p=>p[0]).join('').toUpperCase().slice(0,2);
    }
    function equipeColor(eq) {
        const map = {Marketing:'#3bb273',Vendas:'#0b6ac1',Atendimento:'#ffd24a',Técnica:'#7c3aed',Financeiro:'#ef4444'};
        return map[eq]||'#888';
    }
    function statusColor(st) {
        const map = {'Pendente':'#f97316','Em andamento':'#06b6d4','Concluída':'#3bb273'};
        return map[st]||'#888';
    }
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function (s) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s]; });
    }
}

// Notificação visual ao criar tarefa
function showNotification(msg, tipo='success') {
  const el = document.getElementById('novaTarefaMsg');
  el.textContent = msg;
  el.className = tipo === 'success' ? 'alert alert-success mt-2' : 'alert alert-danger mt-2';
  setTimeout(()=>{el.textContent='';el.className='';}, 3000);
}

// Carregar tarefas iniciais
atualizarTarefas();

// Função para carregar atividades recentes
async function loadRecentActivities() {
    const list = document.getElementById('teamTimeline');
    list.innerHTML = '';
    try {
        const activities = await fetchRecentActivities();
        if (!activities.length) {
            list.innerHTML = '<li class="text-muted small">Nenhuma atividade recente.</li>';
            return;
        }
        activities.forEach(a => {
            const li = document.createElement('li');
            li.className = 'mb-2 d-flex align-items-center gap-2';
            const badgeClass = a.type === 'created' ? 'bg-success' : 'bg-warning';
            const icon = a.type === 'created' ? 'fa-user-plus' : 'fa-edit';
            const actionText = a.type === 'created' ? 'adicionou nova tarefa' : 'atualizou tarefa';
            const name = a.responsavel || a.equipe;
            li.innerHTML = `
                <span class="badge ${badgeClass}"><i class="fa ${icon}"></i></span>
                <span><strong>${escapeHtml(name)}</strong> ${actionText} <span class="text-info">"${escapeHtml(a.titulo)}"</span> <span class="text-muted">(${escapeHtml(a.equipe)})</span></span>
                <span class="ms-auto small text-muted">${formatDate(a.timestamp)}</span>
            `;
            list.appendChild(li);
        });
    } catch (e) {
        console.error(e);
        list.innerHTML = '<li class="text-danger small">Erro ao carregar atividades.</li>';
    }
}

function updateBulkActions() {
    const checked = document.querySelectorAll('.task-checkbox:checked');
    console.log('Checkboxes marcadas:', checked.length);
    const bulkDiv = document.getElementById('bulkActions');
    bulkDiv.style.display = checked.length > 0 ? 'block' : 'none';
}

function formatDate(ts) {
    const date = new Date(ts);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    if (days === 0) return 'Hoje, ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    if (days === 1) return 'Ontem, ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    return date.toLocaleDateString('pt-BR') + ', ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
}

// Funções para editar e excluir tarefas
function openEditModal(task) {
    document.getElementById('edit-id').value = task.id;
    document.getElementById('edit-equipe').value = task.equipe;
    document.getElementById('edit-responsavel').value = task.responsavel;
    document.getElementById('edit-titulo').value = task.titulo;
    document.getElementById('edit-status').value = task.status;
    document.getElementById('edit-data-vencimento').value = task.data_vencimento;
    document.getElementById('edit-descricao').value = task.descricao;
    const modal = new bootstrap.Modal(document.getElementById('modalEditarTarefa'));
    modal.show();
}

async function deleteTaskConfirm(id) {
    if (confirm('Tem certeza que deseja excluir esta tarefa?')) {
        const resposta = await deleteTask(id);
        if (resposta.success) {
            atualizarTarefas();
        } else {
            alert('Erro ao excluir tarefa');
        }
    }
}

// --- Abas: Tarefas / Lembretes ---
const tabTarefas = document.getElementById('tabTarefas');
const tabMinhasBtn = document.getElementById('tabMinhasTarefas');
const tabLembretesBtn = document.getElementById('tabLembretesBtn');
const tarefasArea = document.getElementById('tarefasArea');
const minhasArea = document.getElementById('minhasArea');
const lembretesArea = document.getElementById('lembretesArea');

// Add event listeners
if (tabTarefas) {
    tabTarefas.addEventListener('click', () => { console.log('Tarefas clicked'); showTab('tarefas'); });
}
if (tabMinhasBtn) {
    console.log('Adding event listener to tabMinhasBtn');
    tabMinhasBtn.addEventListener('click', () => { console.log('Minhas Tarefas clicked'); showTab('minhas'); });
}
if (tabLembretesBtn) tabLembretesBtn.addEventListener('click', () => { console.log('Lembretes clicked'); showTab('lembretes'); });

function showTab(name) {
    console.log('showTab called with:', name);
    // reset all
    if (tabTarefas) { tabTarefas.classList.remove('btn-outline-primary', 'btn-primary', 'active'); tabTarefas.classList.add('btn-outline-primary'); }
    if (tabMinhasBtn) { tabMinhasBtn.classList.remove('btn-outline-primary', 'btn-primary', 'active'); tabMinhasBtn.classList.add('btn-outline-primary'); }
    if (tabLembretesBtn) { tabLembretesBtn.classList.remove('btn-outline-primary', 'btn-primary', 'active'); tabLembretesBtn.classList.add('btn-outline-primary'); }
    if (tarefasArea) tarefasArea.style.display = 'none';
    if (minhasArea) minhasArea.style.display = 'none';
    if (lembretesArea) lembretesArea.style.display = 'none';

    if (name === 'tarefas') {
        if (tabTarefas) { tabTarefas.classList.remove('btn-outline-primary'); tabTarefas.classList.add('btn-primary', 'active'); }
        if (tarefasArea) tarefasArea.style.display = '';
        atualizarTarefas();
    } else if (name === 'minhas') {
        console.log('Setting minhas tab active');
        if (tabMinhasBtn) { tabMinhasBtn.classList.remove('btn-outline-primary'); tabMinhasBtn.classList.add('btn-primary', 'active'); }
        if (minhasArea) minhasArea.style.display = '';
        atualizarMinhasTarefas();
    } else {
        if (tabLembretesBtn) { tabLembretesBtn.classList.remove('btn-outline-primary'); tabLembretesBtn.classList.add('btn-primary', 'active'); }
        if (lembretesArea) lembretesArea.style.display = '';
        loadRemindersLayout();
    }
}

// Minhas Tarefas: carregar/listar/CRUD (executa via endpoint que respeita session user)
async function atualizarMinhasTarefas() {
    console.log('atualizarMinhasTarefas called');
    const statusFiltro = document.getElementById('myFiltroStatus') ? document.getElementById('myFiltroStatus').value : '';
    const buscaFiltro = document.getElementById('myFiltroBusca') ? document.getElementById('myFiltroBusca').value.trim().toLowerCase() : '';
    const list = document.getElementById('myTasksList');
    if (!list) { console.log('myTasksList not found'); return; }
    list.innerHTML = '';
    try {
        const filtros = {};
        if (statusFiltro) filtros.status = statusFiltro;
        // request tasks assigned to current username or created by user
        filtros.mine = '1';
        console.log('MinhasTarefas - filtros antes fetch:', filtros);
        const tarefas = await fetchTasks(filtros);
        console.log('MinhasTarefas - resposta fetchTasks:', tarefas);
        const itens = tarefas.filter(t => {
            if (!buscaFiltro) return true;
            return ((t.titulo||'').toLowerCase().includes(buscaFiltro) || (t.descricao||'').toLowerCase().includes(buscaFiltro));
        });
        if (!itens.length) { list.innerHTML = '<div class="text-muted">Nenhuma tarefa encontrada.</div>'; return; }
        itens.forEach(t => {
            const card = document.createElement('div');
            card.className = 'mb-2 p-2 border rounded d-flex align-items-center gap-3 bg-white';
            card.style.cursor = 'pointer';
            const avatar = document.createElement('div');
            avatar.className = 'rounded-circle d-flex align-items-center justify-content-center me-2';
            avatar.style.width = '38px'; avatar.style.height = '38px'; avatar.style.background = (t.equipe ? ( {Marketing:'#3bb273',Vendas:'#0b6ac1',Atendimento:'#ffd24a',Técnica:'#7c3aed',Financeiro:'#ef4444'}[t.equipe] || '#888') : '#888');
            avatar.style.color = '#fff'; avatar.style.fontWeight = 'bold'; avatar.style.fontSize = '1.1rem';
            avatar.textContent = (t.responsavel||'?').split(' ').map(p=>p[0]||'').join('').toUpperCase().slice(0,2);
            card.appendChild(avatar);
            const content = document.createElement('div'); content.className = 'flex-grow-1';
            content.innerHTML = `<div class="fw-semibold">${escapeHtml(t.titulo)} <span class="badge ms-2" style="background:${escapeHtml(t.equipe||'')} ;color:#fff;">${escapeHtml(t.equipe||'')}</span> <span class="badge ms-1" style="background:${escapeHtml(t.status||'')} ;color:#fff;">${escapeHtml(t.status||'')}</span></div>
                <div class="small text-muted">${t.responsavel ? 'Responsável: ' + escapeHtml(t.responsavel) : ''} ${t.data_vencimento ? ' | Vencimento: ' + t.data_vencimento : ''}</div>
                <div class="mt-1">${escapeHtml(t.descricao || '')}</div>`;
            card.appendChild(content);
            // make whole card clickable to edit (like reminders)
            card.addEventListener('click', () => openEditModal(t));
            const actions = document.createElement('div'); actions.className = 'd-flex flex-column gap-1';
            actions.innerHTML = `<button class="btn btn-sm btn-outline-primary" title="Editar"><i class="fa fa-edit"></i></button>
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="fa fa-trash"></i></button>`;
            const editBtn = actions.querySelector('.btn-outline-primary');
            const deleteBtn = actions.querySelector('.btn-outline-danger');
            // prevent action buttons from triggering the card click
            editBtn.addEventListener('click', (ev) => { ev.stopPropagation(); openEditModal(t); });
            deleteBtn.addEventListener('click', (ev) => { ev.stopPropagation(); deleteTaskConfirm(t.id); });
            card.appendChild(actions);
            list.appendChild(card);
        });
    } catch (e) {
        console.error('Erro em atualizarMinhasTarefas:', e);
        list.innerHTML = '<div class="text-danger">Erro ao carregar tarefas</div>';
    }
}

async function fetchReminderTemplates() {
    try {
        const res = await fetch('includes/reminder_templates_api.php?action=list');
        if (!res.ok) return [];
        return await res.json();
    } catch (e) { return []; }
}

async function loadRemindersLayout() {
    const agendaEl = document.getElementById('agendaHoje');
    const proximosEl = document.getElementById('proximosLembretes');
    agendaEl.textContent = 'Carregando...'; proximosEl.textContent = 'Carregando...';
    try {
        const res = await fetch('includes/reminders_api.php?action=list');
        if (!res.ok) throw new Error('Erro');
        const reminders = await res.json();
        const now = new Date();
        const today = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
        const todayItems = reminders.filter(r => r.remind_at && r.remind_at.slice(0,10) === today);
        const upcoming = reminders.filter(r => r.remind_at && r.remind_at.slice(0,10) > today).sort((a,b)=> a.remind_at.localeCompare(b.remind_at));

        if (!todayItems.length) agendaEl.innerHTML = '<div class="text-muted small" style="padding:12px;">Nenhum lembrete para hoje.</div>'; else {
            agendaEl.innerHTML = '';
            todayItems.forEach(r=>{
                const timeStr = r.remind_at ? r.remind_at.split(' ')[1]?.substring(0,5) || '' : '';
                const it = document.createElement('div');
                it.className = 'reminder-card-modern';
                it.innerHTML = `
                    <div class="reminder-icon-circle">
                        <i class="fa fa-clock-o"></i>
                    </div>
                    <div class="reminder-content-modern">
                        <div class="reminder-title-modern">
                            ${escapeHtmlGlobal(r.lead_name||('Lead #'+r.lead_id))}
                        </div>
                        <div class="reminder-time-modern">${timeStr}</div>
                    </div>`;
                agendaEl.appendChild(it);
            });
        }

        if (!upcoming.length) proximosEl.innerHTML = '<div class="text-muted" style="padding:12px;">Nenhum lembrete agendado.</div>'; else {
            proximosEl.innerHTML = '<div class="proximos-eventos-list"></div>';
            const list = proximosEl.querySelector('.proximos-eventos-list');
            // Contar leads únicos
            const uniqueLeads = new Set(upcoming.map(r => r.lead_id).filter(id => id && id > 0));
            const totalLeadsBtn = document.getElementById('totalLeadsBtn');
            if (totalLeadsBtn) {
                totalLeadsBtn.textContent = `${uniqueLeads.size} LEADS`;
            }
            upcoming.forEach(r => {
                const dt = new Date(r.remind_at.replace(' ','T'));
                const isToday = r.remind_at && r.remind_at.slice(0,10) === today;
                const monthNames = ['JAN.','FEV.','MAR.','ABR.','MAI.','JUN.','JUL.','AGO.','SET.','OUT.','NOV.','DEZ.'];
                const month = monthNames[dt.getMonth()];
                const day = dt.getDate();
                const timeStr = r.remind_at ? r.remind_at.split(' ')[1]?.substring(0,5) || '' : '';
                
                const card = document.createElement('div');
                card.className = 'evento-card-modern d-flex align-items-start';
                card.innerHTML = `
                    <div class="evento-date-box">
                        <div class="evento-date-month">${month}</div>
                        <div class="evento-date-day">${day}</div>
                    </div>
                    <div class="evento-content-modern">
                        <div class="evento-title-modern">
                            ${escapeHtmlGlobal(r.lead_name ? r.lead_name + ' (#' + r.lead_id + ')' : 'Lead #' + r.lead_id)}
                            ${isToday ? '<span class="reminder-badge-hoje">HOJE</span>' : ''}
                        </div>
                        <div class="evento-time-icon">
                            <i class="fa fa-clock-o"></i> ${timeStr}
                        </div>
                        ${r.message ? `<div class="evento-message">${escapeHtmlGlobal(r.message)}</div>` : ''}
                    </div>
                `;
                list.appendChild(card);
                // make whole card clickable to edit
                card.style.cursor = 'pointer';
                card.addEventListener('click', () => openEditReminderModal(r.id));
            });
        }

        // preencher templates
        const templates = await fetchReminderTemplates();
        const sel = document.getElementById('rem-template');
        sel.innerHTML = '<option value="">(Nenhum)</option>';
        templates.forEach(t=>{ const o = document.createElement('option'); o.value = t.id; o.textContent = t.name || t.title || ('template '+t.id); sel.appendChild(o); });

    } catch (e) {
        agendaEl.innerHTML = '<div class="text-danger">Erro ao carregar</div>';
        proximosEl.innerHTML = '<div class="text-danger">Erro ao carregar</div>';
    }
}

// Adicionar event listeners após o DOM carregar
document.addEventListener('DOMContentLoaded', () => {
    // Evento de mudança nos filtros
    const filtroEquipe = document.getElementById('filtroEquipe');
    if (filtroEquipe) filtroEquipe.addEventListener('change', atualizarTarefas);
    const filtroResp = document.getElementById('filtroResp');
    if (filtroResp) filtroResp.addEventListener('change', atualizarTarefas);
    const filtroStatus = document.getElementById('filtroStatus');
    if (filtroStatus) filtroStatus.addEventListener('change', atualizarTarefas);
    const filtroBusca = document.getElementById('filtroBusca');
    if (filtroBusca) filtroBusca.addEventListener('input', atualizarTarefas);
    // Evento de submissão do formulário de nova tarefa
    const formNovaTarefa = document.getElementById('formNovaTarefa');
    if (formNovaTarefa) {
        formNovaTarefa.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const novaTarefa = Object.fromEntries(formData);
            novaTarefa.user_id = userId;
            const resposta = await addTask(novaTarefa);
            if (resposta.success) {
                showNotification('Tarefa criada com sucesso!','success');
                this.reset();
                atualizarTarefas();
            } else {
                showNotification('Erro ao criar tarefa','danger');
            }
        });
    }
    // Abrir modal de Nova Tarefa a partir do botão na lista e do link superior
    const btnNovaTarefa = document.getElementById('btnNovaTarefa');
    const modalNovaEl = document.getElementById('modalNovaTarefa');
    if (btnNovaTarefa) btnNovaTarefa.addEventListener('click', ()=>{ new bootstrap.Modal(modalNovaEl).show(); });

    // Submissão do formulário do modal
    const btnSalvarNovaModal = document.getElementById('btnSalvarNovaModal');
    if (btnSalvarNovaModal) {
        btnSalvarNovaModal.addEventListener('click', async function() {
            const form = document.getElementById('formModalNovaTarefa');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            data.user_id = userId;
            const resp = await addTask(data);
            if (resp.success) {
                document.getElementById('novaTarefaModalMsg').innerHTML = '<div class="alert alert-success">Tarefa criada com sucesso!</div>';
                setTimeout(()=>{
                    const modal = bootstrap.Modal.getInstance(modalNovaEl);
                    if (modal) modal.hide();
                    document.getElementById('novaTarefaModalMsg').innerHTML = '';
                    form.reset();
                    atualizarTarefas();
                }, 700);
            } else {
                document.getElementById('novaTarefaModalMsg').innerHTML = '<div class="alert alert-danger">Erro ao criar tarefa</div>';
            }
        });
    }
    // submissão do novo lembrete
    const formNovoLembrete = document.getElementById('formNovoLembrete');
    if (formNovoLembrete) {
        formNovoLembrete.addEventListener('submit', async function(e){
            e.preventDefault();
            const leadIdRaw = document.getElementById('rem-lead-id').value.trim();
            const leadId = leadIdRaw && !isNaN(Number(leadIdRaw)) ? Number(leadIdRaw) : null;
            if (!document.getElementById('rem-message').value.trim()) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Mensagem obrigatória</div>'; return; }
            if (!document.getElementById('rem-date').value || !document.getElementById('rem-time').value) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Data e hora obrigatórias</div>'; return; }
            if (!leadId) { document.getElementById('remMsg').innerHTML = '<div class="text-warning">Recomenda-se informar o Lead ID numérico. Use o campo opcional se souber o ID.</div>'; }
            const datetime = document.getElementById('rem-date').value + ' ' + document.getElementById('rem-time').value;
            const payload = new URLSearchParams();
            payload.append('action','add');
            payload.append('datetime', datetime);
            payload.append('message', document.getElementById('rem-message').value.trim());
            payload.append('template_id', document.getElementById('rem-template').value || '');
            payload.append('lead_ident', document.getElementById('rem-lead-ident').value.trim());
            if (leadId) payload.append('lead_id', String(leadId)); else payload.append('lead_id','0');
            try {
                const res = await fetch('includes/reminders_api.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
                const data = await res.json();
                if (data.ok) {
                    document.getElementById('remMsg').innerHTML = '<div class="alert alert-success">Lembrete salvo</div>';
                    this.reset();
                    loadRemindersLayout();
                    setTimeout(()=>{ document.getElementById('remMsg').innerHTML=''; }, 2500);
                } else {
                    document.getElementById('remMsg').innerHTML = '<div class="text-danger">Erro ao salvar</div>';
                }
            } catch (e) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Erro ao salvar</div>'; }
        });
    }

        // Minhas Tarefas: ativar aba e filtros
        const myFiltroStatus = document.getElementById('myFiltroStatus');
        if (myFiltroStatus) myFiltroStatus.addEventListener('change', atualizarMinhasTarefas);
        const myFiltroBusca = document.getElementById('myFiltroBusca');
        if (myFiltroBusca) myFiltroBusca.addEventListener('input', atualizarMinhasTarefas);

        // Formulário rápido na aba Minhas Tarefas
        const formNovaTarefaMy = document.getElementById('formNovaTarefa_My');
        if (formNovaTarefaMy) {
            formNovaTarefaMy.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const nova = Object.fromEntries(formData);
                nova.user_id = userId;
                const resp = await addTask(nova);
                if (resp.success) {
                    document.getElementById('novaTarefaMsg_My').innerHTML = '<div class="alert alert-success">Tarefa criada com sucesso!</div>';
                    this.reset();
                    setTimeout(()=>{ document.getElementById('novaTarefaMsg_My').innerHTML = ''; }, 2000);
                    atualizarMinhasTarefas();
                } else {
                    document.getElementById('novaTarefaMsg_My').innerHTML = '<div class="alert alert-danger">Erro ao criar tarefa</div>';
                }
            });
        }

    // Busca automática de leads para lembretes
    const leadIdentInput = document.getElementById('rem-lead-ident');
    const leadIdInput = document.getElementById('rem-lead-id');
    const suggestionsDiv = document.getElementById('leadSuggestions');
    let searchTimeout;

    if (leadIdentInput) {
        leadIdentInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            if (query.length < 2) {
                if (suggestionsDiv) suggestionsDiv.style.display = 'none';
                return;
            }
            searchTimeout = setTimeout(async () => {
                console.log('Buscando leads para:', query);
                try {
                    const res = await fetch(`includes/leads_api.php?action=search&q=${encodeURIComponent(query)}`);
                    console.log('Resposta da API:', res.status);
                    const leads = await res.json();
                    console.log('Leads encontrados:', leads);
                    if (suggestionsDiv) {
                        suggestionsDiv.innerHTML = '';
                        if (leads.length) {
                            leads.forEach(lead => {
                                const item = document.createElement('a');
                                item.className = 'list-group-item list-group-item-action py-2';
                                item.style.cursor = 'pointer';
                                item.innerHTML = `<strong>${escapeHtml(lead.name)}</strong> - ${escapeHtml(lead.email)} - ${escapeHtml(lead.phone)}`;
                                item.addEventListener('click', () => {
                                    if (leadIdentInput) leadIdentInput.value = lead.name;
                                    if (leadIdInput) leadIdInput.value = lead.id;
                                    if (suggestionsDiv) suggestionsDiv.style.display = 'none';
                                    console.log('Lead selecionado:', lead);
                                });
                                suggestionsDiv.appendChild(item);
                            });
                            suggestionsDiv.style.display = 'block';
                        } else {
                            suggestionsDiv.style.display = 'none';
                        }
                    }
                } catch (e) {
                    console.error('Erro na busca:', e);
                }
            }, 300);
        });
    }

    // Esconder sugestões ao clicar fora
    document.addEventListener('click', (e) => {
        if (leadIdentInput && suggestionsDiv && !leadIdentInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = 'none';
        }
    });

    // Evento para carregar mensagem do template
    const templateSel = document.getElementById('rem-template');
    if (templateSel) {
        templateSel.addEventListener('change', async function() {
            const templateId = this.value;
            if (!templateId) {
                const msgEl = document.getElementById('rem-message');
                if (msgEl) msgEl.value = '';
                return;
            }
            try {
                const res = await fetch(`includes/reminder_templates_api.php?action=get&id=${templateId}`);
                const template = await res.json();
                const msgEl = document.getElementById('rem-message');
                if (msgEl) msgEl.value = template.message || '';
            } catch (e) {
                console.error('Erro ao carregar template:', e);
            }
        });
    }

    // Desmarcar todos
    const desmarcarTodos = document.getElementById('desmarcarTodos');
    if (desmarcarTodos) {
        desmarcarTodos.addEventListener('click', () => {
            document.querySelectorAll('.task-checkbox').forEach(cb => cb.checked = false);
            updateBulkActions();
        });
    }

    // Evento para salvar edição
    const btnSalvarEdicao = document.getElementById('btnSalvarEdicao');
    if (btnSalvarEdicao) {
        btnSalvarEdicao.addEventListener('click', async function() {
            const form = document.getElementById('formEditarTarefa');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            const id = data.id;
            delete data.id;
            const resposta = await updateTask(id, data);
            if (resposta.success) {
                document.getElementById('editarTarefaMsg').innerHTML = '<div class="alert alert-success">Tarefa atualizada com sucesso!</div>';
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarTarefa'));
                    modal.hide();
                    document.getElementById('editarTarefaMsg').innerHTML = '';
                    atualizarTarefas();
                }, 1000);
            } else {
                document.getElementById('editarTarefaMsg').innerHTML = '<div class="alert alert-danger">Erro ao atualizar tarefa</div>';
            }
        });
    }

    // Abas: Tarefas / Lembretes
    const tabTarefas = document.getElementById('tabTarefas');
    const tabLembretesBtn = document.getElementById('tabLembretesBtn');
    if (tabTarefas) tabTarefas.addEventListener('click', () => { showTab('tarefas'); });
    if (tabLembretesBtn) tabLembretesBtn.addEventListener('click', () => { showTab('lembretes'); });
});

// --- Reminders: edit/delete helpers and modal handling ---
async function openEditReminderModal(id) {
    try {
        const res = await fetch(`includes/reminders_api.php?action=get&id=${id}`);
        if (!res.ok) throw new Error('Erro ao buscar lembrete');
        const r = await res.json();
        // populate modal fields
        document.getElementById('editRem-id').value = r.id || '';
        document.getElementById('editRem-lead-ident').value = r.lead_name || '';
        document.getElementById('editRem-lead-id').value = r.lead_id || '';
        document.getElementById('editRem-lead-phone').value = r.lead_phone || '';
        const dt = r.remind_at ? r.remind_at.split(' ') : ['',''];
        document.getElementById('editRem-date').value = dt[0] || '';
        document.getElementById('editRem-time').value = dt[1] ? dt[1].substring(0,5) : '';
        document.getElementById('editRem-message').value = r.message || '';
        // load templates and set value
        const templates = await fetchReminderTemplates();
        const sel = document.getElementById('editRem-template');
        sel.innerHTML = '<option value="">(Nenhum)</option>';
        templates.forEach(t=>{ const o = document.createElement('option'); o.value = t.id; o.textContent = t.name || t.title || ('template '+t.id); sel.appendChild(o); });
        sel.value = r.template_id || '';
        const modalEl = document.getElementById('modalEditarLembrete');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    } catch (e) {
        console.error(e);
        alert('Erro ao carregar lembrete para edição');
    }
}

document.addEventListener('click', (e)=>{
    // Close suggestions click handler preserved above; this is only for safety
});

async function deleteReminderConfirm(id) {
    if (!confirm('Tem certeza que deseja excluir este lembrete?')) return;
    try {
        const payload = new URLSearchParams();
        payload.append('action','delete');
        payload.append('id', String(id));
        const res = await fetch('includes/reminders_api.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
        const data = await res.json();
        if (data.ok) {
            loadRemindersLayout();
        } else {
            alert('Erro ao excluir lembrete');
        }
    } catch (e) { console.error(e); alert('Erro ao excluir lembrete'); }
}

// Save edited reminder
document.addEventListener('DOMContentLoaded', ()=>{
    const btnSaveEditRem = document.getElementById('btnSalvarEdicaoLembrete');
    if (!btnSaveEditRem) return;
    btnSaveEditRem.addEventListener('click', async ()=>{
        const id = document.getElementById('editRem-id').value;
        const date = document.getElementById('editRem-date').value;
        const time = document.getElementById('editRem-time').value;
        const message = document.getElementById('editRem-message').value.trim();
        const templateId = document.getElementById('editRem-template').value || '';
        if (!id || !date || !time || !message) { alert('Preencha data, hora e mensagem'); return; }
        try {
            const payload = new URLSearchParams();
            payload.append('action','update');
            payload.append('id', String(id));
            payload.append('datetime', date + ' ' + time);
            payload.append('message', message);
            payload.append('template_id', templateId);
            const res = await fetch('includes/reminders_api.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
            const data = await res.json();
            if (data.ok) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarLembrete'));
                if (modal) modal.hide();
                loadRemindersLayout();
            } else {
                alert('Erro ao salvar lembrete');
            }
        } catch (e) { console.error(e); alert('Erro ao salvar lembrete'); }
    });
    // delete button inside edit modal
    const btnDeleteRem = document.getElementById('btnExcluirLembrete');
    if (btnDeleteRem) {
        btnDeleteRem.addEventListener('click', async ()=>{
            const id = document.getElementById('editRem-id').value;
            if (!id) return alert('ID do lembrete não encontrado');
            if (!confirm('Confirma exclusão deste lembrete?')) return;
            try {
                const payload = new URLSearchParams();
                payload.append('action','delete');
                payload.append('id', String(id));
                const res = await fetch('includes/reminders_api.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
                const data = await res.json();
                if (data.ok) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarLembrete'));
                    if (modal) modal.hide();
                    loadRemindersLayout();
                } else {
                    alert('Erro ao excluir lembrete');
                }
            } catch (e) { console.error(e); alert('Erro ao excluir lembrete'); }
        });
    }
});

</script>
