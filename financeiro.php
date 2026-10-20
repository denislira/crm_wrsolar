<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/financeiro_lib.php';
require_once __DIR__ . '/includes/financeiro_crm_sync.php';
checkAccessOrRedirect('financeiro');

$syncNotice = '';
$syncError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'sincronizar_concluidos') {
    try {
        fin_check_token();
        $syncStmt = $pdo->query("SELECT l.id
            FROM leads l
            INNER JOIN funil_stages fs ON fs.id = l.stage_id
            WHERE COALESCE(l.deleted, 0) = 0
              AND (COALESCE(fs.is_conversion, 0) = 1 OR COALESCE(fs.final_type, 'none') = 'won')");
        $syncLeadIds = $syncStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($syncLeadIds as $syncLeadId) {
            financeiro_sincronizar_lead_ganho($pdo, (int)$syncLeadId);
        }
        $syncNotice = 'Sincronização concluída. Leads processados: ' . count($syncLeadIds) . '.';
    } catch (Throwable $e) {
        $syncError = 'Não foi possível sincronizar os leads concluídos: ' . $e->getMessage();
        error_log('Financeiro backfill web: ' . $e->getMessage());
    }
}

$tableExists = false;
$vendas = [];
$totals = ['total' => 0, 'aceito' => 0, 'aguardando_projeto' => 0, 'dados_incompletos' => 0, 'aguardando_conferencia' => 0, 'em_revisao' => 0];
$perPage = 20;
$page = max(1, (int)($_GET['pagina'] ?? 1));
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'pendentes');
if (!in_array($statusFilter, ['pendentes', 'aceito', 'todos'], true)) $statusFilter = 'pendentes';
$filteredCount = 0;
$totalPages = 1;

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'financeiro_vendas'");
    $check->execute();
    $tableExists = (bool)$check->fetchColumn();

    if ($tableExists) {
        $statusRows = $pdo->query('SELECT status, COUNT(*) AS total FROM financeiro_vendas GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statusRows as $statusRow) {
            $count = (int)$statusRow['total'];
            $totals['total'] += $count;
            if (isset($totals[$statusRow['status']])) $totals[$statusRow['status']] = $count;
        }

        $where = '1=1'; $params = [];
        if ($statusFilter === 'pendentes') $where .= " AND fv.status <> 'aceito'";
        elseif ($statusFilter === 'aceito') $where .= " AND fv.status = 'aceito'";
        if ($search !== '') { $where .= ' AND (fv.client_name LIKE ? OR fv.cpf_cnpj LIKE ? OR fv.phone LIKE ?)'; $like = '%' . $search . '%'; $params = [$like, $like, $like]; }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM financeiro_vendas fv WHERE ' . $where);
        $countStmt->execute($params); $filteredCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listStmt = $pdo->prepare('SELECT fv.*, p.status AS project_status
            FROM financeiro_vendas fv
            LEFT JOIN projetos p ON p.id = fv.project_id
            WHERE ' . $where . '
            ORDER BY FIELD(fv.status, \'aguardando_conferencia\', \'dados_incompletos\', \'aceito\', \'aguardando_projeto\', \'em_revisao\'), fv.updated_at DESC, fv.id DESC
            LIMIT ' . $offset . ', ' . $perPage);
        $listStmt->execute($params); $vendas = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $tableExists = false;
}

function financeiro_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function financeiro_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function financeiro_status_label(string $status): string {
    return [
        'aceito' => 'Aceito',
        'aguardando_projeto' => 'Aguardando projeto',
        'dados_incompletos' => 'Dados incompletos',
        'aguardando_conferencia' => 'Aguardando conferência',
        'em_revisao' => 'Em revisão',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content-scroll py-4">
  <div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
      <div>
        <h1 class="h3 mb-1">Financeiro</h1>
        <p class="text-muted mb-0">Vendas do CRM e importadas para conferência financeira.</p>
      </div>
      <span class="badge text-bg-primary px-3 py-2">Conferência de vendas</span>
    </div>
    <?php $financeiroView = 'entradas'; include __DIR__ . '/includes/financeiro_nav.php'; ?>

    <?php if ($syncNotice): ?><div class="alert alert-success"><?= financeiro_h($syncNotice) ?></div><?php endif; ?>
    <?php if ($syncError): ?><div class="alert alert-danger"><?= financeiro_h($syncError) ?></div><?php endif; ?>

    <?php if (!$tableExists): ?>
      <div class="alert alert-warning">A base do Financeiro ainda não foi instalada. Execute a migração <code>database/create_financeiro_base.sql</code>.</div>
    <?php else: ?>
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Vendas cadastradas</div><div class="fs-3 fw-bold"><?= $totals['total'] ?></div></div></div></div>
        <div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Aguardando conferência</div><div class="fs-3 fw-bold text-primary"><?= $totals['aguardando_conferencia'] ?></div></div></div></div>
        <div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Dados incompletos</div><div class="fs-3 fw-bold text-warning"><?= $totals['dados_incompletos'] ?></div></div></div></div>
        <div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Aguardando projeto</div><div class="fs-3 fw-bold text-secondary"><?= $totals['aguardando_projeto'] ?></div></div></div></div>
      </div>

      <form method="get" class="row g-2 mb-3"><div class="col-md-6"><input class="form-control" name="q" value="<?= financeiro_h($search) ?>" placeholder="Buscar cliente, CPF/CNPJ ou telefone"></div><div class="col-md-3"><select class="form-select" name="status"><option value="pendentes" <?= $statusFilter==='pendentes'?'selected':'' ?>>Pendentes</option><option value="aceito" <?= $statusFilter==='aceito'?'selected':'' ?>>Aceitas</option><option value="todos" <?= $statusFilter==='todos'?'selected':'' ?>>Todas</option></select></div><div class="col-md-3"><button class="btn btn-outline-primary">Filtrar</button></div></form>
      <form method="post" class="mb-3" onsubmit="return confirm('Sincronizar todos os leads em Venda concluída agora?');"><input type="hidden" name="action" value="sincronizar_concluidos"><input type="hidden" name="csrf" value="<?= financeiro_h(fin_token()) ?>"><button class="btn btn-outline-secondary" type="submit">Sincronizar leads concluídos</button></form>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
          <strong>Fila de conferência</strong>
          <small class="text-muted">Mostrando <?= count($vendas) ?> de <?= $filteredCount ?> entradas filtradas.</small>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Cliente</th><th>Origem</th><th>Projeto</th><th>Valor negociado</th><th>Forma de pagamento</th><th>Situação</th><th>Atualizado</th><th>Ação</th></tr></thead>
            <tbody>
            <?php if (!$vendas): ?>
              <tr><td colspan="8" class="text-center text-muted py-5">Nenhuma venda concluída aguardando entrada financeira.</td></tr>
            <?php else: foreach ($vendas as $venda): ?>
              <tr>
                <td><div class="fw-semibold"><?= financeiro_h($venda['client_name']) ?></div><small class="text-muted"><?= financeiro_h($venda['phone'] ?: $venda['email'] ?: 'Sem contato') ?></small></td>
                <td><span class="badge text-bg-light border"><?= financeiro_h(strtoupper($venda['origem'])) ?></span></td>
                <td><?= $venda['project_id'] ? '<a href="projetos.php">#' . (int)$venda['project_id'] . '</a>' : '<span class="text-muted">Não criado</span>' ?></td>
                <td><?= $venda['valor_negociado'] !== null ? financeiro_money($venda['valor_negociado']) : '<span class="text-muted">Não informado</span>' ?></td>
                <td><?= financeiro_h($venda['forma_pagamento'] ?: 'Não informada') ?></td>
                <td><span class="badge rounded-pill text-bg-<?= $venda['status'] === 'aceito' ? 'success' : ($venda['status'] === 'aguardando_conferencia' ? 'primary' : ($venda['status'] === 'dados_incompletos' ? 'warning' : ($venda['status'] === 'em_revisao' ? 'danger' : 'secondary'))) ?>"><?= financeiro_h(financeiro_status_label($venda['status'])) ?></span></td>
                <td><small><?= financeiro_h(date('d/m/Y H:i', strtotime($venda['updated_at']))) ?></small></td>
                <td><a class="btn btn-sm btn-outline-primary" href="financeiro_venda.php?id=<?= (int)$venda['id'] ?>">Conferir</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
          <small class="text-muted">Página <?= $page ?> de <?= $totalPages ?></small>
          <nav aria-label="Paginação das entradas financeiras">
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="financeiro.php?<?= financeiro_h(http_build_query(['pagina'=>$page-1,'q'=>$search,'status'=>$statusFilter])) ?>" aria-label="Página anterior">Anterior</a>
              </li>
              <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($currentPage = $startPage; $currentPage <= $endPage; $currentPage++):
              ?>
                <li class="page-item <?= $currentPage === $page ? 'active' : '' ?>">
                  <a class="page-link" href="financeiro.php?<?= financeiro_h(http_build_query(['pagina'=>$currentPage,'q'=>$search,'status'=>$statusFilter])) ?>"><?= $currentPage ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="financeiro.php?<?= financeiro_h(http_build_query(['pagina'=>$page+1,'q'=>$search,'status'=>$statusFilter])) ?>" aria-label="Próxima página">Próxima</a>
              </li>
            </ul>
          </nav>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
