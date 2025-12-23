<?php
// Integração de Equipes - Centraliza informações e tarefas entre equipes
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'includes/config.php';


// Buscar equipes e responsáveis distintos para filtros
$equipes = ['Marketing','Vendas','Atendimento','Técnica','Financeiro'];
$respStmt = $pdo->prepare('SELECT DISTINCT responsavel FROM team_tasks WHERE user_id = ? AND responsavel IS NOT NULL AND responsavel <> ""');
$respStmt->execute([$_SESSION['user_id']]);
$responsaveis = array_map(function($r){return $r['responsavel'];}, $respStmt->fetchAll(PDO::FETCH_ASSOC));

$pageTitle = 'Integração de Equipes';
include 'includes/header.php';
?>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h4 mb-1">Integração de Equipes</h1>
                    <div class="text-muted">Centraliza informações e tarefas entre equipes de marketing, vendas e atendimento. Melhora a comunicação interna e reduz falhas no processo.</div>
                </div>
                <div>
                    <a href="add_customer.php" class="btn btn-primary">Adicionar tarefa/registro</a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card card-shadow p-3 mb-3">
                        <h6 class="mb-3">Tarefas de Equipe</h6>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            <select id="filtroEquipe" class="form-select form-select-sm w-auto">
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
                            <button class="btn btn-success btn-sm" id="btnNovaTarefa"><i class="fa fa-plus"></i> Nova tarefa</button>
                        </div>
                                                <div id="tasksList"></div>
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
                                                                    <div class="row g-3">
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Equipe <i class="fa fa-users text-muted"></i></label>
                                                                            <select name="equipe" id="edit-equipe" class="form-select" required>
                                                                                <option value="">Selecione</option>
                                                                                <?php foreach ($equipes as $eq): ?><option value="<?php echo $eq; ?>"><?php echo $eq; ?></option><?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Responsável <i class="fa fa-user text-muted"></i></label>
                                                                            <input type="text" name="responsavel" id="edit-responsavel" class="form-control" required>
                                                                        </div>
                                                                    </div>
                                                                    <div class="row g-3 mt-2">
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Título <i class="fa fa-tasks text-muted"></i></label>
                                                                            <input type="text" name="titulo" id="edit-titulo" class="form-control" required>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Status <i class="fa fa-flag text-muted"></i></label>
                                                                            <select name="status" id="edit-status" class="form-select">
                                                                                <option value="Pendente">Pendente</option>
                                                                                <option value="Em andamento">Em andamento</option>
                                                                                <option value="Concluída">Concluída</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                    <div class="row g-3 mt-2">
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Data de vencimento <i class="fa fa-calendar text-muted"></i></label>
                                                                            <input type="date" name="data_vencimento" id="edit-data-vencimento" class="form-control">
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <label class="form-label">Descrição <i class="fa fa-align-left text-muted"></i></label>
                                                                            <textarea name="descricao" id="edit-descricao" class="form-control" rows="2"></textarea>
                                                                        </div>
                                                                    </div>
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
                    <div class="card card-shadow p-3">
                        <h6 class="mb-2">Atividades recentes</h6>
                        <ul id="teamTimeline" class="list-unstyled mb-0">
                            <li class="mb-2 d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><i class="fa fa-check"></i></span>
                                <span><strong>João Silva</strong> concluiu tarefa <span class="text-success">"Revisar campanha de marketing"</span> <span class="text-muted">(Marketing)</span></span>
                                <span class="ms-auto small text-muted">Hoje, 09:15</span>
                            </li>
                            <li class="mb-2 d-flex align-items-center gap-2">
                                <span class="badge bg-warning"><i class="fa fa-hourglass-half"></i></span>
                                <span><strong>Maria Souza</strong> iniciou tarefa <span class="text-info">"Contato com cliente X"</span> <span class="text-muted">(Vendas)</span></span>
                                <span class="ms-auto small text-muted">Ontem, 16:40</span>
                            </li>
                            <li class="mb-2 d-flex align-items-center gap-2">
                                <span class="badge bg-danger"><i class="fa fa-exclamation-triangle"></i></span>
                                <span><strong>Equipe Técnica</strong> reportou atraso em <span class="text-danger">"Instalação sistema Y"</span></span>
                                <span class="ms-auto small text-muted">24/10, 11:20</span>
                            </li>
                            <li class="mb-2 d-flex align-items-center gap-2">
                                <span class="badge bg-success"><i class="fa fa-user-plus"></i></span>
                                <span><strong>Financeiro</strong> adicionou nova tarefa <span class="text-success">"Emitir nota fiscal"</span></span>
                                <span class="ms-auto small text-muted">23/10, 14:05</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card card-shadow p-3">
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
                    <div class="card card-shadow p-3 mt-3">
                        <h6 class="mb-2">Nova Tarefa</h6>
                        <form id="formNovaTarefa">
                            <div class="mb-2">
                                <label class="form-label">Equipe</label>
                                <select name="equipe" class="form-select form-select-sm" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($equipes as $eq): ?><option value="<?php echo $eq; ?>"><?php echo $eq; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Título</label>
                                <input type="text" name="titulo" class="form-control form-control-sm" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Descrição</label>
                                <textarea name="descricao" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Responsável</label>
                                <input type="text" name="responsavel" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Data de vencimento</label>
                                <input type="date" name="data_vencimento" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="Pendente">Pendente</option>
                                    <option value="Em andamento">Em andamento</option>
                                    <option value="Concluída">Concluída</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                        </form>
                        <div id="novaTarefaMsg" class="mt-2"></div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>

<script type="module">
import { fetchTasks, addTask, updateTask, deleteTask } from './assets/js/team_tasks.js';

const equipes = <?php echo json_encode($equipes); ?>;
const responsaveis = <?php echo json_encode($responsaveis); ?>;
const userId = <?php echo json_encode($_SESSION['user_id']); ?>;

// Funções para atualizar a lista de tarefas e a linha do tempo
async function atualizarTarefas() {
    const equipeFiltro = document.getElementById('filtroEquipe').value;
    const respFiltro = document.getElementById('filtroResp').value;
    const statusFiltro = document.getElementById('filtroStatus').value;
    const buscaFiltro = document.getElementById('filtroBusca').value;

    const tarefas = await fetchTasks({equipe: equipeFiltro, responsavel: respFiltro, status: statusFiltro});
    const list = document.getElementById('tasksList');
    list.innerHTML = '';
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
            // ...eventos de editar/excluir podem ser adicionados aqui...
            card.appendChild(actions);
            list.appendChild(card);
        });
    }
    // Atualizar contadores de tarefas por equipe
    <?php foreach ($equipes as $eq): ?>
        document.getElementById('count_<?php echo strtolower($eq); ?>').innerText = tarefas.filter(t => t.equipe === '<?php echo $eq; ?>').length;
    <?php endforeach; ?>

    // Funções auxiliares
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

// Evento de mudança nos filtros
document.getElementById('filtroEquipe').addEventListener('change', atualizarTarefas);
document.getElementById('filtroResp').addEventListener('change', atualizarTarefas);
document.getElementById('filtroStatus').addEventListener('change', atualizarTarefas);
document.getElementById('filtroBusca').addEventListener('input', atualizarTarefas);

// Evento de submissão do formulário de nova tarefa
const formNovaTarefa = document.getElementById('formNovaTarefa');
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

// Carregar tarefas iniciais
atualizarTarefas();
</script>
