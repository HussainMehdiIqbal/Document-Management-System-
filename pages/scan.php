<?php
// ============================================================
// pages/scan.php — Scan / Upload Module (dms_database schema)
// ============================================================
require_once '../includes/auth.php';
requireLogin();

$pageTitle = 'Scan & Upload';

$message = '';
$msgType = 'success';
$userId  = getUserId();

// ── Scan KPI — target set by management: 400 scans/day ────────
// Formula (as instructed): (count * 100) / target
// Independent of Rename/Validate: only counts documents THIS user actually
// scanned (source='scan'), never anything they renamed or validated.
// Scoped to the shared "Reset Dashboard" cutoff (see includes/config.php)
// instead of a hardcoded CURDATE() so pressing Reset actually zeroes it.
$scanKpiTarget = 400;
$scanKpiCount  = 0;
$scanKpiCutoff = getDashboardResetCutoff($conn, $userId);
$kpiStmt = $conn->prepare("SELECT COUNT(*) FROM documents WHERE scanned_by = ? AND source = 'scan' AND scanned_at >= ?");
if ($kpiStmt) {
    $kpiStmt->bind_param("is", $userId, $scanKpiCutoff);
    $kpiStmt->execute();
    $scanKpiCount = (int)$kpiStmt->get_result()->fetch_row()[0];
    $kpiStmt->close();
}
$scanKpiPercent          = ($scanKpiCount * 100) / $scanKpiTarget;
$scanKpiPercentFormatted = number_format($scanKpiPercent, 1);
$scanKpiPercentWidth     = min(100, $scanKpiPercent);

// ── Load folders for dropdown ─────────────────────────────────
$folders = $conn->query("SELECT folder_id, folder_name FROM folders WHERE status='active' ORDER BY folder_name");

// Ensure physical directories exist for dropdown active folders
if ($folders && $folders->num_rows > 0) {
    while ($f = $folders->fetch_assoc()) {
        $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $f['folder_name']);
        $physicalPath = UPLOAD_DIR . $safeFolderName;
        if (!is_dir($physicalPath)) {
            mkdir($physicalPath, 0755, true);
        }
    }
    $folders->data_seek(0);
}

// ── Default / custom save location for scans ───────────────────
$defaultScanDir = rtrim(SCAN_DIRECTORY, '/\\');
if (!is_dir($defaultScanDir)) {
    mkdir($defaultScanDir, 0755, true);
}

// ── Handle File Upload (manual browse, OR a scan produced by the local
//    scan-agent running on the thin client) ────────────────────────────
// Scanning now happens entirely on the client (see scan-agent/), so by the
// time this page's POST handler runs, every scanned page has already
// travelled here as a normal uploaded file — there is no server-side scan
// folder to read from anymore. $_FILES['document_files'] holds one entry
// per physical sheet (Multiple-PDF mode) or one entry (Single-PDF mode);
// $_FILES['document_file'] is the older single-file field, kept for a
// plain manual "browse and upload" pick with no scan involved.
$uploadedFilesRaw = [];
if (!empty($_FILES['document_files']) && is_array($_FILES['document_files']['name'] ?? null)) {
    $count = count($_FILES['document_files']['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($_FILES['document_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploadedFilesRaw[] = [
            'name'     => $_FILES['document_files']['name'][$i],
            'type'     => $_FILES['document_files']['type'][$i],
            'tmp_name' => $_FILES['document_files']['tmp_name'][$i],
            'error'    => $_FILES['document_files']['error'][$i],
            'size'     => $_FILES['document_files']['size'][$i],
        ];
    }
}

// Folder the user picked in the "Where do you want to save this scan?"
// browse dialog when they clicked Save. Must resolve to somewhere inside
// the project (same guard as ajax/browse_folders.php) — otherwise we
// silently fall back to the normal folder-derived destination below.
$finalSaveDir = trim($_POST['final_save_dir'] ?? '');
$projectRoot  = realpath(__DIR__ . '/..');

// Saves one physical file (scanned or uploaded) into the target folder and
// inserts its document record. Returns ['ok'=>bool,'duplicate'=>bool,
// 'name'=>string,'error'=>string|null] so callers (single-file and
// batch/multi-PDF) can build their own success/summary message.
function saveIncomingDocument(
    mysqli $conn, string $rawFilename, string $ext, int $fileSize,
    array $uploadedFile,
    string $targetDir, string $storageDirPrefix, int $folderId, string $folderName,
    string $branch, string $docType, string $fileNo, string $phase,
    string $plot, string $docYear, int $userId
): array {
    $safeName    = preg_replace('/[^A-Za-z0-9._-]/', '_', $rawFilename);
    $storedName  = date('Ymd_His') . '_' . uniqid() . '_' . $safeName;
    $destPath    = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
    $storagePath = $storageDirPrefix . $storedName;

    $fileType  = strtolower($ext);
    $scannedAt = date('Y-m-d H:i:s');

    // Every incoming file — scanned by the local agent or picked manually —
    // arrives here as a genuine HTTP upload now, so this is always a real
    // move_uploaded_file(), never a server-side rename() of a path that no
    // longer exists on this machine.
    $transferSuccess = move_uploaded_file($uploadedFile['tmp_name'], $destPath);

    if (!$transferSuccess) {
        return ['ok' => false, 'duplicate' => false, 'name' => $rawFilename,
                'error' => 'Failed to save file to disk. Check directory permissions.'];
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO documents
         (raw_filename, file_type, file_size, storage_path, branch, doc_type,
          file_no, phase, plot, doc_year, status, folder_id, scanned_by, scanned_at, source)
         VALUES (?,?,?,?,?,?,?,?,?,?,'pending',?,?,?,'scan')"
    );
    if (!$stmt) {
        @unlink($destPath);
        return ['ok' => false, 'duplicate' => false, 'name' => $rawFilename,
                'error' => 'DB prepare failed: ' . $conn->error];
    }

    $stmt->bind_param(
        "ssisssssssiis",
        $rawFilename, $fileType, $fileSize, $storagePath,
        $branch, $docType, $fileNo, $phase, $plot, $docYear,
        $folderId, $userId, $scannedAt
    );

    if (!$stmt->execute()) {
        $err = $conn->error;
        $stmt->close();
        @unlink($destPath);
        return ['ok' => false, 'duplicate' => false, 'name' => $rawFilename,
                'error' => 'Database INSERT failed: ' . $err];
    }

    if ($stmt->affected_rows > 0) {
        $newDocId = $conn->insert_id;
        $stmt->close();
        logActivity($conn, $userId, 'create',
            "Scanned '{$rawFilename}' into '{$folderName}' (ID #{$newDocId})", $newDocId);
        return ['ok' => true, 'duplicate' => false, 'name' => $rawFilename, 'docId' => $newDocId, 'error' => null];
    }

    // UNIQUE index silently blocked a duplicate row
    $stmt->close();
    @unlink($destPath); // remove the duplicate physical file too
    return ['ok' => false, 'duplicate' => true, 'name' => $rawFilename, 'docId' => null, 'error' => null];
}

$hasBatchUpload  = !empty($uploadedFilesRaw);
$hasSingleUpload = isset($_FILES['document_file']) && $_FILES['document_file']['error'] !== UPLOAD_ERR_NO_FILE;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($hasBatchUpload || $hasSingleUpload)) {
    $folderId = (int)($_POST['folder_id'] ?? 0);
    $branch   = clean($_POST['branch']    ?? '');
    $docType  = clean($_POST['doc_type']  ?? '');
    $fileNo   = clean($_POST['file_no']   ?? '');
    $phase    = clean($_POST['phase']     ?? '');
    $plot     = clean($_POST['plot']      ?? '');
    $docYear  = clean($_POST['doc_year']  ?? date('Y'));

    if ($folderId < 1) {
        $message = 'Please select a folder.'; $msgType = 'danger';
    } elseif ($hasSingleUpload && !$hasBatchUpload && $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'File upload error. Please try again.'; $msgType = 'danger';
    } elseif ($hasSingleUpload && !$hasBatchUpload && $_FILES['document_file']['size'] > MAX_FILE_SIZE) {
        $message = 'File too large. Maximum size is 20MB.'; $msgType = 'danger';
    } else {
        $allowedExt = ['pdf','jpg','jpeg','png','tiff','tif'];

        // Fetch the selected folder name for physical directory sorting
        // (shared across every file in this batch — a batch is always
        // saved into a single folder).
        $fStmt = $conn->prepare("SELECT folder_name FROM folders WHERE folder_id = ? LIMIT 1");
        $fStmt->bind_param("i", $folderId);
        $fStmt->execute();
        $fRes = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();

        if (!$fRes) {
            $message = 'Selected folder not found in database.'; $msgType = 'danger';
        } else {
            $folderName     = $fRes['folder_name'];
            $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $folderName);

            // Default destination: uploads/<FolderName>/ (existing behaviour)
            $targetDir        = UPLOAD_DIR . $safeFolderName . DIRECTORY_SEPARATOR;
            $storageDirPrefix = UPLOAD_URL . $safeFolderName . '/';

            // If the user browsed to and picked a specific folder in the
            // Save dialog, save the file(s) there instead.
            if ($finalSaveDir !== '') {
                if (!is_dir($finalSaveDir)) {
                    @mkdir($finalSaveDir, 0755, true);
                }
                $realFinalDir = realpath($finalSaveDir);
                if ($realFinalDir !== false && $projectRoot !== false
                    && strpos($realFinalDir, $projectRoot) === 0) {
                    $targetDir   = rtrim($realFinalDir, '/\\') . DIRECTORY_SEPARATOR;
                    $relativeDir = ltrim(str_replace('\\', '/', substr($realFinalDir, strlen($projectRoot))), '/');
                    $storageDirPrefix = ($relativeDir !== '' ? $relativeDir . '/' : '');
                }
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if ($hasBatchUpload) {
                // ── Multiple files uploaded in one batch: either several
                //    physical sheets from a scan, or a single scanned file
                //    forwarded through the same field. Every entry here is
                //    a real HTTP upload from the browser (the scan-agent
                //    handed the bytes to the page, the page attached them
                //    to this form) — never a server-side path.
                $savedNames = [];
                $dupNames   = [];
                $errorMsgs  = [];

                foreach ($uploadedFilesRaw as $uploadedFile) {
                    $rawFilename = basename($uploadedFile['name']);
                    $ext         = strtolower(pathinfo($rawFilename, PATHINFO_EXTENSION));
                    $fileSize    = (int)$uploadedFile['size'];

                    if (!in_array($ext, $allowedExt)) {
                        $errorMsgs[] = "'{$rawFilename}': invalid file type.";
                        continue;
                    }
                    if ($uploadedFile['error'] !== UPLOAD_ERR_OK || $fileSize === 0) {
                        $errorMsgs[] = "'{$rawFilename}': upload error or empty file.";
                        continue;
                    }

                    $result = saveIncomingDocument(
                        $conn, $rawFilename, $ext, $fileSize, $uploadedFile,
                        $targetDir, $storageDirPrefix, $folderId, $folderName,
                        $branch, $docType, $fileNo, $phase, $plot, $docYear, $userId
                    );

                    if ($result['ok']) {
                        $savedNames[] = $result['name'] . ' (#' . $result['docId'] . ')';
                    } elseif ($result['duplicate']) {
                        $dupNames[] = $result['name'];
                    } else {
                        $errorMsgs[] = "'{$result['name']}': " . $result['error'];
                    }
                }

                $savedCount = count($savedNames);
                $total      = count($uploadedFilesRaw);

                if ($savedCount === $total) {
                    $msgType = 'success';
                    $message = $savedCount === 1
                        ? "Document '{$savedNames[0]}' saved successfully into '{$folderName}'! Status: Pending validation."
                        : "{$savedCount} documents saved successfully into '{$folderName}'! Status: Pending validation.";
                } elseif ($savedCount > 0) {
                    $msgType = 'warning';
                    $message = "{$savedCount} of {$total} document(s) saved into '{$folderName}'.";
                    if (!empty($dupNames))  $message .= ' Duplicates skipped: ' . implode(', ', $dupNames) . '.';
                    if (!empty($errorMsgs)) $message .= ' Errors: ' . implode(' ', $errorMsgs);
                } else {
                    $msgType = !empty($dupNames) && empty($errorMsgs) ? 'warning' : 'danger';
                    $message = !empty($dupNames) && empty($errorMsgs)
                        ? 'All scanned document(s) already exist in \'' . $folderName . '\'. No duplicate records were created.'
                        : 'Could not save the scanned document(s). ' . implode(' ', $errorMsgs);
                }
            } else {
                $file        = $_FILES['document_file'];
                $rawFilename = $file['name'];
                $ext         = strtolower(pathinfo($rawFilename, PATHINFO_EXTENSION));
                $fileSize    = (int)$file['size'];

                if (!in_array($ext, $allowedExt)) {
                    $message = 'Invalid file type. Allowed: PDF, JPG, PNG, TIFF.'; $msgType = 'danger';
                } else {
                    $result = saveIncomingDocument(
                        $conn, $rawFilename, $ext, $fileSize, $file,
                        $targetDir, $storageDirPrefix, $folderId, $folderName,
                        $branch, $docType, $fileNo, $phase, $plot, $docYear, $userId
                    );

                    if ($result['ok']) {
                        $message = "Document '{$result['name']}' saved successfully into '{$folderName}'! Status: Pending validation.";
                    } elseif ($result['duplicate']) {
                        $message = "'{$result['name']}' already exists in '{$folderName}'. No duplicate record was created."; $msgType = 'warning';
                    } else {
                        $message = $result['error']; $msgType = 'danger';
                    }
                }
            }
        }
    }
}

// ── Role flag (still used for header include + Verify nav link) ────
$isAdmin = ((int)getUserRoleId() === 1);
if (!in_array((int)getUserRoleId(), [1, 2])) {
    header('Location: ../user_dashboard.php');
    exit();
}

if ($isAdmin) {
    require_once '../includes/header.php';
} else {
    require_once '../includes/user_header.php';
}
?>

<!-- ── CSS OVERRIDES AND PREMIUM STYLING ── -->
<style>
/* ── Scan Page Wrapper ────────────────────────────────────── */
.scan-page-wrapper {
  background-color: var(--bg);
  min-height: 100vh;
  color: var(--text-primary);
  font-family: 'Inter', sans-serif;
  padding-bottom: 3rem;
}

/* ── Layout & Cards ───────────────────────────────────────── */
.scan-container { max-width: 2000px; margin: 2rem auto 0 auto; padding: 0 1.5rem; }
.scan-card {
  background: var(--surface); border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.03);
  border: 1px solid var(--border); margin-bottom: 1.5rem;
  overflow: hidden; display: flex; flex-direction: column;
}
.scan-card-header {
  padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.scan-card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.75rem; }
.scan-card-title i { color: #f1b21c; }
.scan-card-subtitle { font-size: 0.8rem; color: var(--text-secondary); }
.scan-card-body { padding: 1.5rem; flex: 1; min-height: 0; }

/* ── Scanner Bed ──────────────────────────────────────────── */
.scanner-bed-container {
  background-color: #0d172a; border-radius: 12px;
  flex: 1; min-height: 900px; position: relative;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 2rem; margin-bottom: 1.5rem;
  transition: border-color 0.2s, background-color 0.2s;
  cursor: pointer; border: 1px dashed rgba(255,255,255,0.1);
}
.scanner-bed-container:hover  { background-color: #0f1e38; }
.scanner-bed-container.drag-over { border: 2px dashed #f1b21c; background-color: #0f1e38; }

/* Corner markers */
.scanner-corner { position: absolute; width: 20px; height: 20px; border-color: #f1b21c; border-style: solid; pointer-events: none; }
.corner-tl { top: 20px; left: 20px;   border-width: 2.5px 0 0 2.5px; border-top-left-radius: 6px; }
.corner-tr { top: 20px; right: 20px;  border-width: 2.5px 2.5px 0 0; border-top-right-radius: 6px; }
.corner-bl { bottom: 20px; left: 20px;  border-width: 0 0 2.5px 2.5px; border-bottom-left-radius: 6px; }
.corner-br { bottom: 20px; right: 20px; border-width: 0 2.5px 2.5px 0; border-bottom-right-radius: 6px; }

.scanner-bed-icon  { font-size: 3.25rem; color: #f1b21c; margin-bottom: 1rem; opacity: 0.9; }
.scanner-bed-title { color: #fff; font-weight: 700; font-size: 1.35rem; margin-bottom: 0.25rem; }
.scanner-bed-desc  { color: #8fa0ba; font-size: 0.85rem; margin-bottom: 1.5rem; }
.scanner-bed-actions { display: flex; gap: 1rem; z-index: 10; }

/* Buttons */
.btn-scan-gold {
  background-color: #f1b21c; color: #0b1329; border: none;
  font-weight: 700; padding: 0.625rem 1.75rem; border-radius: 8px;
  display: inline-flex; align-items: center; gap: 0.5rem;
  transition: background-color 0.2s, transform 0.1s;
}
.btn-scan-gold:hover   { background-color: #d99e12; color: #0b1329; }
.btn-scan-gold:active  { transform: scale(0.97); }
.btn-scan-outline {
  background-color: transparent; color: #fff;
  border: 1.5px solid rgba(255,255,255,0.2); font-weight: 600;
  padding: 0.625rem 1.75rem; border-radius: 8px;
  display: inline-flex; align-items: center; gap: 0.5rem;
  transition: border-color 0.2s, background-color 0.2s, transform 0.1s;
}
.btn-scan-outline:hover  { border-color: #fff; background-color: rgba(255,255,255,0.05); color: #fff; }
.btn-scan-outline:active { transform: scale(0.97); }
.btn-scan-outline-dark {
  background-color: var(--surface-2); color: var(--text-secondary); border: none;
  font-weight: 600; padding: 0.625rem 1rem; border-radius: 8px;
  display: inline-flex; align-items: center; gap: 0.5rem;
  font-size: 0.85rem; transition: background-color 0.2s;
}
.btn-scan-outline-dark:hover    { background-color: var(--border); }
.btn-scan-outline-dark:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-scan-stop {
  background-color: #dc2626; color: #fff; border: none;
  font-weight: 700; padding: 0.625rem 1.75rem; border-radius: 8px;
  display: inline-flex; align-items: center; gap: 0.5rem;
  transition: background-color 0.2s, transform 0.1s;
}
.btn-scan-stop:hover   { background-color: #b91c1c; }
.btn-scan-stop:active  { transform: scale(0.97); }
.btn-scan-stop:disabled { opacity: 0.6; cursor: not-allowed; }

/* Scan-side toggle */
.scan-side-toggle { display: flex; background-color: var(--surface-2); border-radius: 8px; padding: 3px; gap: 3px; }
.scan-side-btn { border: none; background: transparent; color: var(--text-secondary); font-weight: 600; font-size: 0.78rem; padding: 0.4rem 0.7rem; border-radius: 6px; cursor: pointer; transition: all 0.15s; }
.scan-side-btn.active { background-color: #f1b21c; color: #0b1329; }

/* ── Live PDF/Image Preview ───────────────────────────────── */
.doc-preview-area {
  position: absolute; inset: 0; background-color: #0d172a;
  border-radius: 12px; display: flex; align-items: center; justify-content: center;
  padding: 0.75rem 1rem; z-index: 20;
}
.doc-preview-canvas-wrap {
  position: relative; z-index: 1;
  height: 100%; width: 100%; max-height: 100%; max-width: 100%;
  display: flex; align-items: center; justify-content: center;
  padding: 6px; border: 1px solid rgba(255,255,255,0.12);
  border-radius: 10px; background-color: rgba(255,255,255,0.02); overflow: hidden;
}
.doc-preview-canvas-wrap.fit-page { align-items: flex-start; justify-content: center; overflow-y: auto; overflow-x: hidden; }
#pdfPreviewCanvas, #imgPreview {
  height: auto; width: auto; max-height: 100%; max-width: 100%;
  border-radius: 6px; box-shadow: 0 4px 18px rgba(0,0,0,0.45);
  background: #fff; transition: transform 0.25s ease;
}
/* Fit-to-Page: the canvas/image width is computed in JS to exactly match the
   wrap's width, so it must NOT be capped by max-height here too — that cap
   was fighting the JS scale and shrinking+left-aligning the page (the "stuck
   to one side" bug). Let it use the full width and scroll vertically instead. */
.doc-preview-canvas-wrap.fit-page #pdfPreviewCanvas,
.doc-preview-canvas-wrap.fit-page #imgPreview {
  max-height: none;
  width: 100%;
  max-width: 100%;
}
.doc-preview-toolbar { position: absolute; top: 12px; right: 12px; z-index: 30; display: flex; align-items: center; gap: 8px; }
.preview-fit-toggle  { display: flex; background-color: rgba(255,255,255,0.10); border-radius: 20px; padding: 3px; gap: 2px; }
.preview-fit-btn {
  border: none; background: transparent; color: rgba(255,255,255,0.75);
  font-size: 0.72rem; font-weight: 600; padding: 5px 12px; border-radius: 16px;
  cursor: pointer; transition: background-color 0.2s, color 0.2s; white-space: nowrap;
}
.preview-fit-btn:hover  { color: #fff; }
.preview-fit-btn.active { background-color: #f1b21c; color: #0b1329; }
.preview-rotate-btn {
  width: 30px; height: 30px; border-radius: 50%; border: none;
  background-color: rgba(255,255,255,0.10); color: #fff;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem; transition: background-color 0.2s;
}
.preview-rotate-btn:hover { background-color: rgba(241,178,28,0.85); color: #0b1329; }
.preview-nav-arrow {
  position: absolute; top: 50%; transform: translateY(-50%);
  width: 38px; height: 38px; border-radius: 50%; border: none;
  background-color: rgba(255,255,255,0.12); color: #fff;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background-color 0.2s; z-index: 21;
}
.preview-nav-arrow:hover    { background-color: rgba(241,178,28,0.85); color: #0b1329; }
.preview-nav-arrow:disabled { opacity: 0.3; cursor: not-allowed; }
.preview-nav-prev { left: 12px; }
.preview-nav-next { right: 12px; }
.doc-preview-page-indicator {
  position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
  background-color: rgba(0,0,0,0.5); color: #fff;
  font-size: 0.75rem; font-weight: 600; padding: 3px 12px; border-radius: 20px;
}
/* Multiple-PDF mode: strip for browsing between the separate scanned files */
.doc-preview-file-nav {
  position: absolute; top: 12px; left: 50%; transform: translateX(-50%);
  background-color: rgba(241,178,28,0.92); color: #0b1329;
  font-size: 0.75rem; font-weight: 700; padding: 4px 6px 4px 10px; border-radius: 20px;
  display: flex; align-items: center; gap: 8px; z-index: 30;
}
.file-nav-arrow {
  border: none; background-color: rgba(11,19,41,0.12); color: #0b1329;
  width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center;
  justify-content: center; cursor: pointer; transition: background-color 0.15s;
}
.file-nav-arrow:hover    { background-color: rgba(11,19,41,0.25); }
.file-nav-arrow:disabled { opacity: 0.35; cursor: not-allowed; }

/* ── Folder Browse Modal ──────────────────────────────────── */
.folder-modal-backdrop {
  position: fixed; inset: 0; background-color: rgba(11,19,41,0.55);
  z-index: 2000; display: flex; align-items: center; justify-content: center;
}
.folder-modal {
  background: var(--surface); border-radius: 14px; width: 480px;
  max-width: 92vw; max-height: 80vh;
  display: flex; flex-direction: column; overflow: hidden;
  box-shadow: 0 12px 40px rgba(0,0,0,0.3);
}
.folder-modal-header { background-color: #0b1329; color: #fff; font-weight: 700; padding: 1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; }
.folder-modal-close  { background: none; border: none; color: #8fa0ba; font-size: 1rem; cursor: pointer; }
.folder-modal-close:hover { color: #fff; }
.folder-modal-path { padding: 0.65rem 1.25rem; font-size: 0.78rem; color: var(--text-secondary); background-color: var(--surface-2); border-bottom: 1px solid var(--border); word-break: break-all; }
.folder-modal-list { overflow-y: auto; flex: 1; padding: 0.5rem; min-height: 220px; }
.folder-modal-newfolder { padding: 0.65rem 1.25rem; border-top: 1px solid var(--border); background-color: var(--surface-2); }
.folder-modal-newfolder-input { width: 100%; padding: 0.55rem 0.75rem; font-size: 0.82rem; border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); background-color: var(--surface); }
.folder-modal-newfolder-input:focus { outline: none; border-color: #f1b21c; }
.folder-modal-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.75rem; border-radius: 8px; cursor: pointer; font-size: 0.88rem; color: var(--text-primary); }
.folder-modal-item:hover { background-color: var(--surface-2); }
.folder-modal-item i { color: #f1b21c; }
.folder-modal-loading, .folder-modal-empty { padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
.folder-modal-footer { padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; gap: 0.75rem; }

/* ── Scanner Footer & Progress ────────────────────────────── */
.scanner-footer-text { font-size: 0.85rem; color: var(--text-secondary); margin-top: 1rem; border-top: 1px solid var(--border); padding-top: 0.875rem; }
.scan-progress-container { background-color: var(--border); border-radius: 10px; height: 8px; width: 100%; overflow: hidden; margin-top: 0.5rem; }
.scan-progress-bar { background-color: #f1b21c; height: 100%; width: 0%; transition: width 0.1s ease-out; }

/* ── Scanner Settings ─────────────────────────────────────── */
.settings-row { display: flex; align-items: center; justify-content: space-between; padding: 0.875rem 0; border-bottom: 1px solid var(--border); }
.settings-row:last-child { border-bottom: none; }
.settings-label { font-weight: 600; color: var(--text-secondary); font-size: 0.9rem; }
.settings-select {
  background-color: var(--surface-2); border: none; border-radius: 8px;
  padding: 0.5rem 2rem 0.5rem 0.875rem; font-size: 0.875rem;
  color: var(--text-primary); font-weight: 600; width: 160px; cursor: pointer;
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
  background-repeat: no-repeat; background-position: right 0.875rem center;
  background-size: 12px 12px; appearance: none; transition: background-color 0.2s;
}
.settings-select:hover { background-color: var(--border); }
.settings-select:focus { outline: 1.5px solid #f1b21c; }
[data-theme="dark"] .settings-select,
[data-theme="dark"] select.info-input {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%237090b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
}

/* Custom gold toggle switches */
.custom-switch { position: relative; display: inline-block; width: 46px; height: 26px; }
.custom-switch input { opacity: 0; width: 0; height: 0; }
.switch-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .2s; border-radius: 26px; }
.switch-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .2s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
input:checked + .switch-slider { background-color: #f1b21c; }
input:checked + .switch-slider:before { transform: translateX(20px); }

/* ── Metadata / Info Inputs ───────────────────────────────── */
.info-field { margin-bottom: 1.25rem; }
.info-label { display: block; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.75px; margin-bottom: 0.5rem; }
.info-input {
  background-color: var(--surface-2); border: none; border-radius: 8px;
  padding: 0.675rem 0.875rem; font-size: 0.9rem; color: var(--text-primary);
  font-weight: 600; width: 100%; transition: all 0.2s;
}
.info-input:hover  { background-color: var(--border); }
.info-input:focus  { outline: 1.5px solid #f1b21c; background-color: var(--surface); box-shadow: 0 0 0 3px rgba(241,178,28,0.12); }
select.info-input {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
  background-repeat: no-repeat; background-position: right 0.875rem center;
  background-size: 14px 14px; appearance: none; padding-right: 2.25rem; cursor: pointer;
}

/* Save / Reset buttons */
.btn-save-document {
  background-color: #0b1329; color: #fff; border: none;
  font-weight: 700; padding: 0.825rem 1.5rem; border-radius: 8px;
  width: 100%; margin-top: 0.5rem;
  display: flex; align-items: center; justify-content: center; gap: 0.5rem;
  transition: background-color 0.2s, transform 0.1s; cursor: pointer;
}
.btn-save-document:hover  { background-color: #1a2f4e; }
.btn-save-document:active { transform: scale(0.98); }
.btn-reset-document { background-color: var(--border); color: var(--text-secondary); border: none; font-weight: 600; padding: 0.825rem; border-radius: 8px; transition: background-color 0.2s; cursor: pointer; }
.btn-reset-document:hover { background-color: var(--surface-2); color: var(--text-primary); }

/* ── Recent Uploads Table ─────────────────────────────────── */
.recent-uploads-card  { margin-top: 1.5rem; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
.recent-table-responsive { border-radius: 12px; overflow: hidden; border: 1px solid var(--border); margin: 1rem; }
.recent-table { width: 100%; border-collapse: collapse; text-align: left; background-color: var(--surface); }
.recent-table th { background-color: var(--surface-2); color: var(--text-secondary); font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.75px; padding: 1rem; border-bottom: 1px solid var(--border); }
.recent-table td { padding: 1rem; border-bottom: 1px solid var(--border); color: var(--text-primary); font-size: 0.875rem; vertical-align: middle; }
.recent-table tr:last-child td { border-bottom: none; }
.recent-table tr:hover td { background-color: var(--surface-2); }
.badge-status { padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; display: inline-block; letter-spacing: 0.5px; }
.badge-approved { background-color: #dcfce7; color: #15803d; }
.badge-changed { background-color: #fee2e2; color: #b91c1c; }
.badge-pending  { background-color: #fef9c3; color: #a16207; }

/* Ensure toast appears above the navbar */
#toast-container { z-index: 9999 !important; }

/* ── Scan KPI Banner ──────────────────────────────────────── */
.scan-kpi-card {
  background: linear-gradient(135deg, rgba(241,178,28,0.08) 0%, rgba(11,19,41,0.04) 100%);
  border-left: 5px solid #f1b21c;
}
.scan-kpi-body {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1.5rem; flex-wrap: wrap; padding: 1.25rem 1.5rem;
}
.scan-kpi-info { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 260px; }
.scan-kpi-icon {
  width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
  background-color: rgba(241,178,28,0.15); color: #f1b21c;
  display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
}
.scan-kpi-title { font-weight: 800; color: var(--text-primary); margin: 0 0 0.2rem 0; font-size: 1rem; }
.scan-kpi-sub   { color: var(--text-secondary); font-size: 0.8rem; margin: 0; }
.scan-kpi-progress-wrap { flex: 1.4; min-width: 240px; }
.scan-kpi-progress-track { height: 10px; background-color: var(--border); border-radius: 8px; overflow: hidden; }
.scan-kpi-progress-fill  { height: 100%; background: linear-gradient(90deg, #f1b21c, #d99e12); border-radius: 8px; transition: width 0.4s ease; }
.scan-kpi-progress-labels { display: flex; justify-content: space-between; margin-top: 0.4rem; font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
.scan-kpi-percent { flex-shrink: 0; text-align: center; min-width: 110px; }
.scan-kpi-percent-value { font-size: 1.8rem; font-weight: 900; color: var(--text-primary); line-height: 1; }
.scan-kpi-percent-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-top: 0.2rem; }
</style>

<div class="scan-page-wrapper">
  <div class="scan-container">

    <?php if ($message): ?>
      <div class="alert alert-<?= $msgType ?> alert-dismissible fade show alert-auto-dismiss mb-4" role="alert">
        <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
        <?= esc($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="uploadForm" novalidate>
      <!-- Real file(s) staged here right before submit: either the bytes
           fetched from the local scan-agent (one input per physical sheet),
           or a manual pick funnelled through the same field. The server
           never sees a local path now — only actual uploaded bytes. -->
      <input type="file" name="document_files[]" id="scannedFilesInput" class="d-none" multiple>
      <input type="hidden" name="final_save_dir" id="finalSaveDir" value="">
      <div id="scanTokenHolder" data-token="" class="d-none"></div>

      <div class="row g-4">
        <!-- ── LEFT COLUMN: Document Scanner (9/12) ───────── -->
        <div class="col-lg-9">
          <div class="scan-card h-100">
            <div class="scan-card-header">
              <h5 class="scan-card-title">
                <i class="fas fa-expand-alt"></i> Document Scanner
              </h5>
              <span class="scan-card-subtitle">Place document on scanner bed or upload a file</span>
            </div>

            <div class="scan-card-body d-flex flex-column justify-content-between">
              <!-- Scanner Bed -->
              <div class="scanner-bed-container" id="uploadZone">
                <div class="scanner-corner corner-tl"></div>
                <div class="scanner-corner corner-tr"></div>
                <div class="scanner-corner corner-bl"></div>
                <div class="scanner-corner corner-br"></div>

                <!-- Fit-mode toolbar: always visible, even before a scan exists -->
                <div class="doc-preview-toolbar">
                  <div class="preview-fit-toggle">
                    <button type="button" class="preview-fit-btn" id="btnFitStandard" data-fit="standard">Standard</button>
                    <button type="button" class="preview-fit-btn active" id="btnFitPage" data-fit="page">Fit to Page</button>
                  </div>
                  <button type="button" class="preview-rotate-btn" id="btnRotatePreview" title="Rotate 90°">
                    <i class="fas fa-rotate-right"></i>
                  </button>
                </div>

                <div class="scanner-bed-icon" id="scanBedIcon">
                  <i class="fas fa-print"></i>
                </div>
                <div class="scanner-bed-title" id="scanStatusTitle">Click to Scan</div>
                <div class="scanner-bed-desc"  id="scanStatusDesc">Or connect scanner via USB / Network</div>

                <div class="scanner-bed-actions">
                  <button type="button" class="btn-scan-gold" id="btnStartScan">
                    <i class="fas fa-expand"></i> Start Scan
                  </button>
                  <button type="button" class="btn-scan-stop d-none" id="btnStopScan">
                    <i class="fas fa-stop"></i> Stop Scan
                  </button>
                </div>

                <!-- Hidden file input -->
                <input type="file" name="document_file" id="docFile" class="d-none"
                       accept=".pdf,.jpg,.jpeg,.png,.tiff,.tif">

                <!-- Live document preview (shown after scan or file select) -->
                <div class="doc-preview-area d-none" id="docPreviewArea">
                  <button type="button" class="preview-nav-arrow preview-nav-prev" id="btnPrevPage" title="Previous page">
                    <i class="fas fa-chevron-left"></i>
                  </button>
                  <div class="doc-preview-canvas-wrap fit-page" id="docPreviewCanvasWrap">
                    <canvas id="pdfPreviewCanvas" class="d-none"></canvas>
                    <img    id="imgPreview"        class="d-none" alt="Document preview">
                  </div>
                  <button type="button" class="preview-nav-arrow preview-nav-next" id="btnNextPage" title="Next page">
                    <i class="fas fa-chevron-right"></i>
                  </button>
                  <div class="doc-preview-page-indicator" id="pageIndicator">Page 1 of 1</div>
                  <div class="doc-preview-file-nav d-none" id="fileNav">
                    <button type="button" class="file-nav-arrow" id="btnPrevFile" title="Previous document">
                      <i class="fas fa-caret-left"></i>
                    </button>
                    <span id="fileNavIndicator">Document 1 of 1</span>
                    <button type="button" class="file-nav-arrow" id="btnNextFile" title="Next document">
                      <i class="fas fa-caret-right"></i>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Footer: status + progress bar -->
              <div>
                <div class="scanner-footer-text d-flex justify-content-between align-items-center">
                  <span id="scannerStatus">Ready to scan</span>
                  <div class="w-50 d-none" id="progressWrapper">
                    <div class="scan-progress-container">
                      <div class="scan-progress-bar" id="progressFill"></div>
                    </div>
                    <small class="text-secondary mt-1 d-block text-end" id="progressText">Scanning…</small>
                  </div>
                </div>
              </div>
            </div>
          </div><!-- /.scan-card -->
        </div><!-- /.col-lg-9 -->

        <!-- ── RIGHT COLUMN: Settings + Document Info (3/12) ── -->
        <div class="col-lg-3 d-flex flex-column gap-4">

          <!-- Document Info Card (moved above Scanner Settings so the
               "Save Scans To" browse control is the first thing seen) -->
          <div class="scan-card">
            <div class="scan-card-header">
              <h5 class="scan-card-title"><i class="fas fa-tag"></i> Document Info</h5>
            </div>
            <div class="scan-card-body">
              <!-- Folder + other classification fields are kept as hidden inputs
                   so saving still works; only Save Scans To is shown per request.
                   The folder defaults to the first active folder. -->
              <select class="d-none" id="folderId" name="folder_id">
                <?php if ($folders && $folders->num_rows > 0): ?>
                  <?php $firstFolder = true; ?>
                  <?php while ($f = $folders->fetch_assoc()): ?>
                    <option value="<?= esc($f['folder_id']) ?>" <?= $firstFolder ? 'selected' : '' ?>><?= esc($f['folder_name']) ?></option>
                    <?php $firstFolder = false; ?>
                  <?php endwhile; ?>
                <?php endif; ?>
              </select>
              <input type="hidden" id="fieldBranch" name="branch" value="">
              <input type="hidden" id="fieldDocType" name="doc_type" value="">
              <input type="hidden" id="fieldFileNo" name="file_no" value="">
              <input type="hidden" id="fieldDocYear" name="doc_year" value="<?= date('Y') ?>">
              <input type="hidden" id="fieldPhase" name="phase" value="">
              <input type="hidden" id="fieldPlot" name="plot" value="">

              <!-- Save-To folder selector -->
              <div class="info-field">
                <label class="info-label">Save Scans To</label>
                <div class="d-flex gap-2 align-items-center">
                  <input type="text" class="info-input" id="saveDirDisplay"
                         value="<?= esc(rtrim(SCAN_DIRECTORY, '/\\')) ?>"
                         readonly style="font-size:0.78rem;cursor:pointer;"
                         onclick="openScanFolderPicker()">
                  <button type="button" class="btn-scan-outline-dark flex-shrink-0"
                          onclick="openScanFolderPicker()" title="Browse folders">
                    <i class="fas fa-folder-open"></i>
                  </button>
                  <input type="file" id="scanBrowseFileInput"
                         webkitdirectory directory multiple
                         style="display:none" onchange="handleScanBrowsedFolder(this)">
                </div>
              </div>

              <!-- Action buttons -->
              <div class="d-flex gap-2 mt-auto pt-2">
                <button type="reset" class="btn-reset-document flex-shrink-0" id="btnReset" onclick="resetScanPage()">
                  <i class="fas fa-undo"></i>
                </button>
                <button type="button" class="btn-save-document" id="btnSaveDoc" onclick="handleSaveClick()">
                  <i class="fas fa-save"></i> Save
                </button>
              </div>
            </div>
          </div>

          <!-- Scanner Settings Card -->
          <div class="scan-card flex-fill">
            <div class="scan-card-header">
              <h5 class="scan-card-title"><i class="fas fa-sliders-h"></i> Scanner Settings</h5>
            </div>
            <div class="scan-card-body py-2">
              <div class="settings-row">
                <span class="settings-label">Resolution</span>
                <select class="settings-select" id="settingResolution">
                  <option value="100 DPI">100 DPI</option>
                  <option value="150 DPI" selected>150 DPI</option>
                  <option value="200 DPI">200 DPI</option>
                  <option value="300 DPI">300 DPI</option>
                  <option value="400 DPI">400 DPI</option>
                  <option value="600 DPI">600 DPI</option>
                  <option value="1200 DPI">1200 DPI</option>
                </select>
              </div>
              <div class="settings-row">
                <span class="settings-label">Colour Mode</span>
                <select class="settings-select" id="settingColorMode">
                  <option value="Colour">Colour</option>
                  <option value="Greyscale" selected>Greyscale</option>
                  <option value="Black &amp; White">Black &amp; White</option>
                </select>
              </div>
              <div class="settings-row">
                <span class="settings-label">Paper Size</span>
                <select class="settings-select" id="settingPaperSize">
                  <option value="A4" selected>A4</option>
                  <option value="Letter">Letter</option>
                  <option value="Legal">Legal</option>
                </select>
              </div>
              <div class="settings-row">
                <span class="settings-label">Scan Side</span>
                <div class="scan-side-toggle" id="settingScanSide" data-value="duplex">
                  <button type="button" class="scan-side-btn"        data-side="single">Single-Side</button>
                  <button type="button" class="scan-side-btn active" data-side="duplex">Duplex</button>
                </div>
              </div>
              <div class="settings-row">
                <span class="settings-label">PDF Output</span>
                <div class="scan-side-toggle" id="settingPdfOutput" data-value="single"
                     title="Single PDF: every sheet fed through the scanner is combined into one PDF (you'll be asked to name it). Multiple PDF: each physical sheet (both sides, if Duplex) is saved as its own separate PDF, numbered 1, 2, 3…">
                  <button type="button" class="scan-side-btn active" data-side="single">Single PDF</button>
                  <button type="button" class="scan-side-btn"        data-side="multiple">Multiple PDF</button>
                </div>
              </div>
              <div class="settings-row">
                <span class="settings-label">Auto Deskew</span>
                <label class="custom-switch">
                  <input type="checkbox" id="settingDeskew" checked>
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="settings-row">
                <span class="settings-label">OCR</span>
                <label class="custom-switch">
                  <input type="checkbox" id="settingOcr">
                  <span class="switch-slider"></span>
                </label>
              </div>
             
            </div>
          </div>

        </div><!-- /.col-lg-3 -->
      </div><!-- /.row -->
    </form>

  </div><!-- /.scan-container -->
</div><!-- /.scan-page-wrapper -->

<!-- ── Folder Browse Modal ──────────────────────────────────── -->
<div class="folder-modal-backdrop d-none" id="folderModalBackdrop">
  <div class="folder-modal">
    <div class="folder-modal-header">
      <span id="folderModalTitle"><i class="fas fa-folder-open me-2"></i>Select Save Folder</span>
      <button class="folder-modal-close" onclick="closeFolderModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="folder-modal-path" id="folderModalPath">/</div>
    <div class="folder-modal-list" id="folderModalList">
      <div class="folder-modal-loading"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div>
    </div>
    <div class="folder-modal-newfolder">
      <input type="text" class="folder-modal-newfolder-input"
             id="newFolderInput" placeholder="New folder name…"
             onkeydown="if(event.key==='Enter')createNewFolder()">
    </div>
    <div class="folder-modal-footer">
      <button type="button" class="btn-scan-outline-dark" onclick="folderModalUp()">
        <i class="fas fa-arrow-up"></i> Up
      </button>
      <button type="button" class="btn-scan-outline-dark" onclick="createNewFolder()">
        <i class="fas fa-folder-plus"></i> New Folder
      </button>
      <button type="button" class="btn-save-document" style="width:auto;margin:0;" onclick="confirmFolderChoice()" id="folderModalSelectBtn">
        <i class="fas fa-check"></i> Select
      </button>
    </div>
  </div>
</div>

<!-- ── Filename Prompt Modal (Single PDF mode only) ─────────────── -->
<div class="folder-modal-backdrop d-none" id="filenameModalBackdrop">
  <div class="folder-modal" style="max-width:420px;">
    <div class="folder-modal-header">
      <span><i class="fas fa-file-pdf me-2"></i>Name This Scan</span>
      <button class="folder-modal-close" onclick="closeFilenameModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div style="padding:1.25rem;">
      <label class="info-label" style="display:block;margin-bottom:0.5rem;">File name</label>
      <div class="d-flex align-items-center gap-2">
        <input type="text" class="info-input" id="filenameModalInput"
               placeholder="e.g. Invoice_2026" style="flex:1;"
               onkeydown="if(event.key==='Enter')confirmFilenameChoice()">
        <span class="text-secondary">.pdf</span>
      </div>
    </div>
    <div class="folder-modal-footer">
      <button type="button" class="btn-scan-outline-dark" onclick="closeFilenameModal()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button type="button" class="btn-save-document" style="width:auto;margin:0;" onclick="confirmFilenameChoice()">
        <i class="fas fa-expand"></i> Start Scan
      </button>
    </div>
  </div>
</div>

<!-- ── PDF.js (self-hosted — see assets/vendor; a failed CDN load here used
     to throw "pdfjsLib is not defined" and silently kill every script below
     it, including the Start Scan button's click handler) ──────────────── -->
<script src="../assets/vendor/pdfjs/pdf.min.js"></script>
<script>
// ──────────────────────────────────────────────────────────────
// Scan Page JavaScript
// ──────────────────────────────────────────────────────────────

// Configure PDF.js worker
pdfjsLib.GlobalWorkerOptions.workerSrc =
  '../assets/vendor/pdfjs/pdf.worker.min.js';

// ── Local Scan Agent ──────────────────────────────────────────
// The scanner is attached to THIS PC, not the DMS server, so scanning is
// driven by a small local program (scan-agent/scan_agent.ps1) listening on
// localhost. Port must match PORT in that script's own CONFIG block.
const AGENT_PORT = <?= (int) SCAN_AGENT_PORT ?>;
const AGENT_BASE = 'http://127.0.0.1:' + AGENT_PORT;

// ── State ──────────────────────────────────────────────────────
let pdfDoc        = null;
let currentPage   = 1;
let totalPages    = 1;
let rotationDeg   = 0;
let currentFitMode = 'page';
// Every scan batch gets a token from the agent identifying the temp folder
// it wrote the file(s) into on the CLIENT — this replaces the old
// server-side scan directory path entirely.
let lastScanToken   = '';
let lastScannedFile = '';
let folderModalCurrentPath = '';

// Multiple-PDF mode: every file produced by the last scan batch (all share
// lastScanToken). Single-PDF mode keeps this as a 1-item list so save/reset
// logic doesn't need to branch on the mode.
let scannedFilesList  = [];
let currentFileIndex  = 0;

function parseFolderName(folderName) {
  if (!folderName) return null;
  const nameOnly = folderName.split('/').pop().split('\\').pop();
  const parts = nameOnly.split(/[_-]/);
  if (parts.length !== 3) return null;
  const phase = parts[0].trim();
  const sector = parts[1].trim();
  const plot = parts[2].trim();
  if (!phase || !sector || !plot) return null;
  return { phase, sector, plot };
}

function handleFolderChange(silent = false) {
  const select = document.getElementById('folderId');
  if (!select) return;
  const selectedOption = select.options[select.selectedIndex];
  if (!selectedOption || !select.value) {
    document.getElementById('fieldPhase').value = '';
    document.getElementById('fieldBranch').value = '';
    document.getElementById('fieldPlot').value = '';
    return;
  }
  const folderName = selectedOption.text;
  const parsed = parseFolderName(folderName);
  if (parsed) {
    document.getElementById('fieldPhase').value = parsed.phase;
    document.getElementById('fieldBranch').value = parsed.sector;
    document.getElementById('fieldPlot').value = parsed.plot;
  } else {
    document.getElementById('fieldPhase').value = '';
    document.getElementById('fieldBranch').value = '';
    document.getElementById('fieldPlot').value = '';
    if (!silent) {
      if (typeof showToast === 'function') {
        showToast('Warning: Folder name does not match expected PHASE_SECTOR_PLOTNO format.', 'warning');
      } else {
        alert('Warning: Folder name does not match expected PHASE_SECTOR_PLOTNO format.');
      }
    }
  }
}


// ── Scan-Side Toggle ───────────────────────────────────────────
document.getElementById('settingScanSide').addEventListener('click', function (e) {
  const btn = e.target.closest('.scan-side-btn');
  if (!btn) return;
  this.querySelectorAll('.scan-side-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  this.dataset.value = btn.dataset.side;
});

// ── PDF Output Toggle (Single PDF vs Multiple PDF) ──────────────
document.getElementById('settingPdfOutput').addEventListener('click', function (e) {
  const btn = e.target.closest('.scan-side-btn');
  if (!btn) return;
  this.querySelectorAll('.scan-side-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  this.dataset.value = btn.dataset.side;
});

// ── File input (kept for drag-and-drop uploads) ─────────────────
document.getElementById('docFile').addEventListener('change', function () {
  if (!this.files[0]) return;
  loadLocalFile(this.files[0]);
});

// ── Drag & Drop ────────────────────────────────────────────────
const uploadZone = document.getElementById('uploadZone');
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
uploadZone.addEventListener('drop', e => {
  e.preventDefault();
  uploadZone.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (!f) return;
  const dt = new DataTransfer();
  dt.items.add(f);
  document.getElementById('docFile').files = dt.files;
  loadLocalFile(f);
});

// ── Load a locally-selected file into the preview ─────────────
function loadLocalFile(file) {
  const isPdf = file.type === 'application/pdf';
  const url   = URL.createObjectURL(file);
  setStatus('Previewing: ' + file.name);
  if (isPdf) {
    loadPdfFromUrl(url);
  } else {
    loadImageFromUrl(url);
  }
}

// ── Start Scan → local scan-agent (127.0.0.1) ──────────────────
// Single PDF mode asks the user to name the file before scanning;
// Multiple PDF mode (files are numbered 1, 2, 3…) goes straight through.
document.getElementById('btnStartScan').addEventListener('click', handleStartScanClick);
document.getElementById('btnStopScan').addEventListener('click', stopScan);

function handleStartScanClick() {
  const pdfOutput = document.getElementById('settingPdfOutput').dataset.value; // 'single' | 'multiple'
  if (pdfOutput === 'single') {
    openFilenameModal();
  } else {
    startScan(null);
  }
}

function openFilenameModal() {
  const input = document.getElementById('filenameModalInput');
  input.value = '';
  document.getElementById('filenameModalBackdrop').classList.remove('d-none');
  setTimeout(() => input.focus(), 50);
}
function closeFilenameModal() {
  document.getElementById('filenameModalBackdrop').classList.add('d-none');
}
function confirmFilenameChoice() {
  const name = document.getElementById('filenameModalInput').value.trim();
  if (!name) {
    if (typeof showToast === 'function') showToast('Please enter a file name.', 'warning');
    else alert('Please enter a file name.');
    return;
  }
  closeFilenameModal();
  startScan(name);
}
// Close filename modal on backdrop click
document.getElementById('filenameModalBackdrop').addEventListener('click', function (e) {
  if (e.target === this) closeFilenameModal();
});

let activeScanController = null;
let scanWasStopped       = false;

async function startScan(customFilename) {
  const btn     = document.getElementById('btnStartScan');
  const stopBtn = document.getElementById('btnStopScan');
  btn.disabled = true;
  btn.classList.add('d-none');
  stopBtn.classList.remove('d-none');
  stopBtn.disabled = false;
  stopBtn.innerHTML = '<i class="fas fa-stop"></i> Stop Scan';

  scanWasStopped     = false;
  activeScanController = new AbortController();

  setStatus('Contacting scanner…');
  showProgress(true, 'Initialising NAPS2…', 10);

  const isDuplex   = document.getElementById('settingScanSide').dataset.value === 'duplex';
  const pdfOutput  = document.getElementById('settingPdfOutput').dataset.value; // 'single' | 'multiple'
  const body = {
    resolution:  document.getElementById('settingResolution').value,
    color_mode:  document.getElementById('settingColorMode').value,
    paper_size:  document.getElementById('settingPaperSize').value,
    duplex:      isDuplex,
    deskew:      document.getElementById('settingDeskew').checked,
    ocr:         document.getElementById('settingOcr').checked,
    pdf_output:  pdfOutput,
  };
  if (customFilename) {
    body.custom_filename = customFilename;
  }

  try {
    showProgress(true, 'Waiting for scanner…', 40);
    let resp;
    try {
      resp = await fetch(AGENT_BASE + '/scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        signal: activeScanController.signal,
      });
    } catch (networkErr) {
      if (networkErr.name === 'AbortError') throw networkErr;
      throw new Error('Could not reach the local scan agent on this PC (' + AGENT_BASE + '). ' +
        'Make sure scan-agent/scan_agent.ps1 is running on this machine — see scan-agent/README.md.');
    }
    showProgress(true, 'Processing image…', 80);

    let data;
    try {
      data = await resp.json();
    } catch {
      throw new Error('Scan agent returned a non-JSON response.');
    }

    if (!data.success) {
      throw new Error(data.error || 'Scan failed for unknown reason.');
    }

    // ── Scan succeeded ────────────────────────────────────────
    showProgress(true, 'Scan complete!', 100);
    setTimeout(() => showProgress(false), 800);

    // data.files is always present: one entry in Single-PDF mode, one
    // entry per physical sheet (front+back combined when Duplex) in
    // Multiple-PDF mode. All of them live in the agent's temp folder under
    // data.token on THIS PC until Save actually uploads them.
    scannedFilesList = data.files;
    currentFileIndex = 0;

    lastScanToken = data.token;
    document.getElementById('scanTokenHolder').dataset.token = data.token;
    lastScannedFile = scannedFilesList[0].filename;

    const totalKb = scannedFilesList.reduce((sum, f) => sum + (f.size || 0), 0);
    setStatus(scannedFilesList.length > 1
      ? `Scanned ${scannedFilesList.length} documents (${Math.round(totalKb / 1024)} KB total)`
      : 'Scanned: ' + scannedFilesList[0].filename + ' (' + Math.round((scannedFilesList[0].size || 0) / 1024) + ' KB)');

    updateFileNav();
    loadScannedFileAt(currentFileIndex);

    if (typeof showToast === 'function') {
      showToast(scannedFilesList.length > 1
        ? `Scan complete — ${scannedFilesList.length} separate PDFs created`
        : 'Scan complete — ' + scannedFilesList[0].filename, 'success');
    }

  } catch (err) {
    showProgress(false);
    if (err.name === 'AbortError' || scanWasStopped) {
      setStatus('Scan stopped.');
      if (typeof showToast === 'function') {
        showToast('Scan stopped.', 'warning');
      }
    } else {
      setStatus('Scan error — see details below');
      if (typeof showToast === 'function') {
        showToast('Scan failed: ' + err.message, 'error', 8000);
      } else {
        alert('Scan failed:\n' + err.message);
      }
    }
  } finally {
    activeScanController = null;
    stopBtn.classList.add('d-none');
    btn.classList.remove('d-none');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-expand"></i> Start Scan';
  }
}

// ── Stop Scan ────────────────────────────────────────────────
async function stopScan() {
  scanWasStopped = true;
  const stopBtn = document.getElementById('btnStopScan');
  stopBtn.disabled = true;
  stopBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Stopping…';
  setStatus('Stopping scan…');

  // Cancel the in-flight request from the browser side FIRST so the UI reacts
  // immediately, instead of waiting on the local agent's /stop round trip before
  // aborting — that was the delay: the "Stop Scan" click used to sit idle
  // until the server request finished before the local fetch was even told
  // to cancel.
  if (activeScanController) {
    activeScanController.abort();
  }

  // Ask the local agent to kill the running NAPS2 process on this PC.
  // Fire this off in parallel (not awaited before the abort above) — it's
  // best effort and shouldn't hold up the UI responding right away.
  try {
    await fetch(AGENT_BASE + '/stop', { method: 'POST' });
  } catch { /* best effort — the client-side abort above already applies */ }
}

// ── PDF Preview via PDF.js ─────────────────────────────────────
async function loadPdfFromUrl(url) {
  try {
    // disableRange/disableStream: the scan output is served by the local agent in
    // one shot (readfile()), so we ask PDF.js to fetch it as a single plain
    // request instead of issuing byte-range requests. On some server/proxy
    // setups a Range request against that endpoint gets a mismatched response,
    // which makes PDF.js fail to render with no visible error — the scan
    // saves fine but the preview never appears. Fetching the whole file in
    // one go avoids that entirely.
    pdfDoc = await pdfjsLib.getDocument({
      url,
      disableRange:  true,
      disableStream: true,
      disableAutoFetch: true
    }).promise;
    totalPages  = pdfDoc.numPages;
    currentPage = 1;
    rotationDeg = 0;
    // Reveal the preview area FIRST. The Fit-to-Page scale calculation
    // below reads docPreviewCanvasWrap's clientWidth, and that element is
    // still display:none (inside the d-none preview area) until showPreview()
    // runs — reading its width beforehand always returns 0, so the "Fit to
    // Page" mode rendered a blank/zero-size canvas. Standard mode happened
    // to look fine because clicking it re-ran showPdfPage() after the area
    // was already visible.
    showPreview();
    await showPdfPage(currentPage);
  } catch (e) {
    console.error('PDF.js error:', e);
    const msg = 'Could not render document preview: ' + e.message;
    if (typeof showToast === 'function') {
      showToast(msg, 'warning', 6000);
    } else {
      alert(msg);
    }
  }
}

async function showPdfPage(pageNum) {
  if (!pdfDoc) return;
  const page      = await pdfDoc.getPage(pageNum);
  const canvas    = document.getElementById('pdfPreviewCanvas');
  const ctx       = canvas.getContext('2d');
  const wrap      = document.getElementById('docPreviewCanvasWrap');
  const isFitPage = currentFitMode === 'page';

  const baseViewport = page.getViewport({ scale: 1, rotation: rotationDeg });
  // Guard against a 0-width wrap (e.g. rendered a frame before layout
  // settles) so we never divide down to an invisible/zero-size canvas.
  const wrapWidth  = wrap.clientWidth  || wrap.parentElement.clientWidth  || baseViewport.width;
  const wrapHeight = wrap.clientHeight || wrap.parentElement.clientHeight || baseViewport.height;
  const scale = isFitPage
    ? wrapWidth / baseViewport.width
    : Math.min((wrapHeight - 16) / baseViewport.height,
               (wrapWidth  - 16) / baseViewport.width);

  const viewport = page.getViewport({ scale, rotation: rotationDeg });
  canvas.height  = viewport.height;
  canvas.width   = viewport.width;

  await page.render({ canvasContext: ctx, viewport }).promise;

  // Show canvas, hide image
  canvas.classList.remove('d-none');
  document.getElementById('imgPreview').classList.add('d-none');

  // Update nav
  document.getElementById('pageIndicator').textContent = `Page ${pageNum} of ${totalPages}`;
  document.getElementById('btnPrevPage').disabled = pageNum <= 1;
  document.getElementById('btnNextPage').disabled = pageNum >= totalPages;
}

function loadImageFromUrl(url) {
  const img = document.getElementById('imgPreview');
  img.src = url;
  img.classList.remove('d-none');
  document.getElementById('pdfPreviewCanvas').classList.add('d-none');
  document.getElementById('pageIndicator').textContent = 'Page 1 of 1';
  document.getElementById('btnPrevPage').disabled = true;
  document.getElementById('btnNextPage').disabled = true;
  pdfDoc     = null;
  totalPages = 1;
  showPreview();
}

function showPreview() {
  document.getElementById('docPreviewArea').classList.remove('d-none');
  document.getElementById('scanBedIcon').style.display      = 'none';
  document.getElementById('scanStatusTitle').style.display  = 'none';
  document.getElementById('scanStatusDesc').style.display   = 'none';
  document.getElementById('btnStartScan').style.display     = 'none';
  document.getElementById('btnStopScan').classList.add('d-none');
}

// ── Page navigation ────────────────────────────────────────────
document.getElementById('btnPrevPage').addEventListener('click', () => {
  if (currentPage > 1) { currentPage--; showPdfPage(currentPage); }
});
document.getElementById('btnNextPage').addEventListener('click', () => {
  if (currentPage < totalPages) { currentPage++; showPdfPage(currentPage); }
});

// ── Multiple-PDF mode: file navigation between the batch's separate PDFs ──
function loadScannedFileAt(index) {
  const entry = scannedFilesList[index];
  if (!entry) return;
  currentFileIndex = index;
  lastScannedFile   = entry.filename;
  const previewUrl = AGENT_BASE + '/files/' + encodeURIComponent(lastScanToken)
                   + '/' + encodeURIComponent(entry.filename);
  loadPdfFromUrl(previewUrl);
}

function updateFileNav() {
  const nav = document.getElementById('fileNav');
  if (scannedFilesList.length > 1) {
    nav.classList.remove('d-none');
    document.getElementById('fileNavIndicator').textContent =
      `Document ${currentFileIndex + 1} of ${scannedFilesList.length}`;
    document.getElementById('btnPrevFile').disabled = currentFileIndex <= 0;
    document.getElementById('btnNextFile').disabled = currentFileIndex >= scannedFilesList.length - 1;
  } else {
    nav.classList.add('d-none');
  }
}

document.getElementById('btnPrevFile').addEventListener('click', () => {
  if (currentFileIndex > 0) { loadScannedFileAt(currentFileIndex - 1); updateFileNav(); }
});
document.getElementById('btnNextFile').addEventListener('click', () => {
  if (currentFileIndex < scannedFilesList.length - 1) { loadScannedFileAt(currentFileIndex + 1); updateFileNav(); }
});

// ── Rotate ────────────────────────────────────────────────────
document.getElementById('btnRotatePreview').addEventListener('click', () => {
  rotationDeg = (rotationDeg + 90) % 360;
  if (pdfDoc) showPdfPage(currentPage);
  else {
    const img = document.getElementById('imgPreview');
    img.style.transform = `rotate(${rotationDeg}deg)`;
  }
});

// ── Fit-mode toggle ───────────────────────────────────────────
[document.getElementById('btnFitStandard'), document.getElementById('btnFitPage')].forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.preview-fit-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentFitMode = this.dataset.fit;
    const wrap = document.getElementById('docPreviewCanvasWrap');
    if (currentFitMode === 'page') {
      wrap.classList.add('fit-page');
    } else {
      wrap.classList.remove('fit-page');
    }
    if (pdfDoc) showPdfPage(currentPage);
  });
});

// ── Progress helpers ──────────────────────────────────────────
function showProgress(visible, text = '', pct = 0) {
  const wrapper = document.getElementById('progressWrapper');
  if (!visible) { wrapper.classList.add('d-none'); return; }
  wrapper.classList.remove('d-none');
  document.getElementById('progressText').textContent = text;
  document.getElementById('progressFill').style.width = pct + '%';
}

function setStatus(msg) {
  document.getElementById('scannerStatus').textContent = msg;
}

// ── Reset page ────────────────────────────────────────────────
function resetScanPage() {
  pdfDoc = null; currentPage = 1; totalPages = 1; rotationDeg = 0;
  // Best-effort: tell the agent it can delete this batch's temp files on
  // the client now that the user discarded them without saving.
  if (lastScanToken) {
    fetch(AGENT_BASE + '/cleanup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: lastScanToken }),
    }).catch(() => {});
  }
  lastScanToken = ''; lastScannedFile = '';
  document.getElementById('scanTokenHolder').dataset.token = '';
  scannedFilesList = []; currentFileIndex = 0;
  document.getElementById('scannedFilesInput').value = '';
  document.getElementById('fileNav').classList.add('d-none');
  document.getElementById('docPreviewArea').classList.add('d-none');
  document.getElementById('scanBedIcon').style.display     = '';
  document.getElementById('scanStatusTitle').style.display = '';
  document.getElementById('scanStatusDesc').style.display  = '';
  document.getElementById('btnStartScan').style.display    = '';
  document.getElementById('btnStopScan').classList.add('d-none');
  document.getElementById('docFile').value = '';
  setStatus('Ready to scan');
  showProgress(false);
}

// ── "Save Scans To" Browse button — same behaviour as the Rename tab ──
// FileSystemDirectoryHandle from a successful native pick, reused by the
// Save button below so the user isn't asked twice.
let scanDirHandle = null;

// Checks whether a FileSystemDirectoryHandle (from showDirectoryPicker)
// contains at least one file. Only looks at direct children — matches how
// the folder-browse flow itself is scoped (one branch's folder, not its
// whole subtree).
async function scanFolderHasFiles(dirHandle) {
  for await (const entry of dirHandle.values()) {
    if (entry.kind === 'file') return true;
  }
  return false;
}

function notifyScanFolderEmpty() {
  const msg = 'There are no files in the selected folder of the selected branch.';
  if (typeof showToast === 'function') showToast(msg, 'warning');
  else alert(msg);
}

async function openScanFolderPicker() {
  if ('showDirectoryPicker' in window) {
    try {
      const dirHandle = await window.showDirectoryPicker({ id: 'dha-scan-folder', mode: 'readwrite' });
      scanDirHandle = dirHandle;
      document.getElementById('saveDirDisplay').value = dirHandle.name;
      if (!(await scanFolderHasFiles(dirHandle))) notifyScanFolderEmpty();
      return;
    } catch (err) {
      if (err.name === 'AbortError') return;
      console.warn('showDirectoryPicker unavailable or cancelled, falling back to input:', err);
    }
  }
  document.getElementById('scanBrowseFileInput').click();
}

function handleScanBrowsedFolder(input) {
  if (!input.files || input.files.length === 0) {
    // An empty folder yields zero File objects from webkitdirectory, so
    // there's no name to read off them either — same generic notice as the
    // native-picker path above.
    notifyScanFolderEmpty();
    input.value = '';
    return;
  }
  const rel        = input.files[0].webkitRelativePath || '';
  const folderName = rel.split('/')[0] || 'Selected Folder';
  scanDirHandle = null; // this path only gives a folder name, not a writable handle
  document.getElementById('saveDirDisplay').value = folderName;
  input.value = ''; // reset so picking the same folder again still fires change
}

// ── Pull the scanned file(s) off THIS PC via the local agent ─────────
// Every entry in scannedFilesList only exists as bytes inside the agent's
// temp folder (identified by lastScanToken) until this runs. Returns
// [{blob, filename}, …] ready either for a local backup write or for
// staging into the real upload field below.
async function fetchScannedBlobsFromAgent() {
  return Promise.all(scannedFilesList.map(async f => {
    const url = AGENT_BASE + '/files/' + encodeURIComponent(lastScanToken)
              + '/' + encodeURIComponent(f.filename);
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('Could not retrieve "' + f.filename + '" from the local scan agent.');
    return { blob: await resp.blob(), filename: f.filename };
  }));
}

// Puts real File objects into the hidden multi-file input so the normal
// form POST uploads actual bytes to the server — this is what makes a
// client-side scan indistinguishable from a manual upload once it reaches
// pages/scan.php's PHP handler.
function stageBlobsForUpload(blobEntries) {
  const dt = new DataTransfer();
  for (const { blob, filename } of blobEntries) {
    dt.items.add(new File([blob], filename, { type: 'application/pdf' }));
  }
  document.getElementById('scannedFilesInput').files = dt.files;
}

// ── Save button: validate, stage the real file bytes, then let the user
//    pick WHERE to save ────────────────────────────────────────────────
// Clicking Save no longer posts the form directly. It first checks the
// usual things (a folder is selected, something was scanned/uploaded),
// pulls any scanned file(s) off this PC via the local agent, and stages
// them into the upload form — then asks where to save:
//   - If the browser supports the File System Access API (Chrome/Edge,
//     served over https:// or localhost), we call showDirectoryPicker(),
//     which opens the REAL native OS folder window (the actual Windows
//     Explorer / Finder dialog) — not an in-page popup — and writes an
//     extra local backup copy there before uploading to the server.
//   - Otherwise (Firefox, Safari, or an insecure http:// address that
//     isn't localhost) that API simply doesn't exist, so we fall back to
//     the in-app folder browser built earlier (used only to tell the
//     server which project subfolder to physically store the upload in).
// Either way, this is a self-contained step — it does not forward to
// Rename or Verify; those remain separate pages visited on their own.
async function handleSaveClick() {
  const folderId       = document.getElementById('folderId').value;
  const fileInput      = document.getElementById('docFile');
  const hasManualFile  = fileInput.files.length > 0;
  const hasScannedDocs = scannedFilesList.length > 0;

  if (!folderId) {
    if (typeof showToast === 'function') showToast('Please select a folder first.', 'warning');
    else alert('Please select a folder first.');
    return;
  }
  if (!hasScannedDocs && !hasManualFile) {
    if (typeof showToast === 'function') showToast('Please scan or upload a document first.', 'warning');
    else alert('Please scan or upload a document first.');
    return;
  }

  let stagedBlobs = [];
  if (hasScannedDocs) {
    try {
      stagedBlobs = await fetchScannedBlobsFromAgent();
      stageBlobsForUpload(stagedBlobs);
    } catch (err) {
      const msg = 'Could not retrieve the scanned document(s) from this PC: ' + err.message;
      if (typeof showToast === 'function') showToast(msg, 'danger');
      else alert(msg);
      return;
    }
  }
  // Manual-upload case (hasManualFile, no scan): document_file already
  // holds the real File the user picked/dropped — nothing to stage.

  if (window.showDirectoryPicker) {
    pickFolderNatively(stagedBlobs);
  } else {
    if (typeof showToast === 'function') {
      showToast('Your browser can\u2019t open a native folder window here — using the in-app browser instead.', 'info');
    }
    openFolderModal('finalSave');
  }
}

// Opens the browser's real, native OS folder-picker window (reusing the
// folder already chosen via the Browse button, if any) and writes an extra
// local backup copy of the scanned/uploaded file(s) into whichever folder
// the user selects, before uploading to the server as usual.
async function pickFolderNatively(stagedBlobs) {
  let dirHandle = scanDirHandle;
  if (!dirHandle) {
    try {
      dirHandle = await window.showDirectoryPicker({ id: 'dha-scan-save', mode: 'readwrite' });
    } catch (err) {
      // User closed/cancelled the native dialog — do nothing and stay put.
      return;
    }
  }

  try {
    const fileInput = document.getElementById('docFile');
    const localFile = fileInput.files.length > 0 ? fileInput.files[0] : null;

    // Which files need a local backup copy: the whole scan batch (already
    // fetched from the agent above), or the single manually-uploaded file.
    const filesToWrite = localFile
      ? [{ blob: localFile, filename: localFile.name }]
      : stagedBlobs;

    for (const { blob, filename } of filesToWrite) {
      const fileHandle = await dirHandle.getFileHandle(filename, { create: true });
      const writable    = await fileHandle.createWritable();
      await writable.write(blob);
      await writable.close();
    }

    if (typeof showToast === 'function') {
      showToast(filesToWrite.length > 1
        ? `Saved ${filesToWrite.length} documents to your chosen folder.`
        : 'Saved a copy to your chosen folder.', 'success');
    }
  } catch (err) {
    console.error('Native folder save failed:', err);
    const msg = 'Could not save to the chosen folder: ' + err.message;
    if (typeof showToast === 'function') showToast(msg, 'danger');
    else alert(msg);
    return; // don't submit if the local save failed — let the user retry
  }

  submitScanForm();
}

function submitScanForm() {
  const btn = document.getElementById('btnSaveDoc');
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
  btn.disabled  = true;
  document.getElementById('uploadForm').submit();
}

// ── Folder Browse Modal (fallback only) ─────────────────────────
// Two modes:
//  - 'scanLocation' (default): choosing where NAPS2 should write the raw
//    scan output, opened from the "Save Scans To" field before scanning.
//    This ALWAYS uses this in-app browser — a webpage can never learn a
//    real server-side path from a native OS dialog, so this is the only
//    way to hand a usable path to the server-side scanner process.
//  - 'finalSave': fallback used only when the browser has no native
//    directory-picker support. Confirming in this mode submits the form.
let folderModalMode = 'scanLocation';

function openFolderModal(mode) {
  folderModalMode = mode || 'scanLocation';

  const titleEl  = document.getElementById('folderModalTitle');
  const selectBtn = document.getElementById('folderModalSelectBtn');
  if (folderModalMode === 'finalSave') {
    titleEl.innerHTML  = '<i class="fas fa-folder-open me-2"></i>Choose Folder to Save Scan';
    selectBtn.innerHTML = '<i class="fas fa-save"></i> Save Here';
  } else {
    titleEl.innerHTML  = '<i class="fas fa-folder-open me-2"></i>Select Save Folder';
    selectBtn.innerHTML = '<i class="fas fa-check"></i> Select';
  }

  document.getElementById('folderModalBackdrop').classList.remove('d-none');
  loadFolderList('');
}
function closeFolderModal() {
  document.getElementById('folderModalBackdrop').classList.add('d-none');
  document.getElementById('newFolderInput').value = '';
}
function confirmFolderChoice() {
  // 'finalSave' (choosing where on the server this upload gets physically
  // stored) is the only mode this modal is opened in now — scanning itself
  // happens on this PC via the local agent, so there's no server-side
  // "where should NAPS2 write its output" location to browse to anymore.
  document.getElementById('finalSaveDir').value = folderModalCurrentPath;
  closeFolderModal();
  submitScanForm();
}
function folderModalUp() {
  if (!folderModalCurrentPath) return;
  const parts = folderModalCurrentPath.replace(/\\/g, '/').split('/');
  parts.pop();
  loadFolderList(parts.join('/') || folderModalCurrentPath);
}
function loadFolderList(path) {
  const list = document.getElementById('folderModalList');
  list.innerHTML = '<div class="folder-modal-loading"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div>';
  fetch('../ajax/browse_folders.php?path=' + encodeURIComponent(path))
    .then(r => r.json())
    .then(data => {
      if (data.error) { list.innerHTML = `<div class="folder-modal-empty">${data.error}</div>`; return; }
      folderModalCurrentPath = data.path;
      document.getElementById('folderModalPath').textContent = data.path;
      if (!data.dirs || data.dirs.length === 0) {
        list.innerHTML = '<div class="folder-modal-empty">No sub-folders here.</div>';
        return;
      }
      list.innerHTML = data.dirs.map(d =>
        `<div class="folder-modal-item" onclick="loadFolderList('${d.path.replace(/\\/g,'\\\\').replace(/'/g,"\\'")}')">
           <i class="fas fa-folder"></i> ${d.name}
         </div>`
      ).join('');
    })
    .catch(() => { list.innerHTML = '<div class="folder-modal-empty">Could not load folders.</div>'; });
}
async function createNewFolder() {
  const name = document.getElementById('newFolderInput').value.trim();
  if (!name) return;
  const newPath = (folderModalCurrentPath + '/' + name).replace(/\/+/g, '/');
  try {
    const resp = await fetch('../ajax/browse_folders.php?path='
      + encodeURIComponent(newPath) + '&create=1');
    const data = await resp.json();
    document.getElementById('newFolderInput').value = '';
    loadFolderList(folderModalCurrentPath);
  } catch { /* silently reload */ loadFolderList(folderModalCurrentPath); }
}

// Close modal on backdrop click
document.getElementById('folderModalBackdrop').addEventListener('click', function (e) {
  if (e.target === this) closeFolderModal();
});

// ── Pagination (recentUploads table) ─────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // ── Folder dropdown: auto-fill Phase/Sector/Plot from folder name ──
  const folderSelect = document.getElementById('folderId');
  if (folderSelect) {
    folderSelect.addEventListener('change', () => handleFolderChange(false));
    // If a folder is already selected on page load, parse it silently (no warning popup)
    if (folderSelect.value) {
      handleFolderChange(true);
    }
  }

  <?php if ($message && $msgType === 'success'): ?>
  if (typeof showToast === 'function') {
    showToast('<?= addslashes($message) ?>', 'success');
  }
  <?php endif; ?>
});
</script>

<?php require_once '../includes/footer.php'; ?>