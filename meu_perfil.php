<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
}
$user_id = (int) $_SESSION['user_id'];
// fetch user info (best-effort)
$user = null;
try {
        $stmt = $pdo->prepare('SELECT id, username, email, name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
        // ignore - we'll show basic info from session
}

// Server-side fetch of profile-related data as a reliable fallback
$profile_leads = [];
$profile_projects = [];
$profile_movements = [];
$profile_reminders = [];
$profile_tasks = [];
try {
    $tstmt = $pdo->prepare('SELECT * FROM team_tasks WHERE user_id = ? OR responsavel_id = ? OR responsavel = ? ORDER BY data_vencimento ASC, criado_em DESC LIMIT 500');
    $tstmt->execute([$user_id, $user_id, $user['username'] ?? '']);
    $profile_tasks = $tstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }
try {
    $lstmt = $pdo->prepare('SELECT id, user_id, name, email, phone, status, source, created_at FROM leads WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
    $lstmt->execute([$user_id]);
    $profile_leads = $lstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }
try {
    $pjstmt = $pdo->prepare('SELECT id, user_id, client_name, proposal_value, status, created_at FROM projetos WHERE user_id = ? ORDER BY created_at DESC LIMIT 500');
    $pjstmt->execute([$user_id]);
    $profile_projects = $pjstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* projetos may not exist */ }
try {
    $mstmt = $pdo->prepare('SELECT id, lead_id, user_id, from_stage_id, to_stage_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements WHERE user_id = ? OR changed_by = ? ORDER BY created_at DESC LIMIT 1000');
    $mstmt->execute([$user_id, $user['username'] ?? '']);
    $profile_movements = $mstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }
try {
    $rstmt = $pdo->prepare('SELECT r.id, r.lead_id, r.message, r.remind_at, r.status, r.created_at, l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id = r.lead_id WHERE r.created_by = ? ORDER BY r.created_at DESC LIMIT 500');
    $rstmt->execute([$user_id]);
    $profile_reminders = $rstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

// Build usersMap for avatar display
$usersMap = [];
try {
    $usersStmt = $pdo->query('SELECT id, username, name, email, avatar FROM users ORDER BY id');
    $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allUsers as $u) {
        $usersMap[$u['id']] = $u;
    }
} catch (Exception $e) { /* ignore */ }

// User-scoped metrics (cards similar to dashboard but only for this user)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0 AND status NOT IN ('Convertido','Perdido')");
    $stmt->execute([$user_id]);
    $totalLeadsUser = $stmt->fetchColumn();
} catch (Exception $e) { $totalLeadsUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $totalProjetosUser = $stmt->fetchColumn();
} catch (Exception $e) { $totalProjetosUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $valorNegociacaoUser = $stmt->fetchColumn();
} catch (Exception $e) { $valorNegociacaoUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE user_id = ? AND status = 'Finalizado'");
    $stmt->execute([$user_id]);
    $projetosFinalizadosUser = $stmt->fetchColumn();
} catch (Exception $e) { $projetosFinalizadosUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status = 'Finalizado'");
    $stmt->execute([$user_id]);
    $valorContratadoUser = $stmt->fetchColumn();
} catch (Exception $e) { $valorContratadoUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0");
    $stmt->execute([$user_id]);
    $totalLeadsAllUser = $stmt->fetchColumn();
    $conversionRateUser = $totalLeadsAllUser > 0 ? round(($projetosFinalizadosUser / $totalLeadsAllUser) * 100, 1) : 0;
} catch (Exception $e) { $totalLeadsAllUser = 0; $conversionRateUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND deleted = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute([$user_id]);
    $newLeads30User = $stmt->fetchColumn();
} catch (Exception $e) { $newLeads30User = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(AVG(NULLIF(proposal_value,0)),0) FROM projetos WHERE user_id = ? AND proposal_value > 0");
    $stmt->execute([$user_id]);
    $avgProposalUser = $stmt->fetchColumn();
} catch (Exception $e) { $avgProposalUser = 0; }
try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE user_id = ? AND status NOT IN ('Finalizado','Perdido')");
    $stmt->execute([$user_id]);
    $openProposalsSumUser = $stmt->fetchColumn();
    $revenueForecastUser = round(($openProposalsSumUser * ($conversionRateUser / 100)), 2);
} catch (Exception $e) { $openProposalsSumUser = 0; $revenueForecastUser = 0; }


// Carregar cor primária das configurações
$primary_color = '#667eea';
$storageDir = __DIR__ . '/storage';
$settingsPath = $storageDir . '/settings.json';
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
    if (!empty($settings['primary_color'])) {
        $primary_color = $settings['primary_color'];
    }
}

include __DIR__ . '/includes/header.php';
// sidebar contains navigation
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .profile-header-gradient {
        background: <?php echo htmlspecialchars($primary_color); ?>;
        border-radius: 16px;
        padding: 2rem;
        color: white !important;
        margin-bottom: 2rem;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }
    .profile-header-gradient h2,
    .profile-header-gradient p,
    .profile-header-gradient a,
    .profile-header-gradient * {
        color: white !important;
    }
    .profile-card {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        border: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        height: 100%;
    }
    .profile-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    .profile-avatar-large {
        width: 140px;
        height: 140px;
        object-fit: cover;
        border-radius: 50%;
        border: 5px solid white;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .profile-info-item {
        padding: 0.75rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .profile-info-item:last-child {
        border-bottom: none;
    }
    .profile-info-label {
        font-weight: 600;
        color: #6c757d;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .profile-info-value {
        color: #2d3748;
        font-size: 1rem;
        margin-top: 0.25rem;
    }
    .profile-section-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: #2d3748;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .profile-section-title::before {
        content: '';
        width: 4px;
        height: 24px;
        background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        border-radius: 4px;
    }
    .task-item {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
    }
    .task-item:hover {
        border-color: #667eea;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
    }
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.875rem;
    }
    .badge-custom {
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .badge-pendente { background: #fef3c7; color: #92400e; }
    .badge-andamento { background: #dbeafe; color: #1e40af; }
    .badge-concluida { background: #d1fae5; color: #065f46; }
    .list-item {
        padding: 1rem;
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.2s ease;
    }
    .list-item:hover {
        background: #f8f9fa;
    }
    .list-item:last-child {
        border-bottom: none;
    }
    .btn-modern {
        border-radius: 8px;
        font-weight: 600;
        padding: 0.5rem 1.25rem;
        transition: all 0.2s ease;
    }
    .btn-modern:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .scrollable-list {
        max-height: 400px;
        overflow-y: auto;
        padding-right: 0.5rem;
    }
    .scrollable-list::-webkit-scrollbar {
        width: 6px;
    }
    .scrollable-list::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    .scrollable-list::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }
    .scrollable-list::-webkit-scrollbar-thumb:hover {
        background: #a0aec0;
    }
</style>

<main class="flex-grow-1 p-4 main-content-scroll">
    <div class="container-fluid">
        <!-- Header com gradiente -->
        <div class="profile-header-gradient">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-2" style="font-weight: 700;">Meu Perfil</h2>
                    <p class="mb-0 opacity-75">Gerencie suas informações e acompanhe suas atividades</p>
                </div>
                <div>
                    <a href="logout.php" class="btn btn-outline-light btn-modern">
                        <i class="fas fa-sign-out-alt me-2"></i>Sair
                    </a>
                </div>
            </div>
        </div>

        <div id="profileDebug" class="mb-3"></div>

        <div class="row g-4">
            <!-- Coluna Esquerda - Foto e Informações -->
            <div class="col-lg-4">
                <!-- Card Foto de Perfil -->
                <div class="profile-card">
                    <h5 class="profile-section-title">Foto de Perfil</h5>
                    <div class="text-center mb-4">
                        <?php
                            // load avatar if present
                            $avatarPath = null;
                            try {
                                $ast = $pdo->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
                                $ast->execute([$user_id]);
                                $arow = $ast->fetch(PDO::FETCH_ASSOC);
                                if (!empty($arow['avatar']) && file_exists(__DIR__ . '/' . $arow['avatar'])) {
                                    $avatarPath = $arow['avatar'];
                                }
                            } catch (Exception $e) { }
                            $avatarSrc = $avatarPath ?: 'assets/img/avatar-placeholder.png';
                        ?>
                        <img id="profileAvatar" src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="Avatar" class="profile-avatar-large" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Escolher nova foto</label>
                        <input id="avatarInput" type="file" accept="image/*" class="form-control" />
                    </div>
                    <div class="d-flex gap-2 mb-4">
                        <button id="btnUploadAvatar" class="btn btn-primary btn-modern flex-grow-1">
                            <i class="fas fa-upload me-2"></i>Salvar
                        </button>
                        <button id="btnRemoveAvatar" class="btn btn-outline-danger btn-modern">
                            <i class="fas fa-trash me-2"></i>Remover
                        </button>
                    </div>
                    <div id="avatarMsg" class="mt-3 mb-4"></div>

                    <!-- Informações Pessoais -->
                    <hr class="my-4">
                    <h6 class="fw-bold mb-3 text-secondary">Informações Pessoais</h6>
                    <div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-hashtag me-2"></i>ID do Usuário
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['id'] ?? $user_id); ?></div>
                        </div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-user me-2"></i>Usuário
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['username'] ?? ($_SESSION['username'] ?? '')); ?></div>
                        </div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-id-card me-2"></i>Nome Completo
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['name'] ?? ''); ?></div>
                        </div>
                        <div class="profile-info-item">
                            <div class="profile-info-label">
                                <i class="fas fa-envelope me-2"></i>E-mail
                            </div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Coluna Direita - Tarefas -->
            <div class="col-lg-8">
                <div class="profile-card">
                    <h5 class="profile-section-title">Minhas Tarefas</h5>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <select id="profileFiltroStatus" class="form-select">
                                <option value="">📋 Todos os status</option>
                                <option value="Pendente">⏳ Pendente</option>
                                <option value="Em andamento">⚙️ Em andamento</option>
                                <option value="Concluída">✅ Concluída</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <input id="profileFiltroBusca" class="form-control" placeholder="🔍 Buscar tarefas..." />
                        </div>
                    </div>
                    <div id="profileTasksList" class="scrollable-list" style="min-height:350px;">
                        <?php if (!empty($profile_tasks)): ?>
                            <?php foreach ($profile_tasks as $t): ?>
                                <div class="task-item">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="position-relative" style="width: 52px; height: 38px; flex-shrink: 0;">
                                            <!-- Avatares: criador à esquerda, responsável à direita -->
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($t['titulo'] ?? ''); ?></h6>
                                                <span class="badge-custom badge-pendente"><?php echo htmlspecialchars($t['status'] ?? ''); ?></span>
                                            </div>
                                            <p class="text-muted small mb-2">
                                                <i class="far fa-calendar me-1"></i>
                                                <?php echo htmlspecialchars($t['data_vencimento'] ?? 'Sem data'); ?>
                                            </p>
                                            <p class="mb-0 text-secondary"><?php echo htmlspecialchars($t['descricao'] ?? ''); ?></p>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <button class="btn btn-sm btn-outline-primary btn-modern" data-task-id="<?php echo $t['id']; ?>" onclick="openEditTaskModal(this)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger btn-modern" data-task-id="<?php echo $t['id']; ?>" onclick="deleteTaskConfirm(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-tasks fa-3x mb-3 opacity-25"></i>
                                <p>Nenhuma tarefa encontrada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seções de Leads, Projetos, Movimentações e Lembretes -->
        <div class="row g-4 mt-2">
            <div class="col-lg-6">
                <div class="profile-card">
                    <h5 class="profile-section-title">📊 Meus Leads</h5>
                    <div id="profileLeadsList" class="scrollable-list" style="min-height:200px;">
                        <?php if (!empty($profile_leads)): ?>
                            <?php foreach (array_slice($profile_leads,0,50) as $l): ?>
                                <div class="list-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($l['name']); ?></h6>
                                            <p class="mb-0 small text-muted">
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($l['email'] ?? ''); ?>
                                                <span class="mx-2">•</span>
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($l['phone'] ?? ''); ?>
                                            </p>
                                        </div>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($l['status'] ?? ''); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-user-tie fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum lead encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="profile-card">
                    <h5 class="profile-section-title">💼 Meus Projetos</h5>
                    <div id="profileProjectsList" class="scrollable-list" style="min-height:200px;">
                        <?php if (!empty($profile_projects)): ?>
                            <?php foreach (array_slice($profile_projects,0,50) as $p): ?>
                                <div class="list-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($p['client_name'] ?? 'Projeto'); ?></h6>
                                            <p class="mb-0 small">
                                                <span class="text-success fw-bold">
                                                    <i class="fas fa-dollar-sign me-1"></i>R$ <?php echo number_format($p['proposal_value'] ?? 0,2,',','.'); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($p['status'] ?? ''); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-briefcase fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum projeto encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="profile-card">
                    <h5 class="profile-section-title">📈 Histórico de Movimentações</h5>
                    <div id="profileMovementsList" class="scrollable-list" style="min-height:200px;">
                        <?php if (!empty($profile_movements)): ?>
                            <?php foreach (array_slice($profile_movements,0,200) as $m): ?>
                                <div class="list-item">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="avatar-circle" style="width:32px;height:32px;font-size:0.75rem;">
                                            <?php echo strtoupper(substr(trim($m['changed_by'] ?: $m['user_id']),0,2)); ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="small mb-1">
                                                <strong><?php echo htmlspecialchars($m['changed_by'] ?: $m['user_id']); ?></strong>
                                                <span class="text-muted mx-2">•</span>
                                                <span class="text-muted">
                                                    <i class="far fa-clock me-1"></i><?php echo htmlspecialchars($m['created_at']); ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted mb-1">
                                                Lead #<?php echo htmlspecialchars($m['lead_id']); ?>
                                                <span class="mx-2">→</span>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($m['from_status'] ?? ''); ?></span>
                                                <i class="fas fa-arrow-right mx-1"></i>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($m['to_status'] ?? ''); ?></span>
                                            </div>
                                            <?php if(!empty($m['note'])): ?>
                                                <p class="mb-0 small"><?php echo htmlspecialchars($m['note']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i>
                                <p>Nenhuma movimentação encontrada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="profile-card">
                    <h5 class="profile-section-title">🔔 Lembretes Criados</h5>
                    <div id="profileRemindersList" class="scrollable-list" style="min-height:200px;">
                        <?php if (!empty($profile_reminders)): ?>
                            <?php foreach (array_slice($profile_reminders,0,200) as $r): ?>
                                <div class="list-item">
                                    <div class="d-flex align-items-start gap-3">
                                        <div style="color:#667eea;font-size:1.5rem;">
                                            <i class="far fa-bell"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($r['message']); ?></h6>
                                            <p class="mb-0 small text-muted">
                                                <i class="far fa-calendar-alt me-1"></i><?php echo htmlspecialchars($r['remind_at']); ?>
                                                <span class="mx-2">•</span>
                                                Lead: <strong><?php echo htmlspecialchars($r['lead_name'] ?? $r['lead_id']); ?></strong>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <i class="far fa-bell fa-3x mb-3 opacity-25"></i>
                                <p>Nenhum lembrete encontrado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script type="module">
    let fetchTasks = null, addTask = null, updateTask = null, deleteTask = null;
    const PROFILE_USER_ID = <?php echo json_encode($user_id); ?>;
    const usersMap = <?php echo json_encode($usersMap ?? []); ?>;
    // show numeric id for debug
    (function(){ const el = document.querySelector('.card p-3 dl'); if(el){ const info = document.createElement('div'); info.className='small text-muted mt-2'; info.textContent = 'PROFILE_USER_ID: ' + PROFILE_USER_ID; el.appendChild(info); } })();

    function escapeHtml(str){ if(!str) return ''; return String(str).replace(/[&<>'"]/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":"&#39;",'"':'&quot;'})[s]); }

    function initials(nome) {
        return nome.split(' ').map(p=>p[0]).join('').toUpperCase().slice(0,2);
    }

    function equipeColor(eq) {
        const map = {Marketing:'#3bb273',Vendas:'#0b6ac1',Atendimento:'#ffd24a',Técnica:'#7c3aed',Financeiro:'#ef4444'};
        return map[eq]||'#888';
    }

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

    function withTimeout(fn, ms){
        return async (...args) => {
            return await Promise.race([
                fn(...args),
                new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), ms))
            ]);
        };
    }

    async function loadProfileTasks(){
        const list = document.getElementById('profileTasksList');
        list.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x mb-3"></i><p>Carregando tarefas...</p></div>';
        console.debug('loadProfileTasks: start');
        try{
            const status = document.getElementById('profileFiltroStatus').value;
            const q = document.getElementById('profileFiltroBusca').value.trim().toLowerCase();
            const filtros = {};
            if(status) filtros.status = status;
            if (typeof fetchTasks !== 'function') throw new Error('fetchTasks not defined');
            console.debug('Calling fetchTasks with filters', filtros);
            const tarefas = await fetchTasks(filtros);
            console.debug('fetchTasks result', tarefas && tarefas.length ? tarefas.length : tarefas);
            if(!tarefas || !Array.isArray(tarefas) || !tarefas.length){ 
                list.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-tasks fa-3x mb-3 opacity-25"></i><p>Nenhuma tarefa encontrada.</p></div>'; 
                return; 
            }
            
            // Filtrar por user_id ou responsavel_id, e depois por busca
            let tarefasFiltradas = tarefas.filter(t => {
                return (t.user_id == PROFILE_USER_ID || t.responsavel_id == PROFILE_USER_ID || t.responsavel === '<?php echo ($_SESSION['username'] ?? ''); ?>');
            });
            
            if(q) {
                tarefasFiltradas = tarefasFiltradas.filter(t => {
                    const titulo = (t.titulo || '').toLowerCase();
                    const descricao = (t.descricao || '').toLowerCase();
                    const responsavel = (t.responsavel || '').toLowerCase();
                    return titulo.includes(q) || descricao.includes(q) || responsavel.includes(q);
                });
            }
            
            if(!tarefasFiltradas.length){ 
                list.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-search fa-3x mb-3 opacity-25"></i><p>Nenhuma tarefa encontrada com esse critério.</p></div>'; 
                return; 
            }
            
            tarefasFiltradas.forEach(t=>{
                const card = document.createElement('div');
                card.className = 'task-item';
                card.style.display = 'flex';
                card.style.alignItems = 'center';
                card.style.gap = '12px';
                
                const badgeClass = t.status === 'Pendente' ? 'badge-pendente' : 
                                  t.status === 'Em andamento' ? 'badge-andamento' : 
                                  t.status === 'Concluída' ? 'badge-concluida' : 'badge-pendente';
                
                // Avatares sobrepostos
                const avatarWrap = document.createElement('div');
                avatarWrap.className = 'position-relative';
                avatarWrap.style.width = '52px';
                avatarWrap.style.height = '38px';
                avatarWrap.style.flex = '0 0 52px';

                const criadorId = t.user_id || null;
                const responsavelId = t.responsavel_id || null;
                const criadorInfo = (criadorId && usersMap && usersMap[criadorId]) ? usersMap[criadorId] : null;
                const responsavelInfo = (responsavelId && usersMap && usersMap[responsavelId]) ? usersMap[responsavelId] : null;
                const criadorNome = criadorInfo && criadorInfo.username ? criadorInfo.username : 'Criador';
                const responsavelNome = responsavelInfo && responsavelInfo.username ? responsavelInfo.username : t.responsavel;

                const creatorEl = buildAvatar(criadorInfo, criadorNome, '#6c757d');
                creatorEl.style.position = 'absolute';
                creatorEl.style.left = '0';
                creatorEl.style.top = '0';
                creatorEl.style.zIndex = '2';

                const respEl = buildAvatar(responsavelInfo, responsavelNome, equipeColor(t.equipe));
                respEl.style.position = 'absolute';
                respEl.style.left = '22px';
                respEl.style.top = '0';
                respEl.style.zIndex = '1';

                avatarWrap.appendChild(respEl);
                avatarWrap.appendChild(creatorEl);
                
                const content = document.createElement('div');
                content.className = 'flex-grow-1';
                content.innerHTML = `
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h6 class="mb-0 fw-bold">${escapeHtml(t.titulo)}</h6>
                        <span class="badge-custom ${badgeClass}">${escapeHtml(t.status||'')}</span>
                    </div>
                    <p class="text-muted small mb-2">
                        <i class="far fa-calendar me-1"></i>
                        ${t.data_vencimento ? escapeHtml(t.data_vencimento) : 'Sem data'} | Responsável: ${escapeHtml(responsavelNome || '')}
                    </p>
                    <p class="mb-0 text-secondary">${escapeHtml(t.descricao||'')}</p>
                `;

                const actions = document.createElement('div');
                actions.className = 'd-flex gap-2';
                actions.style.flex = '0 0 auto';
                const editBtn = document.createElement('button');
                editBtn.className = 'btn btn-sm btn-outline-primary btn-modern';
                editBtn.setAttribute('data-task-id', t.id);
                editBtn.onclick = openEditTaskModal;
                editBtn.innerHTML = '<i class="fas fa-edit"></i>';

                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'btn btn-sm btn-outline-danger btn-modern';
                deleteBtn.setAttribute('data-task-id', t.id);
                deleteBtn.onclick = deleteTaskConfirm;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';

                actions.appendChild(editBtn);
                actions.appendChild(deleteBtn);

                card.appendChild(avatarWrap);
                card.appendChild(content);
                card.appendChild(actions);
                list.appendChild(card);
            });
        }catch(e){ 
            console.error('loadProfileTasks error:', e); 
            list.innerHTML = '<div class="text-center text-danger py-5"><i class="fas fa-exclamation-triangle fa-2x mb-3"></i><p>Erro ao carregar tarefas: ' + (e.message || 'desconhecido') + '</p></div>'; 
        }
    }

    window.openEditTaskModal = async function(e) {
        const taskId = e.currentTarget.getAttribute('data-task-id');
        console.log('Edit task:', taskId);
        alert('Editar tarefa ' + taskId + ' (em desenvolvimento)');
    };

    window.deleteTaskConfirm = async function(e) {
        const taskId = e.currentTarget.getAttribute('data-task-id');
        if (!confirm('Tem certeza que deseja deletar essa tarefa?')) return;
        try {
            const resp = await deleteTask(taskId);
            if (resp.success) {
                alert('Tarefa deletada com sucesso!');
                loadProfileTasks();
            } else {
                alert('Erro ao deletar: ' + (resp.error || 'erro desconhecido'));
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao deletar tarefa');
        }
    };

    document.addEventListener('DOMContentLoaded', ()=>{
        // Try to dynamically import team_tasks helper; don't block the rest on failure
        (async function(){
            let imported = false;
            try{
                const mod = await import('/assets/js/team_tasks.js');
                fetchTasks = withTimeout(mod.fetchTasks.bind(mod), 8000);
                addTask = withTimeout(mod.addTask.bind(mod), 8000);
                updateTask = withTimeout(mod.updateTask.bind(mod), 8000);
                deleteTask = withTimeout(mod.deleteTask.bind(mod), 8000);
                imported = true;
            }catch(e1){
                try{
                    const mod = await import('./assets/js/team_tasks.js');
                    fetchTasks = withTimeout(mod.fetchTasks.bind(mod), 8000);
                    addTask = withTimeout(mod.addTask.bind(mod), 8000);
                    updateTask = withTimeout(mod.updateTask.bind(mod), 8000);
                    deleteTask = withTimeout(mod.deleteTask.bind(mod), 8000);
                    imported = true;
                }catch(e2){
                    console.warn('dynamic import team_tasks failed', e1, e2);
                }
            }

            // Fallback implementations using direct fetch if dynamic import isn't available
            if(!imported){
                const _fetchTasks = async (filters = {}) => {
                    console.debug('fallback fetchTasks called with', filters);
                    const params = new URLSearchParams(Object.assign({action:'list'}, filters));
                    const res = await fetch('includes/team_tasks_api.php?' + params, {credentials: 'same-origin'});
                    return await res.json();
                };
                const _addTask = async (data) => {
                    const res = await fetch('includes/team_tasks_api.php?action=add', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'});
                    return await res.json();
                };
                const _updateTask = async (id, data) => {
                    const res = await fetch('includes/team_tasks_api.php?action=update&id=' + encodeURIComponent(id), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'});
                    return await res.json();
                };
                const _deleteTask = async (id) => {
                    const res = await fetch('includes/team_tasks_api.php?action=delete&id=' + encodeURIComponent(id), {method:'POST', credentials: 'same-origin'});
                    return await res.json();
                };

                fetchTasks = withTimeout(_fetchTasks, 8000);
                addTask = withTimeout(_addTask, 8000);
                updateTask = withTimeout(_updateTask, 8000);
                deleteTask = withTimeout(_deleteTask, 8000);
            }

            console.debug('Functions initialized:', {fetchTasks: typeof fetchTasks, addTask: typeof addTask, updateTask: typeof updateTask, deleteTask: typeof deleteTask});

            // wire filters after attempting import
            document.getElementById('profileFiltroStatus').addEventListener('change', loadProfileTasks);
            document.getElementById('profileFiltroBusca').addEventListener('input', loadProfileTasks);
            loadProfileTasks();
            loadProfileData();
        })();

        // Avatar upload handlers
        const avatarInput = document.getElementById('avatarInput');
        const btnUpload = document.getElementById('btnUploadAvatar');
        const btnRemove = document.getElementById('btnRemoveAvatar');
        const avatarMsg = document.getElementById('avatarMsg');
        const profileAvatar = document.getElementById('profileAvatar');

        if (btnUpload) btnUpload.addEventListener('click', async ()=>{
            if (!avatarInput.files || !avatarInput.files[0]) { avatarMsg.innerHTML = '<div class="text-danger small">Selecione um arquivo.</div>'; return; }
            const fd = new FormData(); fd.append('avatar', avatarInput.files[0]);
            try{
                btnUpload.disabled = true;
                const res = await fetch('api/upload_avatar.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch (e){ avatarMsg.innerHTML = '<div class="text-danger small">Resposta inválida do servidor</div>'; console.error('upload raw:', text); btnUpload.disabled = false; return; }
                if (data.success) {
                    avatarMsg.innerHTML = '<div class="text-success small">Foto salva.</div>';
                    if (data.avatar) profileAvatar.src = data.avatar + '?v=' + Date.now();
                } else {
                    avatarMsg.innerHTML = '<div class="text-danger small">' + (data.message||'Erro') + '</div>';
                }
            }catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Erro ao enviar</div>'; console.error(e); }
            btnUpload.disabled = false;
        });

        if (btnRemove) btnRemove.addEventListener('click', async ()=>{
            if (!confirm('Remover sua foto de perfil?')) return;
            try{
                btnRemove.disabled = true;
                const fd = new FormData(); fd.append('remove','1');
                const res = await fetch('api/upload_avatar.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Resposta inválida do servidor</div>'; btnRemove.disabled = false; return; }
                if (data.success) {
                    avatarMsg.innerHTML = '<div class="text-success small">Foto removida.</div>';
                    profileAvatar.src = 'assets/img/avatar-placeholder.png';
                } else {
                    avatarMsg.innerHTML = '<div class="text-danger small">' + (data.message||'Erro') + '</div>';
                }
            }catch(e){ avatarMsg.innerHTML = '<div class="text-danger small">Erro ao remover</div>'; console.error(e); }
            btnRemove.disabled = false;
        });

        
    });

    async function loadProfileData(){
        const base = 'api/get_user_activity.php?id=' + encodeURIComponent(PROFILE_USER_ID);
        try{
            const res = await fetch(base);
            const text = await res.text();
            // clear debug element (do not display raw JSON)
            const debugEl = document.getElementById('profileDebug');
            if (debugEl) debugEl.innerHTML = '';
            if(!res.ok) throw new Error('HTTP ' + res.status + ' — ' + text);
            let data = null;
            try { data = JSON.parse(text); } catch(parseErr){ throw new Error('Invalid JSON response: ' + parseErr.message); }
            if(!data.success){ console.warn('get_user_activity:', data); }

            // Leads
            const leadsWrap = document.getElementById('profileLeadsList');
            if(Array.isArray(data.leads) && data.leads.length){
                leadsWrap.innerHTML = '';
                data.leads.slice(0,50).forEach(l=>{
                    const el = document.createElement('div'); 
                    el.className='list-item'; 
                    el.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold">${escapeHtml(l.name)}</h6>
                                <p class="mb-0 small text-muted">
                                    <i class="fas fa-envelope me-1"></i>${escapeHtml(l.email||'')}
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-phone me-1"></i>${escapeHtml(l.phone||'')}
                                </p>
                            </div>
                            <span class="badge bg-primary">${escapeHtml(l.status||'')}</span>
                        </div>
                    `;
                    leadsWrap.appendChild(el);
                });
            } else { 
                leadsWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-user-tie fa-3x mb-3 opacity-25"></i><p>Nenhum lead encontrado.</p></div>'; 
            }

            // Projects
            const projWrap = document.getElementById('profileProjectsList');
            if(Array.isArray(data.projects) && data.projects.length){
                projWrap.innerHTML = '';
                data.projects.slice(0,50).forEach(p=>{
                    const el = document.createElement('div'); 
                    el.className='list-item'; 
                    el.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold">${escapeHtml(p.client_name||'Projeto')}</h6>
                                <p class="mb-0 small">
                                    <span class="text-success fw-bold">
                                        <i class="fas fa-dollar-sign me-1"></i>R$ ${Number(p.proposal_value||0).toLocaleString('pt-BR')}
                                    </span>
                                </p>
                            </div>
                            <span class="badge bg-success">${escapeHtml(p.status||'')}</span>
                        </div>
                    `;
                    projWrap.appendChild(el);
                });
            } else { 
                projWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-briefcase fa-3x mb-3 opacity-25"></i><p>Nenhum projeto encontrado.</p></div>'; 
            }

            // Movements
            const movWrap = document.getElementById('profileMovementsList');
            if(Array.isArray(data.movements) && data.movements.length){
                movWrap.innerHTML = '';
                data.movements.slice(0,200).forEach(m=>{
                    const el = document.createElement('div'); 
                    el.className='list-item';
                    const initials = (m.changed_by||m.user_id||'?').split(' ').map(p=>p[0]).join('').slice(0,2).toUpperCase();
                    const note = m.note ? `<p class="mb-0 small">${escapeHtml(m.note)}</p>` : '';
                    el.innerHTML = `
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar-circle" style="width:32px;height:32px;font-size:0.75rem;">
                                ${initials}
                            </div>
                            <div class="flex-grow-1">
                                <div class="small mb-1">
                                    <strong>${escapeHtml(m.changed_by||m.user_id||'')}</strong>
                                    <span class="text-muted mx-2">•</span>
                                    <span class="text-muted">
                                        <i class="far fa-clock me-1"></i>${escapeHtml(m.created_at||'')}
                                    </span>
                                </div>
                                <div class="small text-muted mb-1">
                                    Lead #${escapeHtml(m.lead_id||'')}
                                    <span class="mx-2">→</span>
                                    <span class="badge bg-secondary">${escapeHtml(m.from_status||'')}</span>
                                    <i class="fas fa-arrow-right mx-1"></i>
                                    <span class="badge bg-primary">${escapeHtml(m.to_status||'')}</span>
                                </div>
                                ${note}
                            </div>
                        </div>
                    `;
                    movWrap.appendChild(el);
                });
            } else { 
                movWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i><p>Nenhuma movimentação encontrada.</p></div>'; 
            }

            // Reminders
            const remWrap = document.getElementById('profileRemindersList');
            if(Array.isArray(data.reminders) && data.reminders.length){
                remWrap.innerHTML = '';
                data.reminders.slice(0,200).forEach(r=>{
                    const el = document.createElement('div'); 
                    el.className='list-item';
                    el.innerHTML = `
                        <div class="d-flex align-items-start gap-3">
                            <div style="color:#667eea;font-size:1.5rem;">
                                <i class="far fa-bell"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="mb-1 fw-semibold">${escapeHtml(r.message||'')}</h6>
                                <p class="mb-0 small text-muted">
                                    <i class="far fa-calendar-alt me-1"></i>${escapeHtml(r.remind_at||'')}
                                    <span class="mx-2">•</span>
                                    Lead: <strong>${escapeHtml(r.lead_name||r.lead_id||'')}</strong>
                                </p>
                            </div>
                        </div>
                    `;
                    remWrap.appendChild(el);
                });
            } else { 
                remWrap.innerHTML = '<div class="text-center text-muted py-5"><i class="far fa-bell fa-3x mb-3 opacity-25"></i><p>Nenhum lembrete encontrado.</p></div>'; 
            }

        }catch(e){ 
            console.error('loadProfileData', e); 
            const wrap = document.getElementById('profileLeadsList'); 
            if(wrap) wrap.innerHTML = '<div class="text-center text-danger py-5"><i class="fas fa-exclamation-triangle fa-2x mb-3"></i><p>Erro ao carregar dados</p></div>'; 
        }
    }
</script>

<?php include __DIR__ . '/includes/footer.php';

