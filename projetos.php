<?php
// Ensure session and auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

checkAccessOrRedirect('projetos');

$stmt = $pdo->prepare('SELECT * FROM projetos WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$_SESSION['user_id']]);
$projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div id="projetos">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h1 class="h4 mb-0">Projetos</h1>
                <div>
                    <button id="btnNovoProjeto" class="btn btn-primary btn-sm">Novo Projeto</button>
                </div>
            </div>
            <!-- Informational blurb removed as requested -->
            <!-- KPIs -->
            <div class="row g-3 mb-4 justify-content-center">
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Total de Projetos</div>
                        <div class="h4 mb-0"><?= count($projetos) ?></div>
                        <i class="fa fa-folder-open fa-2x text-primary"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Concluídos</div>
                        <div class="h4 mb-0"><?= count(array_filter($projetos, fn($p)=>$p['status']==='Concluído')) ?></div>
                        <i class="fa fa-check fa-2x text-success"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Atrasados</div>
                        <div class="h4 mb-0"><?= count(array_filter($projetos, fn($p)=>$p['status']==='Atrasado')) ?></div>
                        <i class="fa fa-exclamation-triangle fa-2x text-danger"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Ticket Médio</div>
                        <div class="h4 mb-0">
                            <?php $avg = count($projetos) ? array_sum(array_column($projetos,'proposal_value'))/count($projetos) : 0; echo 'R$ '.number_format($avg,2,',','.'); ?>
                        </div>
                        <i class="fa fa-dollar-sign fa-2x text-warning"></i>
                    </div>
                </div>
            </div>
            <!-- Filtros -->
            <div class="d-flex gap-2 mb-4 flex-wrap justify-content-center">
                <select id="filtroStatus" class="form-select form-select-sm w-auto">
                    <option value="">Todos status</option>
                    <option value="Prospecção">Prospecção</option>
                    <option value="Em andamento">Em andamento</option>
                    <option value="Concluído">Concluído</option>
                    <option value="Atrasado">Atrasado</option>
                </select>
                <input type="search" id="filtroBusca" class="form-control form-control-sm w-50" placeholder="Buscar projeto ou cliente...">
            </div>
            <!-- Cards de projetos -->
            <div class="row g-4" id="cardsProjetos">
                <?php foreach($projetos as $p): ?>
                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch">
                    <div class="card h-100 shadow-sm w-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge" style="background:#0b6ac1;color:#fff;">Projeto #<?= $p['id'] ?></span>
                                <span class="badge <?= $p['status']==='Concluído'?'bg-success':($p['status']==='Atrasado'?'bg-danger':'bg-warning') ?>"><?= htmlspecialchars($p['status']) ?></span>
                            </div>
                            <h5 class="mb-1"><i class="fa fa-user text-muted"></i> <?= htmlspecialchars($p['client_name']) ?></h5>
                            <div class="mb-1"><i class="fa fa-map-marker-alt text-muted"></i> <?= htmlspecialchars($p['address']) ?></div>
                            <div class="mb-1"><i class="fa fa-dollar-sign text-warning"></i> <strong>R$ <?= number_format($p['proposal_value'],2,',','.') ?></strong></div>
                            <?php if(!empty($p['closed_date'])): ?>
                            <div class="mb-1"><i class="fa fa-calendar-check text-success"></i> Fechado em <?= date('d/m/Y', strtotime($p['closed_date'])) ?></div>
                            <?php endif; ?>
                            <div class="mb-2"><i class="fa fa-file-alt text-info"></i> <span class="small">Contrato: <?= !empty($p['contract']) ? htmlspecialchars($p['contract']) : '—' ?></span></div>
                            <div class="mb-2"><i class="fa fa-history text-muted"></i> <span class="small">Última atualização: <?= date('d/m/Y H:i', strtotime($p['updated_at'])) ?></span></div>
                            <div class="d-flex flex-wrap gap-1 mt-2 justify-content-start">
                                <button class="btn btn-xs btn-outline-primary px-2 py-1 btn-edit" data-id="<?= $p['id'] ?>" title="Editar"><i class="fa fa-edit"></i></button>
                                <button class="btn btn-xs btn-outline-info px-2 py-1 btn-view" data-id="<?= $p['id'] ?>" title="Histórico"><i class="fa fa-eye"></i></button>
                                <button class="btn btn-xs btn-outline-secondary px-2 py-1 btn-attach" data-id="<?= $p['id'] ?>" title="Anexar"><i class="fa fa-paperclip"></i></button>
                                <?php if($p['status']!=='Concluído'): ?><button class="btn btn-xs btn-outline-success px-2 py-1 btn-mark-complete" data-id="<?= $p['id'] ?>" title="Marcar como concluído"><i class="fa fa-check"></i></button><?php endif; ?>
                                <?php if($p['status']!=='Atrasado'): ?><button class="btn btn-xs btn-outline-danger px-2 py-1 btn-mark-delayed" data-id="<?= $p['id'] ?>" title="Marcar como atrasado"><i class="fa fa-exclamation-triangle"></i></button><?php endif; ?>
                                <button class="btn btn-xs btn-outline-danger px-2 py-1 btn-delete" data-id="<?= $p['id'] ?>" title="Excluir"><i class="fa fa-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>

<div class="modal fade" id="modalProjeto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Projeto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formProjeto">
                <div class="modal-body">
                    <input type="hidden" name="id" id="proj_id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Cliente</label>
                            <input class="form-control" name="client_name" id="proj_client_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Valor Proposta</label>
                            <input class="form-control" name="proposal_value" id="proj_proposal_value">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Endereço</label>
                            <input class="form-control" name="address" id="proj_address">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="proj_status">
                                <option>Prospecção</option>
                                <option>Em andamento</option>
                                <option>Concluído</option>
                                <option>Atrasado</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Data de fechamento</label>
                            <input type="date" class="form-control" name="closed_date" id="proj_closed_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Contrato / Observações</label>
                            <textarea class="form-control" name="contract" id="proj_contract" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function(){
        const modalEl = document.getElementById('modalProjeto');
        const bsModal = new bootstrap.Modal(modalEl);

        document.getElementById('btnNovoProjeto').addEventListener('click', ()=>{
            document.getElementById('formProjeto').reset();
            document.getElementById('proj_id').value = '';
            bsModal.show();
        });

        // Delegate card buttons
        document.getElementById('cardsProjetos').addEventListener('click', async (e)=>{
            const edit = e.target.closest('.btn-edit');
            const del = e.target.closest('.btn-delete');
            const complete = e.target.closest('.btn-mark-complete');
            const delayed = e.target.closest('.btn-mark-delayed');
            if (edit) {
                const id = edit.getAttribute('data-id');
                const res = await fetch('api/get_project.php?id='+encodeURIComponent(id));
                const j = await res.json();
                if (j.success) {
                    const p = j.data;
                    document.getElementById('proj_id').value = p.id;
                    document.getElementById('proj_client_name').value = p.client_name;
                    document.getElementById('proj_proposal_value').value = p.proposal_value;
                    document.getElementById('proj_address').value = p.address;
                    document.getElementById('proj_status').value = p.status;
                    document.getElementById('proj_closed_date').value = p.closed_date ? p.closed_date.split(' ')[0] : '';
                    document.getElementById('proj_contract').value = p.contract || '';
                    bsModal.show();
                } else alert(j.message || 'Erro ao carregar projeto');
            }

            if (del) {
                const id = del.getAttribute('data-id');
                if (!confirm('Confirma exclusão do projeto #' + id + '?')) return;
                const f = new FormData(); f.append('id', id);
                const res = await fetch('api/delete_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao excluir');
            }

            if (complete) {
                const id = complete.getAttribute('data-id');
                if (!confirm('Marcar como concluído?')) return;
                const f = new FormData();
                f.append('id', id);
                f.append('status', 'Concluído');
                f.append('closed_date', new Date().toISOString().split('T')[0]);
                const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao atualizar');
            }

            if (delayed) {
                const id = delayed.getAttribute('data-id');
                if (!confirm('Marcar como atrasado?')) return;
                const f = new FormData();
                f.append('id', id);
                f.append('status', 'Atrasado');
                const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao atualizar');
            }
        });

        document.getElementById('formProjeto').addEventListener('submit', async (ev)=>{
            ev.preventDefault();
            const form = ev.target;
            const data = new FormData(form);
            const id = document.getElementById('proj_id').value;
            const url = id ? 'api/update_project.php' : 'api/add_project.php';
            const res = await fetch(url, { method: 'POST', body: data });
            const j = await res.json();
            if (j.success) location.reload(); else alert(j.message || 'Erro ao salvar');
        });
    })();
</script>
