<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'includes/config.php';

function isConsultorRoleFromSessionOrUser($roleId) {
    // Only treat as external consultant when role is 'consultor_externo'
    global $pdo;
    if ($roleId === null) return false;

    $roleName = null;
    if (is_numeric($roleId)) {
        $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([(int)$roleId]);
        $roleName = $stmt->fetchColumn();
    } else {
        $roleName = (string)$roleId;
    }

    if (!$roleName) return false;

    return strtolower($roleName) === 'consultor_externo';
}

if (isset($_SESSION['user_id'])) {
    $alreadyRoleId = $_SESSION['role_id'] ?? null;
    if (isConsultorRoleFromSessionOrUser($alreadyRoleId)) {
        header('Location: consultoria_externa.php');
        exit;
    }
    header('Location: index.php');
    exit;
}

// On the login page we don't want the global navbar to appear
$noNavbar = true;

$loginBackground = 'assets/img/fundoplaca2.jpg';
$settingsPath = __DIR__ . '/storage/settings.json';
if (file_exists($settingsPath)) {
    $rawAppearance = @file_get_contents($settingsPath);
    $appearanceSettings = $rawAppearance ? json_decode($rawAppearance, true) : [];
    if (!empty($appearanceSettings['login_background'])) {
        $candidate = ltrim((string)$appearanceSettings['login_background'], '/\\');
        $fullPath = __DIR__ . '/' . $candidate;
        if (file_exists($fullPath)) {
            $loginBackground = $candidate;
        }
    }
}

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
        // store role name for easier checks in the session
        $stmt_role = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt_role->execute([$user['role_id']]);
        $_SESSION['role_name'] = $stmt_role->fetchColumn();
        
        // Load permissions
        $stmt_perm = $pdo->prepare('SELECT screen FROM role_permissions WHERE role_id = ? AND allowed = 1');
        $stmt_perm->execute([$user['role_id']]);
        $_SESSION['permissions'] = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);

        if (isConsultorRoleFromSessionOrUser($user['role_id'])) {
            header('Location: consultoria_externa.php');
            exit;
        }

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
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/fontawesome.min.css" rel="stylesheet">
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
                url('<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>');
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
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(24px) saturate(150%);
            border-radius: 28px;
            box-shadow: 0 28px 68px rgba(8, 35, 75, 0.18);
            padding: 3rem;
            width: 420px;
            max-width: 90vw;
            border: 1px solid rgba(255, 255, 255, 0.32);
            position: relative;
            overflow: hidden;
            background-clip: padding-box;
        }

        .login-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(11, 106, 193, 0.12), transparent 35%),
                        radial-gradient(circle at bottom right, rgba(75, 191, 75, 0.1), transparent 28%);
            pointer-events: none;
        }

        .login-card > * {
            position: relative;
            z-index: 1;
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
        /* Lighter border for password eye toggle */
        #togglePassword {
            border-color: rgba(11,106,193,0.12) !important;
            border-width: 1px !important;
            background: transparent !important;
            color: var(--blue-900);
        }
        #togglePassword:hover {
            border-color: rgba(11,106,193,0.18) !important;
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
                        <i class="fa fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fa fa-user me-2"></i>Usuário
                    </label>
                    <input type="text" name="username" id="username" class="form-control" required autocomplete="username" placeholder="Digite seu usuário">
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="fa fa-lock me-2"></i>Senha
                    </label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password" placeholder="Digite sua senha">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="Mostrar senha"><i class="fa fa-eye"></i></button>
                    </div>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="fa fa-sign-in-alt me-2"></i>Entrar no Sistema
                    </button>
                </div>
            </form>
            
            <!-- Rodapé do card -->
            <div class="text-center mt-4">
                <small class="text-muted">
                    <i class="fa fa-shield-alt me-1"></i>
                    Acesso seguro e protegido
                </small>
            </div>
        </div>
    </div>
    
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const pwd = document.getElementById('password');
            const btn = document.getElementById('togglePassword');
            if (pwd && btn) {
                btn.addEventListener('click', function(){
                    const isPwd = pwd.type === 'password';
                    pwd.type = isPwd ? 'text' : 'password';
                    const icon = btn.querySelector('i');
                    if (icon) icon.className = isPwd ? 'fa fa-eye-slash' : 'fa fa-eye';
                    btn.title = isPwd ? 'Ocultar senha' : 'Mostrar senha';
                });
            }
        })();
    </script>
</body>
</html>