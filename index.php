<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// Redirect to the full dashboard page
header('Location: dashboard.php');
exit;