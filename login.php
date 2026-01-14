<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'includes/config.php';
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// On the login page we don't want the global navbar to appear
$noNavbar = true;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $pdo->prepare('SELECT id, username, password, role_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_id'] = $user['role_id'];
        
        // Load permissions
        $stmt_perm = $pdo->prepare('SELECT screen FROM role_permissions WHERE role_id = ? AND allowed = 1');
        $stmt_perm->execute([$user['role_id']]);
        $_SESSION['permissions'] = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
        
        header('Location: index.php'); exit;
    } else { $error = 'Usuário ou senha incorretos!'; }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WR Solare CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --blue-700: #0b6ac1;
            --blue-900: #073b6b;
            --green: #4bbf4b;
            --yellow: #ffd24a;
        }
        
        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        
        .login-container {
            height: 100vh;
            width: 100vw;
            background: 
                linear-gradient(135deg, rgba(7, 59, 107, 0.7) 0%, rgba(11, 106, 193, 0.6) 50%, rgba(75, 191, 75, 0.5) 100%),
                url('assets/img/fundoplaca2.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            padding: 3rem;
            width: 420px;
            max-width: 90vw;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-image {
            max-width: 200px;
            max-height: 120px;
            width: auto;
            height: auto;
            margin-bottom: 1rem;
            filter: drop-shadow(0 10px 20px rgba(11, 106, 193, 0.3));
        }
        
        .company-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        .form-control:focus {
            border-color: var(--blue-700);
            box-shadow: 0 0 0 0.2rem rgba(11, 106, 193, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(45deg, var(--blue-700), var(--blue-900));
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            background: linear-gradient(45deg, var(--blue-900), var(--blue-700));
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(11, 106, 193, 0.3);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--blue-900);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .floating-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }
        
        .floating-element {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        .floating-element:nth-child(1) {
            width: 100px;
            height: 100px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .floating-element:nth-child(2) {
            width: 150px;
            height: 150px;
            top: 60%;
            right: 15%;
            animation-delay: 2s;
        }
        
        .floating-element:nth-child(3) {
            width: 80px;
            height: 80px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }
        
        .login-card {
            position: relative;
            z-index: 2;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Elementos flutuantes decorativos -->
        <div class="floating-elements">
            <div class="floating-element"></div>
            <div class="floating-element"></div>
            <div class="floating-element"></div>
        </div>
        
        <div class="login-card">
            <!-- Logo e branding -->
            <div class="logo-section">
                <img src="assets/img/logomarca.png" alt="WR Solare" class="logo-image">
                <!-- <div class="company-subtitle">Sistema de Gerenciamento</div> -->
            </div>
            
            <!-- Formulário de login -->
            <form method="POST">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fa-regular fa-user me-2"></i>Usuário
                    </label>
                    <input type="text" name="username" id="username" class="form-control" required autocomplete="username" placeholder="Digite seu usuário">
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="fa-regular fa-lock me-2"></i>Senha
                    </label>
                    <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password" placeholder="Digite sua senha">
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="fa-regular fa-right-to-bracket me-2"></i>Entrar no Sistema
                    </button>
                </div>
            </form>
            
            <!-- Rodapé do card -->
            <div class="text-center mt-4">
                <small class="text-muted">
                    <i class="fa-regular fa-shield-halved me-1"></i>
                    Acesso seguro e protegido
                </small>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>