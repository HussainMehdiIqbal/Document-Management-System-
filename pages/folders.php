<?php
// ============================================================
// pages/folders.php — Folder Management (dms_database schema)
// ============================================================
require_once '../includes/auth.php';
requireLogin();
$pageTitle = 'Folder Management';

$message = '';
$msgType = 'success';
$userId  = getUserId();
$isAdmin = ((int)getUserRoleId() === 1);
$userRoleId = (int)getUserRoleId();

// ── Handle Folder Creation & Actions ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action'] ?? '');

    if ($action === 'create') {
        // Only Admin (1) and Operator (2) can create folders
        if ($userRoleId !== 1 && $userRoleId !== 2) {
            $message = 'Unauthorized action. Only Administrators and Operators can create folders.';
            $msgType = 'danger';
        } else {
            $folderName = clean($_POST['folder_name'] ?? '');
            if (empty($folderName)) {
                $message = 'Folder name cannot be empty.'; $msgType = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO folders (folder_name, created_by, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("si", $folderName, $userId);
                if ($stmt->execute()) {
                    $newFolderId = $conn->insert_id;

                    // Physically create the folder in uploads/ directory
                    $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $folderName);
                    $physicalPath = UPLOAD_DIR . $safeFolderName;
                    if (!is_dir($physicalPath)) {
                        mkdir($physicalPath, 0755, true);
                    }

                    logActivity($conn, $userId, 'create', "Created folder '{$folderName}' (ID #{$newFolderId})");
                    $message = "Folder '{$folderName}' created successfully!";
                } else {
                    $message = 'Error creating folder: ' . $conn->error; $msgType = 'danger';
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'upload_folder') {
        // Only Admin (1) and Operator (2) can create folders and upload
        if ($userRoleId !== 1 && $userRoleId !== 2) {
            $message = 'Unauthorized action. Only Administrators and Operators can create folders.';
            $msgType = 'danger';
        } else {
            $folderName = clean($_POST['folder_name'] ?? '');
            if (empty($folderName)) {
                $message = 'Folder name cannot be empty.'; $msgType = 'danger';
            } else {
                // 1. Check if folder already exists
                $check = $conn->prepare("SELECT folder_id FROM folders WHERE folder_name = ? LIMIT 1");
                $check->bind_param("s", $folderName);
                $check->execute();
                $checkRes = $check->get_result()->fetch_assoc();
                $check->close();

                if ($checkRes) {
                    $newFolderId = (int)$checkRes['folder_id'];
                } else {
                    $stmt = $conn->prepare("INSERT INTO folders (folder_name, created_by, status) VALUES (?, ?, 'active')");
                    $stmt->bind_param("si", $folderName, $userId);
                    if ($stmt->execute()) {
                        $newFolderId = $conn->insert_id;
                        logActivity($conn, $userId, 'create', "Created folder '{$folderName}' (ID #{$newFolderId}) via folder upload");
                    } else {
                        $message = 'Error creating folder: ' . $conn->error; $msgType = 'danger';
                        $newFolderId = 0;
                    }
                    $stmt->close();
                }

                if ($newFolderId > 0) {
                    // Create physical folder directory
                    $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $folderName);
                    $targetDir = UPLOAD_DIR . $safeFolderName . DIRECTORY_SEPARATOR;
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    $uploadedCount = 0;
                    $failedCount = 0;
                    $allowedExt = ['pdf','jpg','jpeg','png','tiff','tif'];

                    if (isset($_FILES['folder_files']) && is_array($_FILES['folder_files']['name'])) {
                        $totalFiles = count($_FILES['folder_files']['name']);
                        
                        // Query current document count to build next seq number
                        $countQuery = $conn->query("SELECT COUNT(*) as total FROM documents");
                        $seqNumber  = ($countQuery->fetch_assoc()['total'] ?? 0) + 1;

                        for ($i = 0; $i < $totalFiles; $i++) {
                            if ($_FILES['folder_files']['error'][$i] !== UPLOAD_ERR_OK) {
                                // Skip files with upload errors
                                continue;
                            }

                            $rawName = $_FILES['folder_files']['name'][$i];
                            $tmpName = $_FILES['folder_files']['tmp_name'][$i];
                            $fileSize = $_FILES['folder_files']['size'][$i];
                            $ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));

                            if (!in_array($ext, $allowedExt)) {
                                $failedCount++;
                                continue; // Skip unsupported extensions
                            }

                            // Generate a unique sequential name like document_123.pdf
                            while (true) {
                                $storedName = 'document_' . $seqNumber . '.' . $ext;
                                if (!file_exists($targetDir . $storedName)) break;
                                $seqNumber++;
                            }

                            $destPath    = $targetDir . $storedName;
                            $storagePath = UPLOAD_URL . $safeFolderName . '/' . $storedName;
                            $fileType    = strtolower($ext);
                            $scannedAt   = date('Y-m-d H:i:s');

                            if (move_uploaded_file($tmpName, $destPath)) {
                                $docStmt = $conn->prepare(
                                    "INSERT IGNORE INTO documents (raw_filename, file_type, file_size, storage_path, status, folder_id, scanned_by, scanned_at, source)
                                     VALUES (?,?,?,?,'pending',?,?,?,'upload')"
                                );
                                if ($docStmt) {
                                    $docStmt->bind_param("ssisiis", $rawName, $fileType, $fileSize, $storagePath, $newFolderId, $userId, $scannedAt);
                                    if ($docStmt->execute()) {
                                        $newDocId = $docStmt->insert_id;
                                        logActivity($conn, $userId, 'create', "Uploaded '{$rawName}' to folder '{$folderName}' (Doc ID #{$newDocId})", $newDocId);
                                        $uploadedCount++;
                                    } else {
                                        $failedCount++;
                                        @unlink($destPath);
                                    }
                                    $docStmt->close();
                                } else {
                                    $failedCount++;
                                    @unlink($destPath);
                                }
                            } else {
                                $failedCount++;
                            }
                        }
                    }

                    if ($uploadedCount > 0) {
                        $message = "Folder '{$folderName}' created and {$uploadedCount} document(s) uploaded successfully!" . ($failedCount > 0 ? " ({$failedCount} files failed/skipped)." : "");
                        $msgType = 'success';
                    } else {
                        $message = "Folder created, but no valid documents were uploaded inside it.";
                        $msgType = 'warning';
                    }
                }
            }
        }
    }


    if ($action === 'status') {
        // Only Admin (1) can change status
        if ($userRoleId !== 1) {
            $message = 'Unauthorized action. Only Administrators can change folder status.';
            $msgType = 'danger';
        } else {
            $fid    = (int)($_POST['folder_id'] ?? 0);
            $status = clean($_POST['status'] ?? 'active');

            if (in_array($status, ['active', 'archived'])) {
                $stmt = $conn->prepare("UPDATE folders SET status=? WHERE folder_id=?");
                $stmt->bind_param("si", $status, $fid);
                if ($stmt->execute()) {
                    logActivity($conn, $userId, 'update', "Updated folder status for ID #$fid to '$status'");
                    $message = "Folder status updated to '$status'.";
                } else {
                    $message = 'Error: ' . $conn->error; $msgType = 'danger';
                }
                $stmt->close();
            }
        }
    }

    // ── Real Hard Delete ─────────────────────────────────────────
    if ($action === 'delete') {
        // Only Admin (1) can delete folders
        if ($userRoleId !== 1) {
            $message = 'Unauthorized action. Only Administrators can delete folders.';
            $msgType = 'danger';
        } else {
            $fid = (int)($_POST['folder_id'] ?? 0);
            if ($fid > 0) {

                // 1. Fetch folder name (for physical directory path)
                $fStmt = $conn->prepare("SELECT folder_name FROM folders WHERE folder_id = ? LIMIT 1");
                $fStmt->bind_param("i", $fid);
                $fStmt->execute();
                $fRow = $fStmt->get_result()->fetch_assoc();
                $fStmt->close();

                // 2. Fetch all document storage paths in this folder
                $dStmt = $conn->prepare("SELECT document_id, storage_path FROM documents WHERE folder_id = ?");
                $dStmt->bind_param("i", $fid);
                $dStmt->execute();
                $docsResult = $dStmt->get_result();
                $docIds = [];
                while ($d = $docsResult->fetch_assoc()) {
                    $docIds[] = $d['document_id'];
                    // Delete physical file from disk
                    $fullPath = __DIR__ . '/../' . $d['storage_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
                $dStmt->close();

                // 3. Delete validation records for all documents in this folder
                if (!empty($docIds)) {
                    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                    $types        = str_repeat('i', count($docIds));
                    $vStmt = $conn->prepare("DELETE FROM validations WHERE document_id IN ($placeholders)");
                    $vStmt->bind_param($types, ...$docIds);
                    $vStmt->execute();
                    $vStmt->close();
                }

                // 4. Delete all document records from this folder
                $delDocs = $conn->prepare("DELETE FROM documents WHERE folder_id = ?");
                $delDocs->bind_param("i", $fid);
                $delDocs->execute();
                $delDocs->close();

                // 5. Delete physical folder directory (and all remaining contents)
                if ($fRow) {
                    $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $fRow['folder_name']);
                    $physicalDir    = UPLOAD_DIR . $safeFolderName;
                    if (is_dir($physicalDir)) {
                        // Remove all files inside first, then the directory
                        $files = glob($physicalDir . '/*');
                        if ($files) {
                            foreach ($files as $file) { @unlink($file); }
                        }
                        @rmdir($physicalDir);
                    }
                }

                // 6. Delete folder record from database
                $delFolder = $conn->prepare("DELETE FROM folders WHERE folder_id = ?");
                $delFolder->bind_param("i", $fid);
                if ($delFolder->execute()) {
                    logActivity($conn, $userId, 'delete', "Permanently deleted folder ID #$fid and all its documents");
                    $message = "Folder and all its documents have been permanently deleted.";
                } else {
                    $message = 'Error deleting folder: ' . $conn->error; $msgType = 'danger';
                }
                $delFolder->close();
            }
        }
    }
} // end if POST

// ── Fetch Folders with document count ───────────────────────
$sql = "SELECT f.*, u.full_name AS creator_name, COUNT(d.document_id) AS doc_count
        FROM folders f
        LEFT JOIN users u ON f.created_by = u.user_id
        LEFT JOIN documents d ON f.folder_id = d.folder_id
        WHERE f.status != 'deleted'
        GROUP BY f.folder_id
        ORDER BY f.created_at DESC";
$folders = $conn->query($sql);

// Ensure physical directories exist for all active/archived folders in database
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
if ($isAdmin) {
    require_once '../includes/header.php';
} else {
    require_once '../includes/user_header.php';
}
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between">
  <div>
    <h1><i class="fas fa-folder-open text-dha me-2"></i>Folder Management</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= $isAdmin ? '../dashboard.php' : '../user_dashboard.php' ?>" class="text-dha">Dashboard</a></li>
        <li class="breadcrumb-item active">Folders</li>
      </ol>
    </nav>
  </div>
  <?php if ((int)getUserRoleId() === 1 || (int)getUserRoleId() === 2): // Admin and Operator can create folders ?>
  <button class="btn btn-dha" data-bs-toggle="modal" data-bs-target="#folderModal">
    <i class="fas fa-folder-plus me-2"></i>Create New Folder
  </button>
  <?php endif; ?>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?> alert-dismissible fade show alert-auto-dismiss mb-4" role="alert">
    <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?> me-2"></i>
    <?= esc($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- ── Folders Grid ── -->
<div class="row g-4">
  <?php
  if ($folders->num_rows > 0):
      while ($f = $folders->fetch_assoc()):
          $statusBadge = match($f['status']) {
              'active'   => 'badge-approved',
              'archived' => 'badge-pending',
              default    => 'badge-changed'
          };
          // Physical folder info
          $safeFolderName   = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $f['folder_name']);
          $physicalPath     = UPLOAD_DIR . $safeFolderName;
          $displayPath      = 'uploads/' . $safeFolderName;
          $physicalExists   = is_dir($physicalPath);
          $diskFileCount    = $physicalExists ? count(glob($physicalPath . '/*.*')) : 0;
  ?>
  <div class="col-xl-3 col-lg-4 col-sm-6">
    <div class="doc-card" style="position: relative; cursor: pointer; transition: transform .2s;" onclick="viewFolderContents(<?= $f['folder_id'] ?>)" title="Click to view folder location and files">
      <div class="doc-card-thumb" style="font-size: 4rem; color: #d4af37;">
        <i class="fas fa-folder"></i>
      </div>
      <div class="doc-card-body">
        <h6 title="<?= esc($f['folder_name']) ?>" class="fw-700 mb-1 text-truncate"><?= esc($f['folder_name']) ?></h6>
        <p class="mb-1" style="font-size:.75rem;color:#6c757d;">
          <i class="fas fa-folder-open me-1" style="color:#d4af37;"></i>
          <code style="font-size:.7rem;"><?= esc($displayPath) ?></code>
        </p>
        <p class="mb-1">DB Records: <strong><?= $f['doc_count'] ?></strong> &nbsp;
          <span title="Files on disk in this folder" style="color:#6c757d;font-size:.8rem;">
            <i class="fas fa-hdd me-1"></i><?= $diskFileCount ?> file<?= $diskFileCount !== 1 ? 's' : '' ?> on disk
          </span>
        </p>
        <p class="mb-1">Created By: <small><?= esc($f['creator_name'] ?? 'System') ?></small></p>
        <p class="mb-2">
          <?php if ($physicalExists): ?>
            <span class="badge bg-success" style="font-size:.65rem;"><i class="fas fa-check-circle me-1"></i>Folder synced on disk</span>
          <?php else: ?>
            <span class="badge bg-danger" style="font-size:.65rem;"><i class="fas fa-exclamation-circle me-1"></i>Folder missing on disk</span>
          <?php endif; ?>
        </p>
        <div class="d-flex justify-content-between align-items-center" onclick="event.stopPropagation();">
          <span class="<?= $statusBadge ?>"><?= ucfirst($f['status']) ?></span>
          <button type="button" class="btn btn-xs btn-outline-info py-1 px-2" onclick="event.stopPropagation(); viewFolderContents(<?= $f['folder_id'] ?>)">
            <i class="fas fa-eye me-1"></i>View Files
          </button>
          <?php if ($isAdmin): // Only Admin can manage folder status or delete ?>
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="dropdown">
              <i class="fas fa-ellipsis-v"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
              <?php if ($f['status'] !== 'active'): ?>
              <li>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="folder_id" value="<?= $f['folder_id'] ?>">
                  <input type="hidden" name="status" value="active">
                  <button type="submit" class="dropdown-item py-2 text-success">
                    <i class="fas fa-check-circle me-2"></i>Mark Active
                  </button>
                </form>
              </li>
              <?php endif; ?>
              <?php if ($f['status'] !== 'archived'): ?>
              <li>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="folder_id" value="<?= $f['folder_id'] ?>">
                  <input type="hidden" name="status" value="archived">
                  <button type="submit" class="dropdown-item py-2 text-warning">
                    <i class="fas fa-archive me-2"></i>Archive
                  </button>
                </form>
              </li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="POST" class="d-inline" onsubmit="return confirm('⚠️ PERMANENTLY DELETE this folder?\n\nThis will:\n• Delete ALL documents inside from the database\n• Delete ALL physical files from disk\n• Remove the folder directory\n\nThis action CANNOT be undone!');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="folder_id" value="<?= $f['folder_id'] ?>">
                  <button type="submit" class="dropdown-item py-2 text-danger">
                    <i class="fas fa-trash me-2"></i>Delete Folder (Permanent)
                  </button>
                </form>
              </li>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php
      endwhile;
  else:
  ?>
  <div class="col-12 text-center text-secondary py-5">
    <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
    <p>No active folders found. Create one to begin organizing scanned documents.</p>
  </div>
  <?php endif; ?>
</div>

<?php if ($userRoleId === 1 || $userRoleId === 2): // Only Admin and Operator get the create folder modal ?>
<!-- Folder Create Modal -->
<div class="modal fade" id="folderModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Create New Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="createFolderForm" enctype="multipart/form-data">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="folder_path" id="folder_path_hidden">
        <div class="modal-body">

          <!-- Folder Name Input -->
          <div class="mb-3">
            <label class="dha-label">Folder Name *</label>
            <div class="input-group">
              <input type="text" class="dha-form-control" name="folder_name" id="folder_name_field"
                placeholder="e.g. DHA Phase 5 Commercial Plot Records" required autocomplete="off">
              <button type="button" class="btn btn-outline-info px-3" id="browseFolderBtn"
                title="Browse system folder" onclick="triggerFolderBrowse()">
                <i class="fas fa-folder-open me-1"></i> Browse
              </button>
            </div>
            <small class="text-secondary mt-1 d-block">Type a name manually <em>or</em> click Browse to pick a folder from your system.</small>
          </div>

          <!-- Hidden file input for folder selection -->
          <input type="file" id="folderPickerInput" name="folder_files[]" webkitdirectory mozdirectory allowdirs
            style="display:none;" onchange="onFolderSelected(this)" multiple>

          <!-- Selected path preview -->
          <div id="folderPathPreview" class="d-none mt-2 p-3 rounded-3 d-flex align-items-start gap-3"
            style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.25);">
            <div style="width:38px;height:38px;border-radius:10px;background:rgba(59,130,246,0.18);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="fas fa-folder-open" style="color:#3b82f6;font-size:1.1rem;"></i>
            </div>
            <div>
              <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;letter-spacing:.04em;text-transform:uppercase;">
                Selected Path
              </div>
              <div id="folderPathText" style="font-family:monospace;font-size:.85rem;color:var(--text-primary);
                   word-break:break-all;margin-top:3px;"></div>
              <div id="folderFileCount" style="font-size:.72rem;color:var(--text-muted);margin-top:3px;"></div>
            </div>
            <button type="button" class="btn-close ms-auto" style="opacity:.4;" onclick="clearFolderSelection()"></button>
          </div>

          <!-- Files listing container -->
          <div id="fileListContainer" class="d-none mt-3"></div>

          <!-- Tip -->
          <div class="mt-3 p-3 rounded-3" style="background:rgba(250,204,21,0.06);border:1px solid rgba(250,204,21,0.15);">
            <i class="fas fa-lightbulb me-2" style="color:#ca8a04;"></i>
            <small style="color:var(--text-muted);">
              <strong style="color:var(--text-primary);">Tip:</strong>
              Click <strong>Browse</strong> to select a folder. You can see its content above, and upload it with all its files using <strong>Upload Folder</strong>.
            </small>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dha"><i class="fas fa-save me-1"></i>Create Folder</button>
          <button type="button" class="btn btn-success d-none" id="uploadFolderBtn" onclick="submitUploadFolder()">
            <i class="fas fa-upload me-1"></i>Upload Folder
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ── Folder Browse Logic ─────────────────────────────── */
function triggerFolderBrowse() {
  document.getElementById('folderPickerInput').click();
}

function onFolderSelected(input) {
  const files = input.files;
  if (!files || files.length === 0) return;

  // Extract the top-level folder name from the first file's webkitRelativePath
  // webkitRelativePath looks like: "FolderName/subfolder/file.txt"
  const firstPath = files[0].webkitRelativePath || files[0].name;
  const topFolder = firstPath.split('/')[0];

  // Populate the folder name field
  const nameField = document.getElementById('folder_name_field');
  nameField.value = topFolder;

  // Store the folder name as the path hint
  document.getElementById('folder_path_hidden').value = topFolder;

  // Show preview
  const preview = document.getElementById('folderPathPreview');
  document.getElementById('folderPathText').textContent = topFolder;
  document.getElementById('folderFileCount').textContent =
    files.length + ' file(s) found inside this folder';
  preview.classList.remove('d-none');

  // Show "Upload Folder" button in footer
  document.getElementById('uploadFolderBtn').classList.remove('d-none');

  // Render the file list inside the modal
  const listContainer = document.getElementById('fileListContainer');
  listContainer.classList.remove('d-none');
  
  let html = `
    <h6 style="font-size: .8rem; font-weight: 700; color: var(--accent); margin-bottom: 8px;" class="d-flex align-items-center justify-content-between">
      <span><i class="fas fa-list me-2"></i>Files inside Folder:</span>
      <span class="badge bg-secondary" style="font-size: .7rem; padding: 3px 8px;">Total Size: ${formatTotalSize(files)}</span>
    </h6>
    <div class="table-responsive rounded-3 border border-secondary" style="max-height: 200px; overflow-y: auto; background: rgba(0,0,0,0.15);">
      <table class="table table-dark table-sm table-striped mb-0 text-white" style="font-size: .78rem;">
        <thead class="sticky-top" style="background:#1e293b; z-index:1;">
          <tr>
            <th class="ps-2 py-2" style="background:#1e293b; border-bottom:1px solid rgba(255,255,255,0.08);">File Name</th>
            <th class="py-2" style="background:#1e293b; border-bottom:1px solid rgba(255,255,255,0.08);">Size</th>
            <th class="py-2 pe-2 text-end" style="background:#1e293b; border-bottom:1px solid rgba(255,255,255,0.08);">Type</th>
          </tr>
        </thead>
        <tbody>
  `;

  const allowedExt = ['pdf','jpg','jpeg','png','tiff','tif'];
  Array.from(files).forEach(f => {
    const ext = f.name.split('.').pop().toLowerCase();
    const isAllowed = allowedExt.includes(ext);
    const sizeStr = formatBytes(f.size);
    
    // Choose file icon
    let iconClass = 'fa-file text-secondary';
    if (ext === 'pdf') iconClass = 'fa-file-pdf text-danger';
    else if (['jpg','jpeg','png'].includes(ext)) iconClass = 'fa-file-image text-warning';
    else if (['tiff','tif'].includes(ext)) iconClass = 'fa-file-medical text-info';

    html += `
          <tr>
            <td class="ps-2 py-2 text-truncate" style="max-width: 320px;" title="${f.webkitRelativePath}">
              <i class="far ${iconClass} me-2"></i>${f.name}
            </td>
            <td class="py-2 text-white-50">${sizeStr}</td>
            <td class="py-2 pe-2 text-end">
              ${isAllowed 
                ? `<span class="badge bg-success" style="font-size: .65rem; padding: 2px 6px;">Ready</span>`
                : `<span class="badge bg-danger" style="font-size: .65rem; padding: 2px 6px;" title="Supported formats: PDF, JPG, PNG, TIFF">Skip</span>`
              }
            </td>
          </tr>
    `;
  });

  html += `
        </tbody>
      </table>
    </div>
  `;
  listContainer.innerHTML = html;
}

function formatTotalSize(files) {
  let total = 0;
  for (let i = 0; i < files.length; i++) {
    total += files[i].size;
  }
  return formatBytes(total);
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function submitUploadFolder() {
  document.getElementById('formAction').value = 'upload_folder';
  document.getElementById('createFolderForm').submit();
}

function clearFolderSelection() {
  document.getElementById('folder_name_field').value = '';
  document.getElementById('folder_path_hidden').value = '';
  document.getElementById('folderPathPreview').classList.add('d-none');
  document.getElementById('folderPathText').textContent = '';
  document.getElementById('folderFileCount').textContent = '';
  document.getElementById('fileListContainer').innerHTML = '';
  document.getElementById('fileListContainer').classList.add('d-none');
  document.getElementById('uploadFolderBtn').classList.add('d-none');
  document.getElementById('formAction').value = 'create';
  document.getElementById('folderPickerInput').value = '';
}

// Clear on modal close
document.getElementById('folderModal')?.addEventListener('hidden.bs.modal', function () {
  clearFolderSelection();
  document.getElementById('createFolderForm').reset();
});

/* ── View Folder Contents Modal Logic ─────────────────── */
let currentVfmData = null;

async function viewFolderContents(folderId) {
  const modalEl = document.getElementById('viewFolderModal');
  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  document.getElementById('vfmLoading').style.display = 'block';
  document.getElementById('vfmTableWrapper').style.display = 'none';

  try {
    const res = await fetch('../ajax/get_folder_details.php?folder_id=' + folderId);
    const data = await res.json();
    if (!data.success) {
      alert(data.message || 'Error loading folder details.');
      return;
    }

    currentVfmData = data;
    const f = data.folder;

    document.getElementById('vfmTitle').innerHTML = `<i class="fas fa-folder-open text-warning me-2"></i><span>Folder: ${f.folder_name}</span>`;
    document.getElementById('vfmDisplayPath').textContent = f.display_path;
    document.getElementById('vfmPhysicalPath').textContent = f.physical_path;
    document.getElementById('vfmDbCount').textContent = f.db_count;
    document.getElementById('vfmDiskCount').textContent = f.disk_count;

    document.getElementById('vfmSyncBadge').className = f.physical_exists ? 'badge bg-success' : 'badge bg-danger';
    document.getElementById('vfmSyncBadge').innerHTML = f.physical_exists
      ? '<i class="fas fa-check-circle me-1"></i>Folder synced on disk'
      : '<i class="fas fa-exclamation-circle me-1"></i>Folder missing on disk';

    document.getElementById('vfmVerifyBtn').href = 'validate.php?folder_name=' + encodeURIComponent(f.folder_name);
    document.getElementById('vfmRenameBtn').href = 'rename.php?folder_id=' + f.folder_id;

    filterVfmFiles('all');
    document.getElementById('vfmLoading').style.display = 'none';
    document.getElementById('vfmTableWrapper').style.display = 'block';

  } catch (err) {
    console.error(err);
    document.getElementById('vfmLoading').innerHTML = '<div class="text-danger py-4 text-center"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>Failed to load folder details.</div>';
  }
}

function renderVfmFiles(filterMode) {
  if (!currentVfmData) return;
  const tbody = document.getElementById('vfmTableBody');
  tbody.innerHTML = '';

  const dbFiles = currentVfmData.db_files || [];
  const diskFiles = currentVfmData.disk_files || [];

  let list = [];
  if (filterMode === 'all') {
    list = [...dbFiles.map(d => ({ ...d, source: 'db' }))];
    diskFiles.forEach(df => {
      if (!df.is_linked) {
        list.push({ ...df, source: 'disk_only' });
      }
    });
  } else if (filterMode === 'db') {
    list = dbFiles.map(d => ({ ...d, source: 'db' }));
  } else if (filterMode === 'disk') {
    list = diskFiles.map(df => ({ ...df, source: 'disk' }));
  }

  document.getElementById('vfmTotalFilesCount').textContent = list.length;

  if (list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-folder-open fa-2x mb-2 opacity-50 d-block"></i>No files found in this folder.</td></tr>';
    return;
  }

  list.forEach(item => {
    const tr = document.createElement('tr');
    let name = item.renamed_filename || item.raw_filename || item.filename || 'Unnamed File';
    let rawSub = (item.renamed_filename && item.raw_filename) ? `<br><small class="text-muted">Original: ${item.raw_filename}</small>` : '';
    let ext = name.split('.').pop().toLowerCase();

    let iconClass = 'fa-file text-secondary';
    if (ext === 'pdf') iconClass = 'fa-file-pdf text-danger';
    else if (['jpg','jpeg','png'].includes(ext)) iconClass = 'fa-file-image text-primary';
    else if (['tiff','tif'].includes(ext)) iconClass = 'fa-file-medical text-info';

    let statusBadge = '<span class="badge bg-secondary">Disk Only File</span>';
    if (item.source === 'db') {
      if (item.status === 'approved') statusBadge = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Verified</span>';
      else if (item.status === 'changed') statusBadge = '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Validated (Corrected)</span>';
      else if (item.renamed_filename) statusBadge = '<span class="badge bg-warning text-dark"><i class="fas fa-tag me-1"></i>Renamed</span>';
      else statusBadge = '<span class="badge bg-info"><i class="fas fa-clock me-1"></i>Pending</span>';
    }

    let sizeText = item.formatted_size || formatBytes(item.file_size || item.size || 0);
    let dateText = item.scanned_at || item.mtime || '—';

    let storagePath = item.storage_path ? ('../' + item.storage_path) : (item.relative_path ? ('../' + item.relative_path) : '#');
    let viewAction = storagePath !== '#' 
      ? `<a href="${storagePath}" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;" title="Preview File"><i class="fas fa-eye me-1"></i>Preview</a>`
      : '';

    tr.innerHTML = `
      <td class="ps-3 fw-600">
        <i class="fas ${iconClass} me-2"></i>${name}${rawSub}
      </td>
      <td>${statusBadge}</td>
      <td class="text-muted">${sizeText}</td>
      <td class="text-uppercase text-muted" style="font-size:0.75rem;">${ext}</td>
      <td class="text-muted" style="font-size:0.78rem;">${dateText}</td>
      <td class="pe-3 text-end">${viewAction}</td>
    `;
    tbody.appendChild(tr);
  });
}

function filterVfmFiles(mode) {
  ['All','Db','Disk'].forEach(m => {
    const btn = document.getElementById('vfmTab' + m);
    if (btn) btn.classList.remove('active');
  });
  const activeBtn = document.getElementById('vfmTab' + mode.charAt(0).toUpperCase() + mode.slice(1));
  if (activeBtn) activeBtn.classList.add('active');
  renderVfmFiles(mode);
}
</script>

<!-- Folder View Modal (Inspect Saved Location & Files) -->
<div class="modal fade" id="viewFolderModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header py-3" style="background:#1e293b; color:#fff;">
        <h5 class="modal-title d-flex align-items-center gap-2" id="vfmTitle">
          <i class="fas fa-folder-open text-warning"></i>
          <span>Folder Details</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        
        <!-- Folder Metadata & Location Card -->
        <div class="card border-0 mb-4 shadow-sm" style="background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2) !important;">
          <div class="card-body p-3">
            <div class="row g-3 align-items-center">
              <div class="col-md-7">
                <h6 class="text-uppercase fw-700 text-secondary mb-1" style="font-size:0.7rem; letter-spacing:0.05em;">Saved Physical Location</h6>
                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                  <code id="vfmDisplayPath" class="px-2 py-1 rounded" style="font-size:0.85rem; background:rgba(0,0,0,0.1); color:#3b82f6; font-weight:600;">uploads/...</code>
                  <span id="vfmSyncBadge"></span>
                </div>
                <div class="text-muted" style="font-size:0.78rem;">
                  <i class="fas fa-server me-1"></i><strong>Full Server Path:</strong> <span id="vfmPhysicalPath" class="font-monospace text-dark">...</span>
                </div>
              </div>
              <div class="col-md-5 text-md-end">
                <div class="d-flex align-items-center justify-content-md-end gap-2 flex-wrap mb-2">
                  <a id="vfmVerifyBtn" href="#" class="btn btn-sm btn-success fw-600 px-3 py-1">
                    <i class="fas fa-check-double me-1"></i>Verify in Validate Page
                  </a>
                  <a id="vfmRenameBtn" href="#" class="btn btn-sm btn-warning fw-600 px-3 py-1">
                    <i class="fas fa-edit me-1"></i>Rename Documents
                  </a>
                </div>
                <div class="text-muted" style="font-size:0.78rem;">
                  DB Records: <strong id="vfmDbCount" class="text-dark">0</strong> &nbsp;|&nbsp;
                  Disk Files: <strong id="vfmDiskCount" class="text-dark">0</strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Files List Header & Filter Tabs -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          <h6 class="fw-700 mb-0"><i class="fas fa-file-alt me-2 text-primary"></i>Files inside Folder (<span id="vfmTotalFilesCount">0</span>)</h6>
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-primary active" id="vfmTabAll" onclick="filterVfmFiles('all')">All Files</button>
            <button type="button" class="btn btn-outline-primary" id="vfmTabDb" onclick="filterVfmFiles('db')">DB Registered</button>
            <button type="button" class="btn btn-outline-primary" id="vfmTabDisk" onclick="filterVfmFiles('disk')">Disk Only</button>
          </div>
        </div>

        <!-- Files Table -->
        <div id="vfmLoading" class="text-center py-5">
          <div class="spinner-border text-primary me-2"></div> Loading folder contents...
        </div>
        <div id="vfmTableWrapper" class="table-responsive rounded-3 border" style="display:none; max-height:400px; overflow-y:auto;">
          <table class="table table-hover align-middle mb-0" style="font-size:0.82rem;">
            <thead class="table-light sticky-top">
              <tr>
                <th class="ps-3">File Name</th>
                <th>Status</th>
                <th>Size</th>
                <th>Type</th>
                <th>Last Modified / Scanned</th>
                <th class="pe-3 text-end">Action</th>
              </tr>
            </thead>
            <tbody id="vfmTableBody">
            </tbody>
          </table>
        </div>

      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

