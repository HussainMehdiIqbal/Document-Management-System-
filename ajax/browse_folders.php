<?php
// ============================================================
// ajax/browse_folders.php — Folder-picker AJAX helper
// Returns JSON {path, dirs:[{name,path}]} for the scan-page
// folder-browse modal.  Only paths inside the project root are
// allowed (path-traversal guard).
// ============================================================
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

// Project root = one level above this file's directory (ajax/../)
$projectRoot = realpath(__DIR__ . '/../');

$requested = trim($_GET['path'] ?? '');
if ($requested === '') {
    // Default: start at the scans/ directory (or project root if absent)
    $startDir = is_dir(SCAN_DIRECTORY) ? SCAN_DIRECTORY : $projectRoot;
    $requested = realpath($startDir);
}

$realRequested = realpath($requested);

// Security: the resolved path must sit inside the project root
if ($realRequested === false
    || strpos($realRequested, $projectRoot) !== 0
    || !is_dir($realRequested))
{
    echo json_encode(['error' => 'Invalid path.']);
    exit;
}

$dirs = [];
$scan = @scandir($realRequested);
if ($scan) {
    foreach ($scan as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $realRequested . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            $dirs[] = [
                'name' => $item,
                'path' => $full,
            ];
        }
    }
}

echo json_encode([
    'path' => $realRequested,
    'dirs' => $dirs,
]);
