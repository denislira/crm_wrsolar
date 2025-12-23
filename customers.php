<?php
// Ensure session is started without causing notices if already started elsewhere
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'includes/config.php';
// Buscar clientes do banco
$stmt = $pdo->prepare('SELECT * FROM customers WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$customers = $stmt->fetchAll();
include 'includes/header.php';
?>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">Clientes</h1>
                <a href="add_customer.php" class="btn btn-primary">Adicionar Cliente</a>
            </div>
            <div class="card card-shadow p-3">
                <?php if (empty($customers)): ?>
                    <p>Nenhum cliente cadastrado.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Telefone</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone'] ?? ''); ?></td>
                                        <td>
                                            <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-warning">Editar</a>
                                            <a href="delete_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza que deseja excluir?');">Excluir</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>