<?php
// ============================================================
// DEPRECATED as of the client-side scanning change: the browser now talks
// directly to scan-agent/scan_agent.ps1 running on the thin client (see
// pages/scan.php's startScan()), so this server-side endpoint is no longer
// called by the normal flow. Left in place for reference / rollback only —
// it would only ever scan whatever is attached to the SERVER, which is
// the exact problem the local agent was built to avoid.
// ============================================================
// pages/trigger_scan.php — NAPS2 Scanner Bridge Controller
// Correct CLI flags: --bitdepth (color/gray/bw), --source (glass/feeder/duplex),
//                   --pagesize (a4/letter/legal), --dpi, --deskew, --enableocr
//
// The physical scanner is NOT hardcoded here. Which device NAPS2 talks to
// is entirely controlled by includes/config.php:
//   - If SCANNER_DEVICE_NAME is set, we scan "ad hoc" via
//     --driver / --device (currently the Canon DR-3010C's TWAIN driver),
//     bypassing any saved NAPS2 GUI profile entirely.
//   - If SCANNER_DEVICE_NAME is blank, we fall back to the saved NAPS2 GUI
//     profile named by SCAN_PROFILE_NAME instead.
// To move to a different scanner in future, only includes/config.php needs
// to change — this file and the frontend stay untouched.
// ============================================================

// Capture ALL output from this point on. PHP warnings/notices/deprecations
// (e.g. from filter_var(), undefined array keys, etc.) print directly into
// the response body if display_errors is on anywhere in the stack. That
// breaks the JSON the frontend is expecting even though the HTTP status is
// still 200 — the browser sees "200 OK" but response.json() throws because
// the body isn't pure JSON, and the whole request looks like a failure even
// though the scan itself worked. respondJson() below discards anything
// accidental and guarantees the client only ever receives clean JSON.
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

// Catch fatal errors the same way, instead of letting PHP print an HTML
// error page into what's supposed to be a JSON response.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        respondJson([
            'success' => false,
            'error'   => 'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
        ], 500);
    }
});

require_once '../includes/auth.php';
requireLogin();

// Scanning can take time — disable PHP execution timeout for this request
set_time_limit(0);
ini_set('max_execution_time', 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

// ── Determine where to save this scan ──────────────────────────
// Uses the folder the user chose in "Save Scans To" (Document Info panel),
// falling back to the default SCAN_DIRECTORY from config.php if that folder
// is missing, empty, or not writable.
$requestedDir = trim($_POST['save_location'] ?? '');
$scanDir = rtrim(SCAN_DIRECTORY, '/\\');

if ($requestedDir !== '') {
    if (!is_dir($requestedDir)) {
        @mkdir($requestedDir, 0755, true);
    }
    if (is_dir($requestedDir) && is_writable($requestedDir)) {
        $scanDir = rtrim($requestedDir, '/\\');
    }
}

// Ensure the scan output directory exists
if (!is_dir($scanDir)) {
    mkdir($scanDir, 0755, true);
}

// Verify NAPS2 is installed
if (!file_exists(NAPS2_CONSOLE_PATH)) {
    respondJson([
        'success' => false,
        'error'   => 'NAPS2 not found at: ' . NAPS2_CONSOLE_PATH
    ]);
}

// ── PDF output mode: single combined PDF, or one PDF per sheet ────
// 'single'   (default): every page scanned in this batch is saved into one PDF.
// 'multiple': each physical sheet gets saved as its own PDF. NAPS2's
//             --splitsize does this natively — it splits the output every
//             N pages. With Duplex scanning each sheet produces 2 pages
//             (front + back) in the raw page stream, so N must be 2 to keep
//             each sheet's two sides together in one file; single-sided
//             scanning is 1 page per sheet, so N is 1.
$pdfOutputMode = strtolower(clean($_POST['pdf_output'] ?? 'single'));
$isMultiplePdf = ($pdfOutputMode === 'multiple');

// ── Build a unique output filename (base, before duplex is known) ──
$baseName = 'Scan_' . date('Ymd_His') . '_' . substr(uniqid(), -6);

// Returns a path in $dir for "$name.$ext" that doesn't collide with an
// existing file, appending " (1)", " (2)", ... if needed.
function uniqueOutputPath(string $dir, string $name, string $ext): string {
    $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . '.' . $ext;
    if (!file_exists($candidate)) return $candidate;
    $i = 1;
    do {
        $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . " ({$i}).{$ext}";
        $i++;
    } while (file_exists($candidate));
    return $candidate;
}

if ($isMultiplePdf) {
    // Multiple-PDF mode: NAPS2 needs a "$(n)" placeholder to number each
    // split file. NAPS2 always starts that placeholder at 1 with no offset
    // option, so we let it write under an internal temp name first, then
    // rename each result to a plain "1.pdf", "2.pdf", ... after scanning
    // (see the success handling below) — that also lets us avoid clobbering
    // numbered files already left in this folder by an earlier scan batch.
    $tempBase   = '_tmp_' . $baseName;
    $filename   = $tempBase . '_$(n).pdf';
    $outputPath = $scanDir . DIRECTORY_SEPARATOR . $filename;
} else {
    // Single-PDF mode: use the name the user typed in the "Name This Scan"
    // prompt, sanitised for the filesystem, falling back to the timestamped
    // default if none was provided or it sanitises to nothing.
    $customFilename = trim($_POST['custom_filename'] ?? '');
    $safeCustomName = preg_replace('/[^A-Za-z0-9 _-]/', '_', $customFilename);
    $safeCustomName = trim($safeCustomName);
    $desiredName    = $safeCustomName !== '' ? $safeCustomName : $baseName;

    $outputPath = uniqueOutputPath($scanDir, $desiredName, 'pdf');
    $filename   = basename($outputPath);
}

// ── Map frontend settings to correct NAPS2 CLI arguments ─────

// Resolution: strip everything except digits (e.g. "300 DPI" → 300)
// (preg_replace instead of the deprecated FILTER_SANITIZE_NUMBER_INT,
// which throws a deprecation notice on PHP 8.1+ that used to leak into
// this response and corrupt the JSON.)
$rawRes  = clean($_POST['resolution'] ?? '300 DPI');
$dpi     = (int) preg_replace('/[^0-9]/', '', $rawRes);
if ($dpi < 75 || $dpi > 1200) $dpi = 300;

// Color mode: --bitdepth color | gray | bw
$colorMode = strtolower(clean($_POST['color_mode'] ?? 'Greyscale'));
if (str_contains($colorMode, 'grey') || str_contains($colorMode, 'gray')) {
    $bitdepth = 'gray';
} elseif (str_contains($colorMode, 'black') || str_contains($colorMode, 'bw')) {
    $bitdepth = 'bw';
} else {
    $bitdepth = 'color';
}

// Paper size: --pagesize a4 | letter | legal
$rawPaper = strtolower(clean($_POST['paper_size'] ?? 'A4'));
if (str_contains($rawPaper, 'letter')) {
    $pagesize = 'letter';
} elseif (str_contains($rawPaper, 'legal')) {
    $pagesize = 'legal';
} else {
    $pagesize = 'a4';
}

// Source: --source duplex | feeder | glass
// The Canon DR-3010C is a sheet-fed ADF scanner (no flatbed/glass bed), so
// single-side scans use the feeder rather than glass.
$isDuplex  = ($_POST['duplex'] ?? 'false') === 'true';
$source    = $isDuplex ? 'duplex' : 'feeder';

// Deskew: --deskew flag
$deskew    = ($_POST['deskew'] ?? 'false') === 'true' ? ' --deskew' : '';

// OCR: --enableocr / --disableocr
$ocrFlag   = ($_POST['ocr'] ?? 'false') === 'true' ? ' --enableocr' : ' --disableocr';

// ── Stop-scan support ───────────────────────────────────────────
// A running scan can be cancelled from the frontend (Stop Scan button),
// which posts to stop_scan.php. That script kills the process below by
// PID and drops a "stop flag" file so this script can report a clean
// "stopped by user" result instead of a raw process-failure error.
$pidFile      = sys_get_temp_dir() . '/dha_scan_' . session_id() . '.pid';
$stopFlagFile = sys_get_temp_dir() . '/dha_scan_' . session_id() . '.stop';
@unlink($stopFlagFile); // clear any stale flag left over from a previous scan

// ── Build the full command ────────────────────────────────────
// Scanner source: prefer the device configured centrally in config.php
// (SCANNER_DEVICE_NAME) over a saved NAPS2 GUI profile, so the target
// scanner can be swapped by editing one config value only.
$useAdhocDevice = defined('SCANNER_DEVICE_NAME') && trim(SCANNER_DEVICE_NAME) !== '';

$cmd = escapeshellarg(NAPS2_CONSOLE_PATH)
     . ' -o '          . escapeshellarg($outputPath);

if ($useAdhocDevice) {
    // Ad hoc mode: scan directly against the named TWAIN/WIA device,
    // ignoring any saved profile. --noprofile is required by NAPS2
    // whenever --driver/--device are used on the command line.
    $driver = defined('SCANNER_DRIVER') && trim(SCANNER_DRIVER) !== '' ? SCANNER_DRIVER : 'twain';
    $cmd .= ' --noprofile'
          . ' --driver ' . escapeshellarg($driver)
          . ' --device ' . escapeshellarg(SCANNER_DEVICE_NAME);
} else {
    // Fallback: use the profile saved inside NAPS2's own GUI.
    $cmd .= ' --profile ' . escapeshellarg(SCAN_PROFILE_NAME);
}

$cmd .= ' --dpi '       . $dpi
      . ' --bitdepth '  . $bitdepth
      . ' --pagesize '  . $pagesize
      . ' --source '    . $source
      . $deskew
      . $ocrFlag
      . ' --force'
      . ' -v';           // verbose: errors go to stderr so we can capture them

if ($isMultiplePdf) {
    // One PDF per physical sheet: Duplex scans 2 pages (front+back) per
    // sheet, single-sided scans 1 page per sheet — splitsize must match so
    // a sheet's two sides always land in the same output file.
    $splitSize = $isDuplex ? 2 : 1;
    $cmd .= ' --splitsize ' . $splitSize;
}

// ── Execute via proc_open (non-blocking read, with timeout) ──
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptorSpec, $pipes);

if (!is_resource($process)) {
    respondJson([
        'success' => false,
        'error'   => 'Failed to start NAPS2 process. Check server permissions.'
    ]);
}

fclose($pipes[0]); // close stdin

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Save the PID so stop_scan.php can terminate this process on request.
// (On Windows this is the wrapper process PHP actually spawned; stop_scan.php
// kills it with /T so the whole tree — including the real NAPS2 process — dies.)
$procStatus = proc_get_status($process);
if (!empty($procStatus['pid'])) {
    @file_put_contents($pidFile, $procStatus['pid']);
}

$startTime = time();
$stdout    = '';
$stderr    = '';

while (true) {
    $status  = proc_get_status($process);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    if (!$status['running']) {
        break;
    }

    // Stop Scan was pressed — stop_scan.php already sent taskkill; once the
    // process actually exits we'll fall through the loop and report below.
    if (file_exists($stopFlagFile)) {
        // Give the taskkill a brief moment to finish tearing the process down.
        usleep(300000);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
    }

    if (time() - $startTime > SCAN_TIMEOUT_SECONDS) {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        @unlink($pidFile);
        respondJson([
            'success' => false,
            'error'   => 'Scan timed out after ' . SCAN_TIMEOUT_SECONDS . 's. Is the scanner on and loaded with paper?'
        ]);
    }

    usleep(200000); // poll every 0.2s
}

fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($pidFile);

// ── Stopped by user? ────────────────────────────────────────
if (file_exists($stopFlagFile)) {
    @unlink($stopFlagFile);
    respondJson([
        'success' => false,
        'stopped' => true,
        'error'   => 'Scan stopped by user.'
    ]);
}

// ── Check result ─────────────────────────────────────────────
if ($exitCode !== 0) {
    $errMsg = trim($stderr ?: $stdout);
    $deviceLabel = $useAdhocDevice ? SCANNER_DEVICE_NAME : SCAN_PROFILE_NAME;

    // Recognize the common "scanner not there" failure modes and surface a
    // clear, actionable message instead of a raw NAPS2 exit code/stack trace.
    $lowerErr = strtolower($errMsg);
    $notFoundPatterns = [
        'no such device', 'device not found', 'could not find', 'no devices found',
        'not found', 'no scanners', 'unable to open', 'device is offline',
        'device offline', 'not available', 'no driver',
    ];
    $isDeviceUnavailable = $errMsg === '';
    foreach ($notFoundPatterns as $pattern) {
        if (str_contains($lowerErr, $pattern)) {
            $isDeviceUnavailable = true;
            break;
        }
    }

    if ($isDeviceUnavailable) {
        respondJson([
            'success' => false,
            'error'   => "Could not connect to the scanner (\"$deviceLabel\"). " .
                         "Please check that the Canon DR-3010C is powered on, connected " .
                         "(USB or network), and that its TWAIN driver is installed on this PC, " .
                         "then try again." .
                         ($errMsg !== '' ? " Details: $errMsg" : '')
        ]);
    }

    // "Ran, but nothing actually came through the scanner" — this is what NAPS2
    // reports when it can talk to the driver but the device isn't really there
    // (or is there but has no paper loaded), so treat it the same as
    // "scanner unavailable" instead of showing the raw NAPS2 log to the user.
    $noPagesPatterns = [
        '0 page(s) scanned', 'no scanned pages', 'no pages scanned', 'no pages to export',
    ];
    $noPagesScanned = false;
    foreach ($noPagesPatterns as $pattern) {
        if (str_contains($lowerErr, $pattern)) {
            $noPagesScanned = true;
            break;
        }
    }

    if ($noPagesScanned) {
        respondJson([
            'success' => false,
            'error'   => "No pages came through the scanner (\"$deviceLabel\"). " .
                         "Please check that the Canon DR-3010C is powered on, connected, " .
                         "and has paper loaded in the feeder, then try again."
        ]);
    }

    respondJson([
        'success' => false,
        'error'   => "NAPS2 exited with code $exitCode. $errMsg"
    ]);
}

if ($isMultiplePdf) {
    // NAPS2 replaces the "$(n)" placeholder with the split-file index
    // (1, 2, 3, ...) — glob for every temp file it actually produced for
    // this batch, in order, and ignore any zero-byte leftovers.
    $matches = glob($scanDir . DIRECTORY_SEPARATOR . $tempBase . '_*.pdf') ?: [];
    natsort($matches);
    $matches = array_values(array_filter($matches, fn($p) => filesize($p) > 0));

    if (empty($matches)) {
        respondJson([
            'success' => false,
            'error'   => 'NAPS2 ran successfully but no output files were produced. Ensure paper is loaded in the scanner.'
        ]);
    }

    // Name each file after the next document ID(s) that will be created
    // when this batch gets saved (see the INSERT in pages/scan.php):
    // "<next_id>.pdf", "<next_id + 1>.pdf", ... True IDs are only assigned
    // by MySQL's AUTO_INCREMENT at that later Save step (once folder/
    // branch/etc. are filled in), so this can't guarantee an exact match —
    // it reads the highest document_id saved so far and counts up from
    // there. If another operator saves a document in the gap between this
    // scan and this batch's Save click, the filenames drift from the real
    // IDs by a little, but the folder+filename uniqueness check on Save
    // still protects the database either way.
    $nextId = 1;
    $res = $conn->query("SELECT MAX(document_id) AS max_id FROM documents");
    if ($res && ($row = $res->fetch_assoc())) {
        $nextId = ((int)($row['max_id'] ?? 0)) + 1;
    }

    $files = [];
    foreach ($matches as $tmpPath) {
        $finalPath = $scanDir . DIRECTORY_SEPARATOR . $nextId . '.pdf';
        // Skip past any name already sitting in this folder (e.g. a
        // leftover from an earlier failed/uncompleted save) instead of
        // overwriting it.
        while (file_exists($finalPath)) {
            $nextId++;
            $finalPath = $scanDir . DIRECTORY_SEPARATOR . $nextId . '.pdf';
        }
        if (!rename($tmpPath, $finalPath)) {
            $finalPath = $tmpPath; // fallback: keep temp name if rename fails
        }
        $files[]  = ['filename' => basename($finalPath), 'size' => filesize($finalPath)];
        $nextId++;
    }

    // ── Success (Multiple PDF) ──────────────────────────────────
    respondJson([
        'success'    => true,
        'filename'   => $files[0]['filename'], // first file, kept for backward compatibility
        'size'       => $files[0]['size'],
        'files'      => $files,
        'count'      => count($files),
        'type'       => 'application/pdf',
        'scanned_at' => date('d-M-Y h:i A'),
        'dir'        => $scanDir,
    ]);
}

if (!file_exists($outputPath) || filesize($outputPath) === 0) {
    respondJson([
        'success' => false,
        'error'   => 'NAPS2 ran successfully but no output file was produced. Ensure paper is loaded in the scanner.'
    ]);
}

// ── Success (Single PDF) ────────────────────────────────────────
respondJson([
    'success'    => true,
    'filename'   => $filename,
    'size'       => filesize($outputPath),
    'files'      => [['filename' => $filename, 'size' => filesize($outputPath)]],
    'count'      => 1,
    'type'       => 'application/pdf',
    'scanned_at' => date('d-M-Y h:i A', filemtime($outputPath)),
    'dir'        => $scanDir,
]);