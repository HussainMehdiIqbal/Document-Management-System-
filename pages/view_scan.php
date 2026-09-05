<?php
// ============================================================
// pages/view_scan.php — Streams a scanned file for live preview
// Used by the Scan tab so the just-scanned PDF can be shown on
// the page (with page-by-page navigation) before it's saved.
// ============================================================

// IMPORTANT: this endpoint streams raw binary bytes to PDF.js.
// If output compression or an output buffer is active, the bytes
// actually sent won't match the Content-Length we declare below,
// PDF.js will receive a truncated/garbled file, and it fails to
// parse it silently (no visible error) — which looks exactly like
// "the scan works but nothing shows on screen". Kill both before
// any output is produced.
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once '../includes/auth.php';
requireLogin();

$dir  = trim($_GET['dir']  ?? '');
$file = basename(trim($_GET['file'] ?? '')); // basename() strips any path traversal

if ($dir === '' || $file === '') {
    http_response_code(400);
    exit('Missing parameters.');
}

$realDir = realpath($dir);
if ($realDir === false || !is_dir($realDir)) {
    http_response_code(404);
    exit('Folder not found.');
}

$fullPath = $realDir . DIRECTORY_SEPARATOR . $file;

// Safety check: the resolved file must live directly inside $realDir
if (dirname($fullPath) !== $realDir || !is_file($fullPath)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'tif'  => 'image/tiff',
    'tiff' => 'image/tiff',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store');
header('Accept-Ranges: none'); // force PDF.js (and the browser) to fetch the whole file in one request
readfile($fullPath);