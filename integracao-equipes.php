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

// Lista de usuários para selecionar como responsável em novas tarefas
$users = [];
$usersMap = [];
try {
    // tentar carregar avatar se a coluna existir
    try {
        $usersStmt = $pdo->query('SELECT id, username, avatar FROM users ORDER BY username');
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $inner) {
        // instalações antigas sem coluna `avatar`
        $usersStmt = $pdo->query('SELECT id, username FROM users ORDER BY username');
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($users as $u) {
        $usersMap[$u['id']] = [
            'username' => $u['username'] ?? '',
            'avatar' => $u['avatar'] ?? ''
        ];
    }
} catch (Exception $e) {
    $users = [];
    $usersMap = [];
}

$pageTitle = 'Integração de Equipes';
include 'includes/header.php';
// Preparar resumo por usuário para a aba "Minhas Integrações"
// coletamos as últimas tarefas de cada usuário e inferimos um 'last_activity' simples
$userIntegrations = [];
try {
    foreach ($users as $u) {
        $uid = $u['id'];
        $uname = $u['username'] ?? '';
        $tasksStmt = $pdo->prepare('SELECT id,titulo,status,data_vencimento,criado_em,responsavel FROM team_tasks WHERE user_id = ? OR responsavel = ? ORDER BY criado_em DESC LIMIT 6');
        $tasksStmt->execute([$uid, $uname]);
        $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
        $lastActivity = null;
        if (!empty($tasks) && !empty($tasks[0]['criado_em'])) {
            $lastActivity = $tasks[0]['criado_em'];
        }
        // inferir online se última atividade há menos de 15 minutos
        $isOnline = false;
        if ($lastActivity) {
            $ts = strtotime($lastActivity);
            if ($ts !== false && ($ts > time() - 15*60)) $isOnline = true;
        }
        $userIntegrations[] = [
            'id' => $uid,
            'username' => $uname,
            'avatar' => $u['avatar'] ?? '',
            'tasks' => $tasks,
            'last_activity' => $lastActivity,
            'online' => $isOnline,
        ];
    }
} catch (Exception $e) {
    // em caso de erro, deixamos lista vazia e prosseguimos sem travar a página
    $userIntegrations = [];
}
?>

<style>
.section-left-border { border-left: 6px solid #0b6ac1 !important; }
.section-left-border.secondary { border-left-color: #6c757d !important; }
.section-left-border.primary { border-left-color: #0d6efd !important; }
.section-left-border.success { border-left-color: #198754 !important; }

/* Estilos modernos para filtros */
#filtroMinhas:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
}

.form-select:focus, .form-control:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 0.15rem rgba(59, 130, 246, 0.1) !important;
}

.form-select, .form-control {
    transition: all 0.2s ease;
}

.form-select:hover, .form-control:hover {
    border-color: #94a3b8 !important;
}

.vr {
    width: 1px;
    height: 24px;
    background-color: #cbd5e1;
    opacity: 0.3;
}
/* Avatares/fotos: borda primária personalizada (usa --blue-700 do includes/header.php) */
.avatar-border, .task-avatar, .rounded-circle, .rounded-circle img, #modal-header-avatar img, #edit-avatar img, .task-avatar img {
    box-sizing: border-box;
    border: 2px solid var(--blue-700) !important;
    border-radius: 50% !important;
}

.task-avatar { overflow: hidden; }
/* Card action buttons: manter semi-transparente por padrão e ficar totalmente opaco ao hover */
#tasksList .btn-link { opacity: 0.5 !important; transition: opacity .12s ease; }
#tasksList .btn-link:hover { opacity: 1 !important; }
/* Integrações: cartão, avatar e itens suaves de atividades */
.integration-card { transition: border-color .15s ease, transform .12s ease; }
.integration-card:hover { border-color: var(--blue-700); transform: translateY(-4px); }
.integration-avatar-img { width:48px; height:48px; object-fit:cover; border-radius:50%; border:2px solid var(--blue-700); }
.integration-avatar-fallback { display:flex; align-items:center; justify-content:center; }
.integration-tasks-list { display:flex; flex-direction:column; gap:8px; }
.integration-task-item { padding:8px; border-radius:8px; border:1px solid transparent; display:flex; flex-direction:column; }
.integration-task-item .task-title { font-weight:600; color:#0f172a; font-size:0.92rem; }
.integration-task-item .task-meta { font-size:0.78rem; color:#475569; }
.status-Pendente { background: linear-gradient(180deg, rgba(249,115,22,0.06), rgba(249,115,22,0.03)); border-color: rgba(249,115,22,0.12); }
.status-Em\ andamento { background: linear-gradient(180deg, rgba(13,110,253,0.06), rgba(13,110,253,0.03)); border-color: rgba(13,110,253,0.12); }
.status-Conclu\00edda, .status-Concluida { background: linear-gradient(180deg, rgba(59,181,115,0.06), rgba(59,181,115,0.03)); border-color: rgba(59,181,115,0.12); }

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
                    <button id="tabLembretesBtn" type="button" class="btn btn-outline-primary">Lembretes</button>
                    <button id="tabMinhasIntegracoes" type="button" class="btn btn-outline-primary">Integrações</button>
                </div>
            </div>

            <!-- Aba: Tarefas de Equipe -->
            <div id="tarefasArea">
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card card-shadow border-0 rounded-3 mb-3" style="box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                        <div class="card-header bg-white border-0 px-4 py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-semibold d-flex align-items-center gap-2" style="color: #1e293b; font-size: 1rem;">
                                    <i class="fa fa-tasks" style="color: #3b82f6;"></i>
                                    Tarefas de Equipe
                                </h6>
                                <span id="taskCount" class="badge rounded-pill" style="background: #e0e7ff; color: #4f46e5; font-size: 0.75rem; padding: 0.35em 0.75em;">0 tarefas</span>
                            </div>
                        </div>
                        <div class="card-body px-4 py-3">
                            <!-- Filtros Modernos e Compactos -->
                            <div class="bg-light rounded-3 p-3 mb-3" style="background: #f8fafc !important; border: 1px solid #e2e8f0;">
                                <div class="row g-2 align-items-center">
                                    <!-- Linha 1: Filtros principais -->
                                    <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                                        <button id="filtroMinhas" class="btn btn-sm" style="font-size: 0.8rem; padding: 0.35rem 0.85rem; border: 1px solid #cbd5e1; background: white; color: #64748b; border-radius: 6px; transition: all 0.2s;">
                                            <i class="fa fa-user me-1" style="font-size: 0.75rem;"></i>
                                            <span>Minhas</span>
                                        </button>
                                        
                                        <div class="vr" style="opacity: 0.2;"></div>
                                        
                                        <div class="d-flex align-items-center gap-2 flex-grow-1 flex-wrap">
                                            <select id="filtroEquipe" class="form-select form-select-sm" style="width: auto; min-width: 140px; font-size: 0.8rem; padding: 0.35rem 2rem 0.35rem 0.75rem; border-color: #cbd5e1; border-radius: 6px; display:none;">
                                                <option value="">🏛️ Todas equipes</option>
                                                <?php foreach ($equipes as $eq): ?><option value="<?php echo $eq; ?>"><?php echo $eq; ?></option><?php endforeach; ?>
                                            </select>
                                            
                                            <select id="filtroResp" class="form-select form-select-sm" style="width: auto; min-width: 160px; font-size: 0.8rem; padding: 0.35rem 2rem 0.35rem 0.75rem; border-color: #cbd5e1; border-radius: 6px;">
                                                <option value="">👥 Todos responsáveis</option>
                                                <?php foreach ($responsaveis as $r): ?><option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option><?php endforeach; ?>
                                            </select>
                                            
                                            <select id="filtroStatus" class="form-select form-select-sm" style="width: auto; min-width: 130px; font-size: 0.8rem; padding: 0.35rem 2rem 0.35rem 0.75rem; border-color: #cbd5e1; border-radius: 6px;">
                                                <option value="">🎯 Todos status</option>
                                                <option value="Pendente">⏳ Pendente</option>
                                                <option value="Em andamento">▶️ Em andamento</option>
                                                <option value="Concluída">✅ Concluída</option>
                                            </select>
                                            
                                            <select id="filtroOrdem" class="form-select form-select-sm" style="width: auto; min-width: 135px; font-size: 0.8rem; padding: 0.35rem 2rem 0.35rem 0.75rem; border-color: #cbd5e1; border-radius: 6px;">
                                                <option value="desc">🔽 Mais recentes</option>
                                                <option value="asc">🔼 Mais antigas</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Linha 2: Busca -->
                                    <div class="col-12">
                                        <div class="position-relative">
                                            <i class="fa fa-search position-absolute" style="left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem;"></i>
                                            <input type="search" id="filtroBusca" class="form-control form-control-sm" style="padding-left: 36px; font-size: 0.85rem; border-color: #cbd5e1; border-radius: 6px; padding-top: 0.45rem; padding-bottom: 0.45rem;" placeholder="Buscar por título, descrição, responsável...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-4 py-2">
                            <div id="tasksList"></div>
                        </div>
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
                                                <select name="responsavel" id="new-responsavel" class="form-select" onchange="document.getElementById('new-responsavel-id').value = this.options[this.selectedIndex].dataset.userId || '';">
                                                    <?php foreach ($users as $u): ?>
                                                        <option value="<?php echo htmlspecialchars($u['username']); ?>" data-user-id="<?php echo $u['id']; ?>" <?php if (($u['username'] ?? '') === ($_SESSION['username'] ?? '')) echo 'selected'; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="hidden" name="responsavel_id" id="new-responsavel-id" value="">
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

                        <!-- Modal Editar Tarefa (design melhorado) -->
                        <style>
                            .task-avatar { width:56px; height:56px; border-radius:50%; background:#e9ecef; display:inline-flex; align-items:center; justify-content:center; font-weight:600; color:#fff; box-shadow:0 2px 6px rgba(0,0,0,0.08); font-size:1.05rem; }
                            .task-meta-label { font-size:0.85rem; color:#6c757d; }
                            .modal-edit-left { border-right:1px solid #f1f3f5; }
                            .modal-header.colorful { padding:18px 24px; border-bottom:0; background:#0d6efd; transition:background .18s ease; }
                            .modal-header.colorful .modal-title { color:#fff; }
                            .modal-header.colorful .btn-close { filter: invert(1) grayscale(1) contrast(150%); }
                            /* inputs and selects always show system blue borders */
                            .modal-content .form-control,
                            .modal-content .form-select {
                                border-color: #0d6efd !important;
                            }
                            /* focus styles for inputs/selects to match system blue */
                            .modal-content .form-control:focus,
                            .modal-content .form-select:focus {
                                border-color: #0d6efd !important;
                                box-shadow: 0 0 0 .15rem rgba(13,110,253,0.15) !important;
                                outline: 0 !important;
                            }
                            .modal-header.colorful .text-muted { color:rgba(255,255,255,0.85); }
                            .status-badge { display:inline-block; padding:4px 8px; border-radius:12px; font-size:0.75rem; }
                            @media (max-width:767px){ .modal-edit-left { border-right: none; } }
                        </style>
                        <div class="modal fade" id="modalEditarTarefa" tabindex="-1" aria-labelledby="modalEditarTarefaLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header colorful d-flex align-items-center">
                                        <div class="d-flex align-items-center gap-3">
                                            <div id="modal-header-avatar" class="task-avatar bg-dark"><i class="fa fa-edit"></i></div>
                                            <div>
                                                <h5 class="modal-title mb-0" id="modalEditarTarefaLabel">Editar Tarefa de Equipe</h5>
                                                <div class="text-muted small">Altere dados e atribua a outro responsável</div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="formEditarTarefa">
                                            <input type="hidden" name="id" id="edit-id">
                                            <div class="row">
                                                <div class="col-md-8 modal-edit-left pe-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Título <span class="task-meta-label"><i class="fa fa-tasks"></i></span></label>
                                                        <input type="text" name="titulo" id="edit-titulo" class="form-control form-control-lg" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Descrição</label>
                                                        <textarea name="descricao" id="edit-descricao" class="form-control" rows="4" placeholder="Notas, passos a seguir, links úteis..."></textarea>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Data de vencimento</label>
                                                            <input type="date" name="data_vencimento" id="edit-data-vencimento" class="form-control">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Status</label>
                                                            <select name="status" id="edit-status" class="form-select">
                                                                <option value="Pendente">Pendente</option>
                                                                <option value="Em andamento">Em andamento</option>
                                                                <option value="Concluída">Concluída</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="equipe" id="edit-equipe" value="">
                                                </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3 text-center">
                                                            <div id="edit-avatar" class="task-avatar mb-2">?</div>
                                                            <div class="fw-semibold" id="edit-responsavel_name">&nbsp;</div>
                                                            <div class="small text-muted">ID: <span id="edit-responsavel-id" class="text-muted">-</span></div>
                                                        </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Atribuir responsável</label>
                                                        <select name="responsavel" id="edit-responsavel" class="form-select" onchange="document.getElementById('edit-responsavel-id-hidden').value = this.options[this.selectedIndex].dataset.userId || '';">
                                                            <?php foreach ($users as $u): ?>
                                                                <option value="<?php echo htmlspecialchars($u['username']); ?>" data-user-id="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="hidden" name="responsavel_id" id="edit-responsavel-id-hidden" value="">
                                                        <div class="small text-muted mt-1">Escolha outro usuário para transferir a tarefa.</div>
                                                    </div>
                                                    <div class="mt-4">
                                                        <h6 class="small text-muted">Meta</h6>
                                                        <div class="small text-muted">Use o campo de descrição para detalhes, e altere status/data conforme necessário.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                        <div id="editarTarefaMsg" class="mt-2"></div>
                                    </div>
                                    <div class="modal-footer d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button id="btnSalvarEdicao" type="button" class="btn btn-primary"><i class="fa fa-save me-1"></i> Salvar alterações</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card card-shadow border-0 rounded-3" style="box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                        <div class="card-header bg-white border-0 px-4 py-3">
                            <h6 class="mb-3 fw-semibold d-flex align-items-center gap-2" style="color: #1e293b; font-size: 1rem;">
                                <i class="fa fa-clock-o" style="color: #3b82f6;"></i>
                                Atividades Recentes
                            </h6>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Tipo:</span>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-sm activity-filter-type active" data-type="all" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-color: #e2e8f0;">Todas</button>
                                    <button type="button" class="btn btn-sm activity-filter-type" data-type="created" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-color: #e2e8f0;">Criadas</button>
                                    <button type="button" class="btn btn-sm activity-filter-type" data-type="updated" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-color: #e2e8f0;">Editadas</button>
                                    <button type="button" class="btn btn-sm activity-filter-type" data-type="deleted" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-color: #e2e8f0;">Excluídas</button>
                                </div>
                                <span class="text-muted ms-2" style="font-size: 0.75rem; font-weight: 500;">Equipe:</span>
                                <select id="activityEquipeFilter" class="form-select form-select-sm" style="width: auto; font-size: 0.75rem; padding: 0.25rem 2rem 0.25rem 0.6rem; border-color: #e2e8f0;">
                                    <option value="">Todas</option>
                                    <?php foreach ($equipes as $eq): ?>
                                        <option value="<?php echo htmlspecialchars($eq); ?>"><?php echo htmlspecialchars($eq); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="card-body px-4 py-3">
                            <ul id="teamTimeline" class="list-unstyled mb-0">
                            </ul>
                        </div>
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
                                        <select name="responsavel" id="quick-new-responsavel" class="form-select form-select-sm" onchange="document.getElementById('quick-new-responsavel-id').value = this.options[this.selectedIndex].dataset.userId || '';">
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?php echo htmlspecialchars($u['username']); ?>" data-user-id="<?php echo $u['id']; ?>" <?php if (($u['username'] ?? '') === ($_SESSION['username'] ?? '')) echo 'selected'; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="responsavel_id" id="quick-new-responsavel-id" value="">
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

            <!-- Aba: Minhas Tarefas removed -->
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

            <!-- Aba: Minhas Integrações -->
            <div id="minhasIntegracoesArea" style="display:none;">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card card-shadow p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0"><i class="fa fa-plug text-primary"></i> Minhas Integrações</h6>
                                <small class="text-muted">Visão por consultor — status e atividades recentes</small>
                            </div>
                            <div class="row" id="integrationsColumns">
                                <div class="col-12 text-muted">Carregando integrações...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>

<script type="module">
import { fetchTasks, addTask, updateTask, deleteTask, fetchRecentActivities } from './assets/js/team_tasks.js';

const equipes = <?php echo json_encode($equipes); ?>;
const responsaveis = <?php echo json_encode($responsaveis); ?>;
const userId = <?php echo json_encode($_SESSION['user_id']); ?>;
const username = <?php echo json_encode($_SESSION['username'] ?? ''); ?>;
const usersMap = <?php echo json_encode($usersMap); ?>;

function escapeHtmlGlobal(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (s) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s]; });
}

// Alias para compatibilidade
const escapeHtml = escapeHtmlGlobal;

// Estado do filtro "Minhas tarefas"
let filtroMinhasAtivo = false;

// Funções para atualizar a lista de tarefas e a linha do tempo
async function atualizarTarefas() {
    const equipeFiltro = document.getElementById('filtroEquipe').value;
    const respFiltro = document.getElementById('filtroResp').value;
    const mineChecked = filtroMinhasAtivo;
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
    const responsavelParam = mineChecked ? username : respFiltro;
    let tarefas = await fetchTasks({equipe: equipeFiltro, responsavel: responsavelParam, status: statusFiltro});
    
    // Aplicar filtro de busca
    if (buscaFiltro && buscaFiltro.trim() !== '') {
        const busca = buscaFiltro.toLowerCase();
        tarefas = tarefas.filter(t => {
            return (t.titulo && t.titulo.toLowerCase().includes(busca)) ||
                   (t.descricao && t.descricao.toLowerCase().includes(busca)) ||
                   (t.responsavel && t.responsavel.toLowerCase().includes(busca)) ||
                   (t.equipe && t.equipe.toLowerCase().includes(busca));
        });
    }
    
    // Aplicar ordenação por data de criação
    const ordem = document.getElementById('filtroOrdem')?.value || 'desc';
    tarefas.sort((a, b) => {
        const dateA = new Date(a.criado_em || 0);
        const dateB = new Date(b.criado_em || 0);
        return ordem === 'desc' ? dateB - dateA : dateA - dateB;
    });
    
    if (!tarefas.length) {
        list.innerHTML = '<div class="text-muted text-center py-4" style="font-size: 0.9rem;">Nenhuma tarefa encontrada.</div>';
    } else {
        tarefas.forEach(t => {
            const card = document.createElement('div');
            card.className = 'mb-3 p-3 rounded-3 d-flex align-items-start gap-3 bg-white position-relative';
            card.style.cssText = 'border: 1px solid #e8ecf1; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.3s ease; cursor: pointer;';
            card.addEventListener('mouseenter', () => {
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)';
                card.style.transform = 'translateY(-2px)';
                try { card.style.borderColor = 'var(--blue-700)'; } catch(e){}
            });
            card.addEventListener('mouseleave', () => {
                card.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
                card.style.transform = 'translateY(0)';
                try { card.style.border = '1px solid #e8ecf1'; } catch(e){}
            });
            // clicar no card abre o modal de edição
            card.addEventListener('click', () => { openEditModal(t); });
            // Avatar composto: criador sobre responsável (metade sobreposta)
            const avatarWrap = document.createElement('div');
            avatarWrap.className = 'me-2';
            avatarWrap.style.width = '38px';
            avatarWrap.style.height = '38px';
            avatarWrap.style.flex = '0 0 38px';

            function buildAvatar(userInfo, fallbackName, bgColor) {
                if (userInfo && userInfo.avatar) {
                    const img = document.createElement('img');
                    img.src = userInfo.avatar + '?v=' + Date.now();
                    img.className = 'rounded-circle';
                    img.style.width = '38px';
                    img.style.height = '38px';
                    img.style.objectFit = 'cover';
                    img.alt = userInfo.username || fallbackName || 'Avatar';
                    return img;
                }
                const d = document.createElement('div');
                d.className = 'rounded-circle d-flex align-items-center justify-content-center';
                d.style.width = '38px';
                d.style.height = '38px';
                d.style.background = bgColor;
                d.style.color = '#fff';
                d.style.fontWeight = 'bold';
                d.style.fontSize = '1.1rem';
                d.textContent = fallbackName ? initials(fallbackName) : '?';
                return d;
            }

            try {
                const responsavelId = t.responsavel_id || null;
                const responsavelInfo = (responsavelId && usersMap && usersMap[responsavelId]) ? usersMap[responsavelId] : null;
                const responsavelNome = responsavelInfo && responsavelInfo.username ? responsavelInfo.username : t.responsavel;

                const respEl = buildAvatar(responsavelInfo, responsavelNome, equipeColor(t.equipe));

                avatarWrap.appendChild(respEl);
            } catch (e) {
                const fallback = document.createElement('div');
                fallback.className = 'rounded-circle d-flex align-items-center justify-content-center';
                fallback.style.width = '38px';
                fallback.style.height = '38px';
                fallback.style.background = equipeColor(t.equipe);
                fallback.style.color = '#fff';
                fallback.style.fontWeight = 'bold';
                fallback.style.fontSize = '1.1rem';
                fallback.textContent = t.responsavel ? initials(t.responsavel) : '?';
                avatarWrap.appendChild(fallback);
            }

            card.appendChild(avatarWrap);
            // Conteúdo
            const content = document.createElement('div');
            content.className = 'flex-grow-1';
            const responsavelId = t.responsavel_id || t.user_id;
            const responsavelInfo = (responsavelId && usersMap && usersMap[responsavelId]) ? usersMap[responsavelId] : null;
            const responsavelNome = responsavelInfo && responsavelInfo.username ? responsavelInfo.username : t.responsavel;
            // Criador da tarefa (user_id) — usar usersMap quando disponível
            const criadorId = t.user_id || null;
            const criadorInfo = (criadorId && usersMap && usersMap[criadorId]) ? usersMap[criadorId] : null;
            const criadorNome = criadorInfo && criadorInfo.username ? criadorInfo.username : (t.username || t.user || '');
            content.innerHTML = `<div class="d-flex align-items-center gap-2 mb-2">
                <h6 class="mb-0 fw-semibold" style="color: #1e293b; font-size: 0.95rem;">${escapeHtml(t.titulo)}</h6>
                <span class="badge rounded-pill" style="background:${equipeColor(t.equipe)};color:#fff;padding:0.35em 0.75em;font-size:0.7rem;font-weight:500;">${escapeHtml(t.equipe)}</span>
                <span class="badge rounded-pill" style="background:${statusColor(t.status)};color:#fff;padding:0.35em 0.75em;font-size:0.7rem;font-weight:500;">${escapeHtml(t.status)}</span>
            </div>
            <div class="d-flex align-items-center gap-3 mb-2" style="font-size: 0.8rem;">
                ${responsavelNome ? '<div class="text-muted"><i class="fa fa-user me-1" style="opacity:0.6;"></i><span>' + escapeHtml(responsavelNome) + '</span></div>' : ''}
                ${t.data_vencimento ? '<div class="text-muted"><i class="fa fa-calendar me-1" style="opacity:0.6;"></i><span>' + t.data_vencimento + (criadorNome && criadorNome !== responsavelNome ? ' • Criado por: <b>' + escapeHtml(criadorNome) + '</b>' : '') + '</span></div>' : ''}
            </div>
            ${t.descricao ? '<div class="text-secondary" style="font-size: 0.85rem; line-height: 1.5; color: #64748b !important;">' + escapeHtml(t.descricao) + '</div>' : ''}`;
            card.appendChild(content);
            // Ações (concluir se for responsável, editar, excluir)
            const actions = document.createElement('div');
            actions.className = 'd-flex gap-2 position-absolute';
            actions.style.cssText = 'top: 12px; right: 12px;';

            // verificar se usuário logado é responsável
            const canComplete = (t.responsavel_id && String(t.responsavel_id) === String(userId)) || (!t.responsavel_id && username && t.responsavel && t.responsavel === username);
            if (canComplete) {
                const completeBtn = document.createElement('button');
                completeBtn.className = 'btn btn-link btn-sm text-success p-1';
                completeBtn.title = 'Concluir';
                completeBtn.style.opacity = '0.5';
                completeBtn.innerHTML = '<i class="fa fa-check" style="font-size: 0.9rem;"></i>';
                completeBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    completeBtn.disabled = true;
                    try {
                        const resp = await updateTask(t.id, { status: 'Concluída' });
                        if (resp && resp.success) atualizarTarefas(); else alert('Erro ao concluir tarefa');
                    } catch (err) { console.error(err); alert('Erro ao concluir tarefa'); }
                    completeBtn.disabled = false;
                });
                actions.appendChild(completeBtn);
            }

            const editBtn = document.createElement('button');
            editBtn.className = 'btn btn-link btn-sm text-primary p-1';
            editBtn.title = 'Editar';
            editBtn.style.opacity = '0.5';
            editBtn.innerHTML = '<i class="fa fa-edit" style="font-size: 0.9rem;"></i>';
            editBtn.addEventListener('click', (e) => { e.stopPropagation(); openEditModal(t); });
            actions.appendChild(editBtn);

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'btn btn-link btn-sm text-danger p-1';
            deleteBtn.title = 'Excluir';
            deleteBtn.style.opacity = '0.5';
            deleteBtn.innerHTML = '<i class="fa fa-trash" style="font-size: 0.9rem;"></i>';
            deleteBtn.addEventListener('click', (e) => { e.stopPropagation(); deleteTaskConfirm(t.id); });
            actions.appendChild(deleteBtn);

            card.appendChild(actions);
            list.appendChild(card);
        });
    }
    
    // Atualizar contador de tarefas
    const taskCount = document.getElementById('taskCount');
    if (taskCount) {
        taskCount.textContent = tarefas.length + (tarefas.length === 1 ? ' tarefa' : ' tarefas');
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

// Estado dos filtros de atividades
let activityFilters = {
    type: 'all',
    equipe: ''
};

let allActivities = [];

// Função para carregar atividades recentes
async function loadRecentActivities() {
    const list = document.getElementById('teamTimeline');
    list.innerHTML = '';
    try {
        const activities = await fetchRecentActivities();
        allActivities = activities;
        renderActivities(activities);
    } catch (e) {
        console.error(e);
        list.innerHTML = '<li class="text-danger small">Erro ao carregar atividades.</li>';
    }
}

// Função para renderizar atividades com filtros aplicados
function renderActivities(activities) {
    const list = document.getElementById('teamTimeline');
    list.innerHTML = '';
    
    // Aplicar filtros
    let filtered = activities.filter(a => {
        // Filtro de tipo
        if (activityFilters.type !== 'all' && a.type !== activityFilters.type) {
            return false;
        }
        // Filtro de equipe
        if (activityFilters.equipe !== '') {
            const equipe = a.equipe || getEquipeFromDetails(a);
            if (equipe !== activityFilters.equipe) {
                return false;
            }
        }
        return true;
    });
    
    if (!filtered.length) {
        list.innerHTML = '<li class="text-muted text-center py-4" style="font-size: 0.9rem;">Nenhuma atividade encontrada.</li>';
        return;
    }
    
    filtered.forEach(a => {
            const li = document.createElement('li');
            li.className = 'mb-3 pb-3 border-bottom';
            li.style.cssText = 'transition: all 0.2s ease;';
            li.addEventListener('mouseenter', () => {
                li.style.background = '#f8fafc';
                li.style.marginLeft = '4px';
            });
            li.addEventListener('mouseleave', () => {
                li.style.background = 'transparent';
                li.style.marginLeft = '0';
            });

            // parse details if available (may be JSON with before/after or simple snapshot)
            let parsed = null;
            if (a.details) {
                try { parsed = JSON.parse(a.details); } catch(e){ parsed = null; }
            }
            const getFromDetails = (k) => {
                if (!parsed) return null;
                if (parsed[k]) return parsed[k];
                if (parsed.before && parsed.before[k]) return parsed.before[k];
                if (parsed.after && parsed.after[k]) return parsed.after[k];
                return null;
            };

            const isCreated = a.type === 'created';
            const isDeleted = a.type === 'deleted';
            const badgeColor = isCreated ? '#10b981' : (isDeleted ? '#ef4444' : '#f59e0b');
            const icon = isCreated ? 'fa-plus-circle' : (isDeleted ? 'fa-trash' : 'fa-edit');
            const actionText = isCreated ? 'adicionou nova tarefa' : (isDeleted ? 'excluiu tarefa' : 'atualizou tarefa');

            const title = a.titulo || getFromDetails('titulo') || '';
            const equipe = a.equipe || getFromDetails('equipe') || '';
            const name = a.username || a.responsavel || equipe || '';

            li.innerHTML = `
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="min-width: 40px; width: 40px; height: 40px; background: ${badgeColor}15; color: ${badgeColor};">
                        <i class="fa ${icon}" style="font-size: 1rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="mb-1">
                            <span class="fw-semibold" style="color: #1e293b; font-size: 0.9rem;">${escapeHtml(name)}</span>
                            <span class="text-muted" style="font-size: 0.85rem;"> ${actionText}</span>
                        </div>
                        ${title ? '<div class="mb-1" style="color: #3b82f6; font-size: 0.85rem; font-weight: 500;">"' + escapeHtml(title) + '"</div>' : ''}
                        <div class="d-flex align-items-center gap-2">
                            ${equipe ? '<span class="badge rounded-pill" style="background: #e0e7ff; color: #4f46e5; font-size: 0.7rem; padding: 0.25em 0.6em;">' + escapeHtml(equipe) + '</span>' : ''}
                            <span class="text-muted" style="font-size: 0.75rem;"><i class="fa fa-clock-o me-1"></i>${formatDate(a.timestamp)}</span>
                        </div>
                    </div>
                </div>
            `;
            list.appendChild(li);
        });
}

// Função auxiliar para extrair equipe dos detalhes
function getEquipeFromDetails(a) {
    if (a.details) {
        try {
            const parsed = JSON.parse(a.details);
            if (parsed.equipe) return parsed.equipe;
            if (parsed.before && parsed.before.equipe) return parsed.before.equipe;
            if (parsed.after && parsed.after.equipe) return parsed.after.equipe;
        } catch(e) {}
    }
    return null;
}

// Event listeners para filtros de atividades
document.querySelectorAll('.activity-filter-type').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.activity-filter-type').forEach(b => {
            b.classList.remove('active', 'btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary');
        this.classList.add('active', 'btn-primary');
        activityFilters.type = this.dataset.type;
        renderActivities(allActivities);
    });
});

document.getElementById('activityEquipeFilter')?.addEventListener('change', function() {
    activityFilters.equipe = this.value;
    renderActivities(allActivities);
});

// Aplicar estilo inicial aos botões
document.querySelectorAll('.activity-filter-type').forEach(btn => {
    if (!btn.classList.contains('active')) {
        btn.classList.add('btn-outline-secondary');
    } else {
        btn.classList.add('btn-primary');
    }
});

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
    // set select value (responsável)
    const sel = document.getElementById('edit-responsavel');
    if (sel) {
        let selectedName = task.responsavel || '';
        if (task.responsavel_id && usersMap && usersMap[task.responsavel_id]) {
            selectedName = usersMap[task.responsavel_id].username || selectedName;
        }
        sel.value = selectedName;
    }
    // Initialize responsavel_id field
    const editRespIdHidden = document.getElementById('edit-responsavel-id-hidden');
    if (editRespIdHidden) {
        if (task.responsavel_id) {
            editRespIdHidden.value = task.responsavel_id;
        } else if (sel && sel.options[sel.selectedIndex]) {
            editRespIdHidden.value = sel.options[sel.selectedIndex].dataset.userId || '';
        } else {
            editRespIdHidden.value = '';
        }
    }
    document.getElementById('edit-titulo').value = task.titulo;
    document.getElementById('edit-status').value = task.status;
    document.getElementById('edit-data-vencimento').value = task.data_vencimento;
    document.getElementById('edit-descricao').value = task.descricao;
    // fill avatar/name area using usersMap if available
    try {
        const nameEl = document.getElementById('edit-responsavel_name');
        const avatarEl = document.getElementById('edit-avatar');
        const headerAvatar = document.getElementById('modal-header-avatar');
        const idEl = document.getElementById('edit-responsavel-id');
        // Header: mostrar foto do RESPONSÁVEL (responsavel_id) ao lado do título
        const respIdForHeader = task.responsavel_id || null;
        const respInfo = (respIdForHeader && usersMap && usersMap[respIdForHeader]) ? usersMap[respIdForHeader] : null;
        if (respInfo && respInfo.avatar) {
            if (headerAvatar) headerAvatar.innerHTML = `<img src="${respInfo.avatar}?v=${Date.now()}" class="rounded-circle" style="width:56px;height:56px;object-fit:cover;">`;
        } else if (headerAvatar) {
            const initialsH = (respInfo && respInfo.username ? respInfo.username : (task.responsavel || '')).split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase() || '?';
            headerAvatar.textContent = initialsH; headerAvatar.style.background = '#0d6efd'; headerAvatar.style.color = '#fff';
        }
        // Body: mostrar a foto e nome do CRIADOR (user_id) e prefixo "Criador por "
        const creatorId = task.user_id || null;
        const creatorInfo = (creatorId && usersMap && usersMap[creatorId]) ? usersMap[creatorId] : null;
        const creatorName = (creatorInfo && creatorInfo.username) ? creatorInfo.username : (task.username || '');
        if (nameEl) nameEl.textContent = 'Criador por ' + (creatorName || '');
        if (idEl) idEl.textContent = creatorId || '-';
        if (creatorInfo && creatorInfo.avatar) {
            if (avatarEl) avatarEl.innerHTML = `<img src="${creatorInfo.avatar}?v=${Date.now()}" class="rounded-circle" style="width:56px;height:56px;object-fit:cover;">`;
        } else {
            const initialsC = (creatorName || '').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase() || '?';
            if (avatarEl) { avatarEl.textContent = initialsC; avatarEl.style.background = '#6c757d'; avatarEl.style.color = '#fff'; }
        }
        const modalHeader = document.querySelector('#modalEditarTarefa .modal-header');
        if (modalHeader) {
            modalHeader.style.background = '#0b5ed7';
            modalHeader.style.borderBottom = '1px solid rgba(0,0,0,0.06)';
        }
    } catch(e){ console.warn('avatar fill error',e); }
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
const tabLembretesBtn = document.getElementById('tabLembretesBtn');
const tabMinhasIntegracoes = document.getElementById('tabMinhasIntegracoes');
const tarefasArea = document.getElementById('tarefasArea');
const minhasArea = document.getElementById('minhasArea');
const minhasIntegracoesArea = document.getElementById('minhasIntegracoesArea');
const lembretesArea = document.getElementById('lembretesArea');

// Note: tab click listeners are attached after DOMContentLoaded to avoid duplicates

function showTab(name) {
    console.log('showTab called with:', name);
    // reset all
    if (tabTarefas) { tabTarefas.classList.remove('btn-outline-primary', 'btn-primary', 'active'); tabTarefas.classList.add('btn-outline-primary'); }
    if (tabLembretesBtn) { tabLembretesBtn.classList.remove('btn-outline-primary', 'btn-primary', 'active'); tabLembretesBtn.classList.add('btn-outline-primary'); }
    if (tarefasArea) tarefasArea.style.display = 'none';
    if (minhasArea) minhasArea.style.display = 'none';
    if (minhasIntegracoesArea) minhasIntegracoesArea.style.display = 'none';
    if (lembretesArea) lembretesArea.style.display = 'none';

    if (name === 'tarefas') {
        if (tabTarefas) { tabTarefas.classList.remove('btn-outline-primary'); tabTarefas.classList.add('btn-primary', 'active'); }
        if (tarefasArea) tarefasArea.style.display = '';
        atualizarTarefas();
    } else if (name === 'lembretes') {
        if (tabLembretesBtn) { tabLembretesBtn.classList.remove('btn-outline-primary'); tabLembretesBtn.classList.add('btn-primary', 'active'); }
        if (lembretesArea) lembretesArea.style.display = '';
        loadRemindersLayout();
    } else if (name === 'integracoes') {
        if (tabMinhasIntegracoes) { tabMinhasIntegracoes.classList.remove('btn-outline-primary'); tabMinhasIntegracoes.classList.add('btn-primary', 'active'); }
        if (minhasIntegracoesArea) minhasIntegracoesArea.style.display = '';
        // carregar integrações via AJAX e iniciar polling
        if (typeof loadIntegrations === 'function') loadIntegrations();
        if (window.__integrationsPollId) clearInterval(window.__integrationsPollId);
        window.__integrationsPollId = setInterval(function(){ if (document.getElementById('minhasIntegracoesArea') && document.getElementById('minhasIntegracoesArea').style.display !== 'none') loadIntegrations(); }, 30000);
    } else {
        // fallback to lembretes
        if (tabLembretesBtn) { tabLembretesBtn.classList.remove('btn-outline-primary'); tabLembretesBtn.classList.add('btn-primary', 'active'); }
        if (lembretesArea) lembretesArea.style.display = '';
        loadRemindersLayout();
    }
}

// Minhas Tarefas removed

// --- Integrações: carregamento AJAX e renderização ---
async function loadIntegrations() {
    const container = document.getElementById('integrationsColumns');
    if (!container) return;
    container.innerHTML = '<div class="col-12 text-muted text-center py-3">Carregando integrações...</div>';
    try {
        const res = await fetch('api/get_integrations.php');
        if (!res.ok) throw new Error('Falha ao carregar integrações');
        const data = await res.json();
        if (!data.success || !Array.isArray(data.integrations)) {
            container.innerHTML = '<div class="col-12 text-muted">Nenhuma integração disponível.</div>';
            return;
        }
        renderIntegrations(data.integrations);
    } catch (e) {
        console.error('loadIntegrations error', e);
        container.innerHTML = '<div class="col-12 text-danger">Erro ao carregar integrações</div>';
    }
}

function renderIntegrations(list) {
    const container = document.getElementById('integrationsColumns');
    if (!container) return;
    container.innerHTML = '';
    if (!list.length) {
        container.innerHTML = '<div class="col-12 text-muted">Nenhum consultor encontrado para integrar.</div>';
        return;
    }
    list.forEach(ui => {
        const col = document.createElement('div'); col.className = 'col-md-4 col-sm-6 mb-3';
                const avatarHtml = ui.avatar ? (`<img src="${escapeHtmlGlobal(ui.avatar)}" class="integration-avatar-img rounded-circle" style="width:48px;height:48px;object-fit:cover;">`) : (`<div class="integration-avatar-fallback" style="width:48px;height:48px;background:#cbd5e1;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;border-radius:50%;">${escapeHtmlGlobal((ui.username||'U').charAt(0).toUpperCase())}</div>`);
                const tasksHtml = (ui.tasks && ui.tasks.length) ? ui.tasks.map(t => {
                    const statusCls = 'status-' + String((t.status||'').replace(/\s+/g, '-'));
                    const titulo = escapeHtmlGlobal(t.titulo||'(sem título)');
                    const meta = escapeHtmlGlobal(t.status||'') + (t.data_vencimento? ' • '+escapeHtmlGlobal(t.data_vencimento):'');
                    return `<div class="integration-task-item ${statusCls}"><div class="task-title">${titulo}</div><div class="task-meta">${meta}</div></div>`;
                }).join('') : '<div class="text-muted small">Nenhuma atividade encontrada.</div>';
                col.innerHTML = `
                <div class="p-3 bg-white rounded-3 integration-card" style="border:1px solid var(--blue-700); box-shadow: 0 6px 20px rgba(11,26,50,0.04);">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div style="width:48px;height:48px;border-radius:50%;overflow:hidden;flex:0 0 48px;">${avatarHtml}</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold" style="font-size:0.98rem;">${escapeHtmlGlobal(ui.username)}</div>
                            <div class="small text-muted">${(ui.last_activity? 'Última: '+escapeHtmlGlobal(ui.last_activity) : 'Sem atividade recente')}</div>
                        </div>
                        <div>
                            ${ui.online? '<span class="badge rounded-pill" style="background:var(--green);color:#fff;">Online</span>':'<span class="badge rounded-pill" style="background:#e2e8f0;color:#64748b;">Offline</span>'}
                        </div>
                    </div>
                    <div class="mt-2">
                        <div class="small text-muted mb-2" style="font-weight:600;letter-spacing:0.2px;">Atividades recentes</div>
                        <div class="integration-tasks-list">${tasksHtml}</div>
                    </div>
                </div>`;
        container.appendChild(col);
    });
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
                it.dataset.reminderId = r.id || '';
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
            // delegated click handler for today's reminders
            agendaEl.addEventListener('click', (ev) => {
                const card = ev.target.closest('.reminder-card-modern');
                if (!card) return;
                const id = card.dataset.reminderId || card.getAttribute('data-reminder-id');
                if (id) openEditReminderModal(id);
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
                card.dataset.reminderId = r.id || '';
                card.style.cursor = 'pointer';
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
            });
            // Delegated click handler on the container to ensure clicks register even if child handlers fail
            const proximosContainer = proximosEl.querySelector('.proximos-eventos-list');
            if (proximosContainer) {
                proximosContainer.addEventListener('click', (ev) => {
                    const card = ev.target.closest('.evento-card-modern');
                    if (!card) return;
                    const id = card.dataset.reminderId || card.getAttribute('data-reminder-id');
                    if (id) openEditReminderModal(id);
                });
            }
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
    const filtroOrdem = document.getElementById('filtroOrdem');
    if (filtroOrdem) filtroOrdem.addEventListener('change', atualizarTarefas);
    const filtroResp = document.getElementById('filtroResp');
    if (filtroResp) filtroResp.addEventListener('change', atualizarTarefas);
    const filtroStatus = document.getElementById('filtroStatus');
    if (filtroStatus) filtroStatus.addEventListener('change', atualizarTarefas);
    
    // Botão toggle "Minhas tarefas"
    const btnFiltroMinhas = document.getElementById('filtroMinhas');
    if (btnFiltroMinhas) {
        btnFiltroMinhas.addEventListener('click', function() {
            filtroMinhasAtivo = !filtroMinhasAtivo;
            if (filtroMinhasAtivo) {
                this.style.background = '#3b82f6';
                this.style.color = 'white';
                this.style.borderColor = '#3b82f6';
            } else {
                this.style.background = 'white';
                this.style.color = '#64748b';
                this.style.borderColor = '#cbd5e1';
            }
            atualizarTarefas();
        });
    }
    
    const filtroBusca = document.getElementById('filtroBusca');
    if (filtroBusca) filtroBusca.addEventListener('input', atualizarTarefas);
    // Evento de submissão do formulário de nova tarefa
    const formNovaTarefa = document.getElementById('formNovaTarefa');
    if (formNovaTarefa) {
        formNovaTarefa.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const novaTarefa = Object.fromEntries(formData);
            // Initialize responsavel_id from select element
            const responsavelSelect = document.getElementById('quick-new-responsavel');
            if (responsavelSelect) {
                const selectedOption = responsavelSelect.options[responsavelSelect.selectedIndex];
                const userId_resp = selectedOption.dataset.userId || '';
                novaTarefa.responsavel_id = userId_resp;
            }
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
    if (btnNovaTarefa) {
        btnNovaTarefa.addEventListener('click', function() {
            // Inicializar responsavel_id quando abre o modal
            const responsavelSelect = document.getElementById('new-responsavel');
            if (responsavelSelect) {
                const selectedOption = responsavelSelect.options[responsavelSelect.selectedIndex];
                const userId = selectedOption.dataset.userId || '';
                document.getElementById('new-responsavel-id').value = userId;
            }
            new bootstrap.Modal(modalNovaEl).show();
        });
    }

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

        // Minhas Tarefas removed: no related listeners

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
            if (!data.responsavel_id) {
                const sel = document.getElementById('edit-responsavel');
                if (sel && sel.options[sel.selectedIndex]) {
                    data.responsavel_id = sel.options[sel.selectedIndex].dataset.userId || '';
                }
            }
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
                const msg = resposta && resposta.error ? resposta.error : 'Erro ao atualizar tarefa';
                document.getElementById('editarTarefaMsg').innerHTML = `<div class="alert alert-danger">${msg}</div>`;
            }
        });
    }

    // Abas: Tarefas / Lembretes
    const tabTarefas = document.getElementById('tabTarefas');
    const tabLembretesBtn = document.getElementById('tabLembretesBtn');
    if (tabTarefas) tabTarefas.addEventListener('click', () => { showTab('tarefas'); });
    if (tabLembretesBtn) tabLembretesBtn.addEventListener('click', () => { showTab('lembretes'); });
    if (tabMinhasIntegracoes) tabMinhasIntegracoes.addEventListener('click', () => { showTab('integracoes'); });
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
        // reuse existing instance when possible to avoid duplicate backdrops
        let modal = bootstrap.Modal.getInstance ? bootstrap.Modal.getInstance(modalEl) : null;
        if (!modal) {
            modal = new bootstrap.Modal(modalEl);
        }
        modal.show();
    } catch (e) {
        console.error(e);
        alert('Erro ao carregar lembrete para edição');
    }
}

// Expose to global scope so non-module delegated handlers can call it
try { window.openEditReminderModal = openEditReminderModal; } catch (e) { /* ignore if not allowed */ }

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
