<?php
require_once 'includes/config.php';
if (isset($_SESSION['user_id']) && isset($_SESSION['user_logged_in'])) {
    logActivity($conn, $_SESSION['user_id'], 'login', $_SESSION['user_username'] . ' logged out');
}
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php?msg=loggedout');
exit();
?>
