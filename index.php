<?php
require_once 'includes/config.php';
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit();
?>
