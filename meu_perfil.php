<?php
session_start();
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
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Meu Perfil</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Meu Perfil</h3>
            <div>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Voltar</a>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">Sair</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="card p-3">
                    <h6>Informações</h6>
                    <dl class="row mb-0 mt-2">
                        <dt class="col-4">ID</dt><dd class="col-8"><?php echo htmlspecialchars($user['id'] ?? $user_id); ?></dd>
                        <dt class="col-4">Usuário</dt><dd class="col-8"><?php echo htmlspecialchars($user['username'] ?? ($_SESSION['username'] ?? '')); ?></dd>
                        <dt class="col-4">Nome</dt><dd class="col-8"><?php echo htmlspecialchars($user['name'] ?? ''); ?></dd>
                        <dt class="col-4">Email</dt><dd class="col-8"><?php echo htmlspecialchars($user['email'] ?? ''); ?></dd>
                    </dl>
                </div>
                <div class="card p-3 mt-3">
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
                <div class="card p-3">
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
                    <div id="profileTasksList" style="min-height:120px;"></div>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        import { fetchTasks, addTask } from './assets/js/team_tasks.js';
        const PROFILE_USER_ID = <?php echo json_encode($user_id); ?>;

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
                    // simple card
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
            document.getElementById('profileFiltroStatus').addEventListener('change', loadProfileTasks);
            document.getElementById('profileFiltroBusca').addEventListener('input', loadProfileTasks);
            loadProfileTasks();

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
    </script>
</body>
</html>
