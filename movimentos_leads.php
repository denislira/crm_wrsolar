<?php
// Tela interna/oculta: histórico geral dos movimentos do Kanban.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
checkAccessOrRedirect('relatorios');

$limit = min(200, max(20, (int)($_GET['limit'] ?? 50)));
$rows = [];
$error = null;
try {
    $sql = "SELECT lm.id, lm.lead_id, l.name AS lead_name,
                   COALESCE(fs_from.stage_name, lm.from_status, '—') AS from_label,
                   COALESCE(fs_to.stage_name, lm.to_status, '—') AS to_label,
                   COALESCE(u.username, 'Usuário') AS username,
                   lm.note, lm.created_at
            FROM lead_movements lm
            LEFT JOIN leads l ON l.id = lm.lead_id
            LEFT JOIN funil_stages fs_from ON fs_from.id = lm.from_stage_id
            LEFT JOIN funil_stages fs_to ON fs_to.id = lm.to_stage_id
            LEFT JOIN users u ON u.id = COALESCE(lm.changed_by, lm.user_id)
            ORDER BY lm.created_at DESC, lm.id DESC
            LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'A tabela de movimentações ainda não está disponível.';
}
$pageTitle = 'Últimos movimentos do Kanban';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-grow-1 p-4 main-content-scroll">
  <div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h3 mb-1">Últimos movimentos do Kanban</h1>
        <p class="text-muted mb-0">Histórico geral de alterações de etapa/status dos leads.</p>
      </div>
      <a href="relatorios.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i> Relatórios</a>
    </div>
    <div class="card shadow-sm border-0">
      <div class="card-body p-0">
        <?php if ($error): ?>
          <div class="alert alert-warning m-3 mb-0"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (!$rows): ?>
          <div class="text-center text-muted py-5">Nenhuma movimentação registrada.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>Data/hora</th><th>Lead</th><th>De</th><th>Para</th><th>Usuário</th><th>Observação</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="text-nowrap"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($row['created_at']))); ?></td>
                  <td><a href="leads_gestao.php?lead_id=<?php echo (int)$row['lead_id']; ?>"><?php echo htmlspecialchars($row['lead_name'] ?: ('Lead #' . $row['lead_id'])); ?></a></td>
                  <td><?php echo htmlspecialchars($row['from_label']); ?></td>
                  <td><strong><?php echo htmlspecialchars($row['to_label']); ?></strong></td>
                  <td><?php echo htmlspecialchars($row['username']); ?></td>
                  <td class="text-muted small"><?php echo htmlspecialchars($row['note'] ?: '—'); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
