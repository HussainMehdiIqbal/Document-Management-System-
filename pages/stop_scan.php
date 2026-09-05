<?php
// ============================================================
// DEPRECATED as of the client-side scanning change: Stop Scan now calls
// the local agent's POST /stop directly (see pages/scan.php's stopScan()).
// Left in place for reference / rollback only.
// ============================================================
// pages/stop_scan.php — Cancels an in-progress scan
// Called by the "Stop Scan" button while trigger_scan.php is still
// running a multi-page batch (e.g. via the ADF on the Canon DR-3010C).
// It kills the process trigger_scan.php recorded for this session and
// drops a "stop flag" file so trigger_scan.php can report a clean
// "stopped by user" result instead of a raw process-failure error.
// ============================================================

ob_start();

function respondJson(array $data, int $httpCode = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

require_once '../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$pidFile      = sys_get_temp_dir() . '/dha_scan_' . session_id() . '.pid';
$stopFlagFile = sys_get_temp_dir() . '/dha_scan_' . session_id() . '.stop';

// Tell trigger_scan.php a stop was requested, regardless of whether we
// can find/kill a PID below (the poll loop checks for this file too).
@file_put_contents($stopFlagFile, '1');

$killed = false;
if (file_exists($pidFile)) {
    $pid = (int) trim((string) file_get_contents($pidFile));
    if ($pid > 0) {
        // Windows only (this project targets NAPS2 on Windows — see config.php).
        // /T kills the whole process tree so the actual NAPS2/scanner-driver
        // child process dies too, not just the wrapper PHP spawned.
        @exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $out, $ret);
        $killed = ($ret === 0);
    }
    @unlink($pidFile);
}

respondJson([
    'success' => true,
    'killed'  => $killed,
]);