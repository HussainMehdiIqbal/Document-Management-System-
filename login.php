<?php
require_once 'includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
  header('Location: dashboard.php');
  exit();
}

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'blocked') {
  $error = 'Your account is suspended or inactive. Contact administrator.';
}
if (isset($_GET['error']) && $_GET['error'] === 'timeout') {
  $error = 'Your session has expired due to inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = clean($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if (empty($username) || empty($password)) {
    $error = 'Both username and password are required.';
  } else {
    $stmt = $conn->prepare(
      "SELECT u.user_id, u.username, u.password_hash, u.full_name,
                    u.role_id, u.avatar_color, u.status, u.assigned_branch, r.role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.role_id
             WHERE u.username = ? LIMIT 1"
    );
    if ($stmt === false) {
      $error = 'Database error: Unable to prepare user query. ' . esc($conn->error);
    } else {
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_logged_in']     = true;
        $_SESSION['user_id']             = $user['user_id'];
        $_SESSION['user_username']       = $user['username'];
        $_SESSION['user_name']           = $user['full_name'];
        $_SESSION['user_initials']       = makeInitials($user['full_name']);
        $_SESSION['user_role_id']        = $user['role_id'];
        $_SESSION['user_role_name']      = $user['role_name'];
        $_SESSION['user_assigned_branch'] = $user['assigned_branch'] ?? null;
        $_SESSION['user_avatar_color']   = $user['avatar_color'] ?? '#1e4db7';
        $_SESSION['last_activity']       = time(); // Track inactivity timeout from login
        session_regenerate_id(true);

        // Update last_login_at
        $conn->query("UPDATE users SET last_login_at = NOW() WHERE user_id = {$user['user_id']}");
        logActivity($conn, $user['user_id'], 'login', $user['username'] . ' logged in');

        // Role-based redirect: Admin → admin dashboard, others → user dashboard
        if ((int) $user['role_id'] === 1) {
          header('Location: dashboard.php');
        } else {
          header('Location: user_dashboard.php');
        }
        exit();
      } else {
        if ($user && $user['status'] !== 'active') {
          $error = 'Your account is ' . ucfirst($user['status'] ?? 'inactive') . '. Contact administrator.';
        } else {
          $error = 'Invalid Username or Password.';
        }
        sleep(1);
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — DHA Document Management System</title>
  <meta name="description" content="Secure login for DHA Document Management System — Defence Housing Authority">
  <!-- Self-hosted vendor assets (see assets/vendor) — this app must work with no internet access -->
  <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <link href="assets/vendor/fonts/inter/inter.css" rel="stylesheet">
  <style>
    :root {
      --dha-primary: #0056b3;
      --dha-primary-hover: #004494;
      --dha-text-dark: #2d3748;
      --dha-text-muted: #718096;
      --dha-bg: #f8fafc;
      --dha-card-bg: #ffffff;
      --dha-border: #e2e8f0;
      --dha-border-focus: #3b82f6;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background-image: linear-gradient(rgba(15, 31, 61, 0.45), rgba(15, 31, 61, 0.45)), url('assets/images/login_bg.jpg');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      background-attachment: fixed;
      color: var(--dha-text-dark);
      padding: 20px;
      -webkit-font-smoothing: antialiased;
    }

    .login-container {
      width: 100%;
      max-width: 440px;
    }

    .login-card {
      background-color: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 16px;
      padding: 48px 40px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
      text-align: center;
    }

    .logo-container {
      margin-bottom: 24px;
    }

    .logo-img {
      width: 120px;
      height: 120px;
      object-fit: contain;
    }

    .login-title {
      font-size: 28px;
      font-weight: 700;
      color: var(--dha-text-dark);
      margin-bottom: 32px;
      letter-spacing: -0.5px;
    }

    .form-group {
      margin-bottom: 20px;
      position: relative;
      text-align: left;
    }

    .dms-input {
      width: 100%;
      height: 52px;
      padding: 12px 16px;
      font-size: 15px;
      font-family: 'Inter', sans-serif;
      color: var(--dha-text-dark);
      background-color: #ffffff;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      outline: none;
      transition: all 0.2s ease;
    }

    .dms-input::placeholder {
      color: #9ca3af;
    }

    .dms-input:focus {
      border-color: var(--dha-border-focus);
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }

    /* Password toggle button */
    .password-wrapper {
      position: relative;
    }

    .password-wrapper .dms-input {
      padding-right: 48px;
    }

    .toggle-pass {
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      font-size: 18px;
      padding: 4px;
      transition: color 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .toggle-pass:hover {
      color: #4b5563;
    }

    /* Primary Login Button */
    .btn-login {
      width: 100%;
      height: 52px;
      background-color: var(--dha-primary);
      border: none;
      border-radius: 8px;
      color: #ffffff;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background-color 0.2s ease, transform 0.1s ease;
      margin-top: 24px;
    }

    .btn-login:hover {
      background-color: var(--dha-primary-hover);
    }

    .btn-login:active {
      transform: scale(0.98);
    }

    .btn-login:disabled {
      opacity: 0.7;
      cursor: not-allowed;
      transform: none;
    }

    /* Error Alert styling */
    .alert-dms {
      background-color: #fee2e2;
      border: 1px solid #fca5a5;
      border-radius: 8px;
      padding: 12px 16px;
      color: #991b1b;
      font-size: 14px;
      text-align: left;
      margin-bottom: 24px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }

    .alert-dms i {
      margin-top: 3px;
      flex-shrink: 0;
    }

    @media (max-width: 480px) {
      .login-card {
        padding: 32px 24px;
      }
    }
  </style>
</head>

<body>

  <div class="login-container">
    <div class="login-card">
      <div class="logo-container">
        <img src="assets/images/images.jpg" alt="DHA Logo" class="logo-img">
      </div>

      <h1 class="login-title">DMS Portal</h1>

      <?php if ($error): ?>
        <div class="alert-dms" role="alert">
          <i class="fas fa-exclamation-circle"></i>
          <span><?= esc($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="" novalidate id="loginForm">
        <!-- Username -->
        <div class="form-group">
          <input type="text" class="dms-input" id="username" name="username" placeholder="Username"
            value="<?= esc($_POST['username'] ?? '') ?>" autocomplete="username" required>
        </div>

        <!-- Password -->
        <div class="form-group password-wrapper">
          <input type="password" class="dms-input" id="password" name="password" placeholder="Password"
            autocomplete="current-password" required>
          <button type="button" class="toggle-pass" id="togglePass" title="Show/Hide Password">
            <i class="fas fa-eye" id="eyeIcon"></i>
          </button>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
          Log In
        </button>
      </form>
    </div>
  </div>

  <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script>
    // Toggle password visibility
    document.getElementById('togglePass').addEventListener('click', function () {
      const pwd = document.getElementById('password');
      const eye = document.getElementById('eyeIcon');
      if (pwd.type === 'password') {
        pwd.type = 'text';
        eye.classList.replace('fa-eye', 'fa-eye-slash');
      } else {
        pwd.type = 'password';
        eye.classList.replace('fa-eye-slash', 'fa-eye');
      }
    });

    // Button loading state on submit
    document.getElementById('loginForm').addEventListener('submit', function () {
      const btn = document.getElementById('loginBtn');
      btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:16px;height:16px;border-width:2px;"></span> Authenticating…';
      btn.disabled = true;
    });

    // Input focus: auto-trim username
    document.getElementById('username').addEventListener('blur', function () {
      this.value = this.value.trim();
    });
  </script>
</body>

</html>