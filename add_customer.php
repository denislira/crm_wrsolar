<?php
// add_customer.php - form + handler to create a new customer
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';

    // Use DB defaults for timestamps (don't require updated_at column to exist)
    $stmt = $pdo->prepare('INSERT INTO customers (user_id, name, email, phone, address) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$_SESSION['user_id'], $name, $email, $phone, $address]);
    header('Location: customers.php');
    exit;
}

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container-fluid">
            <h1 class="h4 mb-3">Adicionar Cliente</h1>
            <div class="card card-shadow p-3">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input name="name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="mb-3 col-md-6">
                            <label class="form-label">Email</label>
                            <input name="email" class="form-control" type="email">
                        </div>
                        <div class="mb-3 col-md-6">
                            <label class="form-label">Telefone</label>
                            <input name="phone" class="form-control" type="tel">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Endereço</label>
                        <textarea name="address" class="form-control"></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <a href="customers.php" class="btn btn-secondary me-2">Cancelar</a>
                        <button class="btn btn-primary" type="submit">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
