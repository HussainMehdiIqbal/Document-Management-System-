<?php
// ============================================================
// user_dashboard.php — Personal User Dashboard (dms_database)
// ============================================================
require_once 'includes/auth.php';
requireLoginRoot();

// Redirect Admin to the full Admin Dashboard
if ((int)getUserRoleId() === 1) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle = 'My Dashboard';
$uid = getUserId();

// Same reasoning as dashboard.php: without this, a browser can serve a
// cached copy of this page from just before a validate/rename/scan action,
// so the KPI strip below looks like it "isn't updating" even though the
// database already reflects the new count.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$isValidator = ((int)getUserRoleId() === 3);
// role_id 2 can only reach scan.php, role_id 4 can only reach rename.php
// (see the access checks at the top of each) — they are two distinct
// roles, not one combined "Operator" who does both.
$isScanner   = ((int)getUserRoleId() === 2);
$isRenamer   = ((int)getUserRoleId() === 4);

// ── Daily Reset Cutoff ─────────────────────────────────────────────
// Shared with dashboard.php, scan.php, rename.php, and validate.php (see
// includes/config.php) so "Reset Dashboard" zeroes every KPI everywhere.
$cutoff = getDashboardResetCutoff($conn, $uid);
$lastReset = $cutoff !== (date('Y-m-d') . ' 00:00:00') ? $cutoff : null;
$cutoffDisplay = $lastReset
    ? 'Last reset: ' . date('h:i A', strtotime($lastReset))
    : 'Resets at midnight';

// ── Stats — all scoped to cutoff (daily reset) ─────────────────────
if ($isValidator) {
    $myTotalDocs     = $conn->query("SELECT COUNT(*) FROM documents WHERE status='pending' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    $myScannedToday  = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $uid AND validated_at >= '$cutoff'")->fetch_row()[0];
    $myRenamedCount  = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $uid AND validated_at >= '$cutoff'")->fetch_row()[0];
    $myPendingCount  = 0;
    $myApprovedCount = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $uid AND decision='approve' AND validated_at >= '$cutoff'")->fetch_row()[0];
    $myChangedCount = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $uid AND decision='change' AND validated_at >= '$cutoff'")->fetch_row()[0];
} elseif ($isScanner) {
    $myTotalDocs     = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND source = 'scan' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    $myScannedToday  = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND source = 'scan' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    $myPendingCount  = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND status='pending' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    $myApprovedCount = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND status='approved' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    $myChangedCount = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $uid AND status='changed' AND scanned_at >= '$cutoff'")->fetch_row()[0];
    // Scanners don't rename — never mix rename activity into their KPI.
    $myRenamedCount  = 0;
} else {
    // Renamer (role_id 4) — mirror of the Scanner branch above, but keyed
    // off what THIS user actually renamed rather than scanned, so Scan
    // activity (someone else's, or none) never leaks into their numbers.
    $myTotalDocs     = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_by = $uid AND renamed_filename IS NOT NULL AND renamed_at >= '$cutoff'")->fetch_row()[0];
    $myPendingCount  = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_by = $uid AND status='pending' AND renamed_at >= '$cutoff'")->fetch_row()[0];
    $myApprovedCount = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_by = $uid AND status='approved' AND renamed_at >= '$cutoff'")->fetch_row()[0];
    $myChangedCount = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_by = $uid AND status='changed' AND renamed_at >= '$cutoff'")->fetch_row()[0];
    $myRenamedCount  = $conn->query("SELECT COUNT(*) FROM documents WHERE renamed_by = $uid AND renamed_filename IS NOT NULL AND renamed_at >= '$cutoff'")->fetch_row()[0];
    // Renamers don't scan — never mix scan activity into their KPI.
    $myScannedToday  = 0;
}

// ── Daily KPI — uses same cutoff as stat cards ─────────────────────
$kpiTarget = 1000;
if ($isValidator) {
    $kpiCount = $myRenamedCount; // validated today
    $kpiLabel = 'Validated Today';
} else {
    $kpiCount = $myRenamedCount; // renamed today (since cutoff)
    $kpiLabel = 'Renamed Today';
}
$kpiPercent          = ($kpiCount * 100) / $kpiTarget;
$kpiPercentFormatted = number_format($kpiPercent, 1);
$kpiPercentWidth     = min(100, $kpiPercent);

// ── Validate KPI — same formula as the Rename KPI: target 1000/day ─────
// Formula (as instructed): (count * 100) / target
// Corrected and Validated docs from Verify are both counted into this
// single KPI count — no separate breakdown, matching how Rename counts
// all renamed docs together. Scoped to the same cutoff as the rest of
// this dashboard (midnight or manual reset).
//
// NOT Validator-only: validate.php lets ANY signed-in user validate a
// pending document once they select it (permission isn't tied to role
// anymore — see the "Any signed-in user..." comment there). Gating this
// count to $isValidator meant an Operator's or Admin's own validations
// counted correctly in Verify's own KPI banner but always showed 0 here,
// which is why this card looked like it "wasn't updating". Count what
// THIS user actually validated, regardless of role — same query
// validate.php itself uses for its own banner.
$validateKpiTarget = 1000;
$myValidatedCount = $isValidator ? $myRenamedCount
    : (int)$conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $uid AND validated_at >= '$cutoff'")->fetch_row()[0];
$validateKpiCount  = $myValidatedCount; // already combines approve + change
$validateKpiPercent          = ($validateKpiCount * 100) / $validateKpiTarget;
$validateKpiPercentFormatted = number_format($validateKpiPercent, 1);
$validateKpiPercentWidth     = min(100, $validateKpiPercent);

// ── Scan KPI — target set by management: 400 scans/day ─────────
// Formula (as instructed): (count * 100) / target
// Scanning is a Scanner-role activity — Renamers and Validators never see
// or act on this number, so it must never leak into their dashboards.
$scanKpiTarget = 400;
$scanKpiCount  = $isScanner ? $myScannedToday : 0;
$scanKpiPercent          = ($scanKpiCount * 100) / $scanKpiTarget;
$scanKpiPercentFormatted = number_format($scanKpiPercent, 1);
$scanKpiPercentWidth     = min(100, $scanKpiPercent);

// ── User Progress — this role's one relevant KPI ────────────────
// Previously summed Scan + Rename + Validate together for every role,
// which is exactly the "unrelated KPI" mixing being fixed here — a
// Scanner's "combined" figure was never really combined, since Rename
// and Validate are both always 0 for them anyway. Show the one metric
// that's actually theirs.
if ($isScanner) {
    $userProgressPercent = $scanKpiPercentWidth;
    $userProgressLabel   = 'Scan KPI — your work done today';
} elseif ($isRenamer) {
    $userProgressPercent = $kpiPercentWidth;
    $userProgressLabel   = 'Rename KPI — your work done today';
} else {
    $userProgressPercent = $validateKpiPercentWidth;
    $userProgressLabel   = 'Validate KPI — your work done today';
}
$userProgressPercentFormatted = number_format($userProgressPercent, 1);
$userProgressPercentWidth     = min(100, $userProgressPercent);

require_once 'includes/user_header.php';
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header">
  <div class="page-header-left">
    <h1><i class="fas fa-th-large text-info me-2"></i>My Workspace</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item active">Overview</li>
        <li class="breadcrumb-item active"><?= date('l, d F Y') ?></li>
      </ol>
    </nav>
  </div>
  <div class="page-header-actions">
    <span class="text-secondary me-2" style="font-size:.75rem;" id="dashResetStatus"><?= esc($cutoffDisplay) ?></span>
    
    <?php if ($isValidator): ?>
      <a href="pages/validate.php" class="btn btn-info text-white">
        <i class="fas fa-check-circle me-1"></i>Go to Validation
      </a>
    <?php endif; ?>
  </div>
</div>

<script>
async function resetDashboard() {
  const btn = document.getElementById('resetDashboardBtn');
  if (!confirm('Reset your dashboard? Every count and chart will go back to 0 until new activity happens.')) {
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

<!-- ── Overall User Progress — this role's one relevant KPI ── -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="kpi-mini-card" style="--kpi-color:#7c3aed;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-chart-pie me-1"></i>User Progress</span>
        <span class="kpi-mini-pct"><?= $userProgressPercentFormatted ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $userProgressPercentWidth ?>%; background: linear-gradient(90deg, #7c3aed, #a855f7);"></div></div>
      <div class="kpi-mini-sub"><?= esc($userProgressLabel) ?></div>
    </div>
  </div>
</div>

<!-- ── KPI Strip ────────────────────────────────────────────────
     Only the ONE KPI relevant to this role is shown — a Scanner never
     sees Rename/Validate KPIs they can't act on, a Renamer never sees
     Scan/Validate, and a Validator never sees Scan/Rename. -->
<div class="row g-3 mb-3">
  <?php if ($isScanner): ?>
  <div class="col-12">
    <div class="kpi-mini-card" style="--kpi-color:#f1b21c;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-print me-1"></i>Scan KPI</span>
        <span class="kpi-mini-pct"><?= $scanKpiPercentFormatted ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $scanKpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $scanKpiCount ?> / <?= $scanKpiTarget ?> scanned</div>
    </div>
  </div>
  <?php elseif ($isRenamer): ?>
  <div class="col-12">
    <div class="kpi-mini-card" style="--kpi-color:#1e4db7;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-chart-line me-1"></i>Rename KPI</span>
        <span class="kpi-mini-pct"><?= $kpiPercentFormatted ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $kpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $kpiCount ?> / <?= $kpiTarget ?> renamed</div>
    </div>
  </div>
  <?php else: ?>
  <div class="col-12">
    <div class="kpi-mini-card" style="--kpi-color:#15803d;">
      <div class="kpi-mini-top">
        <span class="kpi-mini-label"><i class="fas fa-bullseye me-1"></i>Validate KPI</span>
        <span class="kpi-mini-pct"><?= $validateKpiPercentFormatted ?>%</span>
      </div>
      <div class="kpi-mini-track"><div class="kpi-mini-fill" style="width: <?= $validateKpiPercentWidth ?>%;"></div></div>
      <div class="kpi-mini-sub"><?= $validateKpiCount ?> / <?= $validateKpiTarget ?> validated</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<style>
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

<!-- ── Daily KPI Progress Banner(s) ─────────────────────────────── -->
<div class="row mb-4 g-3">
  <!-- Scan KPI -->
  <!--<div class="col-md-6">
    <div class="dha-card p-4 h-100" style="border-left: 5px solid #f1b21c; background: linear-gradient(135deg, rgba(241,178,28,0.08) 0%, rgba(241,178,28,0.02) 100%);">
      <div class="d-flex align-items-start justify-content-between">
        <div>
          <h5 class="mb-1 fw-800" style="color:#b9820a;"><i class="fas fa-bullseye me-2"></i>Scan KPI</h5>
          <p class="text-secondary small mb-0">Daily target: <strong><?= $scanKpiTarget ?></strong> scans.</p>
        </div>
        <div class="text-end">
          <span class="fs-3 fw-900" style="color:#b9820a;"><?= $scanKpiPercentFormatted ?>%</span>
        </div>
      </div>
      <div class="mt-3">
        <div class="progress" style="height: 12px; background-color: rgba(0,0,0,0.06); border-radius: 6px; overflow: hidden;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
               style="width: <?= $scanKpiPercentWidth ?>%; background: linear-gradient(90deg, #f1b21c, #d99e12);"
               aria-valuenow="<?= $scanKpiPercentWidth ?>" aria-valuemin="0" aria-valuemax="100">
          </div>
        </div>
        <div class="d-flex justify-content-between mt-2 text-secondary" style="font-size: 0.72rem; font-weight: 600;">
          <span><?= $scanKpiCount ?> scanned today</span>
          <span>Target: <?= $scanKpiTarget ?> scans</span>
        </div>
      </div>
    </div>
  </div>-->

  <!-- Rename KPI -->
  <!--<div class="col-md-6">
    <div class="dha-card p-4 h-100" style="border-left: 5px solid var(--accent); background: linear-gradient(135deg, rgba(30,77,183,0.05) 0%, rgba(33,150,243,0.05) 100%);">
      <div class="row align-items-center">
        <div class="col-md-8">
          <h5 class="mb-1 text-primary fw-800"><i class="fas fa-chart-line me-2"></i>Rename KPI</h5>
          <p class="text-secondary small mb-3 mb-md-0">
            Daily target of <strong><?= $kpiTarget ?></strong> renamed files. Your progress resets automatically at midnight.
          </p>
        </div>
        <div class="col-md-4 text-md-end">
          <div class="d-inline-block text-center">
            <span class="fs-2 fw-900 text-info"><?= $kpiPercentFormatted ?>%</span>
            <div class="text-secondary" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
              <?= $kpiCount ?> / <?= $kpiTarget ?> <?= $kpiLabel ?>
            </div>
          </div>
        </div>
      </div>
      <div class="mt-3">
        <div class="progress" style="height: 12px; background-color: rgba(255, 255, 255, 0.1); border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05);">
          <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" 
               style="width: <?= $kpiPercentWidth ?>%; background: linear-gradient(90deg, #1e4db7, #0ea5e9);" 
               aria-valuenow="<?= $kpiPercentWidth ?>" aria-valuemin="0" aria-valuemax="100">
          </div>
        </div>
        <div class="d-flex justify-content-between mt-2 text-secondary" style="font-size: 0.72rem; font-weight: 600;">
          <span><?= $kpiCount ?> files renamed today</span>
          <span>Target: <?= $kpiTarget ?> files</span>
        </div>
      </div>
    </div>
  </div>-->

  <!-- Validate KPI -->
  <!--<div class="col-md-12">
    <div class="dha-card p-4 h-100" style="border-left: 5px solid #15803d; background: linear-gradient(135deg, rgba(21,128,61,0.08) 0%, rgba(21,128,61,0.02) 100%);">
      <div class="row align-items-center">
        <div class="col-md-8">
          <h5 class="mb-1 fw-800" style="color:#15803d;"><i class="fas fa-chart-line me-2"></i>Validate KPI</h5>
          <p class="text-secondary small mb-3 mb-md-0">
            Daily target of <strong><?= $validateKpiTarget ?></strong> validated files (corrected + validated combined). Your progress resets automatically at midnight.
          </p>
        </div>
        <div class="col-md-4 text-md-end">
          <div class="d-inline-block text-center">
            <span class="fs-2 fw-900" style="color:#15803d;"><?= $validateKpiPercentFormatted ?>%</span>
            <div class="text-secondary" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
              <?= $validateKpiCount ?> / <?= $validateKpiTarget ?> Validated Today
            </div>
          </div>
        </div>
      </div>
      <div class="mt-3">
        <div class="progress" style="height: 12px; background-color: rgba(0,0,0,0.06); border-radius: 6px; overflow: hidden;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
               style="width: <?= $validateKpiPercentWidth ?>%; background: linear-gradient(90deg, #15803d, #16a34a);"
               aria-valuenow="<?= $validateKpiPercentWidth ?>" aria-valuemin="0" aria-valuemax="100">
          </div>
        </div>
        <div class="d-flex justify-content-between mt-2 text-secondary" style="font-size: 0.72rem; font-weight: 600;">
          <span><?= $validateKpiCount ?> corrected + validated today</span>
          <span>Target: <?= $validateKpiTarget ?> files</span>
        </div>
      </div>
    </div>
  </div>
</div>-->

<!-- ── Stat Cards — Daily (reset at midnight or on manual reset) ──── -->
<div class="row g-3 mb-4">
  <?php if ($isScanner || $isValidator): ?>
  <div class="col-xl-4 col-sm-6">
    <div class="stat-card" style="--card-color:#1e4db7;">
      <div class="card-icon"><i class="fas fa-scanner"></i></div>
      <div class="card-value"><?= $myTotalDocs ?></div>
      <div class="card-label"><?= $isValidator ? 'Pending Validation (Queue)' : 'Scanned Today' ?></div>
      <div class="card-change up"><i class="fas fa-calendar-day"></i><?= $isValidator ? 'System-wide queue' : 'Since last reset' ?></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($isRenamer || $isValidator): ?>
  <div class="col-xl-4 col-sm-6">
    <div class="stat-card" style="--card-color:#9b59b6;">
      <div class="card-icon"><i class="fas fa-file-signature"></i></div>
      <div class="card-value"><?= $myRenamedCount ?></div>
      <div class="card-label"><?= $isValidator ? 'Validated Today' : 'Renamed Today' ?></div>
      <div class="card-change up"><i class="fas fa-tag"></i>Since last reset</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-xl-4 col-sm-6">
    <div class="stat-card" style="--card-color:#27ae60;">
      <div class="card-icon"><i class="fas fa-check-circle"></i></div>
      <div class="card-value"><?= $myApprovedCount ?></div>
      <div class="card-label"><?= $isValidator ? 'Approved Today' : 'Approved Today' ?></div>
      <div class="card-change up"><i class="fas fa-check"></i>Since last reset</div>
    </div>
  </div>
  <?php if (!$isValidator): ?>
  <div class="col-xl-4 col-sm-6">
    <div class="stat-card" style="--card-color:#f39c12;">
      <div class="card-icon"><i class="fas fa-clock"></i></div>
      <div class="card-value"><?= $myPendingCount ?></div>
      <div class="card-label">Awaiting Validation</div>
      <div class="card-change <?= $myPendingCount > 0 ? 'down' : 'up' ?>">
        <i class="fas fa-exclamation-circle"></i>Since last reset
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-xl-4 col-sm-6">
    <div class="stat-card" style="--card-color:#e74c3c;">
      <div class="card-icon"><i class="fas fa-times-circle"></i></div>
      <div class="card-value"><?= $myChangedCount ?></div>
      <div class="card-label"><?= $isValidator ? 'Corrected Today' : 'Corrected Today' ?></div>
      <div class="card-change down"><i class="fas fa-times"></i>Since last reset</div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>