<?php
// Garantir que a sessão esteja iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>

<nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3">
        <h5 class="sidebar-heading px-3 mt-4 mb-1 text-muted">SolarCRM</h5>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
                    Clientes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="logout.php">
                    Sair
                </a>
            </li>
        </ul>
    </div>
</nav>