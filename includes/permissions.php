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
?>