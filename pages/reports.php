<?php
// ============================================================
// pages/reports.php — Reports & Analytics Module (dms_database)
// ============================================================
require_once '../includes/auth.php';
requireLogin();
// Admin-only page
if ((int)getUserRoleId() !== 1) {
    header('Location: ../user_dashboard.php');
    exit();
}

// ── Active Tab ───────────────────────────────────────────────
$activeTab = ($_GET['tab'] ?? 'users') === 'plot' ? 'plot' : 'users';

// ── Build Filter Query ────────────────────────────────────────
$where = "1=1";
$filterParams = [];

$startDate = clean($_GET['start_date'] ?? '');
$endDate   = clean($_GET['end_date'] ?? '');
$userId    = (int)($_GET['user_id'] ?? 0);

// Default to the last 30 days when no range is chosen, so the KPI
// day-count denominator always has a defined range.
if (!$endDate)   $endDate   = date('Y-m-d');
if (!$startDate) $startDate = date('Y-m-d', strtotime($endDate . ' -29 days'));

$safeStart = $conn->real_escape_string($startDate);
$safeEnd   = $conn->real_escape_string($endDate);

$filterParams['start_date'] = $startDate;
$filterParams['end_date']   = $endDate;
if ($userId) $filterParams['user_id'] = $userId;

// Days in the selected range (inclusive) — KPI denominator
$totalDays = (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
if ($totalDays < 1) $totalDays = 1;

// KPI daily targets — match pages/scan.php and user_dashboard.php
$scanTarget   = 400;   // scans/day target
$renameTarget = 1000;  // renames/day target

if ($startDate) {
    $where .= " AND DATE(d.scanned_at) >= '$safeStart'";
}
if ($endDate) {
    $where .= " AND DATE(d.scanned_at) <= '$safeEnd'";
}
if ($userId) {
    $where .= " AND (d.scanned_by = $userId OR d.renamed_by = $userId)";
}

// ── Handle CSV Export ──────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=DHA_DMS_Report_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV Headers
    fputcsv($output, [
        'Document ID',
        'Raw Filename',
        'Renamed Filename',
        'File Type',
        'File Size',
        'Folder',
        'Branch',
        'Doc Type',
        'File No',
        'Phase',
        'Plot',
        'Doc Year',
        'Status',
        'Scanned By',
        'Scanned At'
    ]);

    $exportSql = "SELECT d.*, f.folder_name, u.username AS scanned_by_username
                  FROM documents d
                  LEFT JOIN folders f ON d.folder_id = f.folder_id
                  LEFT JOIN users u ON d.scanned_by = u.user_id
                  WHERE $where
                  ORDER BY d.document_id DESC";
    $exportRes = $conn->query($exportSql);

    while ($row = $exportRes->fetch_assoc()) {
        fputcsv($output, [
            $row['document_id'],
            $row['raw_filename'],
            $row['renamed_filename'] ?? '—',
            $row['file_type'] ?? '—',
            formatSize($row['file_size']),
            $row['folder_name'] ?? '—',
            $row['branch'] ?? '—',
            $row['doc_type'] ?? '—',
            $row['file_no'] ?? '—',
            $row['phase'] ?? '—',
            $row['plot'] ?? '—',
            $row['doc_year'] ?? '—',
            $row['status'] === 'changed' ? 'Validated after Correction' : ucfirst($row['status']),
            $row['scanned_by_username'] ?? '—',
            $row['scanned_at'] ?? '—'
        ]);
    }

    fclose($output);
    exit();
}

// ── Load Dropdown List: Users ───────────────────────────────────
$usersResult = $conn->query("SELECT user_id, full_name FROM users ORDER BY full_name");

// ── KPI Report Data (User Reports tab only) ───────────────────────
$allUsersReport   = [];
$userDailyRows    = [];
$userTotals       = ['scan' => 0, 'rename' => 0, 'verified' => 0, 'files' => 0];
$selectedUserName = '';

if ($activeTab === 'users') {
    if ($userId === 0) {
        // ── All Users: one summary row per user ───────────────────────
        $usersListRes = $conn->query("SELECT user_id, full_name FROM users ORDER BY full_name");
        while ($u = $usersListRes->fetch_assoc()) {
            $uid = (int)$u['user_id'];

            $scanCount = (int)$conn->query(
                "SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND source = 'scan'
                 AND DATE(scanned_at) BETWEEN '$safeStart' AND '$safeEnd'"
            )->fetch_row()[0];

            $renameCount = (int)$conn->query(
                "SELECT COUNT(*) FROM documents WHERE renamed_by = $uid
                 AND DATE(renamed_at) BETWEEN '$safeStart' AND '$safeEnd'"
            )->fetch_row()[0];

            $verifiedCount = (int)$conn->query(
                "SELECT COUNT(*) FROM validations WHERE validated_by = $uid
                 AND DATE(validated_at) BETWEEN '$safeStart' AND '$safeEnd'"
            )->fetch_row()[0];

            if ($scanCount === 0 && $renameCount === 0 && $verifiedCount === 0) continue; // no activity in range

            $filesProcessed = (int)$conn->query(
                "SELECT COUNT(DISTINCT document_id) FROM documents WHERE
                 (scanned_by = $uid AND DATE(scanned_at) BETWEEN '$safeStart' AND '$safeEnd')
                 OR (renamed_by = $uid AND DATE(renamed_at) BETWEEN '$safeStart' AND '$safeEnd')"
            )->fetch_row()[0];

            $allUsersReport[] = [
                'name'     => $u['full_name'],
                'files'    => $filesProcessed,
                'scan'     => $scanCount,
                'rename'   => $renameCount,
                'verified' => $verifiedCount,
            ];
        }
    } else {
        // ── Single User: one row per day, same stat columns as the All
        // Users summary (Files Processed / Scanned / Renamed / Verified)
        // instead of the old Date/Task/Daily-KPI% layout, so both views
        // are structurally consistent.
        $userRow = $conn->query("SELECT full_name FROM users WHERE user_id = $userId")->fetch_assoc();
        $selectedUserName = $userRow['full_name'] ?? '';

        $scanByDay = [];
        $scanDaysRes = $conn->query(
            "SELECT DATE(scanned_at) AS day, COUNT(*) AS cnt FROM documents
             WHERE scanned_by = $userId AND source = 'scan' AND DATE(scanned_at) BETWEEN '$safeStart' AND '$safeEnd'
             GROUP BY DATE(scanned_at)"
        );
        while ($r = $scanDaysRes->fetch_assoc()) { $scanByDay[$r['day']] = (int)$r['cnt']; }

        $renameByDay = [];
        $renameDaysRes = $conn->query(
            "SELECT DATE(renamed_at) AS day, COUNT(*) AS cnt FROM documents
             WHERE renamed_by = $userId AND DATE(renamed_at) BETWEEN '$safeStart' AND '$safeEnd'
             GROUP BY DATE(renamed_at)"
        );
        while ($r = $renameDaysRes->fetch_assoc()) { $renameByDay[$r['day']] = (int)$r['cnt']; }

        $verifiedByDay = [];
        $verifyDaysRes = $conn->query(
            "SELECT DATE(validated_at) AS day, COUNT(*) AS cnt FROM validations
             WHERE validated_by = $userId AND DATE(validated_at) BETWEEN '$safeStart' AND '$safeEnd'
             GROUP BY DATE(validated_at)"
        );
        while ($r = $verifyDaysRes->fetch_assoc()) { $verifiedByDay[$r['day']] = (int)$r['cnt']; }

        // Files Processed per day, mirroring the All Users definition
        // (distinct documents touched by this user that day, scan+rename
        // combined so a document scanned and renamed the same day only
        // counts once for that day).
        $filesByDay = [];
        $filesDaysRes = $conn->query(
            "SELECT day, COUNT(DISTINCT document_id) AS cnt FROM (
                SELECT document_id, DATE(scanned_at) AS day FROM documents
                 WHERE scanned_by = $userId AND source = 'scan' AND DATE(scanned_at) BETWEEN '$safeStart' AND '$safeEnd'
                UNION ALL
                SELECT document_id, DATE(renamed_at) AS day FROM documents
                 WHERE renamed_by = $userId AND DATE(renamed_at) BETWEEN '$safeStart' AND '$safeEnd'
             ) t GROUP BY day"
        );
        while ($r = $filesDaysRes->fetch_assoc()) { $filesByDay[$r['day']] = (int)$r['cnt']; }

        $allDays = array_unique(array_merge(
            array_keys($scanByDay), array_keys($renameByDay),
            array_keys($verifiedByDay), array_keys($filesByDay)
        ));
        sort($allDays);

        foreach ($allDays as $day) {
            $scanCount     = $scanByDay[$day]     ?? 0;
            $renameCount   = $renameByDay[$day]   ?? 0;
            $verifiedCount = $verifiedByDay[$day] ?? 0;
            $filesCount    = $filesByDay[$day]    ?? 0;

            $userDailyRows[] = [
                'date'     => $day,
                'files'    => $filesCount,
                'scan'     => $scanCount,
                'rename'   => $renameCount,
                'verified' => $verifiedCount,
            ];

            $userTotals['scan']     += $scanCount;
            $userTotals['rename']   += $renameCount;
            $userTotals['verified'] += $verifiedCount;
            $userTotals['files']    += $filesCount;
        }
    }
}

// ── Plot Reports: search state ────────────────────────────────────
$searchPH      = clean($_GET['ph']   ?? '');
$searchSEC     = clean($_GET['sec']  ?? '');
$searchPlot    = clean($_GET['plot'] ?? '');
$hasPlotSearch = ($searchPH !== '' || $searchSEC !== '' || $searchPlot !== '');
$plotResults   = [];

if ($activeTab === 'plot' && $hasPlotSearch) {
    $plotWhere = "1=1";
    if ($searchPH !== '') {
        $safePH = $conn->real_escape_string($searchPH);
        $plotWhere .= " AND d.phase = '$safePH'";
    }
    if ($searchSEC !== '') {
        $safeSEC = $conn->real_escape_string($searchSEC);
        $plotWhere .= " AND d.branch = '$safeSEC'";
    }
    if ($searchPlot !== '') {
        $safePlot = $conn->real_escape_string($searchPlot);
        $plotWhere .= " AND d.plot = '$safePlot'";
    }

    // Latest validation per document (a doc can be reviewed more than once
    // if changed and resubmitted — take the most recent one only).
    $plotSql = "SELECT d.document_id, d.raw_filename, d.renamed_filename, d.doc_type,
                       d.phase, d.branch, d.plot,
                       d.scanned_at, d.renamed_at,
                       us.full_name AS scanned_by_name,
                       ur.full_name AS renamed_by_name,
                       v.decision, v.remarks AS val_remarks, v.validated_at,
                       uv.full_name AS validated_by_name
                FROM documents d
                LEFT JOIN users us ON d.scanned_by = us.user_id
                LEFT JOIN users ur ON d.renamed_by = ur.user_id
                LEFT JOIN (
                    SELECT v1.document_id, v1.validated_by, v1.validated_at, v1.decision, v1.remarks
                    FROM validations v1
                    INNER JOIN (
                        SELECT document_id, MAX(validation_id) AS max_id
                        FROM validations GROUP BY document_id
                    ) latest ON v1.validation_id = latest.max_id
                ) v ON v.document_id = d.document_id
                LEFT JOIN users uv ON v.validated_by = uv.user_id
                WHERE $plotWhere
                ORDER BY d.document_id DESC";
    $plotRes = $conn->query($plotSql);
    if ($plotRes) {
        while ($row = $plotRes->fetch_assoc()) {
            $plotResults[] = $row;
        }
    }
}

$pageTitle = 'Reports & Analytics';
require_once '../includes/header.php';
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between">
  <div>
    <h1><i class="fas fa-chart-bar text-dha me-2"></i>Reports &amp; Analytics</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="../dashboard.php" class="text-dha">Dashboard</a></li>
        <li class="breadcrumb-item active">Reports</li>
      </ol>
    </nav>
  </div>
  <div class="d-flex gap-2 no-print">
    <button onclick="window.print()" class="btn btn-dha">
      <i class="fas fa-file-pdf me-2"></i>Export to PDF
    </button>
  </div>
</div>

<!-- ── Tabs ─────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-4 no-print">
  <a href="?tab=users" class="btn <?= $activeTab === 'users' ? 'btn-dha' : 'btn-outline-secondary' ?>">
    <i class="fas fa-users me-1"></i>User Reports
  </a>
  <a href="?tab=plot" class="btn <?= $activeTab === 'plot' ? 'btn-dha' : 'btn-outline-secondary' ?>">
    <i class="fas fa-map-marker-alt me-1"></i>Plot Reports
  </a>
</div>

<?php if ($activeTab === 'users'): ?>

<!-- ── Filter Panel ─────────────────────────────────────────── -->
<div class="dha-card mb-4 no-print">
  <div class="dha-card-header">
    <h5><i class="fas fa-filter text-dha me-2"></i>Report Filters</h5>
  </div>
  <div class="dha-card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="tab" value="users">
      <div class="col-md-2">
        <label class="dha-label">Start Date</label>
        <input type="date" name="start_date" class="dha-form-control" value="<?= esc($startDate) ?>">
      </div>
      <div class="col-md-2">
        <label class="dha-label">End Date</label>
        <input type="date" name="end_date" class="dha-form-control" value="<?= esc($endDate) ?>">
      </div>
      <div class="col-md-3">
        <label class="dha-label">User</label>
        <select name="user_id" class="dha-form-control">
          <option value="">— All Users —</option>
          <?php while ($u = $usersResult->fetch_assoc()): ?>
            <option value="<?= $u['user_id'] ?>" <?= $userId == $u['user_id'] ? 'selected' : '' ?>>
              <?= esc($u['full_name']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-12 d-flex justify-content-end mt-2">
        <button type="submit" class="btn btn-dha"><i class="fas fa-search me-1"></i>Generate Report</button>
      </div>
    </form>
  </div>
</div>

<!-- ── User Report Table ────────────────────────────────────── -->
<div class="dha-card">
  <div class="dha-card-header">
    <h5><i class="fas fa-table text-dha me-2"></i>
      <?= $userId > 0 ? 'Daily Breakdown — ' . esc($selectedUserName) : 'All Users — Summary' ?>
    </h5>
    <span class="text-secondary small"><?= esc($startDate) ?> to <?= esc($endDate) ?> (<?= $totalDays ?> days)</span>
  </div>
  <div class="dha-card-body p-0">
    <div class="table-responsive">

      <?php if ($userId === 0): ?>
      <table class="dha-table" id="reportTable" style="table-layout:fixed;">
        <thead>
          <tr>
            <th style="width:5%;">#</th>
            <th style="width:27%;">User</th>
            <th style="width:17%;">Files Processed</th>
            <th style="width:17%;">Scanned</th>
            <th style="width:17%;">Renamed</th>
            <th style="width:17%;">Verified</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allUsersReport)): ?>
            <tr><td colspan="6" class="text-center text-secondary py-4"><i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>No activity found in this date range.</td></tr>
          <?php else: $i = 1; foreach ($allUsersReport as $row): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= esc($row['name']) ?></td>
              <td><?= $row['files'] ?></td>
              <td><?= $row['scan'] ?></td>
              <td><?= $row['rename'] ?></td>
              <td><?= $row['verified'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <?php else: ?>
      <table class="dha-table" id="reportTable" style="table-layout:fixed;">
        <thead>
          <tr>
            <th style="width:5%;">#</th>
            <th style="width:27%;">Date</th>
            <th style="width:17%;">Files Processed</th>
            <th style="width:17%;">Scanned</th>
            <th style="width:17%;">Renamed</th>
            <th style="width:17%;">Verified</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($userDailyRows)): ?>
            <tr><td colspan="6" class="text-center text-secondary py-4"><i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>No activity found for this user in the date range.</td></tr>
          <?php else: $i = 1; foreach ($userDailyRows as $row): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= esc($row['date']) ?></td>
              <td><?= $row['files'] ?></td>
              <td><?= $row['scan'] ?></td>
              <td><?= $row['rename'] ?></td>
              <td><?= $row['verified'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr class="fw-800">
            <td colspan="2">Total</td>
            <td><?= $userTotals['files'] ?></td>
            <td><?= $userTotals['scan'] ?></td>
            <td><?= $userTotals['rename'] ?></td>
            <td><?= $userTotals['verified'] ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php else: /* ── Plot Reports tab ── */ ?>

<!-- ── Plot Search Panel ────────────────────────────────────── -->
<div class="dha-card mb-4 no-print">
  <div class="dha-card-header">
    <h5><i class="fas fa-filter text-dha me-2"></i>Plot Search</h5>
  </div>
  <div class="dha-card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="tab" value="plot">
      <div class="col-md-2">
        <label class="dha-label">PH (Phase)</label>
        <input type="text" name="ph" class="dha-form-control" value="<?= esc($searchPH) ?>" placeholder="e.g. 6">
      </div>
      <div class="col-md-2">
        <label class="dha-label">SEC (Sector)</label>
        <input type="text" name="sec" class="dha-form-control" value="<?= esc($searchSEC) ?>" placeholder="e.g. A">
      </div>
      <div class="col-md-2">
        <label class="dha-label">Plot No</label>
        <input type="text" name="plot" class="dha-form-control" value="<?= esc($searchPlot) ?>" placeholder="e.g. 454">
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button type="submit" class="btn btn-dha"><i class="fas fa-search me-1"></i>Search</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Plot Report Table ────────────────────────────────────── -->
<div class="dha-card">
  <div class="dha-card-header">
    <h5><i class="fas fa-table text-dha me-2"></i>Plot Documents</h5>
    <?php if ($hasPlotSearch): ?>
      <span class="text-secondary small"><?= count($plotResults) ?> document(s) found</span>
    <?php endif; ?>
  </div>
  <div class="dha-card-body p-0">
    <div class="table-responsive">
      <table class="dha-table" id="plotReportTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Doc ID</th>
            <th>Document</th>
            <th>Doc Type</th>
            <th>Scan</th>
            <th>Rename</th>
            <th>Verify</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$hasPlotSearch): ?>
            <tr><td colspan="7" class="text-center text-secondary py-4"><i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i>Enter a PH, SEC, or Plot No above and click Search.</td></tr>
          <?php elseif (empty($plotResults)): ?>
            <tr><td colspan="7" class="text-center text-secondary py-4"><i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>No documents found for this plot.</td></tr>
          <?php else: $i = 1; foreach ($plotResults as $row): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><code class="text-dha">#<?= esc($row['document_id']) ?></code></td>
              <td>
                <small><?= esc($row['renamed_filename'] ?: $row['raw_filename']) ?></small>
                <div class="text-secondary" style="font-size:0.72rem;">
                  PH <?= esc($row['phase'] ?: '—') ?> / SEC <?= esc($row['branch'] ?: '—') ?> / Plot <?= esc($row['plot'] ?: '—') ?>
                </div>
              </td>
              <td><small><?= esc($row['doc_type'] ?? '—') ?></small></td>
              <td>
                <?php if ($row['scanned_by_name']): ?>
                  <div class="fw-600"><?= esc($row['scanned_by_name']) ?></div>
                  <div class="text-secondary" style="font-size:0.72rem;"><?= (!empty($row['scanned_at']) && strpos($row['scanned_at'], '0000-00-00') !== 0 && strtotime($row['scanned_at'])) ? date('d M Y', strtotime($row['scanned_at'])) : '—' ?></div>
                <?php else: ?>
                  <span class="text-secondary">— Not scanned —</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($row['renamed_by_name']): ?>
                  <div class="fw-600"><?= esc($row['renamed_by_name']) ?></div>
                  <div class="text-secondary" style="font-size:0.72rem;"><?= (!empty($row['renamed_at']) && strpos($row['renamed_at'], '0000-00-00') !== 0 && strtotime($row['renamed_at'])) ? date('d M Y', strtotime($row['renamed_at'])) : '—' ?></div>
                <?php else: ?>
                  <span class="text-secondary">— Not renamed —</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($row['validated_by_name']): ?>
                  <div class="fw-600">
                    <?= esc($row['validated_by_name']) ?>
                    <?php if ($row['decision']): ?>
                      <span class="<?= $row['decision'] === 'approve' ? 'badge-approved' : ($row['decision'] === 'change' ? 'badge-changed' : 'badge-pending') ?> ms-1" title="<?= esc($row['val_remarks'] ?? '') ?>">
                        <?= $row['decision'] === 'change' ? 'Validated after Correction' : ucfirst(str_replace('_', ' ', $row['decision'])) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="text-secondary" style="font-size:0.72rem;"><?= (!empty($row['validated_at']) && strpos($row['validated_at'], '0000-00-00') !== 0 && strtotime($row['validated_at'])) ? date('d M Y', strtotime($row['validated_at'])) : '—' ?></div>
                  <?php if ($row['decision'] === 'change' && !empty($row['val_remarks'])): ?>
                    <div style="font-size:0.72rem; color:#fca5a5; margin-top:2px; font-weight:500;" title="<?= esc($row['val_remarks']) ?>">
                      <i class="fas fa-edit me-1" style="font-size:0.68rem;"></i><?= esc(preg_replace('/^Validated after Correction\s*[\x{2014}\-—]*\s*/u', '', $row['val_remarks'])) ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-secondary">— Pending —</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const initTables = () => {
    if (typeof initPagination !== 'function') return;
    if (document.getElementById('reportTable'))     initPagination('reportTable', 10);
    if (document.getElementById('plotReportTable')) initPagination('plotReportTable', 10);
  };

  initTables();
  window.addEventListener('load', initTables);
});
</script>

<?php require_once '../includes/footer.php'; ?>