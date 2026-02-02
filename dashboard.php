<?php
// Ensure session and DB
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

checkAccessOrRedirect('dashboard');

// Show aggregated data for all users (no user_id filtering)
// Active leads (not converted/lost)
$totalLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE deleted = 0 AND status NOT IN ('Convertido','Perdido')")->fetchColumn();

// Projects in progress (not finalized or lost)
$totalProjetos = $pdo->query("SELECT COUNT(*) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')")->fetchColumn();

// Value currently in negotiation (open proposals)
$valorNegociacao = $pdo->query("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')")->fetchColumn();

// Closed / won projects
$projetosFinalizados = $pdo->query("SELECT COUNT(*) FROM projetos WHERE status='Finalizado'")->fetchColumn();

// Total contracted value (finalized projects)
$valorContratado = $pdo->query("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE status='Finalizado'")->fetchColumn();

// Additional metrics
// All leads and conversion
$totalLeadsAll = $pdo->query("SELECT COUNT(*) FROM leads WHERE deleted = 0")->fetchColumn();
$conversionRate = $totalLeadsAll > 0 ? round(($projetosFinalizados / $totalLeadsAll) * 100, 1) : 0;

// New leads in last 30 days
$newLeads30 = $pdo->query("SELECT COUNT(*) FROM leads WHERE deleted = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

// Average proposal value (all non-zero proposals)
$avgProposal = $pdo->query("SELECT IFNULL(AVG(NULLIF(proposal_value,0)),0) FROM projetos WHERE proposal_value > 0")->fetchColumn();

// Revenue forecast = sum of open proposals * conversionRate (simple forecast)
$openProposalsSum = $pdo->query("SELECT IFNULL(SUM(proposal_value),0) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')")->fetchColumn();
$revenueForecast = round(($openProposalsSum * ($conversionRate / 100)), 2);

// Leads by status for chart
$leadsStatusData = $pdo->query("SELECT status, COUNT(*) as count FROM leads WHERE deleted = 0 GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

// Leads by source (for bar chart)
$leadsSourceData = $pdo->query("SELECT IFNULL(source,'(Indefinido)') as source, COUNT(*) as count FROM leads WHERE deleted = 0 GROUP BY source ORDER BY count DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// Monthly leads (last 12 months)
$monthlyLeadsData = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE deleted = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym")->fetchAll(PDO::FETCH_ASSOC);

// Top open opportunities (by proposal value)
$topOpps = $pdo->query("SELECT id, client_name, proposal_value, status, created_at FROM projetos ORDER BY proposal_value DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

// Recent leads
$recentLeads = $pdo->query("SELECT name, email, status, created_at FROM leads WHERE deleted = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
// include sidebar so navigation is visible
include 'includes/sidebar.php';
?>
<main class="flex-grow-1 p-4 main-content-scroll">
        <div id="dashboard" class="container-fluid">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="h4 mb-1 fw-bold text-dark">Bem-vindo ao SolarCRM, <?= htmlspecialchars(
                        $_SESSION['username'] ?? 'Usuário') ?>!</h1>
                    <p class="text-muted mb-0">Visão geral rápida das métricas e atividades recentes.</p>
                </div>
            </div>
            <hr>
        
        <!-- Metrics Cards -->
            <div class="row g-4 mb-5">
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Leads Ativos</h6>
                    <div class="fs-2 fw-bold text-primary mb-1"><?= $totalLeads ?></div>
                    <small class="text-muted">Em andamento</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Novos (30d)</h6>
                    <div class="fs-2 fw-bold text-primary mb-1"><?= $newLeads30 ?></div>
                    <small class="text-muted">Últimos 30 dias</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Projetos em Andamento</h6>
                    <div class="fs-2 fw-bold text-success mb-1"><?= $totalProjetos ?></div>
                    <small class="text-muted">Não finalizados</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Valor em Negociação</h6>
                    <div class="fs-2 fw-bold text-warning mb-1">R$ <?= number_format($valorNegociacao,2,',','.') ?></div>
                    <small class="text-muted">Total proposto</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Taxa de Conversão</h6>
                    <div class="fs-2 fw-bold text-info mb-1"><?= $conversionRate ?>%</div>
                    <small class="text-muted">Projetos finalizados</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Contratado (Total)</h6>
                    <div class="fs-2 fw-bold text-success mb-1">R$ <?= number_format($valorContratado,2,',','.') ?></div>
                    <small class="text-muted">Projetos finalizados</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Média Proposta</h6>
                    <div class="fs-2 fw-bold text-secondary mb-1">R$ <?= number_format($avgProposal,2,',','.') ?></div>
                    <small class="text-muted">Valor médio</small>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="dashboard-modern-card p-4 text-center">
                    <h6 class="mb-2 text-muted">Forecast R$</h6>
                    <div class="fs-2 fw-bold text-warning mb-1">R$ <?= number_format($revenueForecast,2,',','.') ?></div>
                    <small class="text-muted">Estimativa simples</small>
                </div>
            </div>
        </div>
        
        <!-- Charts and Recent Activity -->
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="dashboard-modern-card p-4">
                    <h5 class="mb-3">Leads por Status</h5>
                    <canvas id="leadsChart" width="400" height="200"></canvas>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="dashboard-modern-card p-4 mb-4">
                    <h5 class="mb-3">Leads - Últimos 12 meses</h5>
                    <canvas id="monthlyLeadsChart" width="400" height="200"></canvas>
                </div>
                <div class="dashboard-modern-card p-4">
                    <h5 class="mb-3">Leads por Fonte</h5>
                    <canvas id="sourcesChart" width="400" height="140"></canvas>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-3">
            <div class="col-12 col-lg-8">
                <div class="dashboard-modern-card p-4">
                    <h5 class="mb-3">Principais Oportunidades</h5>
                    <div class="table-responsive">
                        <table class="table table-borderless align-middle">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Valor (R$)</th>
                                    <th>Status</th>
                                    <th>Aberto em</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topOpps as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['client_name']) ?></td>
                                    <td>R$ <?= number_format($p['proposal_value'],2,',','.') ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['status']) ?></span></td>
                                    <td><?= htmlspecialchars($p['created_at']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="dashboard-modern-card p-4">
                    <h5 class="mb-3">Leads Recentes</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentLeads as $lead): ?>
                        <div class="list-group-item px-0 py-2">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong class="text-dark"><?= htmlspecialchars($lead['name']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($lead['email']) ?></small>
                                </div>
                                <span class="badge bg-secondary"><?= htmlspecialchars($lead['status']) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Leads by status (doughnut)
    const ctxStatus = document.getElementById('leadsChart').getContext('2d');
    const leadsData = <?php echo json_encode($leadsStatusData); ?>;
    const labels = leadsData.map(d => d.status);
    const data = leadsData.map(d => parseInt(d.count,10));
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: data, backgroundColor: ['#0b6ac1','#28a745','#ffc107','#dc3545','#6c757d','#17a2b8','#8e44ad','#e67e22'], borderWidth: 1 }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Monthly leads (line)
    const monthly = <?php echo json_encode($monthlyLeadsData); ?>;
    // normalize months (ensure last 12 months present)
    const months = [];
    const counts = [];
    const now = new Date();
    for(let i=11;i>=0;i--) {
        const m = new Date(now.getFullYear(), now.getMonth()-i, 1);
        const ym = m.toISOString().slice(0,7);
        months.push(m.toLocaleString(undefined,{month:'short', year:'numeric'}));
        const found = monthly.find(x=>x.ym === ym);
        counts.push(found ? parseInt(found.cnt,10) : 0);
    }
    const ctxMonthly = document.getElementById('monthlyLeadsChart').getContext('2d');
    new Chart(ctxMonthly, {
        type: 'line',
        data: { labels: months, datasets: [{ label: 'Leads', data: counts, borderColor: '#0b6ac1', backgroundColor: 'rgba(11,106,193,0.08)', tension:0.3 }] },
        options: { responsive:true, plugins:{legend:{display:false}} }
    });

    // Leads by source (bar)
    const sources = <?php echo json_encode($leadsSourceData); ?>;
    const srcLabels = sources.map(s=>s.source);
    const srcCounts = sources.map(s=>parseInt(s.count,10));
    const ctxSources = document.getElementById('sourcesChart').getContext('2d');
    new Chart(ctxSources, {
        type: 'bar',
        data: { labels: srcLabels, datasets: [{ label:'Leads', data: srcCounts, backgroundColor: '#4bbf4b' }] },
        options: { indexAxis: 'y', responsive:true, plugins:{legend:{display:false}} }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
