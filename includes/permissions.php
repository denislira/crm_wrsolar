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
        // diagnostic log to help debug unexpected session clears
        try {
            $dbg = [
                'time' => date('c'),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'session_id' => session_id(),
                'session_vars' => $_SESSION,
            ];
            $dlog = __DIR__ . '/../logs/session_debug.log';
            @mkdir(dirname($dlog), 0755, true);
            file_put_contents($dlog, json_encode($dbg) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) { /* ignore logging errors */ }

        // Tentativa de recuperação: se temos user_id, recarregamos permissões do banco
        // Tentativa de recuperação: se temos user_id, recarregamos permissões do banco
        try {
            if (isset($GLOBALS['pdo']) && $_SESSION['user_id']) {
                $stmt = $GLOBALS['pdo']->prepare('SELECT role_id FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $role_id = $row['role_id'] ?? null;
                if ($role_id) {
                    $stmt_perm = $GLOBALS['pdo']->prepare('SELECT screen FROM role_permissions WHERE role_id = ? AND allowed = 1');
                    $stmt_perm->execute([$role_id]);
                    $perms = $stmt_perm->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($perms)) {
                        $_SESSION['permissions'] = $perms;
                    }
                }
            }
        } catch (Exception $e) {
            // ignore and proceed to destroy session below
        }

        if (empty($_SESSION['permissions'])) {
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;
        }
    }

    // Se o usuário não tem permissão para a tela, mantém o comportamento original
    if (!hasPermission($screen)) {
        echo "Acesso negado.";
        exit;
    }
}
?>