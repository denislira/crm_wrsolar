<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'includes/config.php';
include 'includes/permissions.php';

checkAccessOrRedirect('configuracoes');

// Buscar todos os usuários (inclui team_id e role_level)
$stmt = $pdo->query('SELECT u.id, u.username, u.email, r.name as role_name, u.team_id, u.role_level, t.name as team_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN teams t ON u.team_id = t.id');
$users = $stmt->fetchAll();

// Buscar papéis para o select
$stmt_roles = $pdo->query('SELECT * FROM roles');
$roles = $stmt_roles->fetchAll();

// Buscar teams para selects
$stmt_teams = $pdo->query('SELECT id, name FROM teams ORDER BY name');
$teams = $stmt_teams->fetchAll();

include 'includes/header.php';
?>

<style>
body.theme-dark .card.card-shadow,
body.theme-dark .card.card-shadow .card-header,
body.theme-dark .card.card-shadow .card-body,
body.theme-dark .modal-content,
body.theme-dark .accordion-item,
body.theme-dark .table-responsive,
body.theme-dark #waStatusCard,
body.theme-dark #waHelpCard,
body.theme-dark #appearancePreview {
    background: rgba(255,255,255,0.04) !important;
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.08) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}
body.theme-dark .nav-tabs {
    border-bottom-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .nav-tabs .nav-link {
    color: #c3d5ea !important;
}
body.theme-dark .nav-tabs .nav-link.active {
    background: rgba(255,255,255,0.08) !important;
    color: #fff !important;
}
body.theme-dark .table thead th,
body.theme-dark .table tbody td,
body.theme-dark .table-striped tbody tr,
body.theme-dark .table-hover tbody tr {
    color: #e6eef8 !important;
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark .table-striped tbody tr:nth-of-type(odd) {
    background: rgba(255,255,255,0.03) !important;
}
body.theme-dark .table-hover tbody tr:hover {
    background: rgba(255,255,255,0.05) !important;
}
body.theme-dark .btn-outline-secondary,
body.theme-dark .btn-outline-primary,
body.theme-dark .btn-outline-warning,
body.theme-dark .btn-outline-danger,
body.theme-dark .btn-outline-info {
    color: #e6eef8 !important;
    background: transparent !important;
    border-color: rgba(255,255,255,0.12) !important;
}
body.theme-dark .btn-outline-secondary:hover,
body.theme-dark .btn-outline-primary:hover,
body.theme-dark .btn-outline-warning:hover,
body.theme-dark .btn-outline-danger:hover,
body.theme-dark .btn-outline-info:hover {
    background: rgba(255,255,255,0.05) !important;
}
body.theme-dark .bg-white,
body.theme-dark .bg-light,
body.theme-dark .border,
body.theme-dark .border-top,
body.theme-dark .border-bottom,
body.theme-dark .border-start,
body.theme-dark .border-end {
    border-color: rgba(255,255,255,0.08) !important;
}
body.theme-dark #waStatusCard,
body.theme-dark #waHelpCard,
body.theme-dark #appearancePreview,
body.theme-dark .accordion-body {
    color: #e6eef8 !important;
}
body.theme-dark .form-check-label,
body.theme-dark .form-label,
body.theme-dark .small,
body.theme-dark .text-muted {
    color: #c3d5ea !important;
}

.settings-page .logo-preview-panel {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.settings-page .logo-preview-panel .preview-box {
    flex: 1;
    min-height: 120px;
    border-radius: 0.75rem;
    background: #d0d0d0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem;
    transition: background 0.2s ease;
}

.settings-page .logo-preview-panel.dark .preview-box {
    background: #111;
}

.settings-page .logo-preview-panel .preview-box img {
    max-width: 160px;
    max-height: 80px;
}

.settings-page .logo-preview-toggle {
    margin-bottom: 1rem;
}

.settings-page {
    background:
        radial-gradient(1100px 500px at -10% -25%, rgba(11, 137, 218, 0.18), transparent 62%),
        radial-gradient(950px 450px at 115% 0%, rgba(255, 140, 66, 0.14), transparent 60%),
        linear-gradient(180deg, #f5fbff 0%, #f2f6fb 42%, #f6f9fc 100%);
    min-height: 100vh;
}

.settings-page .settings-shell {
    max-width: 1320px;
    margin: 0 auto;
}

.settings-page .settings-title {
    margin-bottom: 1.2rem;
    padding: 1.15rem 1.25rem;
    border-radius: 16px;
    color: #fff;
    letter-spacing: 0.2px;
    background: linear-gradient(130deg, #0a58ca 0%, #0b8ada 48%, #27b09e 100%);
    box-shadow: 0 18px 38px rgba(13, 88, 182, 0.24);
}

.settings-page .settings-tabs {
    border: 0;
    gap: 0.5rem;
    padding: 0.3rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 10px 26px rgba(24, 60, 105, 0.12);
    backdrop-filter: blur(4px);
    position: sticky;
    top: 56px;
    z-index: 1050;
}

.settings-page .settings-tabs .nav-link {
    border: 0;
    border-radius: 10px;
    color: #516b89;
    font-weight: 600;
    padding: 0.58rem 0.95rem;
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.settings-page .settings-tabs .nav-link:hover {
    color: #21476f;
    background: rgba(13, 110, 253, 0.08);
    transform: translateY(-1px);
}

.settings-page .settings-tabs .nav-link.active {
    color: #fff;
    background: linear-gradient(120deg, #0d6efd 0%, #1da6ff 100%);
    box-shadow: 0 8px 20px rgba(13, 110, 253, 0.35);
}

.settings-page .settings-tab-content {
    margin-top: 1rem;
}

.settings-page .card.card-shadow {
    border: 1px solid #e2ecf8;
    border-radius: 16px;
    padding: 1.2rem !important;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 12px 28px rgba(18, 52, 94, 0.1);
}

.settings-page .table-responsive {
    border: 1px solid #e5edf8;
    border-radius: 12px;
    overflow: hidden;
}

.settings-page .table {
    --bs-table-bg: transparent;
    margin-bottom: 0;
}

.settings-page .table thead th {
    background: #edf4fc;
    color: #274562;
    font-weight: 700;
    font-size: 0.83rem;
    letter-spacing: 0.2px;
    text-transform: uppercase;
    border-bottom: 1px solid #d9e6f5;
}

.settings-page .table tbody td {
    vertical-align: middle;
    border-color: #ecf2f9;
}

.settings-page .table-hover tbody tr:hover,
.settings-page .table-striped tbody tr:hover {
    background: #f5faff;
}

.settings-page .btn {
    border-radius: 10px;
    font-weight: 600;
}

.settings-page .btn-primary {
    background: linear-gradient(120deg, #0d6efd 0%, #1296ff 100%);
    border: 0;
    box-shadow: 0 10px 20px rgba(13, 110, 253, 0.25);
}

.settings-page .btn-primary:hover {
    transform: translateY(-1px);
}

.settings-page .btn-outline-secondary,
.settings-page .btn-outline-primary,
.settings-page .btn-outline-warning,
.settings-page .btn-outline-danger,
.settings-page .btn-outline-info {
    border-width: 1px;
}

.settings-page .form-control,
.settings-page .form-select {
    border-radius: 10px;
    border-color: #d5e2f3;
}

.settings-page .form-control:focus,
.settings-page .form-select:focus {
    border-color: #84b4f8;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12);
}

.settings-page #waStatusCard,
.settings-page #waHelpCard {
    border: 1px solid #e1ebf7;
    border-radius: 14px !important;
    box-shadow: 0 10px 24px rgba(16, 52, 93, 0.1);
}

.settings-page #waQrImage {
    border-radius: 12px;
}

.settings-page .alert-info {
    border-color: #b7e1ff;
    background: linear-gradient(160deg, #eaf6ff 0%, #f4fbff 100%);
    color: #2d567b;
}

.settings-page .tab-pane > .d-flex .h5 {
    color: #1f4266;
    font-weight: 700;
    letter-spacing: 0.2px;
}

.settings-page #appearance .border.rounded {
    border-color: #dbe8f7 !important;
    background: linear-gradient(145deg, #f3f8ff 0%, #eaf2fd 100%) !important;
}

.settings-page #appearance #appearancePreview {
    background: linear-gradient(155deg, #fdfefe 0%, #edf6ff 100%) !important;
    border-color: #d8e8fb !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 10px 22px rgba(18, 61, 108, 0.08);
}

body.theme-dark .settings-page {
    background:
        radial-gradient(1000px 440px at -10% -20%, rgba(44, 120, 255, 0.22), transparent 62%),
        radial-gradient(900px 380px at 110% 0%, rgba(16, 170, 124, 0.16), transparent 64%),
        #0f1826;
}

body.theme-dark .settings-page .settings-title {
    box-shadow: 0 16px 34px rgba(0, 0, 0, 0.34);
}

body.theme-dark .settings-page .settings-tabs {
    background: rgba(255, 255, 255, 0.04);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.32);
}

body.theme-dark .settings-page .settings-tabs .nav-link {
    color: #c8dbef !important;
}

body.theme-dark .settings-page .settings-tabs .nav-link:hover {
    color: #f5fbff !important;
    background: rgba(255, 255, 255, 0.1);
}

body.theme-dark .settings-page .settings-tabs .nav-link.active {
    color: #fff !important;
    background: linear-gradient(120deg, #0c7de0 0%, #18a2ff 100%);
}

body.theme-dark .settings-page .card.card-shadow {
    background: rgba(255, 255, 255, 0.03) !important;
    border-color: rgba(255, 255, 255, 0.11) !important;
}

body.theme-dark .settings-page .table-responsive {
    border-color: rgba(255, 255, 255, 0.1);
}

body.theme-dark .settings-page .table thead th {
    background: rgba(255, 255, 255, 0.08);
    color: #deebf8;
    border-color: rgba(255, 255, 255, 0.11);
}

body.theme-dark .settings-page .table tbody td {
    border-color: rgba(255, 255, 255, 0.08);
}

body.theme-dark .settings-page .table-hover tbody tr:hover,
body.theme-dark .settings-page .table-striped tbody tr:hover {
    background: rgba(255, 255, 255, 0.06);
}

body.theme-dark .settings-page .form-control,
body.theme-dark .settings-page .form-select {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.12);
    color: #e6eef8;
}

body.theme-dark .settings-page .alert-info {
    border-color: rgba(129, 207, 255, 0.35);
    background: rgba(76, 167, 226, 0.1);
    color: #bfe4ff;
}

body.theme-dark .settings-page .tab-pane > .d-flex .h5 {
    color: #dce9f6;
}

body.theme-dark .settings-page #appearance .border.rounded {
    border-color: rgba(255, 255, 255, 0.12) !important;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.03) 100%) !important;
}

body.theme-dark .settings-page #appearance #appearancePreview {
    border-color: rgba(255, 255, 255, 0.1) !important;
    background: linear-gradient(155deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.03) 100%) !important;
    box-shadow: none;
}

@media (max-width: 991px) {
    .settings-page .settings-tabs {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: thin;
    }

    .settings-page .settings-tabs .nav-item {
        flex: 0 0 auto;
    }
}

@media (max-width: 576px) {
    .settings-page {
        padding-top: 0.15rem;
    }

    .settings-page .settings-title {
        font-size: 1.18rem;
        padding: 0.95rem 1rem;
    }

    .settings-page .card.card-shadow {
        padding: 0.95rem !important;
    }
}

.edit-user-modal .modal-dialog {
    max-width: 680px;
}

.edit-user-modal .modal-content {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(11, 41, 80, 0.18);
}

.edit-user-modal .modal-header {
    border-bottom: 0;
    padding: 1rem 1.25rem;
    background: linear-gradient(120deg, #0d6efd 0%, #0b8ad9 50%, #1fa2ff 100%);
    color: #fff;
}

.edit-user-modal .modal-title {
    font-weight: 600;
    letter-spacing: 0.2px;
}

.edit-user-modal .modal-header .btn-close {
    filter: invert(1) grayscale(100%);
    opacity: 0.9;
}

.edit-user-modal .modal-body {
    padding: 1.25rem;
    background: #f7fbff;
}

.edit-user-modal .user-edit-section {
    background: #ffffff;
    border: 1px solid #e7eef8;
    border-radius: 12px;
    padding: 1rem;
}

.edit-user-modal .form-label {
    font-size: 0.84rem;
    font-weight: 600;
    color: #36516f;
    margin-bottom: 0.35rem;
}

.edit-user-modal .form-control,
.edit-user-modal .form-select {
    border-radius: 10px;
    border-color: #d9e6f6;
    min-height: 42px;
}

.edit-user-modal .form-control:focus,
.edit-user-modal .form-select:focus {
    border-color: #7eb4ff;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}

.edit-user-modal .avatar-box {
    display: flex;
    align-items: center;
    gap: 0.95rem;
    padding: 0.8rem;
    border-radius: 12px;
    background: #f6faff;
    border: 1px solid #dbe9fc;
}

.edit-user-modal .avatar-thumb {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: 14px;
    border: 1px solid #c7d9f1;
    box-shadow: 0 6px 16px rgba(32, 86, 156, 0.18);
    flex-shrink: 0;
}

.edit-user-modal .modal-footer {
    border-top: 1px solid #e6edf8;
    padding: 0.85rem 1.25rem 1.1rem;
    background: #fff;
}

.edit-user-modal .btn {
    border-radius: 10px;
    min-width: 108px;
}

body.theme-dark .edit-user-modal .modal-content {
    background: #142338 !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45) !important;
}

body.theme-dark .edit-user-modal .modal-body,
body.theme-dark .edit-user-modal .modal-footer {
    background: #142338 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
}

body.theme-dark .edit-user-modal .user-edit-section {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.1);
}

body.theme-dark .edit-user-modal .form-label {
    color: #d3e2f2;
}

body.theme-dark .edit-user-modal .form-control,
body.theme-dark .edit-user-modal .form-select {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.12);
    color: #e6eef8;
}

body.theme-dark .edit-user-modal .avatar-box {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
}

@media (max-width: 576px) {
    .edit-user-modal .modal-body {
        padding: 1rem;
    }

    .edit-user-modal .avatar-box {
        flex-direction: column;
        align-items: stretch;
    }

    .edit-user-modal .avatar-thumb {
        margin: 0 auto;
    }
}
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4 settings-page">
        <div class="container-fluid settings-shell">
            <h1 class="h4 settings-title">Configurações</h1>
            
            <!-- Nav tabs -->
            <ul class="nav nav-tabs settings-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="true">Gerenciar Usuários</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="teams-tab" data-bs-toggle="tab" data-bs-target="#teams" type="button" role="tab" aria-controls="teams" aria-selected="false">Equipes</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="integrations-tab" data-bs-toggle="tab" data-bs-target="#integrations" type="button" role="tab" aria-controls="integrations" aria-selected="false">Integrações</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="permissions-tab" data-bs-toggle="tab" data-bs-target="#permissions" type="button" role="tab" aria-controls="permissions" aria-selected="false">Permissões</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance" type="button" role="tab" aria-controls="appearance" aria-selected="false">Aparência</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="smtp-tab" data-bs-toggle="tab" data-bs-target="#smtp" type="button" role="tab" aria-controls="smtp" aria-selected="false">SMTP</button>
                </li>
                <!-- Adicionar mais abas aqui no futuro -->
            </ul>
            
            <div class="tab-content mt-3 settings-tab-content" id="settingsTabsContent">
                <!-- Aba Gerenciar Usuários -->
                <div class="tab-pane fade show active" id="users" role="tabpanel" aria-labelledby="users-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Usuários</h2>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">Adicionar Usuário</button>
                    </div>
                    <div class="card card-shadow p-3">
                        <?php if (empty($users)): ?>
                            <p>Nenhum usuário cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Usuário</th>
                                            <th>Email</th>
                                            <th>Equipe</th>
                                            <th>Nível</th>
                                            <th>Papel</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td><?php echo $user['id']; ?></td>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($user['team_name'] ?? ''); ?></td>
                                                <td><?php echo isset($user['role_level']) ? intval($user['role_level']) : ''; ?></td>
                                                <td><?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-warning me-1" onclick="editUser(<?php echo $user['id']; ?>)" title="Editar"><i class="fa-solid fa-pen"></i></button>
                                                    <button class="btn btn-sm btn-outline-danger me-1" onclick="deleteUser(<?php echo $user['id']; ?>)" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                                                    <button class="btn btn-sm btn-outline-info" onclick="changePassword(<?php echo $user['id']; ?>)" title="Alterar senha"><i class="fa-solid fa-key"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Aba Equipes -->
                <div class="tab-pane fade" id="teams" role="tabpanel" aria-labelledby="teams-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Equipes</h2>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeamModal">Adicionar Equipe</button>
                    </div>
                    <div class="card card-shadow p-3">
                        <?php
                        $stmtT = $pdo->query('SELECT id, name, description, created_at FROM teams ORDER BY name');
                        $teamsList = $stmtT->fetchAll();
                        if (empty($teamsList)) {
                            echo '<p>Nenhuma equipe cadastrada.</p>';
                        } else {
                            echo '<div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>ID</th><th>Nome</th><th>Descrição</th><th>Criado</th><th>Ações</th></tr></thead><tbody>';
                            foreach ($teamsList as $t) {
                                echo '<tr>';
                                echo '<td>'.htmlspecialchars($t['id']).'</td>';
                                echo '<td>'.htmlspecialchars($t['name']).'</td>';
                                echo '<td>'.htmlspecialchars($t['description']).'</td>';
                                echo '<td>'.htmlspecialchars($t['created_at']).'</td>';
                                echo '<td>';
                                echo ' <button class="btn btn-sm btn-outline-warning me-1" onclick="editTeam('.$t['id'].')" title="Editar"><i class="fa-solid fa-pen"></i></button>';
                                echo ' <button class="btn btn-sm btn-outline-danger" onclick="deleteTeam('.$t['id'].')" title="Excluir"><i class="fa-solid fa-trash"></i></button>';
                                echo '</td>';
                                echo '</tr>';
                            }
                            echo '</tbody></table></div>';
                        }
                        ?>
                    </div>
                </div>
                <!-- Aba Integrações -->
                <div class="tab-pane fade" id="integrations" role="tabpanel" aria-labelledby="integrations-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Integrações</h2>
                    </div>
                    <div class="card card-shadow p-3">
                        <p class="mb-2">Integrações externas — conecte seu WhatsApp (Whaileys) para envio de mensagens a leads e usuários.</p>
                        <div id="waIntegrationApp" class="d-flex gap-3 flex-wrap align-items-start">
                            <div id="waStatusCard" class="p-3 rounded shadow-sm" style="min-width:320px; max-width:420px; background:#fff;">
                                <h5 class="mb-2">WhatsApp</h5>
                                <div id="waStatus" class="mb-2 text-muted">Carregando status...</div>
                                <div id="waQrContainer" class="mb-2 d-none">
                                    <img id="waQrImage" src="" alt="QR Code" style="width:260px; height:260px; object-fit:contain; border:1px solid #eee; padding:8px; background:#fafafa;"/>
                                </div>
                                <div class="d-flex gap-2">
                                    <button id="btnGenerateQr" class="btn btn-primary btn-sm">Gerar QrCode</button>
                                    <button id="btnRefreshWa" class="btn btn-outline-secondary btn-sm">Atualizar</button>
                                    <button id="btnDisconnectWa" class="btn btn-danger btn-sm d-none">Desconectar</button>
                                    <button id="btnManualQr" class="btn btn-outline-primary btn-sm">Inserir QR</button>
                                </div>
                                <small class="d-block text-muted mt-2">Use o QR para autenticar seu cliente Whaileys (Node). Consulte a documentação da integração para detalhes.</small>
                            </div>
                            <div id="waHelpCard" class="p-3 rounded shadow-sm" style="min-width:320px; max-width:520px; background:#fff;">
                                <h6 class="mb-2">Instruções rápidas</h6>
                                <ol class="small mb-2">
                                    <li>Inicie seu serviço Whaileys (Node) separado que consome o QR e autentica a sessão.</li>
                                    <li>No momento, este painel fornece QR temporário e controla estado local.</li>
                                    <li>Após autenticar no cliente, o serviço Whaileys deve chamar o endpoint de confirmação para marcar conectado (implemente no seu serviço Node).</li>
                                </ol>
                                <p class="small text-muted mb-0">Quer que eu gere também um exemplo de serviço Node + Whaileys para receber o QR e confirmar a conexão? Peça que eu crie.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Aba Permissões -->
                <div class="tab-pane fade" id="permissions" role="tabpanel" aria-labelledby="permissions-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Permissões por Papel</h2>
                        <div class="d-flex gap-2 align-items-center">
                            <label class="mb-0 me-2">Papel:</label>
                            <select id="perm_role_select" class="form-select form-select-sm">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Permissões de Tela/Páginas -->
                    <div class="card card-shadow p-3 mb-4">
                        <h6 class="mb-3 text-muted">Acesso a Telas e Módulos</h6>
                        <div class="mb-2 d-flex justify-content-between align-items-center">
                            <div>
                                <button id="btn_reload_permissions" class="btn btn-sm btn-outline-secondary">Recarregar</button>
                            </div>
                            <div>
                                <input id="filter_permission" class="form-control form-control-sm" placeholder="Pesquisar permissão" style="max-width:320px;" />
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="permissions_table">
                                <thead>
                                    <tr>
                                        <th>Permissão (screen)</th>
                                        <th style="width:120px;">Permitido</th>
                                    </tr>
                                </thead>
                                <tbody id="permissions_tbody">
                                    <tr><td colspan="2" class="text-muted">Carregando...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button id="btn_clone_permissions" class="btn btn-outline-secondary btn-sm">Clonar para outro papel</button>
                            <button id="btn_save_permissions" class="btn btn-primary btn-sm">Salvar alterações</button>
                        </div>
                    </div>

                    <!-- Ações Granulares -->
                    <div class="card card-shadow p-3">
                        <h6 class="mb-3 text-muted d-flex align-items-center gap-2">
                            <i class="fas fa-sliders-h"></i> Ações Granulares de Leads
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="actions_table">
                                <thead>
                                    <tr>
                                        <th>Ação</th>
                                        <th style="width:120px;">Permitido</th>
                                    </tr>
                                </thead>
                                <tbody id="actions_tbody">
                                    <tr><td colspan="2" class="text-muted text-center py-3">Carregando...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info alert-sm mt-3 mb-0">
                            <small><i class="fas fa-info-circle"></i> Estas são ações específicas que complementam o acesso às telas. Um usuário pode ter acesso à tela, mas sem permissão para executar certas ações.</small>
                        </div>
                    </div>
                </div>
                <!-- Aba Aparência -->
                <div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="appearance-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Aparência</h2>
                    </div>

                    <div class="card card-shadow p-3">
                        <div class="row">
                            <div class="col-md-5">
                                <h6>Logos</h6>
                                <button type="button" id="toggleLogoPreviewBg" class="btn btn-outline-secondary btn-sm logo-preview-toggle">Fundo escuro</button>
                                <div class="logo-preview-panel mb-3">
                                    <div class="preview-box text-center">
                                        <div>
                                            <div class="small text-muted mb-2">Logo padrão</div>
                                            <img id="currentLogo" src="assets/img/logo150-b.png" alt="Logo" />
                                        </div>
                                    </div>
                                    <div class="preview-box" style="width:120px; text-align:center;">
                                        <div class="small text-muted">Logo encolhido</div>
                                        <div class="p-2 rounded mt-2 compact-logo-wrapper" style="background:transparent; display:inline-block; border:none;">
                                            <img id="currentLogoCollapsed" src="assets/img/logo.png" alt="Logo encolhido" style="width:48px; height:48px; object-fit:contain; display:block;" />
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Trocar logo (padrão)</label>
                                    <input id="appearance_logo" type="file" accept="image/*" class="form-control form-control-sm" />
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Trocar logo (encolhido)</label>
                                    <input id="appearance_logo_collapsed" type="file" accept="image/*" class="form-control form-control-sm" />
                                </div>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="removeLogoChk" />
                                        <label class="form-check-label" for="removeLogoChk">Remover logo padrão</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="removeLogoCollapsedChk" />
                                        <label class="form-check-label" for="removeLogoCollapsedChk">Remover logo encolhido</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <h6>Paleta</h6>
                                <div class="row gx-2 align-items-center">
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Cor principal</label>
                                        <input type="color" id="primary_color" class="form-control form-control-color" value="#0b6ac1" />
                                    </div>
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Cor principal (escura)</label>
                                        <input type="color" id="primary_dark" class="form-control form-control-color" value="#073b6b" />
                                    </div>
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Verde (acento)</label>
                                        <input type="color" id="green_color" class="form-control form-control-color" value="#4bbf4b" />
                                    </div>
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Amarelo (acento)</label>
                                        <input type="color" id="yellow_color" class="form-control form-control-color" value="#ffd24a" />
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <label class="form-label">Pré-visualização</label>
                                    <div class="p-3 rounded" id="appearancePreview" style="background:#fff; border:1px solid #eee;">
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <div style="width:56px; height:56px; background:var(--blue-700); border-radius:8px;"></div>
                                            <div>
                                                <div style="height:10px; width:200px; background:var(--blue-700); border-radius:6px;"></div>
                                                <div style="height:8px; width:120px; background:var(--yellow); border-radius:6px; margin-top:6px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <h6>Fundo da tela de login</h6>
                                    <div class="border rounded p-2 mb-2" style="background:#d0d0d0;">
                                        <img id="currentLoginBackground" src="assets/img/fundoplaca2.jpg" alt="Fundo login" style="width:100%; max-height:150px; object-fit:cover; border-radius:8px; display:block;" />
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Trocar fundo do login (papel de parede)</label>
                                        <input id="appearance_login_background" type="file" accept="image/*" class="form-control form-control-sm" />
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="removeLoginBackgroundChk" />
                                        <label class="form-check-label" for="removeLoginBackgroundChk">Remover fundo personalizado do login</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button id="btn_save_appearance" class="btn btn-primary btn-sm">Salvar Aparência</button>
                            <button id="btn_reset_appearance" class="btn btn-outline-secondary btn-sm">Restaurar Padrão</button>
                        </div>
                    </div>
                </div>
                <!-- Aba SMTP -->
                <div class="tab-pane fade" id="smtp" role="tabpanel" aria-labelledby="smtp-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">SMTP</h2>
                    </div>
                    <div class="card card-shadow p-3">
                        <form id="smtpForm">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label class="form-label">Modelo SMTP</label>
                                    <select id="smtp_preset" class="form-select">
                                        <option value="">Personalizado</option>
                                        <option value="gmail_tls">Gmail (smtp.gmail.com, TLS 587)</option>
                                        <option value="gmail_ssl">Gmail (smtp.gmail.com, SSL 465)</option>
                                        <option value="outlook">Outlook / Office365 (smtp.office365.com, TLS 587)</option>
                                        <option value="yahoo">Yahoo (smtp.mail.yahoo.com, SSL 465)</option>
                                        <option value="sendgrid">SendGrid (smtp.sendgrid.net, TLS 587)</option>
                                    </select>
                                    <small class="text-muted">Escolha um modelo para preencher automaticamente os campos.</small>

                                
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Host</label>
                                    <input type="text" id="smtp_host" name="host" class="form-control" />
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Porta</label>
                                    <input type="number" id="smtp_port" name="port" class="form-control" />
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Segurança</label>
                                    <select id="smtp_secure" name="secure" class="form-select">
                                        <option value="">Nenhuma</option>
                                        <option value="ssl">SSL</option>
                                        <option value="tls">TLS</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Usuário</label>
                                    <input type="text" id="smtp_user" name="user" class="form-control" />
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Senha</label>
                                    <input type="password" id="smtp_pass" name="pass" class="form-control" autocomplete="new-password" />
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">From Email</label>
                                    <input type="email" id="smtp_from_email" name="from_email" class="form-control" />
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">From Name</label>
                                    <input type="text" id="smtp_from_name" name="from_name" class="form-control" />
                                </div>
                                <div class="col-12">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="smtp_auth" name="auth" value="1" />
                                        <label class="form-check-label" for="smtp_auth">Requer autenticação</label>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button id="btn_save_smtp" class="btn btn-primary btn-sm">Salvar SMTP</button>
                        </div>

                        <div class="mt-3">
                            <div class="accordion" id="smtp_help">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_gmail_tls">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help_gmail_tls" aria-expanded="false" aria-controls="help_gmail_tls">
                                            Gmail (TLS 587) — Como configurar
                                        </button>
                                    </h2>
                                    <div id="help_gmail_tls" class="accordion-collapse collapse" aria-labelledby="heading_gmail_tls" data-bs-parent="#smtp_help">
                                        <div class="accordion-body small">
                                            Host: <strong>smtp.gmail.com</strong><br>
                                            Porta: <strong>587</strong> (TLS)<br>
                                            Segurança: <strong>TLS</strong><br>
                                            Usuário: seu email completo (ex: usuario@gmail.com).<br>
                                            Senha: use sua senha ou, se tiver 2FA ativado, gere uma <strong>App Password</strong> no Google Account → Segurança → Senhas de App.<br>
                                            Observação: o método "Permitir apps menos seguros" foi descontinuado; prefira App Password ou OAuth2.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_gmail_ssl">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help_gmail_ssl" aria-expanded="false" aria-controls="help_gmail_ssl">
                                            Gmail (SSL 465) — Como configurar
                                        </button>
                                    </h2>
                                    <div id="help_gmail_ssl" class="accordion-collapse collapse" aria-labelledby="heading_gmail_ssl" data-bs-parent="#smtp_help">
                                        <div class="accordion-body small">
                                            Host: <strong>smtp.gmail.com</strong><br>
                                            Porta: <strong>465</strong> (SSL)<br>
                                            Segurança: <strong>SSL</strong><br>
                                            Senha: utilize App Passwords se sua conta tiver 2FA.<br>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_outlook">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help_outlook" aria-expanded="false" aria-controls="help_outlook">
                                            Outlook / Office365 — Como configurar
                                        </button>
                                    </h2>
                                    <div id="help_outlook" class="accordion-collapse collapse" aria-labelledby="heading_outlook" data-bs-parent="#smtp_help">
                                        <div class="accordion-body small">
                                            Host: <strong>smtp.office365.com</strong><br>
                                            Porta: <strong>587</strong> (TLS)<br>
                                            Segurança: <strong>TLS</strong><br>
                                            Observação: em contas corporativas pode ser necessário habilitar o envio SMTP (Authenticated SMTP) para a mailbox no painel do administrador.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_yahoo">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help_yahoo" aria-expanded="false" aria-controls="help_yahoo">
                                            Yahoo — Como configurar
                                        </button>
                                    </h2>
                                    <div id="help_yahoo" class="accordion-collapse collapse" aria-labelledby="heading_yahoo" data-bs-parent="#smtp_help">
                                        <div class="accordion-body small">
                                            Host: <strong>smtp.mail.yahoo.com</strong><br>
                                            Porta: <strong>465</strong> (SSL) ou 587 (TLS)<br>
                                            Segurança: <strong>SSL/TLS</strong><br>
                                            Senha: se usar 2FA gere um <strong>App Password</strong> nas configurações da conta.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_sendgrid">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help_sendgrid" aria-expanded="false" aria-controls="help_sendgrid">
                                            SendGrid — Como configurar
                                        </button>
                                    </h2>
                                    <div id="help_sendgrid" class="accordion-collapse collapse" aria-labelledby="heading_sendgrid" data-bs-parent="#smtp_help">
                                        <div class="accordion-body small">
                                            Host: <strong>smtp.sendgrid.net</strong><br>
                                            Porta: <strong>587</strong> (TLS)<br>
                                            Usuário: <strong>apikey</strong> (quando usar API Key como senha)<br>
                                            Senha: sua API Key gerada no painel SendGrid.<br>
                                            Observação: SendGrid recomenda usar a API para entregabilidade, mas o SMTP funciona com as credenciais acima.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal Adicionar Usuário -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">Adicionar Usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addUserForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Usuário</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="avatar" class="form-label">Foto de Perfil (Avatar)</label>
                        <input type="file" class="form-control" id="add_user_avatar" name="avatar" accept="image/*" />
                    </div>
                    <div class="mb-3">
                        <label for="role_id" class="form-label">Papel</label>
                        <select class="form-select" id="role_id" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="team_id" class="form-label">Equipe</label>
                        <select class="form-select" id="team_id" name="team_id">
                            <option value="">(Nenhuma)</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="role_level" class="form-label">Nível (role_level)</label>
                        <select class="form-select" id="role_level" name="role_level">
                            <option value="0">0 - Usuário</option>
                            <option value="1">1 - Gerente</option>
                            <option value="2">2 - Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Usuário -->
<div class="modal fade edit-user-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Editar Usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editUserForm">
                <input type="hidden" id="edit_user_id" name="id">
                <div class="modal-body">
                    <div class="user-edit-section mb-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_username" class="form-label">Usuário</label>
                                <input type="text" class="form-control" id="edit_username" name="username" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                        </div>
                    </div>
                    <div class="user-edit-section mb-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_role_id" class="form-label">Papel</label>
                                <select class="form-select" id="edit_role_id" name="role_id" required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_team_id" class="form-label">Equipe</label>
                                <select class="form-select" id="edit_team_id" name="team_id">
                                    <option value="">(Nenhuma)</option>
                                    <?php foreach ($teams as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="user-edit-section mb-3">
                        <label for="edit_role_level" class="form-label">Nível (role_level)</label>
                        <select class="form-select" id="edit_role_level" name="role_level">
                            <option value="0">0 - Usuário</option>
                            <option value="1">1 - Gerente</option>
                            <option value="2">2 - Administrador</option>
                        </select>
                    </div>
                    <div class="user-edit-section">
                        <label class="form-label">Avatar atual</label>
                        <div class="avatar-box">
                            <img id="edit_user_avatar_preview" class="avatar-thumb" src="assets/img/avatar-placeholder.png" alt="Avatar" />
                            <div class="flex-grow-1">
                                <label for="edit_user_avatar" class="form-label small">Substituir avatar</label>
                                <input type="file" class="form-control form-control-sm" id="edit_user_avatar" name="avatar" accept="image/*" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Adicionar Equipe -->
<div class="modal fade" id="addTeamModal" tabindex="-1" aria-labelledby="addTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTeamModalLabel">Adicionar Equipe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addTeamForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" class="form-control" id="team_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" id="team_description" name="description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Equipe -->
<div class="modal fade" id="editTeamModal" tabindex="-1" aria-labelledby="editTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTeamModalLabel">Editar Equipe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editTeamForm">
                <input type="hidden" id="edit_team_id_input" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" class="form-control" id="edit_team_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" id="edit_team_description" name="description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Alterar Senha -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">Alterar Senha</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="changePasswordForm">
                <input type="hidden" id="change_user_id" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Nova Senha</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Alterar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUser(id) {
    // Fetch user data
    fetch('api/get_user.php?id=' + id)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('edit_user_id').value = data.user.id;
            document.getElementById('edit_username').value = data.user.username;
            document.getElementById('edit_email').value = data.user.email;
            document.getElementById('edit_role_id').value = data.user.role_id;
            if (document.getElementById('edit_team_id')) document.getElementById('edit_team_id').value = data.user.team_id || '';
            if (document.getElementById('edit_role_level')) document.getElementById('edit_role_level').value = data.user.role_level || 0;
                // set avatar preview if provided
                try{
                    var avatarPreview = document.getElementById('edit_user_avatar_preview');
                    if (avatarPreview) {
                        if (data.user.avatar) avatarPreview.src = data.user.avatar;
                        else avatarPreview.src = 'assets/img/avatar-placeholder.png';
                    }
                }catch(e){}
                new bootstrap.Modal(document.getElementById('editUserModal')).show();
        } else {
            alert('Erro: ' + data.message);
        }
    });
}

// Teams: edit and delete handlers
function editTeam(id) {
    fetch('api/get_teams.php')
    .then(r=>r.json())
    .then(data=>{
        if (!data.success) { alert('Erro ao carregar equipes'); return; }
        const team = data.teams.find(t=>t.id == id);
        if (!team) { alert('Equipe não encontrada'); return; }
        document.getElementById('edit_team_id_input').value = team.id;
        document.getElementById('edit_team_name').value = team.name;
        document.getElementById('edit_team_description').value = team.description;
        new bootstrap.Modal(document.getElementById('editTeamModal')).show();
    }).catch(()=>{ alert('Erro ao carregar equipes'); });
}

function deleteTeam(id) {
    if (!confirm('Tem certeza que deseja excluir esta equipe?')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('api/delete_team.php', { method: 'POST', body: fd })
    .then(r=>r.json()).then(data=>{ alert(data.message); if (data.success) location.reload(); });
}

function deleteUser(id) {
    if (confirm('Tem certeza que deseja excluir este usuário?')) {
        const formData = new FormData();
        formData.append('id', id);
        fetch('api/delete_user.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                location.reload();
            }
        });
    }
}

function changePassword(id) {
    document.getElementById('change_user_id').value = id;
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}

document.getElementById('addUserForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('api/add_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
});

document.getElementById('editUserForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('api/edit_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
});

// Preview selected avatar in add/edit forms
document.addEventListener('DOMContentLoaded', function(){
    const addInput = document.getElementById('add_user_avatar');
    const editInput = document.getElementById('edit_user_avatar');
    const editPreview = document.getElementById('edit_user_avatar_preview');
    if (addInput) addInput.addEventListener('change', function(){
        const f = this.files && this.files[0];
        if (!f) return;
        const url = URL.createObjectURL(f);
        // create a small preview element next to input
        let p = document.getElementById('add_user_avatar_preview');
        if (!p) {
            p = document.createElement('img'); p.id = 'add_user_avatar_preview'; p.style.width='64px'; p.style.height='64px'; p.style.objectFit='cover'; p.style.borderRadius='6px'; p.style.border='1px solid #e6e6e6';
            this.parentNode.insertBefore(p, this.nextSibling);
        }
        p.src = url;
    });
    if (editInput && editPreview) editInput.addEventListener('change', function(){
        const f = this.files && this.files[0]; if (!f) return; editPreview.src = URL.createObjectURL(f);
    });
});

document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('api/change_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
});
</script>

<script src="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); ?>/assets/js/wa_integration.js"></script>
<script>
    (async function(){
        try{
            const url = '<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); ?>/assets/js/wa_integration.js';
            console.log('wa_integration: checking', url);
            const res = await fetch(url, { cache: 'no-store' });
            console.log('wa_integration: fetch status', res.status);
            const txt = await res.text();
            console.log('wa_integration: first chars', txt.slice(0,120));
        }catch(e){ console.error('wa_integration: fetch error', e); }
    })();
</script>

<!-- Modal Inserir QR Manual -->
<div class="modal fade" id="manualQrModal" tabindex="-1" aria-labelledby="manualQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manualQrModalLabel">Inserir QR manualmente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="manualQrForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Texto do QR (ex: whatsapp-qr:...)</label>
                        <textarea id="manualQrText" class="form-control" rows="4" placeholder="Cole aqui o texto do QR"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ou cole a URL de uma imagem QR</label>
                        <input id="manualQrUrl" class="form-control" placeholder="https://.../qr.png" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar QR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const roleSelect = document.getElementById('perm_role_select');
    const permTbody = document.getElementById('permissions_tbody');
    const actionsTbody = document.getElementById('actions_tbody');
    const filterInput = document.getElementById('filter_permission');

    // List of action permissions (these go in the separate section)
    const actionPermissions = ['delete_leads_permanent'];

    // Permission descriptions for better UI
    const permDescriptions = {
        'dashboard': 'Acessar Dashboard',
        'projetos': 'Acessar Projetos',
        'pos-venda': 'Acessar Pós-venda',
        'relatorios': 'Acessar Relatórios',
        'leads_gestao': 'Acessar Gestão de Leads',
        'integracao-equipes': 'Acessar Equipes & Tarefas',
        'funil_config': 'Personalizar Funil de Vendas',
        'configuracoes': 'Acessar Configurações',
        'delete_leads_permanent': 'Excluir leads permanentemente da lixeira'
    };

    const actionDescriptions = {
        'delete_leads_permanent': 'Excluir leads permanentemente da lixeira'
    };

    async function loadScreens() {
        const res = await fetch('api/get_all_screens.php');
        const data = await res.json();
        return data.screens || [];
    }

    async function loadRolePermissions(roleId) {
        permTbody.innerHTML = '<tr><td colspan="2" class="text-muted">Carregando...</td></tr>';
        actionsTbody.innerHTML = '<tr><td colspan="2" class="text-muted text-center">Carregando...</td></tr>';
        
        const [screensRes, roleRes] = await Promise.all([
            fetch('api/get_all_screens.php'),
            fetch('api/get_role_permissions.php?role_id='+encodeURIComponent(roleId))
        ]);
        const screensData = await screensRes.json();
        const roleData = await roleRes.json();
        const screens = screensData.screens || [];
        const allowed = roleData.allowed || {};

        // Separate screens and actions
        const screensList = screens.filter(s => !actionPermissions.includes(s));
        const actionsList = screens.filter(s => actionPermissions.includes(s));

        // Load screen permissions
        if (screensList.length === 0) {
            permTbody.innerHTML = '<tr><td colspan="2" class="text-muted">Nenhuma permissão registrada.</td></tr>';
        } else {
            permTbody.innerHTML = '';
            for (const s of screensList) {
                const tr = document.createElement('tr');
                tr.dataset.screen = s;
                const tdName = document.createElement('td');
                const label = permDescriptions[s] || s;
                tdName.textContent = label;
                const tdCheck = document.createElement('td');
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.className = 'form-check-input';
                chk.checked = !!allowed[s];
                tdCheck.appendChild(chk);
                tr.appendChild(tdName);
                tr.appendChild(tdCheck);
                permTbody.appendChild(tr);
            }
        }

        // Load action permissions
        if (actionsList.length === 0) {
            actionsTbody.innerHTML = '<tr><td colspan="2" class="text-muted text-center py-3">Nenhuma ação disponível.</td></tr>';
        } else {
            actionsTbody.innerHTML = '';
            for (const s of actionsList) {
                const tr = document.createElement('tr');
                tr.dataset.screen = s;
                const tdName = document.createElement('td');
                const label = actionDescriptions[s] || permDescriptions[s] || s;
                tdName.innerHTML = `<i class="fas fa-cogs text-primary me-2"></i>${escapeHtml(label)}`;
                const tdCheck = document.createElement('td');
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.className = 'form-check-input';
                chk.checked = !!allowed[s];
                tdCheck.appendChild(chk);
                tr.appendChild(tdName);
                tr.appendChild(tdCheck);
                actionsTbody.appendChild(tr);
            }
        }
    }

    function escapeHtml(txt) {
        const div = document.createElement('div');
        div.textContent = txt;
        return div.innerHTML;
    }

    roleSelect.addEventListener('change', ()=> loadRolePermissions(roleSelect.value));
    document.getElementById('btn_reload_permissions').addEventListener('click', ()=> loadRolePermissions(roleSelect.value));

    filterInput.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        // Filter both tables
        for (const tr of permTbody.querySelectorAll('tr')) {
            const s = (tr.dataset.screen||'').toLowerCase();
            const label = (permDescriptions[s] || s).toLowerCase();
            tr.style.display = q === '' || s.indexOf(q) !== -1 || label.indexOf(q) !== -1 ? '' : 'none';
        }
        for (const tr of actionsTbody.querySelectorAll('tr')) {
            const s = (tr.dataset.screen||'').toLowerCase();
            const label = (actionDescriptions[s] || permDescriptions[s] || s).toLowerCase();
            tr.style.display = q === '' || s.indexOf(q) !== -1 || label.indexOf(q) !== -1 ? '' : 'none';
        }
    });

    document.getElementById('btn_save_permissions').addEventListener('click', async function(){
        const roleId = roleSelect.value;
        const permissions = [];
        // Collect from both tables
        for (const tr of permTbody.querySelectorAll('tr')) {
            const chk = tr.querySelector('input[type="checkbox"]');
            const screen = tr.dataset.screen;
            if (!screen) continue;
            if (chk && chk.checked) permissions.push(screen);
        }
        for (const tr of actionsTbody.querySelectorAll('tr')) {
            const chk = tr.querySelector('input[type="checkbox"]');
            const screen = tr.dataset.screen;
            if (!screen) continue;
            if (chk && chk.checked) permissions.push(screen);
        }
        const fd = new FormData();
        fd.append('role_id', roleId);
        for (const p of permissions) fd.append('permissions[]', p);
        const res = await fetch('api/save_role_permissions.php', { method: 'POST', body: fd });
        const data = await res.json();
        alert(data.message || (data.success ? 'Salvo' : 'Erro'));
        if (data.success) loadRolePermissions(roleId);
    });

    document.getElementById('btn_clone_permissions').addEventListener('click', async function(){
        const sourceRole = roleSelect.value;
        const target = prompt('Digite o ID do papel alvo para clonar as permissões:');
        if (!target) return;
        if (target == sourceRole) { alert('Papel de destino igual ao de origem.'); return; }
        // read current checked from both tables
        const permissions = [];
        for (const tr of permTbody.querySelectorAll('tr')) {
            const chk = tr.querySelector('input[type="checkbox"]');
            const screen = tr.dataset.screen;
            if (!screen) continue;
            if (chk && chk.checked) permissions.push(screen);
        }
        for (const tr of actionsTbody.querySelectorAll('tr')) {
            const chk = tr.querySelector('input[type="checkbox"]');
            const screen = tr.dataset.screen;
            if (!screen) continue;
            if (chk && chk.checked) permissions.push(screen);
        }
        const fd = new FormData(); fd.append('role_id', target);
        for (const p of permissions) fd.append('permissions[]', p);
        const res = await fetch('api/save_role_permissions.php', { method: 'POST', body: fd });
        const data = await res.json();
        alert(data.message || (data.success ? 'Clonado' : 'Erro'));
    });

    // initial load
    if (roleSelect.value) loadRolePermissions(roleSelect.value);
});
</script>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const currentLogo = document.getElementById('currentLogo');
        const currentLogoCollapsed = document.getElementById('currentLogoCollapsed');
        const logoInput = document.getElementById('appearance_logo');
        const logoCollapsedInput = document.getElementById('appearance_logo_collapsed');
        const loginBgInput = document.getElementById('appearance_login_background');
        const removeChk = document.getElementById('removeLogoChk');
        const removeCollapsedChk = document.getElementById('removeLogoCollapsedChk');
        const removeLoginBgChk = document.getElementById('removeLoginBackgroundChk');
        const currentLoginBackground = document.getElementById('currentLoginBackground');
        const primaryInput = document.getElementById('primary_color');
        const primaryDarkInput = document.getElementById('primary_dark');
        const greenInput = document.getElementById('green_color');
        const yellowInput = document.getElementById('yellow_color');
        const preview = document.getElementById('appearancePreview');
        const toggleLogoPreviewBg = document.getElementById('toggleLogoPreviewBg');
        const logoPreviewPanel = document.querySelector('.logo-preview-panel');

        function updateLogoPreviewToggle(){
            if (!toggleLogoPreviewBg || !logoPreviewPanel) return;
            if (logoPreviewPanel.classList.contains('dark')) {
                toggleLogoPreviewBg.textContent = 'Fundo claro';
            } else {
                toggleLogoPreviewBg.textContent = 'Fundo escuro';
            }
        }

        if (toggleLogoPreviewBg && logoPreviewPanel) {
            toggleLogoPreviewBg.addEventListener('click', function(){
                logoPreviewPanel.classList.toggle('dark');
                updateLogoPreviewToggle();
            });
            updateLogoPreviewToggle();
        }

        async function loadAppearance(){
            try{
                const res = await fetch('api/get_appearance.php');
                const data = await res.json();
                const s = data.appearance || {};
                if (s.logo) {
                    currentLogo.src = s.logo;
                }
                if (s.logo_collapsed) {
                    currentLogoCollapsed.src = s.logo_collapsed;
                }
                if (s.login_background && currentLoginBackground) {
                    currentLoginBackground.src = s.login_background;
                }
                if (s.primary_color) primaryInput.value = s.primary_color;
                if (s.primary_dark) primaryDarkInput.value = s.primary_dark;
                if (s.green) greenInput.value = s.green;
                if (s.yellow) yellowInput.value = s.yellow;
                applyPreviewColors();
            }catch(e){ console.error(e); }
        }

        function applyPreviewColors(){
            const p = primaryInput.value || '#0b6ac1';
            const pd = primaryDarkInput.value || '#073b6b';
            const g = greenInput.value || '#4bbf4b';
            const y = yellowInput.value || '#ffd24a';
            preview.querySelectorAll('div').forEach(d=>d.style.setProperty('--tmp', ''));
            preview.style.setProperty('--tmp', '');
            // apply inline preview colors
            preview.querySelectorAll('div')[0].style.background = p;
            const bars = preview.querySelectorAll('div')[1].querySelectorAll('div');
            if (bars[0]) bars[0].style.background = p;
            if (bars[1]) bars[1].style.background = y;
            // also update document root for live preview
            document.documentElement.style.setProperty('--blue-700', p);
            document.documentElement.style.setProperty('--blue-900', pd);
            document.documentElement.style.setProperty('--green', g);
            document.documentElement.style.setProperty('--yellow', y);
        }

        primaryInput.addEventListener('input', applyPreviewColors);
        primaryDarkInput.addEventListener('input', applyPreviewColors);
        greenInput.addEventListener('input', applyPreviewColors);
        yellowInput.addEventListener('input', applyPreviewColors);

        if (loginBgInput && currentLoginBackground) {
            loginBgInput.addEventListener('change', function(){
                const f = this.files && this.files[0];
                if (!f) return;
                currentLoginBackground.src = URL.createObjectURL(f);
            });
        }

        document.getElementById('btn_save_appearance').addEventListener('click', async function(){
            const fd = new FormData();
            if (logoInput.files && logoInput.files[0]) fd.append('logo', logoInput.files[0]);
            if (logoCollapsedInput.files && logoCollapsedInput.files[0]) fd.append('logo_collapsed', logoCollapsedInput.files[0]);
            if (loginBgInput && loginBgInput.files && loginBgInput.files[0]) fd.append('login_background', loginBgInput.files[0]);
            if (removeChk.checked) fd.append('remove_logo', '1');
            if (removeCollapsedChk && removeCollapsedChk.checked) fd.append('remove_logo_collapsed', '1');
            if (removeLoginBgChk && removeLoginBgChk.checked) fd.append('remove_login_background', '1');
            fd.append('primary_color', primaryInput.value || '');
            fd.append('primary_dark', primaryDarkInput.value || '');
            fd.append('green', greenInput.value || '');
            fd.append('yellow', yellowInput.value || '');
            this.disabled = true;
            try{
                const res = await fetch('api/save_appearance.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message || (data.success ? 'Salvo' : 'Erro'));
                if (data.success) {
                    if (data.appearance && data.appearance.logo) currentLogo.src = data.appearance.logo + '?v=' + Date.now();
                    if (data.appearance && data.appearance.logo_collapsed) currentLogoCollapsed.src = data.appearance.logo_collapsed + '?v=' + Date.now();
                    if (currentLoginBackground) {
                        if (data.appearance && data.appearance.login_background) currentLoginBackground.src = data.appearance.login_background + '?v=' + Date.now();
                        else currentLoginBackground.src = 'assets/img/fundoplaca2.jpg';
                    }
                }
            }catch(e){ alert('Erro ao salvar aparência'); console.error(e); }
            this.disabled = false;
        });

        document.getElementById('btn_reset_appearance').addEventListener('click', async function(){
            if (!confirm('Restaurar aparência para o padrão?')) return;
            const fd = new FormData();
            fd.append('remove_logo', '1');
            fd.append('remove_login_background', '1');
            fd.append('primary_color', '#0b6ac1');
            fd.append('primary_dark', '#073b6b');
            fd.append('green', '#4bbf4b');
            fd.append('yellow', '#ffd24a');
            try{
                const res = await fetch('api/save_appearance.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message || (data.success ? 'Restaurado' : 'Erro'));
                if (data.success) location.reload();
            }catch(e){ alert('Erro ao restaurar'); }
        });

        loadAppearance();
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        async function loadSmtp(){
            try{
                const res = await fetch('api/get_smtp.php');
                const data = await res.json();
                const s = data.smtp || {};
                document.getElementById('smtp_host').value = s.host || '';
                document.getElementById('smtp_port').value = s.port || '';
                document.getElementById('smtp_secure').value = s.secure || '';
                document.getElementById('smtp_user').value = s.user || '';
                // do not pre-fill password for safety unless explicitly provided
                document.getElementById('smtp_pass').value = s.pass || '';
                document.getElementById('smtp_from_email').value = s.from_email || '';
                document.getElementById('smtp_from_name').value = s.from_name || '';
                document.getElementById('smtp_auth').checked = !!s.auth;
                // try to detect a known preset based on loaded values
                try {
                    const presetSelect = document.getElementById('smtp_preset');
                    if (presetSelect) {
                        const host = (s.host||'').toLowerCase();
                        const port = String(s.port||'');
                        const secure = (s.secure||'').toLowerCase();
                        if (host.indexOf('smtp.gmail.com') !== -1 && port === '587' && secure === 'tls') presetSelect.value = 'gmail_tls';
                        else if (host.indexOf('smtp.gmail.com') !== -1 && port === '465' && secure === 'ssl') presetSelect.value = 'gmail_ssl';
                        else if (host.indexOf('smtp.office365.com') !== -1) presetSelect.value = 'outlook';
                        else if (host.indexOf('smtp.mail.yahoo.com') !== -1) presetSelect.value = 'yahoo';
                        else if (host.indexOf('smtp.sendgrid.net') !== -1) presetSelect.value = 'sendgrid';
                        else presetSelect.value = '';
                    }
                } catch(e){}
            }catch(e){ console.error('Erro ao carregar SMTP', e); }
        }

        document.getElementById('btn_save_smtp').addEventListener('click', async function(){
            const btn = this; btn.disabled = true;
            try{
                const fd = new FormData(document.getElementById('smtpForm'));
                // ensure auth checkbox included
                if (!fd.has('auth')) fd.append('auth', document.getElementById('smtp_auth').checked ? '1' : '0');
                const res = await fetch('api/save_smtp.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message || (data.success ? 'Salvo' : 'Erro'));
            }catch(e){ alert('Erro ao salvar SMTP'); console.error(e); }
            btn.disabled = false;
        });

        // preset definitions and handlers
        const smtpPresets = {
            'gmail_tls': { host: 'smtp.gmail.com', port: 587, secure: 'tls', auth: 1 },
            'gmail_ssl': { host: 'smtp.gmail.com', port: 465, secure: 'ssl', auth: 1 },
            'outlook': { host: 'smtp.office365.com', port: 587, secure: 'tls', auth: 1 },
            'yahoo': { host: 'smtp.mail.yahoo.com', port: 465, secure: 'ssl', auth: 1 },
            'sendgrid': { host: 'smtp.sendgrid.net', port: 587, secure: 'tls', auth: 1 }
        };

        const presetSelect = document.getElementById('smtp_preset');
        if (presetSelect) {
            presetSelect.addEventListener('change', function(){
                const v = this.value;
                if (!v) return; // personalizado
                const p = smtpPresets[v];
                if (p) {
                    document.getElementById('smtp_host').value = p.host;
                    document.getElementById('smtp_port').value = p.port;
                    document.getElementById('smtp_secure').value = p.secure;
                    document.getElementById('smtp_auth').checked = !!p.auth;
                }
                // abrir o painel de ajuda correspondente (accordion)
                try {
                    const collapseMap = {
                        'gmail_tls':'help_gmail_tls',
                        'gmail_ssl':'help_gmail_ssl',
                        'outlook':'help_outlook',
                        'yahoo':'help_yahoo',
                        'sendgrid':'help_sendgrid'
                    };
                    const targetId = collapseMap[v];
                    document.querySelectorAll('#smtp_help .accordion-collapse').forEach(function(el){
                        const inst = bootstrap.Collapse.getInstance(el) || new bootstrap.Collapse(el, {toggle:false});
                        inst.hide();
                    });
                    if (targetId) {
                        const el = document.getElementById(targetId);
                        if (el) {
                            const inst2 = bootstrap.Collapse.getInstance(el) || new bootstrap.Collapse(el, {toggle:false});
                            inst2.show();
                            el.scrollIntoView({behavior:'smooth', block:'nearest'});
                        }
                    }
                } catch(e) { console.error(e); }
            });
        }

        // initial load when opening tab
        loadSmtp();
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const tabStorageKey = 'configuracoes-active-tab';
        const tabButtons = document.querySelectorAll('#settingsTabs button[data-bs-toggle="tab"]');
        const savedTab = localStorage.getItem(tabStorageKey);

        if (savedTab) {
            const targetButton = document.querySelector(`#settingsTabs button[data-bs-target="${savedTab}"]`);
            if (targetButton) {
                const tabInstance = new bootstrap.Tab(targetButton);
                tabInstance.show();
            }
        }

        tabButtons.forEach(function(button){
            button.addEventListener('shown.bs.tab', function(event){
                const target = event.target.getAttribute('data-bs-target');
                if (target) {
                    localStorage.setItem(tabStorageKey, target);
                }
            });
        });
    });
    </script>