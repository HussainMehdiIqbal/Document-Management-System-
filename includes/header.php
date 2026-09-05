<?php
/* ── includes/header.php ──────────────────────────────────────
   Top navigation bar — include after body on every page
   Updated for dms_database schema | v3.0 Professional
   ─────────────────────────────────────────────────────────── */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$userName = getUserName();
$userInitials = getUserInitials();
$userAvatarColor = getUserAvatarColor();
$userRoleName = getUserRoleName();
$isInPages = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false);
$base = $isInPages ? '../' : '';
$pagesBase = $isInPages ? '' : 'pages/';

// Notification counts — new schema
$newUsersCount = $conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetch_row()[0];
$notifTotal = $newUsersCount;

// Total docs for sidebar badge
$totalDocs = $conn->query("SELECT COUNT(*) FROM documents")->fetch_row()[0];

// Page icon map
$pageIcons = [
  'dashboard' => 'fa-th-large',
  'folders' => 'fa-folder-open',
  'Admin' => 'fa-users',
  'scan' => 'fa-scanner',
  'rename' => 'fa-file-signature',
  'validate' => 'fa-check-circle',
  'reports' => 'fa-chart-bar',
];
$pageIcon = $pageIcons[$currentPage] ?? 'fa-circle';
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
  <title><?= esc($pageTitle ?? 'Dashboard') ?> — DHA DMS</title>
  <meta name="description" content="DHA Document Management System — <?= esc($pageTitle ?? 'Dashboard') ?>">
  <!-- Bootstrap 5 (self-hosted — see assets/vendor; this app must work with no internet access) -->
  <link href="<?= $base ?>assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 6 (self-hosted) -->
  <link href="<?= $base ?>assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <!-- Inter font (self-hosted) -->
  <link href="<?= $base ?>assets/vendor/fonts/inter/inter.css" rel="stylesheet">
  <!-- Chart.js (self-hosted) -->
  <script src="<?= $base ?>assets/vendor/chartjs/chart.umd.min.js"></script>
  <!-- Custom CSS -->
  <link href="<?= $base ?>assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
</head>

<body>

  <!-- ── Topbar ──────────────────────────────────────────────── -->
  <header class="topbar" id="topbar" role="banner">

    <!-- Logo / Brand -->
    <a href="<?= $base ?>dashboard.php" class="topbar-logo-link" aria-label="DHA DMS Home">
      <img src="<?= $base ?>assets/images/images.jpg" alt="DHA Logo" class="topbar-logo-img">
      <div class="topbar-logo-text">
        <span>DHA Doc Management</span>
        <small>v2.0 — <?= esc($userRoleName) ?></small>
      </div>
    </a>

    <!-- Desktop title -->
    <div class="topbar-brand">
      <span class="accent">DHA</span> Document Management System
    </div>

    <!-- Theme Toggle -->
    <div class="topbar-action" id="themeToggle" title="Toggle Dark/Light Mode" aria-label="Toggle Theme"
      style="cursor:pointer;">
      <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <!-- User Dropdown -->
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
              <span class="badge-<?= strtolower(esc($userRoleName)) ?>" style="font-size:.65rem;padding:2px 8px;">
                <?= esc($userRoleName) ?>
              </span>
            </div>
          </div>
        </li>
        <li>
          <hr class="dropdown-divider">
        </li>
        <li>
          <a class="dropdown-item text-danger" href="<?= $base ?>logout.php" onclick="return confirm('Logout now?')">
            <i class="fas fa-sign-out-alt"></i>
            Logout
          </a>
        </li>
      </ul>
    </div>
  </header>

  <!-- ── Horizontal Nav Bar ──────────────────────────────────── -->
  <nav class="hnav" id="hnav" role="navigation" aria-label="Main Navigation">
    <div class="hnav-inner">
      <a href="<?= $base ?>dashboard.php" class="hnav-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
        <i class="fas fa-th-large"></i><span>Dashboard</span>
      </a>
      <a href="<?= $pagesBase ?>users.php" class="hnav-link <?= ($currentPage === 'Admin') ? 'active' : '' ?>">
        <i class="fas fa-users"></i><span>Users</span>
      </a>
      <a href="<?= $pagesBase ?>reports.php" class="hnav-link <?= ($currentPage === 'reports') ? 'active' : '' ?>">
        <i class="fas fa-chart-bar"></i><span>Reports</span>
      </a>
      <a href="<?= $base ?>logout.php" class="hnav-link hnav-logout"
         onclick="return confirm('Are you sure you want to logout?')">
        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
      </a>
    </div>
  </nav>

  <!-- ── Main Wrapper ─────────────────────────────────────────── -->
  <main class="main-content" id="mainContent">
    <div class="content-area">

      <!-- Toast Container -->
      <div id="toast-container"></div>