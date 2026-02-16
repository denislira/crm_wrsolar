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

                    <div class="card card-shadow p-3">
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
                                <div class="d-flex gap-3 mb-3">
                                    <div class="flex-fill text-center border p-2 rounded" style="background:#d0d0d0; min-height:120px; display:flex; align-items:center; justify-content:center;">
                                        <div>
                                            <div class="small text-muted mb-2">Logo padrão</div>
                                            <img id="currentLogo" src="assets/img/logo150-b.png" alt="Logo" style="max-width:160px; max-height:80px; object-fit:contain; display:block; margin:0 auto;" />
                                        </div>
                                    </div>
                                    <div style="width:120px; text-align:center;">
                                        <div class="small text-muted">Logo encolhido</div>
                                        <div class="border p-2 rounded mt-2" style="background:#d0d0d0; display:inline-block;">
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
                    <div class="mb-3">
                        <label class="form-label">Avatar atual</label>
                        <div class="d-flex align-items-center gap-3">
                            <img id="edit_user_avatar_preview" src="assets/img/avatar-placeholder.png" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e6e6e6;" alt="Avatar" />
                            <div style="flex:1;">
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
    const tbody = document.getElementById('permissions_tbody');
    const filterInput = document.getElementById('filter_permission');

    async function loadScreens() {
        const res = await fetch('api/get_all_screens.php');
        const data = await res.json();
        return data.screens || [];
    }

    async function loadRolePermissions(roleId) {
        tbody.innerHTML = '<tr><td colspan="2" class="text-muted">Carregando...</td></tr>';
        const [screensRes, roleRes] = await Promise.all([
            fetch('api/get_all_screens.php'),
            fetch('api/get_role_permissions.php?role_id='+encodeURIComponent(roleId))
        ]);
        const screensData = await screensRes.json();
        const roleData = await roleRes.json();
        const screens = screensData.screens || [];
        const allowed = roleData.allowed || {};

        if (screens.length === 0) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted">Nenhuma permissão registrada.</td></tr>';
            return;
        }

        tbody.innerHTML = '';
        for (const s of screens) {
            const tr = document.createElement('tr');
            tr.dataset.screen = s;
            const tdName = document.createElement('td');
            tdName.textContent = s;
            const tdCheck = document.createElement('td');
            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.className = 'form-check-input';
            chk.checked = !!allowed[s];
            tdCheck.appendChild(chk);
            tr.appendChild(tdName);
            tr.appendChild(tdCheck);
            tbody.appendChild(tr);
        }
    }

    roleSelect.addEventListener('change', ()=> loadRolePermissions(roleSelect.value));
    document.getElementById('btn_reload_permissions').addEventListener('click', ()=> loadRolePermissions(roleSelect.value));

    filterInput.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        for (const tr of tbody.querySelectorAll('tr')) {
            const s = (tr.dataset.screen||'').toLowerCase();
            tr.style.display = q === '' || s.indexOf(q) !== -1 ? '' : 'none';
        }
    });

    document.getElementById('btn_save_permissions').addEventListener('click', async function(){
        const roleId = roleSelect.value;
        const permissions = [];
        for (const tr of tbody.querySelectorAll('tr')) {
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
        // read current checked
        const permissions = [];
        for (const tr of tbody.querySelectorAll('tr')) {
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

    // init button removed — initialization handled via migrations or API when needed

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
        const removeChk = document.getElementById('removeLogoChk');
        const removeCollapsedChk = document.getElementById('removeLogoCollapsedChk');
        const primaryInput = document.getElementById('primary_color');
        const primaryDarkInput = document.getElementById('primary_dark');
        const greenInput = document.getElementById('green_color');
        const yellowInput = document.getElementById('yellow_color');
        const preview = document.getElementById('appearancePreview');

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

        document.getElementById('btn_save_appearance').addEventListener('click', async function(){
            const fd = new FormData();
            if (logoInput.files && logoInput.files[0]) fd.append('logo', logoInput.files[0]);
            if (logoCollapsedInput.files && logoCollapsedInput.files[0]) fd.append('logo_collapsed', logoCollapsedInput.files[0]);
            if (removeChk.checked) fd.append('remove_logo', '1');
            if (removeCollapsedChk && removeCollapsedChk.checked) fd.append('remove_logo_collapsed', '1');
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
                }
            }catch(e){ alert('Erro ao salvar aparência'); console.error(e); }
            this.disabled = false;
        });

        document.getElementById('btn_reset_appearance').addEventListener('click', async function(){
            if (!confirm('Restaurar aparência para o padrão?')) return;
            const fd = new FormData();
            fd.append('remove_logo', '1');
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