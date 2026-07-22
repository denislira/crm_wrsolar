<?php
// Integração de Equipes - Centraliza informações e tarefas entre equipes
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'includes/config.php';
include 'includes/permissions.php';

checkAccessOrRedirect('integracao-equipes');


// Buscar equipes (preferencialmente da tabela `teams`) e responsáveis distintos para filtros
$equipes = [];
$teamsData = [];
try {
    $teamsStmt = $pdo->query('SELECT id, name FROM teams ORDER BY name');
    $rows = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        foreach ($rows as $r) {
            $equipes[] = $r['name'];
            $teamsData[] = $r;
        }
    }
} catch (Exception $e) {
    // tabela teams pode não existir em instalações antigas — fallback para lista estática
    $equipes = ['Marketing','Vendas','Atendimento','Técnica','Financeiro'];
}
// Lista de responsáveis para o filtro: usa user_id quando existir e mantém fallback por nome.
$responsaveis = [];
try {
    $hasResponsavelId = false;
    try {
        $checkStmt = $pdo->prepare("SHOW COLUMNS FROM team_tasks LIKE 'responsavel_id'");
        $checkStmt->execute();
        $hasResponsavelId = (bool) $checkStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hasResponsavelId = false;
    }

    if ($hasResponsavelId) {
        $stmt = $pdo->query('SELECT DISTINCT COALESCE(u.id, t.responsavel_id) AS filtro_id, COALESCE(u.username, t.responsavel) AS filtro_nome FROM team_tasks t LEFT JOIN users u ON u.id = t.responsavel_id WHERE COALESCE(u.username, t.responsavel) IS NOT NULL AND COALESCE(u.username, t.responsavel) <> "" ORDER BY filtro_nome');
    } else {
        $stmt = $pdo->query('SELECT DISTINCT NULL AS filtro_id, responsavel AS filtro_nome FROM team_tasks WHERE responsavel IS NOT NULL AND responsavel <> "" ORDER BY responsavel');
    }

    $responsaveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usersForFilter = [];
    try {
        $usersFilterStmt = $pdo->query('SELECT id, username FROM users ORDER BY username');
        $usersForFilter = $usersFilterStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $usersForFilter = [];
    }
    $existingKeys = [];
    foreach ($responsaveis as $r) {
        $key = (!empty($r['filtro_id'])) ? ('id:' . $r['filtro_id']) : ('name:' . mb_strtolower((string)($r['filtro_nome'] ?? '')));
        $existingKeys[$key] = true;
    }
    foreach ($usersForFilter as $u) {
        $key = 'id:' . $u['id'];
        if (!isset($existingKeys[$key])) {
            $responsaveis[] = ['filtro_id' => $u['id'], 'filtro_nome' => $u['username']];
        }
    }
    usort($responsaveis, function($a, $b) {
        return strcmp((string)($a['filtro_nome'] ?? ''), (string)($b['filtro_nome'] ?? ''));
    });
} catch (Exception $e) {
    $responsaveis = [];
}

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
.integration-filters-panel {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
.integration-filter-chip {
    display:inline-flex;
    align-items:center;
    gap:7px;
    height:34px;
    padding:0 0.85rem;
    border-radius:6px;
    border:1px solid #cbd5e1;
    background:#fff;
    color:#475569;
    font-size:0.8rem;
    font-weight:600;
    transition: all .18s ease;
}
.integration-filter-chip:hover {
    border-color:#94a3b8;
    color:#0f172a;
    background:#f1f5f9;
}
.integration-filter-chip.is-active {
    background:#3b82f6;
    border-color:#3b82f6;
    color:#fff;
}
.integration-filter-chip.is-active i {
    color:#fff;
}
.integration-filter-chip i {
    font-size:0.78rem;
    color:#3b82f6;
}
.integration-select {
    width:auto;
    min-width: 138px;
    min-height:36px;
    line-height:1.25 !important;
    font-size:0.8rem;
    padding:0.48rem 2rem 0.48rem 0.75rem !important;
    border:1px solid #cbd5e1 !important;
    border-radius:6px !important;
    background-color:#fff !important;
    color:#334155 !important;
    transition: all .18s ease;
}
.integration-select:hover {
    border-color:#94a3b8 !important;
}
.integration-select:focus {
    border-color:#3b82f6 !important;
    box-shadow: 0 0 0 .18rem rgba(59,130,246,0.12) !important;
}
.integration-search-wrap {
    position:relative;
}
.integration-search-wrap i {
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:0.9rem;
}
.integration-search {
    padding-left:36px !important;
    height:36px;
    font-size:0.85rem;
    border:1px solid #cbd5e1 !important;
    border-radius:6px !important;
}
.tasks-list-shell {
    background: transparent !important;
    box-shadow: none !important;
}
#modalNovaTarefa .form-control,
#modalNovaTarefa .form-select {
    border-color:#64748b !important;
}
#modalNovaTarefa .form-control:hover,
#modalNovaTarefa .form-select:hover {
    border-color:#475569 !important;
}
#modalNovaTarefa .form-control:focus,
#modalNovaTarefa .form-select:focus {
    border-color:#475569 !important;
    box-shadow: 0 0 0 .15rem rgba(71,85,105,0.14) !important;
}
#formNovaTarefa .form-control,
#formNovaTarefa .form-select {
    border:1.5px solid #64748b !important;
}
#formNovaTarefa .form-control:hover,
#formNovaTarefa .form-select:hover,
#formNovaTarefa .form-control:focus,
#formNovaTarefa .form-select:focus {
    border-color:#475569 !important;
    box-shadow: 0 0 0 .15rem rgba(71,85,105,0.14) !important;
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
#tasksList .btn-link { opacity: 0 !important; transition: opacity .16s ease; }
#tasksList .task-list-card:hover .btn-link,
#tasksList .task-list-card:focus-within .btn-link { opacity: 1 !important; }
@media (hover: none) {
    #tasksList .task-list-card .btn-link { opacity: 1 !important; }
}
/* Integrações: cartão, avatar e itens suaves de atividades */
.integration-card { transition: border-color .15s ease, transform .12s ease; }
.integration-card:hover { border-color: var(--blue-700); transform: translateY(-4px); }
.integration-avatar-img { width:48px; height:48px; object-fit:cover; border-radius:50%; border:2px solid var(--blue-700); }
.integration-avatar-fallback { display:flex; align-items:center; justify-content:center; }
.integration-tasks-list { display:flex; flex-direction:column; gap:8px; }
.integration-task-item { padding:8px; border-radius:8px; border:1px solid transparent; display:flex; flex-direction:column; position:relative; }
.integration-task-item--featured {
    padding:12px 12px 10px;
    border-color: rgba(11,106,193,0.30);
    box-shadow: 0 10px 24px rgba(11,26,50,0.08);
    background:
        linear-gradient(180deg, rgba(13,110,253,0.10), rgba(13,110,253,0.04)),
        #fff;
}
.integration-task-badge {
    display:inline-flex;
    align-items:center;
    align-self:flex-start;
    gap:6px;
    margin-bottom:8px;
    padding:3px 8px;
    border-radius:999px;
    font-size:0.68rem;
    font-weight:800;
    letter-spacing:0.04em;
    text-transform:uppercase;
    color:#0b6ac1;
    background: rgba(13,110,253,0.12);
}
.task-card--featured {
    border-color: #0d6efd !important;
    box-shadow: 0 10px 24px rgba(13,110,253,0.12) !important;
    background: linear-gradient(180deg, rgba(13,110,253,0.05), rgba(255,255,255,1)) !important;
}
.task-card-ribbon {
    position:absolute;
    top:-8px;
    left:16px;
    display:inline-flex;
    align-items:center;
    padding:2px 7px;
    border-radius:999px;
    background:#0d6efd;
    color:#fff;
    font-size:0.5rem;
    font-weight:700;
    letter-spacing:0.02em;
    text-transform:uppercase;
    box-shadow:0 4px 10px rgba(13,110,253,0.18);
}
.task-card-ribbon i {
    font-size: 0.38rem;
}
.task-status-ribbon {
    position: absolute;
    top: -10px;
    right: 18px;
    padding: 4px 10px;
    border-radius: 999px;
    color: #fff;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    box-shadow: 0 6px 14px rgba(15,23,42,0.16);
}
.task-card--featured h6 {
    font-size:1.02rem !important;
}
.integration-task-item--featured .task-title { font-size:1rem; }
.integration-task-item .task-title { font-weight:600; color:#0f172a; font-size:0.92rem; }
.integration-task-item .task-meta { font-size:0.78rem; color:#475569; }
.status-Pendente { background: linear-gradient(180deg, rgba(249,115,22,0.06), rgba(249,115,22,0.03)); border-color: rgba(249,115,22,0.12); }
.status-Em\ andamento { background: linear-gradient(180deg, rgba(13,110,253,0.06), rgba(13,110,253,0.03)); border-color: rgba(13,110,253,0.12); }
.status-Conclu\00edda, .status-Concluida { background: linear-gradient(180deg, rgba(59,181,115,0.06), rgba(59,181,115,0.03)); border-color: rgba(59,181,115,0.12); }

body.theme-dark .card.card-shadow,
body.theme-dark .form-card-modern,
body.theme-dark .modal-content,
body.theme-dark .integration-card,
body.theme-dark .reminder-card-modern,
body.theme-dark .evento-card-modern,
body.theme-dark .section-left-border,
body.theme-dark .card-body,
body.theme-dark .bg-light,
body.theme-dark .bg-white,
body.theme-dark .list-group.position-absolute.bg-white {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.08) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}
body.theme-dark .card-body .bg-light,
body.theme-dark .card-body .bg-white,
body.theme-dark .card-body .rounded-3.p-3.mb-3,
body.theme-dark .card-header.bg-white,
body.theme-dark .card-header.bg-light,
body.theme-dark .modal-header.bg-light {
    background: rgba(255,255,255,0.04) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .card-body {
    background: rgba(255,255,255,0.03) !important;
}
body.theme-dark .card-body .btn.btn-sm,
body.theme-dark .card-body .form-select,
body.theme-dark .card-body .form-control {
    background: rgba(255,255,255,0.06) !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.12) !important;
}
body.theme-dark .integration-filters-panel {
    background: rgba(255,255,255,0.04) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .integration-task-item {
    background: rgba(255,255,255,0.03) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .integration-filters-panel {
    background: linear-gradient(180deg, rgba(15,23,42,0.92), rgba(15,23,42,0.85)) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .integration-filter-chip,
body.theme-dark .integration-select,
body.theme-dark .integration-search {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.10) !important;
}
body.theme-dark .integration-task-item--featured {
    background: linear-gradient(180deg, rgba(13,110,253,0.16), rgba(255,255,255,0.05)) !important;
    border-color: rgba(59,130,246,0.22) !important;
    box-shadow: 0 12px 28px rgba(0,0,0,0.28) !important;
}
body.theme-dark .integration-task-badge {
    color: #93c5fd !important;
    background: rgba(59,130,246,0.16) !important;
}
body.theme-dark .task-card--featured {
    background: linear-gradient(180deg, rgba(13,110,253,0.18), rgba(255,255,255,0.04)) !important;
    border-color: rgba(59,130,246,0.28) !important;
    box-shadow: 0 14px 30px rgba(0,0,0,0.32) !important;
}
body.theme-dark .task-card-ribbon {
    background: #2563eb !important;
    color: #fff !important;
}
body.theme-dark .integration-task-item .task-title,
body.theme-dark .integration-task-item .task-meta,
body.theme-dark .fw-semibold,
body.theme-dark .small.text-muted,
body.theme-dark .text-muted,
body.theme-dark h5,
body.theme-dark h6 {
    color: #e6eef8 !important;
}
body.theme-dark .integration-avatar-fallback {
    background: rgba(255,255,255,0.08) !important;
    color: #fff !important;
}
body.theme-dark .status-Pendente,
body.theme-dark .status-Em\ andamento,
body.theme-dark .status-Conclu\00edda,
body.theme-dark .status-Concluida {
    border-color: rgba(255,255,255,0.08) !important;
}

/* Modal moderno de edicao de lembrete */
#modalEditarLembrete .modal-dialog { max-width: 760px; }
#modalEditarLembrete .modal-content {
    overflow: hidden;
    border: 0;
    border-radius: 20px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .22);
}
#modalEditarLembrete .modal-header {
    position: relative;
    padding: 22px 28px 20px;
    border: 0;
    color: #fff;
    background: linear-gradient(120deg, #0756a5 0%, #0d6efd 58%, #38a3ff 100%);
}
#modalEditarLembrete .modal-header::after {
    content: '';
    position: absolute;
    width: 180px;
    height: 180px;
    right: -55px;
    top: -100px;
    border-radius: 50%;
    background: rgba(255,255,255,.12);
}
#modalEditarLembrete .modal-title { position: relative; z-index: 1; font-size: 1.15rem; font-weight: 700; }
#modalEditarLembrete .modal-title i { color: #dbeafe !important; margin-right: 8px; }
#modalEditarLembrete .btn-close { position: relative; z-index: 2; filter: brightness(0) invert(1); opacity: .9; }
#modalEditarLembrete .modal-body { padding: 26px 28px 20px; background: #f8fbff; }
#modalEditarLembrete .modal-body form { display: grid; gap: 16px; }
#modalEditarLembrete .modal-body form > .mb-2,
#modalEditarLembrete .modal-body form > .row { margin: 0 !important; }
#modalEditarLembrete .form-label {
    margin-bottom: 7px;
    color: #334155;
    font-size: .75rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}
#modalEditarLembrete .form-control,
#modalEditarLembrete .form-select {
    min-height: 44px;
    padding: 10px 13px;
    border: 1px solid #d6e2f0;
    border-radius: 10px;
    background: #fff;
    color: #172033;
    box-shadow: 0 2px 5px rgba(15,23,42,.03);
    transition: border-color .18s ease, box-shadow .18s ease;
}
#modalEditarLembrete textarea.form-control { min-height: 112px; resize: vertical; }
#modalEditarLembrete .form-control:focus,
#modalEditarLembrete .form-select:focus {
    border-color: #2583ed;
    box-shadow: 0 0 0 4px rgba(13,110,253,.12);
}
#modalEditarLembrete input[readonly] { background: #eef5fc; color: #52647a; }
#modalEditarLembrete #editRem-team-block {
    margin: 0 !important;
    padding: 14px 16px !important;
    border-color: #93c5fd !important;
    border-radius: 12px !important;
    background: linear-gradient(135deg, #eff7ff, #f8fbff) !important;
}
#modalEditarLembrete #editRem-team-block .fw-semibold { font-size: .88rem; }
#modalEditarLembrete .modal-footer {
    padding: 17px 28px;
    border-top: 1px solid #e5edf6;
    background: #fff;
}
#modalEditarLembrete .modal-footer .btn { min-height: 42px; border-radius: 10px; font-weight: 700; }
#modalEditarLembrete #btnSalvarEdicaoLembrete { padding-inline: 20px; box-shadow: 0 7px 16px rgba(13,110,253,.2); }
#modalEditarLembrete #btnExcluirLembrete { padding-inline: 15px; }
body.theme-dark #modalEditarLembrete .modal-body { background: #111c2d; }
body.theme-dark #modalEditarLembrete .modal-footer { background: #172235; border-color: rgba(255,255,255,.1); }
body.theme-dark #modalEditarLembrete .form-label { color: #cbd5e1; }
body.theme-dark #modalEditarLembrete .form-control,
body.theme-dark #modalEditarLembrete .form-select { background: #1c2a3e; border-color: #33465f; color: #e6eef8; }
body.theme-dark #modalEditarLembrete input[readonly] { background: #223249; color: #b9c8da; }
body.theme-dark #modalEditarLembrete #editRem-team-block { background: linear-gradient(135deg, #142d4b, #172b43) !important; border-color: #2563eb !important; }

/* Acabamento do modal de usuarios da equipe */
#modalTeamUsers .modal-content {
    overflow: hidden;
    border: 1px solid #cbdcf0;
    border-radius: 16px;
    box-shadow: 0 22px 55px rgba(15, 23, 42, .24);
}
#modalTeamUsers .modal-header {
    border-bottom: 1px solid #dbe7f3;
}
#modalTeamUsers .modal-body {
    background: #f8fbff;
}
body.theme-dark #modalTeamUsers .modal-content {
    border-color: #33465f !important;
    box-shadow: 0 22px 55px rgba(0, 0, 0, .48) !important;
}
body.theme-dark #modalTeamUsers .modal-header,
body.theme-dark #modalTeamUsers .modal-footer {
    border-color: rgba(255,255,255,.1) !important;
}
body.theme-dark #modalTeamUsers .modal-body {
    background: #111c2d !important;
}

</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
<!-- Modal Editar Lembrete -->
<div class="modal fade" id="modalEditarLembrete" tabindex="-1" aria-labelledby="modalEditarLembreteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarLembreteLabel"><i class="fa fa-edit text-primary"></i> Editar Lembrete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarLembrete">
                    <input type="hidden" id="editRem-id" name="id">
                    <div class="mb-2 p-3 rounded-3 border bg-white">
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
                    <div id="editRem-contact-block" class="mb-2" style="display:none;">
                        <label class="form-label">Contato (livre)</label>
                        <div class="row g-2">
                            <div class="col-md-8">
                                <input type="text" id="editRem-contact-name" name="contact_name" class="form-control form-control-sm" placeholder="Nome do contato">
                            </div>
                            <div class="col-md-4">
                                <input type="text" id="editRem-contact-phone" name="contact_phone" class="form-control form-control-sm" placeholder="Telefone do contato">
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
                    <div class="mb-2 p-3 rounded-3 border bg-white">
                        <label class="form-label">Mensagem</label>
                        <textarea id="editRem-message" class="form-control form-control-sm" rows="4"></textarea>
                    </div>
                    <div id="editRem-team-block" class="mb-2 p-2 rounded-3 border border-2" style="display:none; border-color:#0d6efd !important; background:#f8fbff;">
                        <div class="fw-semibold mb-1" style="color:#0d6efd;">Destino da equipe</div>
                        <div class="small text-muted">Equipe: <span id="editRem-team-name">-</span></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnViewReminderTeamUsers">Ver usuários da equipe</button>
                    </div>
                    <div class="mb-2 position-relative">
                        <label class="form-label">Responsável</label>
                        <input type="text" id="editRem-responsavel" class="form-control form-control-sm" placeholder="Digite nome do responsável" autocomplete="off">
                        <input type="hidden" id="editRem-responsavel-id" value="">
                        <div id="editRemResponsavelSuggestions" class="list-group position-absolute bg-white border" style="display:none; z-index:1000; max-height:200px; overflow-y:auto; width:100%;"></div>
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
                            <div class="integration-filters-panel bg-light rounded-3 p-3 mb-3 border">
                                <div class="row g-2 align-items-center">
                                    <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                                        <button id="filtroMinhas" class="integration-filter-chip" type="button">
                                            <i class="fa fa-user-check"></i>
                                            <span>Minhas</span>
                                        </button>
                                        <div class="vr" style="opacity: 0.2;"></div>
                                        <div class="d-flex align-items-center gap-2 flex-grow-1 flex-wrap">
                                            <select id="filtroEquipe" class="form-select integration-select" style="display:none;">
                                                <option value="">Equipe</option>
                                                <?php foreach ($equipes as $eq): ?><option value="<?php echo $eq; ?>"><?php echo $eq; ?></option><?php endforeach; ?>
                                            </select>
                                            <select id="filtroResp" class="form-select integration-select">
                                                <option value="">Respons&aacute;vel</option>
                                                <?php foreach ($responsaveis as $r): ?>
                                                    <option value="<?php echo htmlspecialchars((string)($r['filtro_id'] ?? $r['filtro_nome'])); ?>" data-responsavel-nome="<?php echo htmlspecialchars($r['filtro_nome'] ?? ''); ?>"><?php echo htmlspecialchars($r['filtro_nome'] ?? ''); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select id="filtroStatus" class="form-select integration-select" style="min-width: 138px;">
                                                <option value="">Status</option>
                                                <option value="Pendente">Pendente</option>
                                                <option value="Em andamento">Em andamento</option>
                                                <option value="Conclu&iacute;da">Conclu&iacute;da</option>
                                            </select>
                                            <select id="filtroOrdem" class="form-select integration-select" style="min-width: 148px;">
                                                <option value="desc">Mais recentes</option>
                                                <option value="asc">Mais antigas</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="integration-search-wrap">
                                            <i class="fa fa-magnifying-glass"></i>
                                            <input type="search" id="filtroBusca" class="form-control form-control-sm integration-search" placeholder="Buscar por t&iacute;tulo, descri&ccedil;&atilde;o, respons&aacute;vel...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tasks-list-shell px-4 py-2">
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
                                                    <div id="edit-task-team-block" class="mb-3 p-2 rounded-3 border border-2" style="display:none; border-color:#0d6efd !important; background:#f8fbff;">
                                                        <div class="fw-semibold mb-1" style="color:#0d6efd;">Destino da tarefa</div>
                                                        <div class="small text-muted">Equipe: <span id="edit-task-team-name">-</span></div>
                                                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnViewTaskTeamUsers">Ver usuários da equipe</button>
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
                                <div class="mb-2">
                                    <label class="form-label">Tipo de lembrete</label>
                                    <div class="d-flex gap-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="rem_type" id="rem-type-lead" value="lead" checked>
                                            <label class="form-check-label small" for="rem-type-lead">Associar a um lead</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="rem_type" id="rem-type-contact" value="contact">
                                            <label class="form-check-label small" for="rem-type-contact">Contato livre (nome + telefone)</label>
                                        </div>
                                    </div>
                                </div>
                                <div id="rem-lead-block" class="mb-2 position-relative">
                                    <label class="form-label">Identificação do Lead</label>
                                    <input type="text" name="lead_ident" id="rem-lead-ident" class="form-control form-control-sm" placeholder="Digite nome, email ou telefone do lead" autocomplete="off">
                                    <div id="leadSuggestions" class="list-group position-absolute bg-white border" style="display:none; z-index:1000; max-height:200px; overflow-y:auto; width:100%;"></div>
                                </div>
                                <div id="rem-lead-id-block" class="mb-2">
                                    <label class="form-label">Lead ID (automático)</label>
                                    <input type="text" name="lead_id" id="rem-lead-id" class="form-control form-control-sm" readonly placeholder="Será preenchido automaticamente">
                                </div>
                                <div id="rem-contact-block" class="mb-2" style="display:none;">
                                    <label class="form-label">Nome do Contato</label>
                                    <input type="text" name="contact_name" id="rem-contact-name" class="form-control form-control-sm" placeholder="Nome do cliente">
                                </div>
                                <div id="rem-contact-phone-block" class="mb-2" style="display:none;">
                                    <label class="form-label">Telefone do Contato</label>
                                    <input type="text" name="contact_phone" id="rem-contact-phone" class="form-control form-control-sm" placeholder="(99) 99999-9999">
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
                                <div id="rem-responsavel-wrap" class="mb-2 position-relative">
                                    <label class="form-label">Responsável</label>
                                    <input type="text" name="responsavel" id="rem-responsavel" class="form-control form-control-sm" placeholder="Digite nome do responsável" autocomplete="off">
                                    <input type="hidden" name="responsavel_id" id="rem-responsavel-id" value="">
                                    <div id="remResponsavelSuggestions" class="list-group position-absolute bg-white border" style="display:none; z-index:1000; max-height:200px; overflow-y:auto; width:100%;"></div>
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

<!-- Modal Usuários da Equipe -->
<div class="modal fade" id="modalTeamUsers" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title"><i class="fa fa-users text-primary me-1"></i> Usuários da Equipe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2" id="teamUsersModalTeamName">-</div>
                <div id="teamUsersModalList" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script type="module">
import { fetchTasks, addTask, updateTask, deleteTask, fetchRecentActivities } from './assets/js/team_tasks.js';

const equipes = <?php echo json_encode($equipes); ?>;
const teamsData = <?php echo json_encode($teamsData); ?>;
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
let taskEditOpenLoading = false;
let reminderEditOpenLoading = false;
let taskListRequestId = 0;

// Funções para atualizar a lista de tarefas e a linha do tempo
async function atualizarTarefas() {
    const currentRequestId = ++taskListRequestId;
    const equipeFiltro = document.getElementById('filtroEquipe').value;
    const respFiltro = document.getElementById('filtroResp').value;
    const respOption = document.getElementById('filtroResp')?.selectedOptions?.[0] || null;
    const respNomeFiltro = respOption ? (respOption.dataset.responsavelNome || respOption.textContent || '') : '';
    const mineChecked = filtroMinhasAtivo;
    const statusFiltro = document.getElementById('filtroStatus').value;
    const buscaFiltro = document.getElementById('filtroBusca').value;

    const list = document.getElementById('tasksList');
    list.innerHTML = '<div class="text-muted text-center py-4" style="font-size: 0.95rem;">Carregando tarefas...</div>';
    if (statusFiltro === 'Lembretes') {
        // load reminders instead of tasks
        try {
            list.innerHTML = '<div class="text-muted text-center py-4" style="font-size: 0.95rem;">Carregando lembretes...</div>';
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
                // prefer contact_name for free contacts when no lead_id is set
                const reminderTitle = (r.lead_id && Number(r.lead_id) > 0)
                    ? (r.lead_name || ('Lead #' + r.lead_id))
                    : ((r.contact_name || 'Contato') + (r.contact_phone ? (' • ' + r.contact_phone) : ''));
                content.innerHTML = `<div class="fw-semibold">${escapeHtml(reminderTitle)} <span class="badge ms-2" style="background:#0b6ac1;color:#fff;">Lembrete</span></div>
                    <div class="small text-muted">Agendado: ${r.remind_at} • Status: ${escapeHtml(r.status)}</div>
                    <div class="mt-1">${escapeHtml(r.message || '')}</div>`;
                card.appendChild(content);
                // make whole card clickable to edit
                card.style.cursor = 'pointer';
                card.addEventListener('click', () => {
                    if (reminderEditOpenLoading) return;
                    openEditReminderModal(r.id);
                });
                list.appendChild(card);
            });
        } catch (e) { console.error(e); list.innerHTML = '<div class="text-danger">Erro carregando lembretes</div>'; }
        return;
    }
    const params = { equipe: equipeFiltro, status: statusFiltro };
    if (mineChecked) {
        params.responsavel = username;
    } else if (respFiltro) {
        params.responsavel_id = respFiltro;
        if (respNomeFiltro) params.responsavel = respNomeFiltro;
    }
    list.innerHTML = '<div class="text-muted text-center py-4" style="font-size: 0.95rem;">Carregando tarefas...</div>';
    let tarefas;
    try {
        tarefas = await fetchTasks(params);
    } catch (e) {
        console.error('Erro ao carregar tarefas:', e);
        if (currentRequestId === taskListRequestId) {
            list.innerHTML = '<div class="text-danger text-center py-4">Erro ao carregar tarefas.</div>';
        }
        return;
    }
    // Ignora respostas antigas quando o usuário alterou filtros rapidamente.
    if (currentRequestId !== taskListRequestId) return;
    
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
        // Remove o estado de carregamento antes de inserir os cards.
        list.innerHTML = '';
        const featuredIndex = ordem === 'asc' ? tarefas.length - 1 : 0;
        tarefas.forEach((t, index) => {
            const card = document.createElement('div');
            card.className = 'task-list-card mb-3 p-3 rounded-3 d-flex align-items-start gap-3 bg-white position-relative';
            card.style.cssText = 'border: 1px solid #cbd5e1; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.3s ease; cursor: pointer;';
            if (index === featuredIndex) {
                card.classList.add('task-card--featured');
                card.style.paddingTop = '22px';
                card.style.borderTopWidth = '3px';
                card.style.borderTopColor = '#0d6efd';
                const ribbon = document.createElement('div');
                ribbon.className = 'task-card-ribbon';
                ribbon.innerHTML = '<i class="fa fa-star me-1"></i> Mais recente';
                card.appendChild(ribbon);
            }
            card.addEventListener('mouseenter', () => {
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)';
                card.style.transform = 'translateY(-2px)';
                try { card.style.borderColor = 'var(--blue-700)'; } catch(e){}
            });
            card.addEventListener('mouseleave', () => {
                card.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
                card.style.transform = 'translateY(0)';
                try { card.style.border = '1px solid #cbd5e1'; } catch(e){}
            });
            // clicar no card abre o modal de edição
            card.addEventListener('click', () => {
                if (taskEditOpenLoading) return;
                openEditModal(t);
            });
            const statusRibbon = document.createElement('div');
            statusRibbon.className = 'task-status-ribbon';
            statusRibbon.style.background = statusColor(t.status);
            statusRibbon.textContent = t.status || 'Pendente';
            card.appendChild(statusRibbon);
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
            </div>
            <div class="d-flex align-items-center gap-3 mb-2" style="font-size: 0.8rem;">
                ${responsavelNome ? '<div class="text-muted"><i class="fa fa-user me-1" style="opacity:0.6;"></i><span>' + escapeHtml(responsavelNome) + '</span></div>' : ''}
                ${t.data_vencimento ? '<div class="text-muted"><i class="fa fa-calendar me-1" style="opacity:0.6;"></i><span>' + t.data_vencimento + (criadorNome && criadorNome !== responsavelNome ? ' • Criado por: <b>' + escapeHtml(criadorNome) + '</b>' : '') + '</span></div>' : ''}
            </div>
            ${t.descricao ? '<div class="text-secondary" style="font-size: 0.85rem; line-height: 1.5; color: #64748b !important;">' + escapeHtml(t.descricao) + '</div>' : ''}`;
            card.appendChild(content);
            // Ações (concluir se for responsável, editar, excluir)
            const actions = document.createElement('div');
            actions.className = 'task-card-actions d-flex gap-2 position-absolute';
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

function setLoadingState(formOrButton, loading, message = 'Salvando...') {
    const root = formOrButton && formOrButton.tagName === 'FORM' ? formOrButton : (formOrButton?.closest('form') || null);
    const btn = formOrButton && formOrButton.tagName === 'BUTTON' ? formOrButton : (root ? root.querySelector('button[type="submit"], button.btn-primary, button.btn-success') : null);
    let overlay = root ? root.querySelector('.wrcrm-loading-overlay') : null;
    if (!overlay && root) {
        root.style.position = root.style.position || 'relative';
        overlay = document.createElement('div');
        overlay.className = 'wrcrm-loading-overlay';
        overlay.style.cssText = 'display:none; position:absolute; inset:0; z-index:30; background:rgba(255,255,255,.75); backdrop-filter: blur(1px); align-items:center; justify-content:center; border-radius:12px;';
        overlay.innerHTML = '<div class="d-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-white shadow-sm border"><div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div><div class="fw-semibold text-primary">' + message + '</div></div>';
        root.appendChild(overlay);
    }
    if (overlay) {
        overlay.style.display = loading ? 'flex' : 'none';
        const label = overlay.querySelector('.fw-semibold');
        if (label) label.textContent = message;
    }
    if (btn) {
        btn.disabled = !!loading;
        btn.dataset.originalText = btn.dataset.originalText || btn.innerHTML;
        if (loading) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + message;
        } else if (btn.dataset.originalText) {
            btn.innerHTML = btn.dataset.originalText;
        }
    }
}

function setModalLoading(modalId, loading, message = 'Carregando...') {
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;
    let overlay = modalEl.querySelector('.wrcrm-modal-loading-overlay');
    if (!overlay && loading) {
        const body = modalEl.querySelector('.modal-content');
        if (!body) return;
        body.style.position = body.style.position || 'relative';
        overlay = document.createElement('div');
        overlay.className = 'wrcrm-modal-loading-overlay';
        overlay.style.cssText = 'display:none; position:absolute; inset:0; z-index:60; background:rgba(255,255,255,.8); backdrop-filter: blur(1px); align-items:center; justify-content:center; border-radius:12px;';
        overlay.innerHTML = '<div class="d-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-white shadow-sm border"><div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div><div class="fw-semibold text-primary">' + message + '</div></div>';
        body.appendChild(overlay);
    }
    if (overlay) {
        overlay.style.display = loading ? 'flex' : 'none';
        const label = overlay.querySelector('.fw-semibold');
        if (label) label.textContent = message;
    }
}

async function showTeamUsers(teamId, teamName) {
    const modalEl = document.getElementById('modalTeamUsers');
    const listEl = document.getElementById('teamUsersModalList');
    const teamNameEl = document.getElementById('teamUsersModalTeamName');
    if (!modalEl || !listEl || !teamNameEl) return;
    teamNameEl.textContent = teamName ? `Equipe: ${teamName}` : 'Equipe';
    listEl.innerHTML = '<div class="text-muted">Carregando...</div>';
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    try {
        const res = await fetch(`api/get_team_users.php?team_id=${encodeURIComponent(teamId)}`);
        const data = await res.json();
        if (!data.success || !Array.isArray(data.users) || !data.users.length) {
            listEl.innerHTML = '<div class="text-muted">Nenhum usuário encontrado nesta equipe.</div>';
            return;
        }
        listEl.innerHTML = '';
        data.users.forEach(u => {
            const row = document.createElement('div');
            row.className = 'list-group-item d-flex align-items-center gap-2';
            row.innerHTML = `
                <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary text-white fw-semibold" style="width:36px;height:36px;">${escapeHtmlGlobal((u.username || '?').charAt(0).toUpperCase())}</div>
                <div class="flex-grow-1">
                    <div class="fw-semibold">${escapeHtmlGlobal(u.username || '')}</div>
                    <div class="small text-muted">${escapeHtmlGlobal(u.email || '')}</div>
                </div>`;
            listEl.appendChild(row);
        });
    } catch (e) {
        listEl.innerHTML = '<div class="text-danger">Erro ao carregar usuários da equipe.</div>';
    }
}

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
    if (taskEditOpenLoading) return;
    taskEditOpenLoading = true;
    setModalLoading('modalEditarTarefa', true, 'Carregando tarefa...');
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
    const editTaskTeamBlock = document.getElementById('edit-task-team-block');
    const editTaskTeamName = document.getElementById('edit-task-team-name');
    const hasTaskTeam = !!(task.team_id || task.team_name);
    if (editTaskTeamBlock && editTaskTeamName) {
        if (hasTaskTeam) {
            editTaskTeamName.textContent = task.team_name || task.equipe || 'Equipe';
            editTaskTeamBlock.style.display = 'block';
        } else {
            editTaskTeamBlock.style.display = 'none';
            editTaskTeamName.textContent = '-';
        }
    }
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
    const editRespWrap = document.getElementById('edit-responsavel')?.closest('.mb-3');
    if (editRespWrap) editRespWrap.style.display = hasTaskTeam ? 'none' : 'block';
    if (hasTaskTeam && editRespIdHidden) editRespIdHidden.value = '';
    const taskTeamBtn = document.getElementById('btnViewTaskTeamUsers');
    if (taskTeamBtn) {
        taskTeamBtn.style.display = hasTaskTeam ? 'inline-flex' : 'none';
        taskTeamBtn.onclick = () => showTeamUsers(task.team_id || '', task.team_name || task.equipe || '');
    }
    const modal = new bootstrap.Modal(document.getElementById('modalEditarTarefa'));
    modal.show();
    setModalLoading('modalEditarTarefa', false);
    taskEditOpenLoading = false;
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
const TEAM_INTEGRATION_ACTIVE_TAB_KEY = 'integracaoEquipesActiveTab';

// Note: tab click listeners are attached after DOMContentLoaded to avoid duplicates

function showTab(name) {
    console.log('showTab called with:', name);
    const validTabs = ['tarefas', 'lembretes', 'integracoes'];
    if (!validTabs.includes(name)) name = 'tarefas';
    try {
        localStorage.setItem(TEAM_INTEGRATION_ACTIVE_TAB_KEY, name);
    } catch (e) {}
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
                const tasksHtml = (ui.tasks && ui.tasks.length) ? ui.tasks.map((t, index) => {
                    const statusCls = 'status-' + String((t.status||'').replace(/\s+/g, '-'));
                    const titulo = escapeHtmlGlobal(t.titulo||'(sem título)');
                    const meta = escapeHtmlGlobal(t.status||'') + (t.data_vencimento? ' • '+escapeHtmlGlobal(t.data_vencimento):'');
                    const featuredClass = index === 0 ? ' integration-task-item--featured' : '';
                    const badge = index === 0 ? '<div class="integration-task-badge"><i class="fa fa-star"></i> Mais recente</div>' : '';
                    return `<div class="integration-task-item ${statusCls}${featuredClass}">${badge}<div class="task-title">${titulo}</div><div class="task-meta">${meta}</div></div>`;
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
                const titleToday = (r.lead_id && Number(r.lead_id) > 0)
                    ? (r.lead_name || ('Lead #' + r.lead_id))
                    : ((r.contact_name || 'Contato') + (r.contact_phone ? (' • ' + r.contact_phone) : ''));
                it.innerHTML = `
                    <div class="reminder-icon-circle">
                        <i class="fa fa-clock-o"></i>
                    </div>
                    <div class="reminder-content-modern">
                        <div class="reminder-title-modern">
                            ${escapeHtmlGlobal(titleToday)}
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
                            ${(() => {
                                if (r.lead_id && Number(r.lead_id) > 0) {
                                    return escapeHtmlGlobal(r.lead_name ? (r.lead_name + ' (#' + r.lead_id + ')') : ('Lead #' + r.lead_id));
                                }
                                return escapeHtmlGlobal((r.contact_name || 'Contato') + (r.contact_phone ? (' • ' + r.contact_phone) : ''));
                            })()}
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
                this.classList.add('is-active');
            } else {
                this.classList.remove('is-active');
            }
            atualizarTarefas();
        });
    }
    
    const filtroBusca = document.getElementById('filtroBusca');
    if (filtroBusca) filtroBusca.addEventListener('input', atualizarTarefas);
    // Evento de submissão do formulário de nova tarefa
    const formNovaTarefa = document.getElementById('formNovaTarefa');
    if (formNovaTarefa) {
        if (!document.getElementById('quick-new-destino')) {
            const destinoWrap = document.createElement('div');
            destinoWrap.className = 'mb-2 p-2 rounded-3 border border-2';
            destinoWrap.style.borderColor = '#0d6efd';
            destinoWrap.style.background = '#f8fbff';
            const teamOptions = (teamsData || []).map(t => `<option value="${t.id}">${escapeHtmlGlobal(t.name || '')}</option>`).join('');
            destinoWrap.innerHTML = `
                <div class="fw-semibold mb-2" style="color:#0d6efd;">Destino</div>
                <select name="destino" id="quick-new-destino" class="form-select form-select-sm">
                    <option value="responsavel" selected>Responsável</option>
                    <option value="team">Equipe inteira</option>
                </select>
                <div id="quick-team-wrap" style="display:none; margin-top:8px;">
                    <label class="form-label small">Equipe</label>
                    <select name="team_id" id="quick-new-team-id" class="form-select form-select-sm">
                        <option value="">Selecione</option>
                        ${teamOptions}
                    </select>
                </div>`;
            formNovaTarefa.insertBefore(destinoWrap, formNovaTarefa.firstChild);
        }
        const quickDestino = document.getElementById('quick-new-destino');
        const quickTeamWrap = document.getElementById('quick-team-wrap');
        if (quickDestino && quickTeamWrap) {
            const syncQuickDestino = () => {
                quickTeamWrap.style.display = quickDestino.value === 'team' ? 'block' : 'none';
            };
            quickDestino.addEventListener('change', syncQuickDestino);
            syncQuickDestino();
        }
        formNovaTarefa.addEventListener('submit', async function(e) {
            e.preventDefault();
            setLoadingState(formNovaTarefa, true, 'Salvando tarefa...');
            try {
                const formData = new FormData(this);
                const novaTarefa = Object.fromEntries(formData);
                novaTarefa.destino = document.getElementById('quick-new-destino')?.value || 'responsavel';
                if (novaTarefa.destino === 'team') {
                    novaTarefa.team_id = document.getElementById('quick-new-team-id')?.value || '';
                    novaTarefa.responsavel_id = '';
                }
                // Initialize responsavel_id from select element
                const responsavelSelect = document.getElementById('quick-new-responsavel');
                if (responsavelSelect) {
                    const selectedOption = responsavelSelect.options[responsavelSelect.selectedIndex];
                    const userId_resp = selectedOption.dataset.userId || '';
                    if (novaTarefa.destino !== 'team') novaTarefa.responsavel_id = userId_resp;
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
            } finally {
                setLoadingState(formNovaTarefa, false);
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
        const formModalNova = document.getElementById('formModalNovaTarefa');
        if (formModalNova && !document.getElementById('modal-new-destino')) {
            const destinoWrap = document.createElement('div');
            destinoWrap.className = 'mb-2 p-2 rounded-3 border border-2';
            destinoWrap.style.borderColor = '#0d6efd';
            destinoWrap.style.background = '#f8fbff';
            const teamOptions = (teamsData || []).map(t => `<option value="${t.id}">${escapeHtmlGlobal(t.name || '')}</option>`).join('');
            destinoWrap.innerHTML = `
                <div class="fw-semibold mb-2" style="color:#0d6efd;">Destino</div>
                <select name="destino" id="modal-new-destino" class="form-select form-select-sm">
                    <option value="responsavel" selected>Responsável</option>
                    <option value="team">Equipe inteira</option>
                </select>
                <div id="modal-team-wrap" style="display:none; margin-top:8px;">
                    <label class="form-label small">Equipe</label>
                    <select name="team_id" id="modal-new-team-id" class="form-select form-select-sm">
                        <option value="">Selecione</option>
                        ${teamOptions}
                    </select>
                </div>`;
            formModalNova.insertBefore(destinoWrap, formModalNova.firstChild);
            const modalDestino = document.getElementById('modal-new-destino');
            const modalTeamWrap = document.getElementById('modal-team-wrap');
            if (modalDestino && modalTeamWrap) {
                const syncModalDestino = () => { modalTeamWrap.style.display = modalDestino.value === 'team' ? 'block' : 'none'; };
                modalDestino.addEventListener('change', syncModalDestino);
                syncModalDestino();
            }
        }
        btnSalvarNovaModal.addEventListener('click', async function() {
            const form = document.getElementById('formModalNovaTarefa');
            setLoadingState(btnSalvarNovaModal, true, 'Salvando...');
            try {
                const formData = new FormData(form);
                const data = Object.fromEntries(formData);
                data.destino = document.getElementById('modal-new-destino')?.value || 'responsavel';
                if (data.destino === 'team') {
                    data.team_id = document.getElementById('modal-new-team-id')?.value || '';
                    data.responsavel_id = '';
                }
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
            } finally {
                setLoadingState(btnSalvarNovaModal, false);
            }
        });
    }
    // submissão do novo lembrete
    const formNovoLembrete = document.getElementById('formNovoLembrete');
    if (formNovoLembrete) {
        if (!document.getElementById('rem-destino')) {
            const destinoWrap = document.createElement('div');
            destinoWrap.className = 'mb-2 p-2 rounded-3 border border-2';
            destinoWrap.style.borderColor = '#0d6efd';
            destinoWrap.style.background = '#f8fbff';
            const teamOptions = (teamsData || []).map(t => `<option value="${t.id}">${escapeHtmlGlobal(t.name || '')}</option>`).join('');
            destinoWrap.innerHTML = `
                <div class="fw-semibold mb-2" style="color:#0d6efd;">Destino</div>
                <select name="destino" id="rem-destino" class="form-select form-select-sm mb-2">
                    <option value="responsavel" selected>Responsável</option>
                    <option value="team">Equipe inteira</option>
                </select>
                <div id="rem-team-wrap" style="display:none; margin-top:8px;">
                    <label class="form-label small">Equipe</label>
                    <select name="team_id" id="rem-team-id" class="form-select form-select-sm">
                        <option value="">Selecione</option>
                        ${teamOptions}
                    </select>
                </div>`;
            formNovoLembrete.insertBefore(destinoWrap, formNovoLembrete.firstChild);
        }
        const remDestino = document.getElementById('rem-destino');
        const remTeamWrap = document.getElementById('rem-team-wrap');
        const remRespWrap = document.getElementById('rem-responsavel-wrap');
        if (remDestino && remTeamWrap && remRespWrap) {
            const syncRemDestino = () => {
                const isTeam = remDestino.value === 'team';
                remTeamWrap.style.display = isTeam ? 'block' : 'none';
                remRespWrap.style.display = isTeam ? 'none' : 'block';
                if (isTeam) {
                    document.getElementById('rem-responsavel-id').value = '';
                } else {
                    document.getElementById('rem-team-id').value = '';
                }
            };
            remDestino.addEventListener('change', syncRemDestino);
            syncRemDestino();
        }
        // toggle between lead search and manual contact
        const remTypeRadios = document.querySelectorAll('input[name="rem_type"]');
        function updateRemTypeUI() {
            const val = document.querySelector('input[name="rem_type"]:checked')?.value || 'lead';
            const leadBlock = document.getElementById('rem-lead-block');
            const leadIdBlock = document.getElementById('rem-lead-id-block');
            const contactBlock = document.getElementById('rem-contact-block');
            const contactPhoneBlock = document.getElementById('rem-contact-phone-block');
            if (val === 'lead') {
                if (leadBlock) leadBlock.style.display = 'block';
                if (leadIdBlock) leadIdBlock.style.display = 'block';
                if (contactBlock) contactBlock.style.display = 'none';
                if (contactPhoneBlock) contactPhoneBlock.style.display = 'none';
            } else {
                if (leadBlock) leadBlock.style.display = 'none';
                if (leadIdBlock) leadIdBlock.style.display = 'none';
                if (contactBlock) contactBlock.style.display = 'block';
                if (contactPhoneBlock) contactPhoneBlock.style.display = 'block';
            }
        }
        remTypeRadios.forEach(r => r.addEventListener('change', updateRemTypeUI));
        updateRemTypeUI();

        formNovoLembrete.addEventListener('submit', async function(e){
            e.preventDefault();
            setLoadingState(formNovoLembrete, true, 'Salvando lembrete...');
            try {
                const remType = document.querySelector('input[name="rem_type"]:checked')?.value || 'lead';
                const leadIdRaw = document.getElementById('rem-lead-id').value.trim();
                const leadId = leadIdRaw && !isNaN(Number(leadIdRaw)) ? Number(leadIdRaw) : null;
                const contactName = document.getElementById('rem-contact-name') ? document.getElementById('rem-contact-name').value.trim() : '';
                const contactPhone = document.getElementById('rem-contact-phone') ? document.getElementById('rem-contact-phone').value.trim() : '';
                if (!document.getElementById('rem-message').value.trim()) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Mensagem obrigatória</div>'; return; }
                if (!document.getElementById('rem-date').value || !document.getElementById('rem-time').value) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Data e hora obrigatórias</div>'; return; }
                if (remType === 'lead' && !leadId) { document.getElementById('remMsg').innerHTML = '<div class="text-warning">Recomenda-se selecionar um lead válido ou mudar para "Contato livre".</div>'; }
                if (remType === 'contact' && (!contactName || !contactPhone)) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Nome e telefone do contato são obrigatórios para contato livre.</div>'; return; }
                const datetime = document.getElementById('rem-date').value + ' ' + document.getElementById('rem-time').value;
                const responsavelId = document.getElementById('rem-responsavel-id') ? document.getElementById('rem-responsavel-id').value.trim() : '';
                const destino = document.getElementById('rem-destino')?.value || 'responsavel';
                const payload = new URLSearchParams();
                payload.append('action','add');
                payload.append('datetime', datetime);
                payload.append('message', document.getElementById('rem-message').value.trim());
                payload.append('template_id', document.getElementById('rem-template').value || '');
                payload.append('lead_ident', document.getElementById('rem-lead-ident').value.trim());
                payload.append('destino', destino);
                if (destino === 'team') {
                    payload.append('team_id', document.getElementById('rem-team-id')?.value || '');
                } else if (responsavelId) payload.append('responsavel_id', responsavelId);
                if (remType === 'lead') {
                    if (leadId) payload.append('lead_id', String(leadId)); else payload.append('lead_id','0');
                } else {
                    payload.append('lead_id','0');
                    payload.append('contact_name', contactName);
                    payload.append('contact_phone', contactPhone);
                }
                const res = await fetch('includes/reminders_api.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
                const data = await res.json();
                if (data.ok) {
                    document.getElementById('remMsg').innerHTML = '<div class="alert alert-success">Lembrete salvo</div>';
                    this.reset();
                    updateRemTypeUI();
                    loadRemindersLayout();
                    setTimeout(()=>{ document.getElementById('remMsg').innerHTML=''; }, 2500);
                } else {
                    document.getElementById('remMsg').innerHTML = '<div class="text-danger">Erro ao salvar</div>';
                }
            } catch (e) { document.getElementById('remMsg').innerHTML = '<div class="text-danger">Erro ao salvar</div>'; }
            finally {
                setLoadingState(formNovoLembrete, false);
            }
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

    // Autocomplete de responsáveis para lembrete (debounce 500ms)
    const remResponsavelInput = document.getElementById('rem-responsavel');
    const remRespHidden = document.getElementById('rem-responsavel-id');
    const remRespSuggestions = document.getElementById('remResponsavelSuggestions');
    let remRespTimer = null;

    function debounceDelay(fn, delay) {
        return function(...args) {
            if (remRespTimer) clearTimeout(remRespTimer);
            remRespTimer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    async function fetchResponsaveis(query) {
        try {
            const res = await fetch(`api/search_users.php?q=${encodeURIComponent(query)}`);
            if (!res.ok) return [];
            const list = await res.json();
            return Array.isArray(list) ? list : [];
        } catch (e) { console.error('erro fetchResponsaveis', e); return []; }
    }

    if (remResponsavelInput) {
        remResponsavelInput.addEventListener('input', debounceDelay(async function() {
            const q = this.value.trim();
            remRespHidden.value = '';
            if (q.length < 2) { if (remRespSuggestions) remRespSuggestions.style.display = 'none'; return; }
            const users = await fetchResponsaveis(q);
            if (!remRespSuggestions) return;
            remRespSuggestions.innerHTML = '';
            if (!users.length) { remRespSuggestions.style.display = 'none'; return; }
            users.forEach(u => {
                const item = document.createElement('a');
                item.className = 'list-group-item list-group-item-action py-2 d-flex align-items-center gap-2';
                item.style.cursor = 'pointer';
                const avatarHtml = u.avatar ? `<img src="${escapeHtmlGlobal(u.avatar)}" style="width:32px;height:32px;object-fit:cover;border-radius:50%;">` : `<div style="width:32px;height:32px;border-radius:50%;background:#cbd5e1;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">${escapeHtmlGlobal((u.username||'U').charAt(0).toUpperCase())}</div>`;
                item.innerHTML = `<div style="flex:0 0 36px">${avatarHtml}</div><div style="flex:1"><div style="font-weight:600;">${escapeHtmlGlobal(u.username)}</div><div class="small text-muted">${escapeHtmlGlobal(u.email||'')}</div></div>`;
                item.addEventListener('click', () => {
                    remResponsavelInput.value = u.username || '';
                    remRespHidden.value = u.id || '';
                    remRespSuggestions.style.display = 'none';
                });
                remRespSuggestions.appendChild(item);
            });
            remRespSuggestions.style.display = 'block';
        }, 500));

        // hide suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (remResponsavelInput && remRespSuggestions && !remResponsavelInput.contains(e.target) && !remRespSuggestions.contains(e.target)) {
                remRespSuggestions.style.display = 'none';
            }
        });
    }

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

    let savedTab = 'tarefas';
    try {
        savedTab = localStorage.getItem(TEAM_INTEGRATION_ACTIVE_TAB_KEY) || 'tarefas';
    } catch (e) {}
    showTab(savedTab);
});

// --- Reminders: edit/delete helpers and modal handling ---
async function openEditReminderModal(id) {
    if (reminderEditOpenLoading) return;
    reminderEditOpenLoading = true;
    setModalLoading('modalEditarLembrete', true, 'Carregando lembrete...');
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
        document.getElementById('editRem-responsavel').value = r.responsavel_name || '';
        document.getElementById('editRem-responsavel-id').value = r.responsavel_id || '';
        const editRemTeamBlock = document.getElementById('editRem-team-block');
        const editRemTeamName = document.getElementById('editRem-team-name');
        const hasRemTeam = !!(r.team_id || r.team_name);
        if (editRemTeamBlock && editRemTeamName) {
            if (hasRemTeam) {
                editRemTeamName.textContent = r.team_name || 'Equipe';
                editRemTeamBlock.style.display = 'block';
            } else {
                editRemTeamBlock.style.display = 'none';
                editRemTeamName.textContent = '-';
            }
        }
        // load templates and set value
        const templates = await fetchReminderTemplates();
        const sel = document.getElementById('editRem-template');
        sel.innerHTML = '<option value="">(Nenhum)</option>';
        templates.forEach(t=>{ const o = document.createElement('option'); o.value = t.id; o.textContent = t.name || t.title || ('template '+t.id); sel.appendChild(o); });
        sel.value = r.template_id || '';
        const modalEl = document.getElementById('modalEditarLembrete');
        // show/hide contact fields vs lead info
        const contactBlock = document.getElementById('editRem-contact-block');
        const contactNameEl = document.getElementById('editRem-contact-name');
        const contactPhoneEl = document.getElementById('editRem-contact-phone');
        const leadIdVal = r.lead_id ? Number(r.lead_id) : 0;
        if (leadIdVal && leadIdVal > 0) {
            if (contactBlock) contactBlock.style.display = 'none';
            if (contactNameEl) contactNameEl.value = '';
            if (contactPhoneEl) contactPhoneEl.value = '';
        } else {
            if (contactBlock) contactBlock.style.display = 'block';
            if (contactNameEl) contactNameEl.value = r.contact_name || '';
            if (contactPhoneEl) contactPhoneEl.value = r.contact_phone || '';
            // clear lead display since this is a free contact
            document.getElementById('editRem-lead-ident').value = '';
            document.getElementById('editRem-lead-id').value = '';
            document.getElementById('editRem-lead-phone').value = '';
        }
        const remRespWrap = document.getElementById('editRem-responsavel')?.closest('.mb-2');
        if (remRespWrap) remRespWrap.style.display = hasRemTeam ? 'none' : 'block';
        if (hasRemTeam) {
            const remRespId = document.getElementById('editRem-responsavel-id');
            if (remRespId) remRespId.value = '';
        }
        const remTeamBtn = document.getElementById('btnViewReminderTeamUsers');
        if (remTeamBtn) {
            remTeamBtn.style.display = hasRemTeam ? 'inline-flex' : 'none';
            remTeamBtn.onclick = () => showTeamUsers(r.team_id || '', r.team_name || 'Equipe');
        }
        // reuse existing instance when possible to avoid duplicate backdrops
        let modal = bootstrap.Modal.getInstance ? bootstrap.Modal.getInstance(modalEl) : null;
        if (!modal) {
            modal = new bootstrap.Modal(modalEl);
        }
        modal.show();
    } catch (e) {
        console.error(e);
        alert('Erro ao carregar lembrete para edição');
    } finally {
        setModalLoading('modalEditarLembrete', false);
        reminderEditOpenLoading = false;
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

    const editRemResponsavelInput = document.getElementById('editRem-responsavel');
    const editRemResponsavelId = document.getElementById('editRem-responsavel-id');
    const editRemRespSuggestions = document.getElementById('editRemResponsavelSuggestions');
    let editRemRespTimer = null;

    async function fetchResponsaveisEditReminder(query) {
        try {
            const res = await fetch(`api/search_users.php?q=${encodeURIComponent(query)}`);
            if (!res.ok) return [];
            const list = await res.json();
            return Array.isArray(list) ? list : [];
        } catch (e) { console.error('erro fetchResponsaveisEditReminder', e); return []; }
    }

    if (editRemResponsavelInput && editRemResponsavelId && editRemRespSuggestions) {
        editRemResponsavelInput.addEventListener('input', function() {
            if (editRemRespTimer) clearTimeout(editRemRespTimer);
            const q = this.value.trim();
            editRemResponsavelId.value = '';
            if (q.length < 2) {
                editRemRespSuggestions.style.display = 'none';
                return;
            }
            editRemRespTimer = setTimeout(async () => {
                const users = await fetchResponsaveisEditReminder(q);
                editRemRespSuggestions.innerHTML = '';
                if (!users.length) {
                    editRemRespSuggestions.style.display = 'none';
                    return;
                }
                users.forEach(u => {
                    const item = document.createElement('a');
                    item.className = 'list-group-item list-group-item-action py-2 d-flex align-items-center gap-2';
                    item.style.cursor = 'pointer';
                    const avatarHtml = u.avatar ? `<img src="${escapeHtmlGlobal(u.avatar)}" style="width:32px;height:32px;object-fit:cover;border-radius:50%;">` : `<div style="width:32px;height:32px;border-radius:50%;background:#cbd5e1;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">${escapeHtmlGlobal((u.username||'U').charAt(0).toUpperCase())}</div>`;
                    item.innerHTML = `<div style="flex:0 0 36px">${avatarHtml}</div><div style="flex:1"><div style="font-weight:600;">${escapeHtmlGlobal(u.username)}</div><div class="small text-muted">${escapeHtmlGlobal(u.email||'')}</div></div>`;
                    item.addEventListener('click', () => {
                        editRemResponsavelInput.value = u.username || '';
                        editRemResponsavelId.value = u.id || '';
                        editRemRespSuggestions.style.display = 'none';
                    });
                    editRemRespSuggestions.appendChild(item);
                });
                editRemRespSuggestions.style.display = 'block';
            }, 500);
        });

        document.addEventListener('click', (e) => {
            if (!editRemResponsavelInput.contains(e.target) && !editRemRespSuggestions.contains(e.target)) {
                editRemRespSuggestions.style.display = 'none';
            }
        });
    }

    btnSaveEditRem.addEventListener('click', async ()=>{
        const id = document.getElementById('editRem-id').value;
        const date = document.getElementById('editRem-date').value;
        const time = document.getElementById('editRem-time').value;
        const message = document.getElementById('editRem-message').value.trim();
        const templateId = document.getElementById('editRem-template').value || '';
        const responsavelId = document.getElementById('editRem-responsavel-id') ? document.getElementById('editRem-responsavel-id').value.trim() : '';
        if (!id || !date || !time || !message) { alert('Preencha data, hora e mensagem'); return; }
        try {
            const payload = new URLSearchParams();
            payload.append('action','update');
            payload.append('id', String(id));
            payload.append('datetime', date + ' ' + time);
            payload.append('message', message);
            payload.append('template_id', templateId);
            payload.append('responsavel_id', responsavelId);
            // include contact fields if present
            const contactName = document.getElementById('editRem-contact-name') ? document.getElementById('editRem-contact-name').value.trim() : '';
            const contactPhone = document.getElementById('editRem-contact-phone') ? document.getElementById('editRem-contact-phone').value.trim() : '';
            if (contactName) payload.append('contact_name', contactName);
            if (contactPhone) payload.append('contact_phone', contactPhone);
            // allow updating lead association when editRem-lead-id contains a numeric value
            const leadIdVal = document.getElementById('editRem-lead-id') ? document.getElementById('editRem-lead-id').value.trim() : '';
            if (leadIdVal !== '') payload.append('lead_id', String(leadIdVal));
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
