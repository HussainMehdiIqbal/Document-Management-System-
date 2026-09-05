<?php
// ============================================================
// dashboard.php — Main Dashboard (dms_database schema)
// ============================================================
require_once 'includes/auth.php';
requireLoginRoot();

// Admin-only page — redirect regular users to their own dashboard
if ((int)getUserRoleId() !== 1) {
    header('Location: user_dashboard.php');
    exit();
}

$pageTitle = 'Dashboard';
$uid = getUserId();

// This page is reached right after validating/renaming/scanning (e.g. via
// a nav link or the browser Back button), and its stats are only computed
// once per request. Without explicit no-store headers, browsers can serve
// a cached copy of this exact page from just before that action — showing
// KPI numbers that look "stuck" at their old values even though the
// database already has the update (which is why validate.php's own KPI
// banner, reached via a fresh redirect, shows the correct number first).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Dashboard Reset Cutoff ──────────────────────────────────────────
// "Reset Dashboard" doesn't delete any real users/documents/folders — it
// moves this admin's cutoff forward (same mechanism already used by
// user_dashboard.php / ajax/reset_dashboard.php), and every count below is
// scoped to "since cutoff". That's what makes the numbers go back to 0
// immediately after pressing the button, without touching real data.
$conn->query("
    CREATE TABLE IF NOT EXISTS user_dashboard_resets (
        user_id   INT PRIMARY KEY,
        reset_at  DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )
");
$resetRow      = $conn->query("SELECT reset_at FROM user_dashboard_resets WHERE user_id = $uid")->fetch_row();
$lastReset     = $resetRow ? $resetRow[0] : null;
$todayStart    = date('Y-m-d') . ' 00:00:00';
$cutoff        = ($lastReset && $lastReset > $todayStart) ? $lastReset : $todayStart;
$cutoffDisplay = ($lastReset && $lastReset > $todayStart)
    ? 'Last reset: ' . date('h:i A', strtotime($lastReset))
    : 'Resets at midnight';
$cutoffSql = $conn->real_escape_string($cutoff);

// ── Stats — all scoped to cutoff (so Reset Dashboard zeroes them) ────
$totalUsers    = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= '$cutoffSql'")->fetch_row()[0];
$totalDocs     = $conn->query("SELECT COUNT(*) FROM documents WHERE source = 'scan' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];
$totalFolders  = $conn->query("SELECT COUNT(*) FROM folders WHERE status='active' AND created_at >= '$cutoffSql'")->fetch_row()[0];
$scannedToday  = $conn->query("SELECT COUNT(*) FROM documents WHERE source = 'scan' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];
$pendingCount  = $conn->query("SELECT COUNT(*) FROM documents WHERE status='pending' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];

// Approved/Corrected must be scoped to WHEN THE DOCUMENT WAS VALIDATED
// (validations.validated_at), not when it was originally scanned
// (documents.scanned_at). Filtering by scanned_at meant validating a
// document that had been scanned before the cutoff (e.g. scanned
// yesterday, validated today) never moved these counters — the Verify
// KPI on this page looked "stuck" even though validations were happening.
// This now matches the same validations-table source used by the
// Org-wide Validate KPI below and by validate.php's own per-user stats.
$approvedCount = $conn->query("SELECT COUNT(*) FROM validations WHERE decision='approve' AND validated_at >= '$cutoffSql'")->fetch_row()[0];
$changedCount  = $conn->query("SELECT COUNT(*) FROM validations WHERE decision='change'  AND validated_at >= '$cutoffSql'")->fetch_row()[0];
$renamedCount  = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_filename IS NOT NULL AND renamed_at >= '$cutoffSql'")->fetch_row()[0];

// ── Org-wide Scan / Rename / Validate KPIs ────────────────────────
// Same three KPIs shown per-user on scan.php / rename.php / validate.php,
// rolled up here for the admin overview. Each is independent — sourced
// from its own column (scanned_at+source='scan', renamed_at, validated_at)
// so activity in one module never moves another's number — and each is
// scoped to the same Reset Dashboard cutoff as everything else on this page.
$activeOperators  = max(1, (int)$conn->query("SELECT COUNT(*) FROM users WHERE role_id = 2 AND status = 'active'")->fetch_row()[0]);
$activeValidators = max(1, (int)$conn->query("SELECT COUNT(*) FROM users WHERE role_id = 3 AND status = 'active'")->fetch_row()[0]);

$orgScanKpiTarget = 400 * $activeOperators;
$orgScanKpiCount  = (int)$conn->query("SELECT COUNT(*) FROM documents WHERE source = 'scan' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];
$orgScanKpiPercent      = ($orgScanKpiTarget > 0) ? ($orgScanKpiCount * 100) / $orgScanKpiTarget : 0;
$orgScanKpiPercentWidth = min(100, $orgScanKpiPercent);

$orgRenameKpiTarget = 1000 * $activeOperators;
$orgRenameKpiCount  = (int)$conn->query("SELECT COUNT(*) FROM documents WHERE renamed_filename IS NOT NULL AND renamed_at >= '$cutoffSql'")->fetch_row()[0];
$orgRenameKpiPercent      = ($orgRenameKpiTarget > 0) ? ($orgRenameKpiCount * 100) / $orgRenameKpiTarget : 0;
$orgRenameKpiPercentWidth = min(100, $orgRenameKpiPercent);

$orgValidateKpiTarget = 1000 * $activeValidators;
$orgValidateKpiCount  = (int)$conn->query("SELECT COUNT(*) FROM validations WHERE validated_at >= '$cutoffSql'")->fetch_row()[0];
$orgValidateKpiPercent      = ($orgValidateKpiTarget > 0) ? ($orgValidateKpiCount * 100) / $orgValidateKpiTarget : 0;
$orgValidateKpiPercentWidth = min(100, $orgValidateKpiPercent);

// ── Per-User Stats ───────────────────────────────────────────
// Cutoff applied inside the JOIN (not WHERE) so every active operator still
// appears in the table, just with 0s, instead of disappearing entirely.
$userStats = $conn->query("
    SELECT
        u.user_id,
        u.full_name,
        u.avatar_color,
        r.role_name,
        COUNT(DISTINCT COALESCE(d_scan.folder_id, d_ren.folder_id)) AS folders_browsed,
        COUNT(DISTINCT d_scan.document_id)                           AS total_files_in_folders,
        COUNT(DISTINCT d_ren.document_id)                            AS files_renamed
    FROM users u
    LEFT JOIN roles r          ON r.role_id        = u.role_id
    LEFT JOIN documents d_scan ON d_scan.scanned_by = u.user_id AND d_scan.scanned_at >= '$cutoffSql'
    LEFT JOIN documents d_ren  ON d_ren.renamed_by  = u.user_id AND d_ren.renamed_at  >= '$cutoffSql'
    WHERE u.status = 'active' AND u.role_id = 2
    GROUP BY u.user_id, u.full_name, u.avatar_color, r.role_name
    ORDER BY files_renamed DESC, folders_browsed DESC
");

// ── Recent Documents (last 8, since cutoff) ───────────────────
$recentDocs = $conn->query(
    "SELECT d.*, u.full_name AS scanned_by_name,
            COALESCE(v.max_val_at, d.renamed_at, d.scanned_at) AS last_activity
     FROM documents d
     LEFT JOIN users u ON d.scanned_by = u.user_id
     LEFT JOIN (
         SELECT document_id, MAX(validated_at) AS max_val_at
         FROM validations
         GROUP BY document_id
     ) v ON d.document_id = v.document_id
     WHERE COALESCE(v.max_val_at, d.renamed_at, d.scanned_at) >= '$cutoffSql'
     ORDER BY last_activity DESC, d.document_id DESC LIMIT 8"
);

// ── Activity Log (last 8, since cutoff) ───────────────────────
$recentActivity = $conn->query(
    "SELECT al.*, u.full_name, u.avatar_color
     FROM activity_log al
     LEFT JOIN users u ON al.user_id = u.user_id
     WHERE al.created_at >= '$cutoffSql'
     ORDER BY al.created_at DESC LIMIT 8"
);

// ── Upload Trend — last 7 days (since cutoff) ─────────────────
$trendLabels = [];
$trendData   = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D d', strtotime("-$i days"));
    $count = $conn->query("SELECT COUNT(*) FROM documents WHERE source = 'scan' AND DATE(scanned_at)='$date' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];
    $trendLabels[] = $label;
    $trendData[]   = (int)$count;
}

// ── Monthly Trend — last 6 months (since cutoff) ──────────────
$monthLabels = [];
$monthData   = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $count = $conn->query("SELECT COUNT(*) FROM documents WHERE source = 'scan' AND DATE_FORMAT(scanned_at,'%Y-%m')='$month' AND scanned_at >= '$cutoffSql'")->fetch_row()[0];
    $monthLabels[] = $label;
    $monthData[]   = (int)$count;
}

require_once 'includes/header.php';
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header">
  <div class="page-header-left">
    <h1><i class="fas fa-th-large text-dha me-2"></i>Dashboard</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item active">Overview</li>
        <li class="breadcrumb-item active"><?= date('l, d F Y') ?></li>
      </ol>
    </nav>
  </div>

  <div class="page-header-actions">
    <span class="text-secondary me-2" style="font-size:.75rem;" id="dashResetStatus"><?= esc($cutoffDisplay) ?></span>
    <button type="button" class="btn btn-outline-dha" id="resetDashboardBtn" onclick="resetDashboard()" title="Reset dashboard numbers to zero">
      <i class="fas fa-rotate-right me-1"></i>Reset Dashboard
    </button>
  </div>
</div>

<script>
async function resetDashboard() {
  const btn = document.getElementById('resetDashboardBtn');
  if (!confirm('Reset the dashboard? Every count and chart will go back to 0 until new activity happens.')) {
    return;
  }
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Resetting…';
  }
  try {
    const res  = await fetch('ajax/reset_dashboard.php', { method: 'POST' });
    const data = await res.json();
    if (!data.success) {
      throw new Error(data.message || 'Reset failed.');
    }
    // Reload so every stat card, chart, and table re-queries against the
    // new cutoff and shows 0 (until new activity happens).
    window.location.reload();
  } catch (err) {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-rotate-right me-1"></i>Reset Dashboard';
    }
    if (typeof showToast === 'function') {
      showToast('Could not reset dashboard: ' + err.message, 'error');
    } else {
      alert('Could not reset dashboard: ' + err.message);
    }
  }
}
</script>

<!-- ── Compact Scan / Rename / Validate KPI Strip ──────────────
     Org-wide roll-up of the same three independent KPIs shown per-user on
     the Scan, Rename, and Validate pages — placed right at the top of the
     dashboard since that's the first thing an admin scans for a daily
     health check, and kept compact (slim bars, no big numbers) so it
     doesn't compete with the stat cards below. -->
<!--<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="kpi-mini-card" style="--kpi-color:#f1b21c;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-print me-1"></i>Scan KPI</span>
        <span class="kpi-mini-pct"><?= number_format($orgScanKpiPercent, 1) ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $orgScanKpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $orgScanKpiCount ?> / <?= $orgScanKpiTarget ?> scanned org-wide</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-mini-card" style="--kpi-color:#1e4db7;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-chart-line me-1"></i>Rename KPI</span>
        <span class="kpi-mini-pct"><?= number_format($orgRenameKpiPercent, 1) ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $orgRenameKpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $orgRenameKpiCount ?> / <?= $orgRenameKpiTarget ?> renamed org-wide</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-mini-card" style="--kpi-color:#15803d;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-bullseye me-1"></i>Validate KPI</span>
        <span class="kpi-mini-pct"><?= number_format($orgValidateKpiPercent, 1) ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $orgValidateKpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $orgValidateKpiCount ?> / <?= $orgValidateKpiTarget ?> validated org-wide</div>
    </div>
  </div>
</div>-->

<!-- ── Stat Cards ───────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#0f1f3d;">
      <div class="card-icon"><i class="fas fa-users"></i></div>
      <div class="card-value"><?= $totalUsers ?></div>
      <div class="card-label">Total Users</div>
      <div class="card-change up"><i class="fas fa-arrow-up"></i>Active accounts</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#1e4db7;">
      <div class="card-icon"><i class="fas fa-file-alt"></i></div>
      <div class="card-value"><?= $totalDocs ?></div>
      <div class="card-label">Total Documents</div>
      <div class="card-change up"><i class="fas fa-arrow-up"></i>All time</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#7c3aed;">
      <div class="card-icon"><i class="fas fa-folder-open"></i></div>
      <div class="card-value"><?= $totalFolders ?></div>
      <div class="card-label">Active Folders</div>
      <div class="card-change up"><i class="fas fa-folder"></i>Organised</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#2196f3;">
      <div class="card-icon"><i class="fas fa-scanner"></i></div>
      <div class="card-value"><?= $scannedToday ?></div>
      <div class="card-label">Scanned Today</div>
      <div class="card-change up"><i class="fas fa-calendar-day"></i>Today</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#f39c12;">
      <div class="card-icon"><i class="fas fa-clock"></i></div>
      <div class="card-value"><?= $pendingCount ?></div>
      <div class="card-label">Pending Validation</div>
      <div class="card-change <?= $pendingCount > 0 ? 'down' : 'up' ?>">
        <i class="fas fa-exclamation-circle"></i>Needs review
      </div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#27ae60;">
      <div class="card-icon"><i class="fas fa-check-circle"></i></div>
      <div class="card-value"><?= $approvedCount ?></div>
      <div class="card-label">Approved</div>
      <div class="card-change up"><i class="fas fa-check"></i>Validated</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#e74c3c;">
      <div class="card-icon"><i class="fas fa-times-circle"></i></div>
      <div class="card-value"><?= $changedCount ?></div>
      <div class="card-label">Corrected</div>
      <div class="card-change down"><i class="fas fa-times"></i>Corrected</div>
    </div>
  </div>
  <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6">
    <div class="stat-card" style="--card-color:#9b59b6;">
      <div class="card-icon"><i class="fas fa-file-signature"></i></div>
      <div class="card-value"><?= $renamedCount ?></div>
      <div class="card-label">Renamed Docs</div>
      <div class="card-change up"><i class="fas fa-tag"></i>Formatted</div>
    </div>
  </div>
</div>

<!-- ── Dashboard Tabs ───────────────────────────────────────── -->
<div class="dashboard-tabs-nav mb-4">
  <button class="dtab-btn active" id="tabOverviewBtn" onclick="switchDTab('overview')">
    <i class="fas fa-th-large me-2"></i>Overview
  </button>
  <button class="dtab-btn" id="tabAnalyticsBtn" onclick="switchDTab('analytics')">
    <i class="fas fa-chart-bar me-2"></i>Analytics
  </button>
  <button class="dtab-btn" id="tabUsersBtn" onclick="switchDTab('users')">
    <i class="fas fa-users me-2"></i>User Performance
  </button>
</div>

<!-- ── Overview Tab ─────────────────────────────────────────── -->
<!-- ── Overview Tab ─────────────────────────────────────────── -->
<div id="tabOverview" class="dtab-content">
  <!-- Recent Documents + Activity ──────────────────────────── -->
  <div class="row g-3">
    <!-- Recent Documents Table -->
    <div class="col-xl-8">
      <div class="dha-card">
        <div class="dha-card-header">
          <h5><i class="fas fa-file-upload text-dha me-2"></i>Recently Scanned Documents</h5>
          <a href="pages/reports.php" class="btn btn-sm btn-outline-dha">View Reports</a>
        </div>
        <div class="dha-card-body p-0">
          <div class="table-responsive">
            <table class="dha-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ID</th>
                  <th>Raw File</th>
                  <th>Doc Type</th>
                  <th>Branch</th>
                  <th>Scanned</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 1;
                while ($doc = $recentDocs->fetch_assoc()):
                  $statusBadge = match($doc['status']) {
                      'approved' => 'badge-approved',
                      'changed' => 'badge-changed',
                      default    => 'badge-pending',
                  };
                ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><code class="text-dha">#<?= esc($doc['document_id']) ?></code></td>
                  <td>
                    <i class="fas fa-file-<?= str_contains($doc['file_type'] ?? '', 'pdf') ? 'pdf text-danger' : 'image text-primary' ?> me-1"></i>
                    <small title="<?= esc($doc['raw_filename']) ?>">
                      <?= esc($doc['renamed_filename'] ?: $doc['raw_filename']) ?>
                    </small>
                  </td>
                  <td><small><?= esc($doc['doc_type'] ?? '—') ?></small></td>
                  <td><small><?= esc($doc['branch'] ?? '—') ?></small></td>
                  <td><small><?= $doc['scanned_at'] ? date('d M Y', strtotime($doc['scanned_at'])) : '—' ?></small></td>
                  <td><span class="<?= $statusBadge ?>"><?= $doc['status'] === 'changed' ? 'Validated after Correction' : ucfirst($doc['status']) ?></span></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Activity Log -->
    <div class="col-xl-4">
      <div class="dha-card h-100">
        <div class="dha-card-header">
          <h5><i class="fas fa-history text-dha me-2"></i>Recent Activity</h5>
        </div>
        <div class="dha-card-body">
          <?php
          $actionColors = [
              'login'    => '#0f1f3d', 'create' => '#2196f3',
              'update'   => '#27ae60', 'delete' => '#e74c3c',
              'view'     => '#9b59b6', 'download' => '#f39c12'
          ];
          $actionIcons = [
              'login'    => 'fa-sign-in-alt', 'create'   => 'fa-plus-circle',
              'update'   => 'fa-check-circle','delete'   => 'fa-trash',
              'view'     => 'fa-eye',          'download' => 'fa-download'
          ];
          while ($log = $recentActivity->fetch_assoc()):
              $at  = $log['action_type'] ?? 'update';
              $ic  = $actionIcons[$at]  ?? 'fa-circle';
              $cl  = $actionColors[$at] ?? '#888';
              $initials = makeInitials($log['full_name'] ?? 'U');
              $avatarColor = $log['avatar_color'] ?? '#1e4db7';
          ?>
          <div class="activity-item">
            <div class="activity-icon" style="background:<?= $cl ?>22; color:<?= $cl ?>;">
              <i class="fas <?= $ic ?>"></i>
            </div>
            <div style="flex:1; min-width:0;">
              <div class="activity-text"><?= esc($log['description']) ?></div>
              <div class="activity-time">
                <span class="d-inline-flex align-items-center justify-content-center me-1"
                      style="width:16px;height:16px;border-radius:4px;background:<?= esc($avatarColor) ?>;color:#fff;font-size:.55rem;font-weight:700;">
                  <?= esc($initials) ?>
                </span>
                <?= esc($log['full_name'] ?? 'System') ?> &bull;
                <?= date('d M, h:i A', strtotime($log['created_at'])) ?>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>
</div><!-- end #tabOverview -->

<!-- ── Analytics Tab ────────────────────────────────────────── -->
<div id="tabAnalytics" class="dtab-content inactive">
  <div class="row g-3 mb-4">
    <!-- Bar: Daily Upload -->
    <div class="col-xl-5 col-lg-7">
      <div class="dha-card h-100">
        <div class="dha-card-header">
          <h5><i class="fas fa-chart-bar text-dha me-2"></i>Scans — Last 7 Days</h5>
        </div>
        <div class="dha-card-body">
          <div class="chart-wrapper"><canvas id="barChart"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Pie: Status Distribution -->
    <div class="col-xl-3 col-lg-5">
      <div class="dha-card h-100">
        <div class="dha-card-header">
          <h5><i class="fas fa-chart-pie text-dha me-2"></i>Status Distribution</h5>
        </div>
        <div class="dha-card-body d-flex align-items-center justify-content-center">
          <div class="chart-wrapper" style="height:220px;">
            <canvas id="pieChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Line: Monthly Trend -->
    <div class="col-xl-4 col-lg-12">
      <div class="dha-card h-100">
        <div class="dha-card-header">
          <h5><i class="fas fa-chart-line text-dha me-2"></i>Monthly Trend</h5>
        </div>
        <div class="dha-card-body">
          <div class="chart-wrapper"><canvas id="lineChart"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Analytics Summary Row -->
  <div class="row g-3">
    <div class="col-xl-4">
      <div class="dha-card">
        <div class="dha-card-header"><h5><i class="fas fa-info-circle text-dha me-2"></i>Summary Statistics</h5></div>
        <div class="dha-card-body">
          <table class="w-100" style="font-size:.85rem;">
            <tr class="border-bottom"><td class="py-2 text-secondary">Total Documents</td><td class="py-2 fw-700 text-end"><?= $totalDocs ?></td></tr>
            <tr class="border-bottom"><td class="py-2 text-secondary">Approved</td><td class="py-2 fw-700 text-success text-end"><?= $approvedCount ?></td></tr>
            <tr class="border-bottom"><td class="py-2 text-secondary">Pending</td><td class="py-2 fw-700 text-warning text-end"><?= $pendingCount ?></td></tr>
            <tr class="border-bottom"><td class="py-2 text-secondary">Corrected</td><td class="py-2 fw-700 text-danger text-end"><?= $changedCount ?></td></tr>
            <tr><td class="py-2 text-secondary">Scanned Today</td><td class="py-2 fw-700 text-primary text-end"><?= $scannedToday ?></td></tr>
          </table>
        </div>
      </div>
    </div>
    <div class="col-xl-8">
      <div class="dha-card">
        <div class="dha-card-header"><h5><i class="fas fa-chart-area text-dha me-2"></i>Approval Rate</h5></div>
        <div class="dha-card-body">
          <?php $total = max(1, $approvedCount + $pendingCount + $changedCount); ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1"><small class="fw-600">Approved</small><small class="text-success fw-700"><?= round($approvedCount/$total*100) ?>%</small></div>
            <div style="height:8px;background:var(--border);border-radius:8px;"><div style="height:8px;background:#27ae60;border-radius:8px;width:<?= round($approvedCount/$total*100) ?>%;"></div></div>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1"><small class="fw-600">Pending</small><small class="text-warning fw-700"><?= round($pendingCount/$total*100) ?>%</small></div>
            <div style="height:8px;background:var(--border);border-radius:8px;"><div style="height:8px;background:#f39c12;border-radius:8px;width:<?= round($pendingCount/$total*100) ?>%;"></div></div>
          </div>
          <div>
            <div class="d-flex justify-content-between mb-1"><small class="fw-600">Corrected</small><small class="text-danger fw-700"><?= round($changedCount/$total*100) ?>%</small></div>
            <div style="height:8px;background:var(--border);border-radius:8px;"><div style="height:8px;background:#e74c3c;border-radius:8px;width:<?= round($changedCount/$total*100) ?>%;"></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div><!-- end #tabAnalytics -->

<!-- ── User Performance Tab ─────────────────────────────── -->
<div id="tabUsers" class="dtab-content inactive">
  <div class="dha-card">
    <div class="dha-card-header d-flex align-items-center justify-content-between">
      <h5><i class="fas fa-users text-dha me-2"></i>User Performance — Folder &amp; Rename Activity</h5>
      <div class="d-flex align-items-center gap-3">
        <span id="userStatsLastUpdated" class="text-secondary" style="font-size:.75rem;"></span>
        <span class="badge bg-success" style="font-size:.65rem;animation:pulse 2s infinite;">
          <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Live
        </span>
      </div>
    </div>
    <div class="dha-card-body p-0">
      <div class="table-responsive">
        <table class="dha-table" id="userPerfTable">
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th style="text-align:center;"><i class="fas fa-folder-open me-1 text-warning"></i>Folders Browsed</th>
              <th style="text-align:center;"><i class="fas fa-file me-1 text-primary"></i>Total Files</th>
              <th style="text-align:center;"><i class="fas fa-file-signature me-1 text-success"></i>Files Renamed</th>
              <th style="text-align:center;">Completion</th>
            </tr>
          </thead>
          <tbody id="userPerfBody">
            <tr><td colspan="6" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div><!-- end #tabUsers -->

<script>
function makeInitialsJS(name) {
  const parts = (name || '').trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
  return (name || '?').substring(0, 2).toUpperCase();
}

async function loadUserStats() {
  try {
    const res  = await fetch('ajax/user_stats.php');
    const data = await res.json();
    const tbody = document.getElementById('userPerfBody');
    if (!tbody) return;

    if (!data || data.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-secondary">No operator data available.</td></tr>';
      return;
    }

    let html = '';
    data.forEach((u, i) => {
      const total    = Math.max(1, u.total_files_in_folders);
      const pct      = Math.min(100, Math.round(u.files_renamed / total * 100));
      const barColor = pct >= 80 ? '#22c55e' : (pct >= 40 ? '#f59e0b' : '#3b82f6');
      const initials = makeInitialsJS(u.full_name);
      html += `
        <tr>
          <td>${i + 1}</td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div style="width:34px;height:34px;border-radius:50%;background:${u.avatar_color};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;">
                ${initials}
              </div>
              <div style="font-weight:600;font-size:.875rem;">${u.full_name}</div>
            </div>
          </td>
          <td style="text-align:center;">
            <span style="display:inline-flex;align-items:center;gap:5px;font-weight:700;">
              <i class="fas fa-folder" style="color:#f59e0b;font-size:.8rem;"></i>${u.folders_browsed}
            </span>
          </td>
          <td style="text-align:center;font-weight:700;">${u.total_files_in_folders}</td>
          <td style="text-align:center;font-weight:700;color:#22c55e;">${u.files_renamed}</td>
          <td style="min-width:130px;">
            <div class="d-flex align-items-center gap-2">
              <div style="flex:1;height:7px;background:var(--border);border-radius:6px;overflow:hidden;">
                <div style="height:7px;background:${barColor};border-radius:6px;width:${pct}%;transition:width .6s;"></div>
              </div>
              <small style="font-weight:700;color:${barColor};min-width:36px;text-align:right;">${pct}%</small>
            </div>
          </td>
        </tr>`;
    });
    tbody.innerHTML = html;

    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const el = document.getElementById('userStatsLastUpdated');
    if (el) el.textContent = 'Updated ' + timeStr;
  } catch (err) {
    console.error('User stats fetch error:', err);
  }
}

// Load immediately on page load
loadUserStats();
// Auto-refresh every 10 seconds
setInterval(loadUserStats, 10000);
</script>
<style>
.dashboard-tabs-nav {
  display: flex;
  gap: 6px;
  border-bottom: 2px solid var(--border);
  padding-bottom: 0;
}
.dtab-btn {
  background: none;
  border: none;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  padding: 10px 22px;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-secondary);
  cursor: pointer;
  transition: all .2s;
  border-radius: 8px 8px 0 0;
  font-family: 'Inter', sans-serif;
}
.dtab-btn:hover { color: var(--dha-primary); background: var(--dha-accent); }
.dtab-btn.active { color: var(--dha-primary); border-bottom-color: var(--dha-primary); background: var(--dha-accent); }

.dtab-content {
  display: block;
}
.dtab-content.inactive {
  position: absolute !important;
  left: -9999px !important;
  top: -9999px !important;
  opacity: 0 !important;
  pointer-events: none !important;
  height: 0 !important;
  overflow: hidden !important;
}

/* ── Compact Scan/Rename/Validate KPI strip ─────────────────── */
.kpi-mini-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 4px solid var(--kpi-color, #1e4db7);
  border-radius: 8px;
  padding: 10px 14px;
}
.kpi-mini-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.kpi-mini-label { font-size: 0.78rem; font-weight: 700; color: var(--text-primary); }
.kpi-mini-pct { font-size: 0.85rem; font-weight: 800; color: var(--kpi-color, #1e4db7); }
.kpi-mini-track { height: 6px; background-color: var(--border); border-radius: 6px; overflow: hidden; }
.kpi-mini-fill { height: 100%; background: var(--kpi-color, #1e4db7); border-radius: 6px; transition: width 0.4s ease; }
.kpi-mini-sub { margin-top: 4px; font-size: 0.68rem; font-weight: 600; color: var(--text-secondary); }
</style>

<script>
function switchDTab(tab) {
  const overview  = document.getElementById('tabOverview');
  const analytics = document.getElementById('tabAnalytics');
  const users     = document.getElementById('tabUsers');
  [overview, analytics, users].forEach(el => el.classList.add('inactive'));
  if (tab === 'overview')  overview.classList.remove('inactive');
  if (tab === 'analytics') analytics.classList.remove('inactive');
  if (tab === 'users')     users.classList.remove('inactive');
  document.getElementById('tabOverviewBtn').classList.toggle('active',  tab === 'overview');
  document.getElementById('tabAnalyticsBtn').classList.toggle('active', tab === 'analytics');
  document.getElementById('tabUsersBtn').classList.toggle('active',     tab === 'users');
}
</script>

<!-- ── Chart.js Init ──────────────────────────────────────────── -->
<script>
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendData   = <?= json_encode($trendData) ?>;
const monthLabels = <?= json_encode($monthLabels) ?>;
const monthData   = <?= json_encode($monthData) ?>;
const pieData = {
  approved: <?= $approvedCount ?>,
  pending:  <?= $pendingCount ?>,
  changed: <?= $changedCount ?>
};
</script>
<script src="assets/js/charts.js"></script>

<?php require_once 'includes/footer.php'; ?>