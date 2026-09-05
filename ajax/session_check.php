<?php
// Lightweight, side-effect-free session check used by pages that run long
// background loops (e.g. rename.php's folder auto-sync) to warn the user
// *before* their session times out mid-workflow, instead of only finding
// out when a save silently fails.
//
// Deliberately does NOT call requireLogin() — that would redirect on
// failure (breaking this from being callable via fetch) and would also
// refresh last_activity, defeating the point of a passive check.
require_once '../includes/auth.php';
header('Content-Type: application/json');

$loggedIn = isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
$expired  = $loggedIn && isset($_SESSION['last_activity'])
            && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT;

echo json_encode(['active' => $loggedIn && !$expired]);
