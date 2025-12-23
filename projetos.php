<?php
// Ensure session and auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/includes/config.php';

$stmt = $pdo->prepare('SELECT * FROM projetos WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$_SESSION['user_id']]);
$projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div id="projetos">
            <h1 class="h4 mb-2">Projetos</h1>
            <div class="alert alert-info mb-4 text-center" style="max-width:700px;margin:auto;">
                <strong>Como funciona:</strong> Quando um lead avança por todas as etapas do funil e a venda é concluída, ele se transforma em um projeto. Aqui você acompanha todos os projetos gerados a partir dos leads convertidos, com informações detalhadas, status e histórico.
            </div>
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
                                <button class="btn btn-xs btn-outline-primary px-2 py-1" title="Editar"><i class="fa fa-edit"></i></button>
                                <button class="btn btn-xs btn-outline-info px-2 py-1" title="Histórico"><i class="fa fa-eye"></i></button>
                                <button class="btn btn-xs btn-outline-success px-2 py-1" title="Anexar"><i class="fa fa-paperclip"></i></button>
                                <?php if($p['status']!=='Concluído'): ?><button class="btn btn-xs btn-outline-success px-2 py-1" title="Marcar como concluído"><i class="fa fa-check"></i></button><?php endif; ?>
                                <?php if($p['status']!=='Atrasado'): ?><button class="btn btn-xs btn-outline-danger px-2 py-1" title="Marcar como atrasado"><i class="fa fa-exclamation-triangle"></i></button><?php endif; ?>
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
