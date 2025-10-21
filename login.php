<?php
session_start(); // Inicia a sessão
include 'includes/config.php'; // Conexão com o BD

// Se já está logado, redireciona para o dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Processar o formulário de login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Buscar usuário no banco
    $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Verificar se usuário existe e senha está correta
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha incorretos!';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - SolarCRM</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css"> <!-- Atualizado para assets/css -->
</head>
<body>
    <div class="container mt-5">
        <h2>Login - SolarCRM</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Usuário</label>
                <input type="text" class="form-control" id="username" name="username" required autocomplete="off">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Senha</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>
    </div>
    <script src="assets/js/bootstrap.bundle.min.js"></script> <!-- Atualizado para assets/js -->

    <!-- <a href="criar_usuario.php">Criar Usuário</a> -->
</body>
</html>