<?php
// Ensure session and auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

checkAccessOrRedirect('pos-venda');

$stmt = $pdo->prepare('SELECT p.client_name, pv.* FROM pos_venda pv JOIN projetos p ON pv.project_id=p.id WHERE pv.user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$posVendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div id="pos-venda">
            <ul class="nav nav-tabs mb-4" id="posVendaTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-acompanhamento" data-bs-toggle="tab" data-bs-target="#tabAcompanhamento" type="button" role="tab">Acompanhamento</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-feedback" data-bs-toggle="tab" data-bs-target="#tabFeedback" type="button" role="tab">Feedback dos Clientes</button>
                </li>
            </ul>
            <div class="tab-content" id="posVendaTabsContent">
                <div class="tab-pane fade show active" id="tabAcompanhamento" role="tabpanel">
            <h1 class="h4 mb-3">Acompanhamento Pós-venda</h1>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Total de Instalações</div>
                        <div class="h4 mb-0"><?= count($posVendas) ?></div>
                        <i class="fa fa-tools fa-2x text-primary"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Manutenções Pendentes</div>
                        <div class="h4 mb-0"><?= count(array_filter($posVendas, fn($pv)=>!empty($pv['next_maintenance']) && strtotime($pv['next_maintenance'])>time())) ?></div>
                        <i class="fa fa-calendar-alt fa-2x text-warning"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3 text-center">
                        <div class="small text-muted">Garantias Ativas</div>
                        <div class="h4 mb-0"><?= count(array_filter($posVendas, fn($pv)=>!empty($pv['warranty_end']) && strtotime($pv['warranty_end'])>time())) ?></div>
                        <i class="fa fa-shield-alt fa-2x text-success"></i>
                    </div>
                </div>
            </div>
            <div class="row g-3" id="cardsPosVenda">
                <?php foreach($posVendas as $pv): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge bg-primary">Instalação</span>
                                <span class="badge bg-success">Garantia ativa</span>
                            </div>
                            <h5 class="mb-1"><i class="fa fa-user text-muted"></i> <?= htmlspecialchars($pv['client_name']) ?></h5>
                            <div class="mb-1"><i class="fa fa-calendar-check text-success"></i> Instalação: <?= date('d/m/Y', strtotime($pv['installation_date'])) ?></div>
                            <div class="mb-1"><i class="fa fa-calendar-alt text-warning"></i> Próx. manutenção: <?= date('d/m/Y', strtotime($pv['next_maintenance'])) ?></div>
                            <div class="mb-1"><i class="fa fa-shield-alt text-info"></i> Garantia até <?= date('d/m/Y', strtotime($pv['warranty_end'])) ?></div>
                            <div class="mb-2"><i class="fa fa-sticky-note text-muted"></i> <span class="small">Notas: <?= !empty($pv['notes']) ? htmlspecialchars($pv['notes']) : '—' ?></span></div>
                            <div class="d-flex gap-2 mt-2">
                                <button class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i> Editar</button>
                                <button class="btn btn-sm btn-outline-success"><i class="fa fa-calendar-check"></i> Marcar manutenção</button>
                                <button class="btn btn-sm btn-outline-info"><i class="fa fa-history"></i> Histórico</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
                </div>
                <div class="tab-pane fade" id="tabFeedback" role="tabpanel">
                    <p>Nenhum feedback de clientes disponível no momento.</p>
                </div>
            </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
