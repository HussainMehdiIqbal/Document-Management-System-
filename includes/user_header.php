<?php
/* ── includes/user_header.php ─────────────────────────────────
   Horizontal topbar + hnav for regular (non-admin) users only
   ─────────────────────────────────────────────────────────── */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$userName = getUserName();
$userInitials = getUserInitials();
$userAvatarColor = getUserAvatarColor();
$userRoleName = getUserRoleName();
$isInPages = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false);
$base = $isInPages ? '../' : '';
$pagesBase = $isInPages ? '' : 'pages/';
$userId = getUserId();

// Verify pending-count badge — count of documents that genuinely still
// need verification, kept in exact sync with the conditions pages/validate.php
// itself uses to decide what belongs in its queue:
//   - status='pending'   (rename.php sets renamed_filename and status='pending'
//                          together in the same UPDATE, so by the time a row
//                          is 'pending' it always has a renamed_filename —
//                          documents not yet renamed use status='awaiting_rename'
//                          instead, see database/dha_dms.sql)
//   - file_type='pdf'    (Verify only ever handles PDFs — image files never
//                          show up in its queue; this condition was missing
//                          here before, which could inflate this badge past
//                          what the Verify module actually displays)
//   - the file must still physically exist on disk
// Not scoped to the current user — validate.php itself only scopes its own
// "pending" stat to scanned_by for the Operator role; Admin and Validator
// both see the system-wide count, and any signed-in user can act on any
// pending document once they open it, so a system-wide count here is
// consistent with what the Verify module itself shows everyone.
$myPending = 0;
$pendingReadyRes = $conn->query("SELECT storage_path FROM documents WHERE status='pending' AND file_type='pdf'");
if ($pendingReadyRes) {
    while ($prow = $pendingReadyRes->fetch_assoc()) {
        $realPath = __DIR__ . '/../' . $prow['storage_path'];
        if ($prow['storage_path'] && file_exists($realPath) && is_file($realPath)) {
            $myPending++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
  <script>
  window.onerror = function(message, source, lineno, colno, error) {
    var errorData = {
      message: message,
      source: source,
      lineno: lineno,
      colno: colno,
      error: error ? error.stack : null,
      url: window.location.href
    };
    fetch('<?= $base ?>js_log.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(errorData)
    }).catch(err => {});
  };
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle ?? 'My Dashboard') ?> — DHA DMS</title>
  <meta name="description" content="DHA Document Management System — <?= esc($pageTitle ?? 'My Dashboard') ?>">
  <!-- Self-hosted vendor assets (see assets/vendor) — this app must work with no internet access -->
  <link href="<?= $base ?>assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $base ?>assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <link href="<?= $base ?>assets/vendor/fonts/inter/inter.css" rel="stylesheet">
  <script src="<?= $base ?>assets/vendor/chartjs/chart.umd.min.js"></script>
  <link href="<?= $base ?>assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
  <style>
    /* ── User-mode accent overrides ── */
    :root {
      --user-accent: #0ea5e9;
      --user-accent-dark: #0369a1;
      --user-glow: rgba(14, 165, 233, .18);
    }

    .user-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: linear-gradient(135deg, #0ea5e914, #7c3aed14);
      border: 1px solid rgba(14, 165, 233, .3);
      border-radius: 20px;
      padding: 3px 10px;
      font-size: .65rem;
      font-weight: 700;
      letter-spacing: .5px;
      color: var(--user-accent);
      text-transform: uppercase;
    }

    .hnav-link.active {
      border-bottom-color: #0ea5e9 !important;
      background: rgba(14,165,233,.12) !important;
    }
  </style>
</head>

<body>

  <!-- ── Topbar ──────────────────────────────────────────── -->
  <header class="topbar" id="topbar" role="banner">

    <!-- Logo / Brand — tabindex="-1" so keyboard Tab starts at the "My
         Dashboard" nav link below, not here. It links to the same page
         "My Dashboard" already goes to, so nothing is lost — it stays
         fully clickable with the mouse, just excluded from the Tab
         sequence so the Dashboard nav cycle genuinely starts at its
         first item. -->
    <a href="<?= $base ?>user_dashboard.php" class="topbar-logo-link" aria-label="DHA DMS Home" tabindex="-1">
      <img src="<?= $base ?>assets/images/images.jpg" alt="DHA Logo" class="topbar-logo-img">
      <div class="topbar-logo-text">
        <span>DHA Doc Management</span>
        <small>v2.0 — <?= esc($userRoleName) ?></small>
      </div>
    </a>

    <div class="topbar-brand">
      <span class="accent">DHA</span> Document Management System
    </div>

    <!-- User badge -->
    <div class="ms-auto d-flex align-items-center gap-3">
      <span class="user-badge d-none d-md-inline-flex">
        <i class="fas fa-user-circle"></i> <?= esc($userRoleName) ?>
      </span>
    </div>

    <!-- Theme Toggle -->
    <div class="topbar-action" id="themeToggle" title="Toggle Dark/Light Mode" aria-label="Toggle Theme"
      style="cursor:pointer;">
      <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <!-- User Avatar Dropdown -->
    <div class="dropdown">
      <div class="admin-avatar" data-bs-toggle="dropdown" title="<?= esc($userName) ?>"
        style="background:<?= esc($userAvatarColor) ?>; cursor:pointer;" aria-label="User Menu" aria-haspopup="true">
        <?= esc($userInitials) ?>
      </div>
      <ul class="dropdown-menu dropdown-menu-end">
        <li>
          <div class="px-4 pt-3 pb-2">
            <div style="font-weight:700;font-size:.9rem;color:var(--text-primary);"><?= esc($userName) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:2px;">
              @<?= esc(getUserUsername()) ?> &bull;
              <span style="font-size:.65rem;padding:2px 8px;"><?= esc($userRoleName) ?></span>
            </div>
          </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <a class="dropdown-item text-danger" href="<?= $base ?>logout.php" onclick="return confirm('Logout now?')">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a>
        </li>
      </ul>
    </div>
  </header>

  <!-- ── Horizontal Nav Bar ──────────────────────────────── -->
  <nav class="hnav" id="hnav" role="navigation" aria-label="User Navigation">
    <div class="hnav-inner">
      <a href="<?= $base ?>user_dashboard.php"
         class="hnav-link <?= ($currentPage === 'user_dashboard') ? 'active' : '' ?>">
        <i class="fas fa-th-large"></i><span>My Dashboard</span>
      </a>
      <?php if (in_array((int)getUserRoleId(), [1, 2])): ?>
      <a href="<?= $pagesBase ?>scan.php"
         class="hnav-link <?= ($currentPage === 'scan') ? 'active' : '' ?>">
        <i class="fas fa-scanner"></i><span>Scan</span>
      </a>
      <?php endif; ?>
      <?php if (in_array((int)getUserRoleId(), [1, 4])): ?>
      <a href="<?= $pagesBase ?>rename.php"
         class="hnav-link <?= ($currentPage === 'rename') ? 'active' : '' ?>">
        <i class="fas fa-file-signature"></i><span>Rename Document</span>
      </a>
      <?php endif; ?>
      <?php if (in_array((int)getUserRoleId(), [1, 3])): ?>
      <a href="<?= $pagesBase ?>validate.php"
         class="hnav-link <?= ($currentPage === 'validate') ? 'active' : '' ?>">
        <i class="fas fa-check-circle"></i><span>Verify</span>
        <?php if ($myPending > 0): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem;"><?= $myPending ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
      <a href="<?= $base ?>logout.php" class="hnav-link hnav-logout"
         onclick="return confirm('Are you sure you want to logout?')">
        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
      </a>
    </div>
  </nav>

  <!-- ── Scoped keyboard focus cycle for the Dashboard nav bar ───────────
       My Dashboard → Scan → Rename Document → Verify → Logout → My
       Dashboard (and reverse with Shift+Tab). A real DOM-based Tab trap,
       not a tabindex trick: it reads the actual <a class="hnav-link">
       elements inside #hnav at runtime, so it keeps working correctly
       even when a role doesn't get the Scan link (it's rendered
       conditionally above) — the first/last elements are whatever's
       actually in the DOM. Only the two cycle boundaries call
       preventDefault(); normal Tab movement between the nav items in
       between is left completely alone. Scoped to #hnav only — nothing
       else on the page is affected, and mouse/click navigation is
       untouched. -->
  <script>
    (function () {
      const hnavLinks = Array.from(document.querySelectorAll('#hnav .hnav-link'));
      if (hnavLinks.length < 2) return;
      const firstHnavLink = hnavLinks[0];
      const lastHnavLink  = hnavLinks[hnavLinks.length - 1];

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        if (!e.shiftKey && document.activeElement === lastHnavLink) {
          e.preventDefault();
          firstHnavLink.focus();
        } else if (e.shiftKey && document.activeElement === firstHnavLink) {
          e.preventDefault();
          lastHnavLink.focus();
        }
      });
    })();
  </script>

  <!-- ── Main Wrapper ──────────────────────────────────────── -->
  <main class="main-content" id="mainContent">
    <div class="content-area">
      <!-- Toast Container -->
      <div id="toast-container"></div>