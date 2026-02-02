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
    $tstmt = $pdo->prepare('SELECT * FROM team_tasks WHERE user_id = ? OR responsavel = ? ORDER BY data_vencimento ASC, criado_em DESC LIMIT 500');
    $tstmt->execute([$user_id, $user['username'] ?? '']);
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


include __DIR__ . '/includes/header.php';
// sidebar contains navigation
include __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-grow-1 p-4 main-content-scroll">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Meu Perfil</h3>
            <div>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Voltar</a>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">Sair</a>
            </div>
        </div>
        <!-- User metrics cards (scoped to logged user) -->
        <div class="row g-4 mb-4">
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Meus Leads Ativos</h6>
                    <div class="fs-2 fw-bold text-primary mb-1"><?= intval($totalLeadsUser) ?></div>
                    <small class="text-muted">Em andamento</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Novos (30d)</h6>
                    <div class="fs-2 fw-bold text-primary mb-1"><?= intval($newLeads30User) ?></div>
                    <small class="text-muted">Últimos 30 dias</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Projetos em Andamento</h6>
                    <div class="fs-2 fw-bold text-success mb-1"><?= intval($totalProjetosUser) ?></div>
                    <small class="text-muted">Não finalizados</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Valor em Negociação</h6>
                    <div class="fs-2 fw-bold text-warning mb-1">R$ <?= number_format($valorNegociacaoUser,2,',','.') ?></div>
                    <small class="text-muted">Total proposto</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Taxa de Conversão</h6>
                    <div class="fs-2 fw-bold text-info mb-1"><?= $conversionRateUser ?>%</div>
                    <small class="text-muted">Projetos finalizados</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Contratado (Total)</h6>
                    <div class="fs-2 fw-bold text-success mb-1">R$ <?= number_format($valorContratadoUser,2,',','.') ?></div>
                    <small class="text-muted">Projetos finalizados</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Média Proposta</h6>
                    <div class="fs-2 fw-bold text-secondary mb-1">R$ <?= number_format($avgProposalUser,2,',','.') ?></div>
                    <small class="text-muted">Valor médio</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="dashboard-modern-card p-3 text-center">
                    <h6 class="mb-2 text-muted">Forecast R$</h6>
                    <div class="fs-2 fw-bold text-warning mb-1">R$ <?= number_format($revenueForecastUser,2,',','.') ?></div>
                    <small class="text-muted">Estimativa simples</small>
                </div>
            </div>
        </div>
        <div id="profileDebug" class="mb-3"></div>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Informações</h6>
                    <dl class="row mb-0 mt-2">
                        <dt class="col-4">ID</dt><dd class="col-8"><?php echo htmlspecialchars($user['id'] ?? $user_id); ?></dd>
                        <dt class="col-4">Usuário</dt><dd class="col-8"><?php echo htmlspecialchars($user['username'] ?? ($_SESSION['username'] ?? '')); ?></dd>
                        <dt class="col-4">Nome</dt><dd class="col-8"><?php echo htmlspecialchars($user['name'] ?? ''); ?></dd>
                        <dt class="col-4">Email</dt><dd class="col-8"><?php echo htmlspecialchars($user['email'] ?? ''); ?></dd>
                    </dl>
                </div>
                <div class="card p-3 mt-3" style="border-right: 3px solid #007bff;">
                    <h6>Criar tarefa rápida</h6>
                    <form id="formQuickTask">
                        <div class="mb-2"><input name="titulo" class="form-control form-control-sm" placeholder="Título" required></div>
                        <div class="mb-2"><textarea name="descricao" class="form-control form-control-sm" rows="2" placeholder="Descrição (opcional)"></textarea></div>
                        <input type="hidden" name="responsavel" value="<?php echo htmlspecialchars($user['username'] ?? ($_SESSION['username'] ?? '')); ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <button class="btn btn-sm btn-primary w-100" type="submit">Salvar</button>
                        <div id="quickTaskMsg" class="mt-2"></div>
                    </form>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Minhas Tarefas</h6>
                    <div class="d-flex gap-2 mb-2">
                        <select id="profileFiltroStatus" class="form-select form-select-sm w-auto">
                            <option value="">Todos status</option>
                            <option value="Pendente">Pendente</option>
                            <option value="Em andamento">Em andamento</option>
                            <option value="Concluída">Concluída</option>
                        </select>
                        <input id="profileFiltroBusca" class="form-control form-control-sm" placeholder="Buscar..." />
                    </div>
                    <div id="profileTasksList" style="min-height:120px; max-height:320px; overflow:auto;">
                        <?php if (!empty($profile_tasks)): ?>
                            <?php foreach ($profile_tasks as $t): ?>
                                <div class="mb-2 p-2 border rounded d-flex align-items-center gap-3 bg-white">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:38px;height:38px;background:#888;color:#fff;">
                                        <?php echo strtoupper(substr(trim($t['responsavel'] ?? ($user['username'] ?? '')),0,2)); ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($t['titulo'] ?? ''); ?> <span class="badge ms-1"><?php echo htmlspecialchars($t['status'] ?? ''); ?></span></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($t['data_vencimento'] ?? ''); ?></div>
                                        <div class="mt-1"><?php echo htmlspecialchars($t['descricao'] ?? ''); ?></div>
                                    </div>
                                    <div class="d-flex flex-column gap-1">
                                        <button class="btn btn-sm btn-outline-primary">Editar</button>
                                        <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">Nenhuma tarefa encontrada.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Additional user activity panels -->
        <div class="row g-3 mt-3">
            <div class="col-md-6">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Leads</h6>
                    <div id="profileLeadsList" class="mb-2" style="min-height:80px; max-height:220px; overflow:auto;">
                        <?php if (!empty($profile_leads)): ?>
                            <?php foreach (array_slice($profile_leads,0,50) as $l): ?>
                                <div class="py-1 border-bottom">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($l['name']); ?> <small class="text-muted">#<?php echo $l['id']; ?></small></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($l['email'] ?? ''); ?> • <?php echo htmlspecialchars($l['phone'] ?? ''); ?> • <?php echo htmlspecialchars($l['status'] ?? ''); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">Nenhum lead.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Projetos</h6>
                    <div id="profileProjectsList" class="mb-2" style="min-height:80px; max-height:220px; overflow:auto;">
                        <?php if (!empty($profile_projects)): ?>
                            <?php foreach (array_slice($profile_projects,0,50) as $p): ?>
                                <div class="py-1 border-bottom">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($p['client_name'] ?? 'Projeto'); ?> <small class="text-muted">#<?php echo $p['id']; ?></small></div>
                                    <div class="small text-muted">R$ <?php echo number_format($p['proposal_value'] ?? 0,2,',','.'); ?> • <?php echo htmlspecialchars($p['status'] ?? ''); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">Nenhum projeto.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Movimentações (histórico)</h6>
                    <div id="profileMovementsList" class="mb-2" style="min-height:80px; max-height:320px; overflow:auto;">
                        <?php if (!empty($profile_movements)): ?>
                            <?php foreach (array_slice($profile_movements,0,200) as $m): ?>
                                <div class="py-1 border-bottom">
                                    <div class="small"><strong><?php echo htmlspecialchars($m['changed_by'] ?: $m['user_id']); ?></strong> em <span class="text-muted"><?php echo htmlspecialchars($m['created_at']); ?></span></div>
                                    <div class="small text-muted">Lead #<?php echo htmlspecialchars($m['lead_id']); ?> • <?php echo htmlspecialchars($m['from_status'] ?? ''); ?> → <?php echo htmlspecialchars($m['to_status'] ?? ''); ?></div>
                                    <div class="mt-1"><?php echo htmlspecialchars($m['note'] ?? ''); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">Nenhuma movimentação encontrada.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card p-3" style="border-right: 3px solid #007bff;">
                    <h6>Lembretes criados</h6>
                    <div id="profileRemindersList" class="mb-2" style="min-height:80px; max-height:220px; overflow:auto;">
                        <?php if (!empty($profile_reminders)): ?>
                            <?php foreach (array_slice($profile_reminders,0,200) as $r): ?>
                                <div class="py-1 border-bottom">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['message']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($r['remind_at']); ?> • Lead: <?php echo htmlspecialchars($r['lead_name'] ?? $r['lead_id']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">Nenhum lembrete.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script type="module">
    let fetchTasks = null, addTask = null;
    const PROFILE_USER_ID = <?php echo json_encode($user_id); ?>;
    // show numeric id for debug
    (function(){ const el = document.querySelector('.card p-3 dl'); if(el){ const info = document.createElement('div'); info.className='small text-muted mt-2'; info.textContent = 'PROFILE_USER_ID: ' + PROFILE_USER_ID; el.appendChild(info); } })();

    function escapeHtml(str){ if(!str) return ''; return String(str).replace(/[&<>'"]/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":"&#39;",'"':'&quot;'})[s]); }

    async function loadProfileTasks(){
        const list = document.getElementById('profileTasksList');
        list.innerHTML = '<div class="text-muted">Carregando...</div>';
        try{
            const status = document.getElementById('profileFiltroStatus').value;
            const q = document.getElementById('profileFiltroBusca').value.trim().toLowerCase();
            const filtros = { user_id: String(PROFILE_USER_ID) };
            if(status) filtros.status = status;
            const tarefas = await fetchTasks(filtros);
            if(!Array.isArray(tarefas) || !tarefas.length){ list.innerHTML = '<div class="text-muted">Nenhuma tarefa encontrada.</div>'; return; }
            list.innerHTML = '';
            tarefas.forEach(t=>{
                const card = document.createElement('div');
                card.className = 'mb-2 p-2 border rounded d-flex align-items-center gap-3 bg-white';
                const avatar = document.createElement('div'); avatar.className='rounded-circle d-flex align-items-center justify-content-center me-2'; avatar.style.width='38px'; avatar.style.height='38px'; avatar.style.background='#888'; avatar.style.color='#fff'; avatar.textContent = (t.responsavel||'?').split(' ').map(p=>p[0]).join('').slice(0,2).toUpperCase();
                const content = document.createElement('div'); content.className='flex-grow-1';
                content.innerHTML = `<div class="fw-semibold">${escapeHtml(t.titulo)} <span class="badge ms-1" style="background:${escapeHtml(t.status||'')};color:#fff;">${escapeHtml(t.status||'')}</span></div>
                    <div class="small text-muted">${t.data_vencimento?('Venc.: '+escapeHtml(t.data_vencimento)):''}</div>
                    <div class="mt-1">${escapeHtml(t.descricao||'')}</div>`;
                const actions = document.createElement('div'); actions.className='d-flex flex-column gap-1'; actions.innerHTML = `<button class="btn btn-sm btn-outline-primary">Editar</button><button class="btn btn-sm btn-outline-danger">Excluir</button>`;
                card.appendChild(avatar); card.appendChild(content); card.appendChild(actions);
                list.appendChild(card);
            });
        }catch(e){ console.error(e); document.getElementById('profileTasksList').innerHTML = '<div class="text-danger">Erro ao carregar tarefas</div>'; }
    }

    document.addEventListener('DOMContentLoaded', ()=>{
        // Try to dynamically import team_tasks helper; don't block the rest on failure
        (async function(){
            try{
                const mod = await import('./assets/js/team_tasks.js');
                fetchTasks = mod.fetchTasks; addTask = mod.addTask;
            }catch(e){
                console.warn('dynamic import team_tasks failed', e);
            }
            // wire filters after attempting import
            document.getElementById('profileFiltroStatus').addEventListener('change', loadProfileTasks);
            document.getElementById('profileFiltroBusca').addEventListener('input', loadProfileTasks);
            loadProfileTasks();
            loadProfileData();
        })();

        // quick task form
        const form = document.getElementById('formQuickTask');
        form.addEventListener('submit', async (e)=>{
            e.preventDefault();
            const fd = new FormData(form); const data = Object.fromEntries(fd);
            const resp = await addTask(data);
            const msg = document.getElementById('quickTaskMsg');
            if(resp && resp.success){ msg.innerHTML = '<div class="alert alert-success">Tarefa criada</div>'; form.reset(); setTimeout(()=>msg.innerHTML='';,1500); loadProfileTasks(); }
            else { msg.innerHTML = '<div class="alert alert-danger">Erro</div>'; }
        });
    });

    async function loadProfileData(){
        const base = 'api/get_user_activity.php?id=' + encodeURIComponent(PROFILE_USER_ID);
        try{
            const res = await fetch(base);
            const text = await res.text();
            // show raw response for debug
            const debugEl = document.getElementById('profileDebug');
            if (debugEl) debugEl.innerHTML = '<pre class="small text-muted">' + escapeHtml(text) + '</pre>';
            if(!res.ok) throw new Error('HTTP ' + res.status + ' — ' + text);
            let data = null;
            try { data = JSON.parse(text); } catch(parseErr){ throw new Error('Invalid JSON response: ' + parseErr.message); }
            if(!data.success){ console.warn('get_user_activity:', data); }

            // Leads
            const leadsWrap = document.getElementById('profileLeadsList');
            if(Array.isArray(data.leads) && data.leads.length){
                leadsWrap.innerHTML = '';
                data.leads.slice(0,50).forEach(l=>{
                    const el = document.createElement('div'); el.className='py-1 border-bottom'; el.innerHTML = `<div class="fw-semibold">${escapeHtml(l.name)} <small class="text-muted">#${l.id}</small></div><div class="small text-muted">${escapeHtml(l.email||'')} • ${escapeHtml(l.phone||'')} • ${escapeHtml(l.status||'')}</div>`;
                    leadsWrap.appendChild(el);
                });
            } else { leadsWrap.innerHTML = '<div class="text-muted">Nenhum lead.</div>'; }

            // Projects
            const projWrap = document.getElementById('profileProjectsList');
            if(Array.isArray(data.projects) && data.projects.length){
                projWrap.innerHTML = '';
                data.projects.slice(0,50).forEach(p=>{
                    const el = document.createElement('div'); el.className='py-1 border-bottom'; el.innerHTML = `<div class="fw-semibold">${escapeHtml(p.client_name||'Projeto')} <small class="text-muted">#${p.id}</small></div><div class="small text-muted">R$ ${Number(p.proposal_value||0).toLocaleString('pt-BR')} • ${escapeHtml(p.status||'')}</div>`;
                    projWrap.appendChild(el);
                });
            } else { projWrap.innerHTML = '<div class="text-muted">Nenhum projeto.</div>'; }

            // Movements
            const movWrap = document.getElementById('profileMovementsList');
            if(Array.isArray(data.movements) && data.movements.length){
                movWrap.innerHTML = '';
                data.movements.slice(0,200).forEach(m=>{
                    const el = document.createElement('div'); el.className='py-1 border-bottom';
                    el.innerHTML = `<div class="small"><strong>${escapeHtml(m.changed_by||m.user_id||'')}</strong> em <span class="text-muted">${escapeHtml(m.created_at||'')}</span></div><div class="small text-muted">Lead #${escapeHtml(m.lead_id||'')} • ${escapeHtml(m.from_status||'')} → ${escapeHtml(m.to_status||'')}</div><div class="mt-1">${escapeHtml(m.note||'')}</div>`;
                    movWrap.appendChild(el);
                });
            } else { movWrap.innerHTML = '<div class="text-muted">Nenhuma movimentação encontrada.</div>'; }

            // Reminders
            const remWrap = document.getElementById('profileRemindersList');
            if(Array.isArray(data.reminders) && data.reminders.length){
                remWrap.innerHTML = '';
                data.reminders.slice(0,200).forEach(r=>{
                    const el = document.createElement('div'); el.className='py-1 border-bottom';
                    el.innerHTML = `<div class="fw-semibold">${escapeHtml(r.message||'')}</div><div class="small text-muted">${escapeHtml(r.remind_at||'')} • Lead: ${escapeHtml(r.lead_name||r.lead_id||'')}</div>`;
                    remWrap.appendChild(el);
                });
            } else { remWrap.innerHTML = '<div class="text-muted">Nenhum lembrete.</div>'; }

        }catch(e){ console.error('loadProfileData', e); const wrap = document.getElementById('profileLeadsList'); if(wrap) wrap.innerHTML = '<div class="text-danger">Erro ao carregar dados</div>'; }
    }
</script>

<?php include __DIR__ . '/includes/footer.php';

