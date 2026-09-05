<?php
// ============================================================
// DHA Document Management System - Database Configuration
// Database: dms_database (new schema)
// ============================================================

define('DB_HOST',     '127.0.0.1');  // Use IP to force TCP (not Unix socket)
define('DB_PORT',     3306);          // MySQL running on port 3307
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'dms_database');
define('DB_CHARSET',  'utf8mb4');

// ── Timezone ────────────────────────────────────────────────
// Every "since last reset" / "today" comparison in this app (dashboard
// cutoffs, scanned_at, renamed_at, validated_at) is written with PHP's
// date(). Without this line PHP defaults to UTC while MySQL's
// CURRENT_TIMESTAMP columns (users.created_at, folders.created_at) use
// the server's local time — two different clocks. That mismatch is why
// "Total Users" / "Active Folders" wouldn't reliably zero out on Reset
// Dashboard even though everything else did. Setting both PHP and the
// MySQL session to the same timezone below fixes it everywhere at once.
date_default_timezone_set('Asia/Karachi');

define('SITE_NAME',   'DHA Document Management System');
define('SITE_SHORT',  'DHA DMS');
define('UPLOAD_DIR',  __DIR__ . '/../uploads/');
define('UPLOAD_URL',  'uploads/');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('ALLOWED_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff']);

// ── NAPS2 Hardware-Scanner Configuration ──────────────────────
// Adjust NAPS2_CONSOLE_PATH to the actual install location on this PC.
// SCANNER_DEVICE_NAME must match the device string shown in NAPS2 > Manage
// Devices exactly (case-sensitive) — open NAPS2, add/select the Canon
// DR-3010C there once, and copy its exact name into SCANNER_DEVICE_NAME
// below. Set it to '' to fall back to the saved NAPS2 GUI profile named
// SCAN_PROFILE_NAME instead.
define('NAPS2_CONSOLE_PATH',   'C:/Program Files/NAPS2/naps2.console.exe');
define('SCAN_PROFILE_NAME',    'DHA-Default');
define('SCAN_DIRECTORY',       __DIR__ . '/../scans/');
define('SCAN_TIMEOUT_SECONDS', 300);
define('SCANNER_DEVICE_NAME',  'Canon DR-3010C TWAIN');   // set '' to use profile
define('SCANNER_DRIVER',       'twain');

// ── Local Scan Agent (client-side scanning) ────────────────────
// pages/trigger_scan.php and pages/stop_scan.php are now unused for the
// normal scan flow — scanning happens on the thin client via
// scan-agent/scan_agent.ps1, which pages/scan.php's JS talks to directly
// at http://127.0.0.1:SCAN_AGENT_PORT. This constant only needs to match
// the PORT value inside agent_config.json; the server
// itself never connects to the agent.
define('SCAN_AGENT_PORT', 51823);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Database connection (MySQLi) — port 3307
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    die('<div style="font-family:sans-serif;padding:20px;color:red;">
        <h3>⚠ Database Connection Failed</h3>
        <p>Could not connect to MySQL on port ' . DB_PORT . '. Please:<br>
        1. Ensure MySQL/MariaDB is running on port ' . DB_PORT . '<br>
        2. Import <code>database/dha_dms.sql</code> into MySQL<br>
        Error: ' . htmlspecialchars($conn->connect_error) . '</p></div>');
}
$conn->set_charset(DB_CHARSET);
// Force this session's clock to match PHP's timezone above, so MySQL's
// CURRENT_TIMESTAMP columns (users.created_at, folders.created_at) land
// on the exact same wall-clock as every PHP-written timestamp/cutoff.
$conn->query("SET time_zone = '+05:00'");

// ============================================================
// Helper Functions
// ============================================================

/**
 * Sanitize output to prevent XSS
 */
function esc($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize input (basic)
 */
function clean($value) {
    return trim(strip_tags($value ?? ''));
}

/**
 * Some databases created before the metadata-snapshot feature was added
 * don't yet have the `documents.rename_meta` column — querying/binding it
 * on those databases makes mysqli's prepare() return false, and calling
 * bind_param() on that false is a fatal error. Detect the column once per
 * request so any query touching it can degrade gracefully instead of
 * crashing. Shared by rename.php, ajax/log_rename.php, and validate.php.
 */
function documentsHasRenameMetaColumn($conn) {
    static $cached = null;
    if ($cached === null) {
        $res = $conn->query("SHOW COLUMNS FROM documents LIKE 'rename_meta'");
        $cached = ($res && $res->num_rows > 0);
    }
    return $cached;
}

/**
 * Returns the cutoff timestamp ('Y-m-d H:i:s') below which activity is
 * excluded from "today's" counts/KPIs for a given user — either midnight
 * today, or that user's last manual "Reset Dashboard" click, whichever is
 * later. Shared by dashboard.php, user_dashboard.php, and every page with
 * its own daily KPI banner (scan.php, rename.php, validate.php) so that
 * pressing "Reset Dashboard" zeroes every KPI everywhere consistently,
 * instead of only the two dashboard overview pages.
 */
function getDashboardResetCutoff($conn, $userId) {
    $todayStart = date('Y-m-d') . ' 00:00:00';

    $createOk = $conn->query("
        CREATE TABLE IF NOT EXISTS user_dashboard_resets (
            user_id   INT PRIMARY KEY,
            reset_at  DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )
    ");
    if (!$createOk) {
        // Table couldn't be created (e.g. users table missing/mismatched FK,
        // or the DB connection is in a bad state) — log it and fall back to
        // "no reset yet" rather than fatally crashing every page that shows
        // a daily KPI banner (dashboard, rename, validate, scan).
        error_log("getDashboardResetCutoff: CREATE TABLE failed — " . $conn->error);
        return $todayStart;
    }

    $uid    = (int)$userId;
    $result = $conn->query("SELECT reset_at FROM user_dashboard_resets WHERE user_id = $uid");
    if (!$result) {
        error_log("getDashboardResetCutoff: SELECT failed — " . $conn->error);
        return $todayStart;
    }

    $resetRow  = $result->fetch_row();
    $lastReset = $resetRow ? $resetRow[0] : null;
    return ($lastReset && $lastReset > $todayStart) ? $lastReset : $todayStart;
}

/**
 * Log activity to activity_log table (new schema)
 */
function logActivity($conn, $userId, $actionType, $description, $targetDocId = null, $targetUserId = null) {
    // Validate action_type ENUM
    $validTypes = ['create', 'update', 'delete', 'login', 'view', 'download'];
    if (!in_array($actionType, $validTypes)) $actionType = 'update';

    // Convert 0 or empty IDs to NULL to prevent foreign key constraint violations
    $dbUserId = (!empty($userId) && (int)$userId > 0) ? (int)$userId : null;
    $dbTargetDocId = (!empty($targetDocId) && (int)$targetDocId > 0) ? (int)$targetDocId : null;
    $dbTargetUserId = (!empty($targetUserId) && (int)$targetUserId > 0) ? (int)$targetUserId : null;

    try {
        $stmt = $conn->prepare(
            "INSERT INTO activity_log (user_id, action_type, description, target_document_id, target_user_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("issii", $dbUserId, $actionType, $description, $dbTargetDocId, $dbTargetUserId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Log logging failures to PHP error log without disrupting the main user action
        error_log("Activity logging failed: " . $e->getMessage());
    }
}

/**
 * Format file size from bytes integer or string
 */
function formatSize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Generate initials from full name
 */
function makeInitials($fullName) {
    $parts = array_filter(explode(' ', trim($fullName)));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($fullName, 0, 2));
}
?>