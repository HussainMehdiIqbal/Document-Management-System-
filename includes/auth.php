<?php
// ============================================================
// Auth Guard - Include at top of protected pages
// ============================================================
require_once __DIR__ . '/config.php';

// Server-side session inactivity timeout (seconds). Kills stale sessions
// even when the browser cookie is still alive (e.g. browser was not fully closed).
define('SESSION_TIMEOUT', 1800); // 30 minutes

function requireLogin() {
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        header('Location: ../login.php');
        exit();
    }

    // Server-side inactivity timeout — destroys stale sessions even if the browser
    // cookie is still alive (e.g. user did not fully close the browser).
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../login.php?error=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time(); // Refresh the activity timestamp on every request

    // Only check live DB status on GET requests — never on POST
    // (Checking on POST would destroy the session mid-form-submit, losing all data)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        global $conn;
        $userId = $_SESSION['user_id'] ?? 0;
        if ($userId > 0 && isset($conn)) {
            try {
                $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$res || $res['status'] !== 'active') {
                        $_SESSION = [];
                        session_destroy();
                        header('Location: ../login.php?error=blocked');
                        exit();
                    }
                }
            } catch (Exception $e) {
                // Don't block access if the DB check itself fails
                error_log('Auth status check failed: ' . $e->getMessage());
            }
        }
    }
}

function requireLoginRoot() {
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        header('Location: login.php');
        exit();
    }

    // Server-side inactivity timeout — destroys stale sessions even if the browser
    // cookie is still alive (e.g. user did not fully close the browser).
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?error=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time(); // Refresh the activity timestamp on every request

    // Only check live DB status on GET requests — never on POST
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        global $conn;
        $userId = $_SESSION['user_id'] ?? 0;
        if ($userId > 0 && isset($conn)) {
            try {
                $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$res || $res['status'] !== 'active') {
                        $_SESSION = [];
                        session_destroy();
                        header('Location: login.php?error=blocked');
                        exit();
                    }
                }
            } catch (Exception $e) {
                error_log('Auth status check failed: ' . $e->getMessage());
            }
        }
    }
}

function getUserId() {
    return $_SESSION['user_id'] ?? 0;
}

function getUserName() {
    return $_SESSION['user_name'] ?? 'Administrator';
}

function getUserUsername() {
    return $_SESSION['user_username'] ?? 'admin';
}

function getUserRoleId() {
    return $_SESSION['user_role_id'] ?? 1;
}

function getUserAssignedBranches() {
    $raw = $_SESSION['user_assigned_branch'] ?? '';
    if ($raw === '' || $raw === null) return [];
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function getUserRoleName() {
    return $_SESSION['user_role_name'] ?? 'Admin';
}

function getUserInitials() {
    return $_SESSION['user_initials'] ?? 'AD';
}

function getUserAvatarColor() {
    return $_SESSION['user_avatar_color'] ?? '#1e4db7';
}

// Legacy aliases for backward compatibility
function getAdminName()     { return getUserName(); }
function getAdminUsername() { return getUserUsername(); }
?>
