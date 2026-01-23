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

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
            <h1 class="h4 mb-3">Configurações</h1>
            
            <!-- Nav tabs -->
            <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="true">Gerenciar Usuários</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="teams-tab" data-bs-toggle="tab" data-bs-target="#teams" type="button" role="tab" aria-controls="teams" aria-selected="false">Equipes</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="integrations-tab" data-bs-toggle="tab" data-bs-target="#integrations" type="button" role="tab" aria-controls="integrations" aria-selected="false">Integrações</button>
                </li>
                <!-- Adicionar mais abas aqui no futuro -->
            </ul>
            
            <div class="tab-content mt-3" id="settingsTabsContent">
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
                                                    <button class="btn btn-sm btn-warning" onclick="editUser(<?php echo $user['id']; ?>)">Editar</button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">Excluir</button>
                                                    <button class="btn btn-sm btn-info" onclick="changePassword(<?php echo $user['id']; ?>)">Alterar Senha</button>
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
                                echo '<td><button class="btn btn-sm btn-warning" onclick="editTeam('.$t['id'].')">Editar</button> <button class="btn btn-sm btn-danger" onclick="deleteTeam('.$t['id'].')">Excluir</button></td>';
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
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Editar Usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editUserForm">
                <input type="hidden" id="edit_user_id" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">Usuário</label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_role_id" class="form-label">Papel</label>
                        <select class="form-select" id="edit_role_id" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_team_id" class="form-label">Equipe</label>
                        <select class="form-select" id="edit_team_id" name="team_id">
                            <option value="">(Nenhuma)</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_role_level" class="form-label">Nível (role_level)</label>
                        <select class="form-select" id="edit_role_level" name="role_level">
                            <option value="0">0 - Usuário</option>
                            <option value="1">1 - Gerente</option>
                            <option value="2">2 - Administrador</option>
                        </select>
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