<?php
// ============================================================
// pages/rename_folders.php — Files to Rename (folder overview)
// Shows every folder that still has documents which have been scanned
// but not yet renamed, grouped exactly like Folder Management. Clicking
// a folder deep-links into pages/rename.php pre-filtered to that folder.
// ============================================================
require_once '../includes/auth.php';
requireLogin();
$pageTitle = 'Files to Rename';

$userId     = getUserId();
$isAdmin    = ((int)getUserRoleId() === 1);
$userRoleId = (int)getUserRoleId();

// ── Fetch folders that have at least one document still awaiting rename ──
// "Awaiting rename" = pending status with no renamed_filename yet.
$sql = "SELECT f.*, u.full_name AS creator_name,
               COUNT(d.document_id) AS pending_count
        FROM folders f
        LEFT JOIN users u ON f.created_by = u.user_id
        JOIN documents d ON f.folder_id = d.folder_id
             AND d.status = 'pending'
             AND (d.renamed_filename IS NULL OR d.renamed_filename = '')
        WHERE f.status != 'deleted'";
if (!$isAdmin) {
    $sql .= " AND d.scanned_by = $userId";
}
$sql .= " GROUP BY f.folder_id
          ORDER BY pending_count DESC, f.folder_name ASC";
$folders = $conn->query($sql);

if ($isAdmin) {
    require_once '../includes/header.php';
} else {
    require_once '../includes/user_header.php';
}
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between">
  <div>
    <h1><i class="fas fa-file-signature text-dha me-2"></i>Files to Rename</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= $isAdmin ? '../dashboard.php' : '../user_dashboard.php' ?>" class="text-dha">Dashboard</a></li>
        <li class="breadcrumb-item active">Files to Rename</li>
      </ol>
    </nav>
  </div>
</div>

<!-- ── Folders Grid ── -->
<div class="row g-4">
  <?php if ($folders && $folders->num_rows > 0): ?>
    <?php while ($f = $folders->fetch_assoc()):
        $safeFolderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $f['folder_name']);
        $physicalPath   = UPLOAD_DIR . $safeFolderName;
        $displayPath    = 'uploads/' . $safeFolderName;
        $physicalExists = is_dir($physicalPath);
        $diskFileCount  = $physicalExists ? count(glob($physicalPath . '/*.*')) : 0;
    ?>
    <div class="col-xl-3 col-lg-4 col-sm-6">
      <div class="doc-card" style="position:relative;cursor:pointer;transition:transform .2s;"
           onclick="location.href='rename.php?folder_name=<?= urlencode($f['folder_name']) ?>'"
           title="Click to open this folder in Rename">
        <div class="doc-card-thumb" style="font-size:4rem;color:#9b59b6;">
          <i class="fas fa-folder"></i>
        </div>
        <div class="doc-card-body">
          <h6 title="<?= esc($f['folder_name']) ?>" class="fw-700 mb-1 text-truncate"><?= esc($f['folder_name']) ?></h6>
          <p class="mb-1" style="font-size:.75rem;color:#6c757d;">
            <i class="fas fa-folder-open me-1" style="color:#9b59b6;"></i>
            <code style="font-size:.7rem;"><?= esc($displayPath) ?></code>
          </p>
          <p class="mb-1">Awaiting Rename: <strong><?= $f['pending_count'] ?></strong> &nbsp;
            <span title="Files on disk in this folder" style="color:#6c757d;font-size:.8rem;">
              <i class="fas fa-hdd me-1"></i><?= $diskFileCount ?> file<?= $diskFileCount !== 1 ? 's' : '' ?> on disk
            </span>
          </p>
          <p class="mb-2">
            <?php if ($physicalExists): ?>
              <span class="badge bg-success" style="font-size:.65rem;"><i class="fas fa-check-circle me-1"></i>Folder synced on disk</span>
            <?php else: ?>
              <span class="badge bg-danger" style="font-size:.65rem;"><i class="fas fa-exclamation-circle me-1"></i>Folder missing on disk</span>
            <?php endif; ?>
          </p>
          <div class="d-flex justify-content-end align-items-center" onclick="event.stopPropagation();">
            <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2"
                    onclick="location.href='rename.php?folder_name=<?= urlencode($f['folder_name']) ?>'">
              <i class="fas fa-file-signature me-1"></i>Rename Files
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="col-12">
      <div class="text-center text-secondary py-5">
        <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-50"></i>
        <p class="mb-0">No folders have files waiting to be renamed right now.</p>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
