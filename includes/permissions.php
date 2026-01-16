<?php
// includes/permissions.php

function hasPermission($screen) {
    if (!isset($_SESSION['permissions'])) {
        return false;
    }
    return in_array($screen, $_SESSION['permissions']);
}

function getUserRole() {
    return $_SESSION['role_id'] ?? null;
}

function isDirector() {
    return getUserRole() == 1;
}

/**
 * Verifica acesso para uma tela e redireciona para login se a sessão estiver inconsistente.
 * - Se não houver `user_id` redireciona para login.
 * - Se não houver permissões carregadas (sessão possivelmente inválida), destrói a sessão e redireciona para login.
 * - Se o usuário estiver autenticado mas não tiver permissão, exibe "Acesso negado." (comportamento atual).
 */
function checkAccessOrRedirect($screen) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Permissões não carregadas: sessão inconsistente (por exemplo cookie persistente mas dados do servidor perdidos)
    if (empty($_SESSION['permissions'])) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Se o usuário não tem permissão para a tela, mantém o comportamento original
    if (!hasPermission($screen)) {
        echo "Acesso negado.";
        exit;
    }
}
?>