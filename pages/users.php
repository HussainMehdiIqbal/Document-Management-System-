<?php
// ============================================================
// pages/users.php — User Management (CRUD for new schema)
// ============================================================
require_once '../includes/auth.php';
requireLogin();

// Only Admins can manage users
if ((int)getUserRoleId() !== 1) {
  header('Location: ../user_dashboard.php');
  exit();
}

$pageTitle = 'User Management';
$message = '';
$msgType = 'success';
$editUser = null;
$currentUserId = getUserId();
$MAIN_ADMIN_ID = 1; // The main admin user_id that cannot have role/status changed

// ── Avatar color palette (auto-assigned) ─────────────────────
$avatarPalette = [
  '#0f1f3d','#1e4db7','#3b82f6','#0ea5e9',
  '#0d9488','#059669','#7c3aed','#9333ea',
  '#c026d3','#db2777','#e11d48','#dc2626',
  '#ea580c','#d97706','#ca8a04','#4f46e5',
];
function autoAvatarColor($userId, $palette) {
    return $palette[$userId % count($palette)];
}

// ── Handle POST Actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = clean($_POST['action'] ?? '');

  if ($action === 'create' || $action === 'update') {
    $firstName   = trim(clean($_POST['first_name'] ?? ''));
    $lastName    = trim(clean($_POST['last_name']  ?? ''));
    $fullName    = trim("$firstName $lastName");
    $username    = clean($_POST['username'] ?? '');
    $roleId      = (int)($_POST['role_id'] ?? 2);
    $status      = clean($_POST['status'] ?? 'active');
    $employeeId  = trim(clean($_POST['employee_id'] ?? ''));
    // Multi-select: 1 or more branches, in any combination. Stored as a
    // comma-separated string in the same assigned_branch column rather
    // than a new table, to keep this a minimal, additive change.
    $postedBranches = $_POST['assigned_branch'] ?? [];
    if (!is_array($postedBranches)) {
        $postedBranches = $postedBranches !== '' ? [$postedBranches] : [];
    }
    $validBranches = ['Transfer', 'Building_Control', 'Land_Acquisition'];
    $postedBranches = array_values(array_intersect($validBranches, $postedBranches));
    $assignedBranch = implode(',', $postedBranches);

    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if ($roleId === 1) {
      // There is exactly one Admin — the pre-existing Super Administrator.
      // This form never offers Admin as an option, but reject a tampered
      // submission just in case.
      $message = 'Admin accounts cannot be created or assigned here.';
      $msgType = 'danger';
    } elseif (empty($firstName) || empty($lastName)) {
      $message = 'First Name and Last Name are required.';
      $msgType = 'danger';
    } elseif (empty($username)) {
      $message = 'Username is required.';
      $msgType = 'danger';
    } elseif (empty($postedBranches)) {
      $message = 'At least one Branch must be selected.';
      $msgType = 'danger';
    } elseif ($action === 'create' && empty($password)) {
      $message = 'Password is required for new users.';
      $msgType = 'danger';
    } elseif (!empty($password) && $password !== $confirm) {
      $message = 'Passwords do not match.';
      $msgType = 'danger';
    } elseif (!empty($password) && !preg_match('/[A-Za-z]/', $password)) {
      $message = 'Password must contain at least one letter.';
      $msgType = 'danger';
    } elseif (!empty($password) && !preg_match('/[0-9]/', $password)) {
      $message = 'Password must contain at least one number.';
      $msgType = 'danger';
    } elseif (!empty($password) && strlen($password) <= 4) {
      $message = 'Password must be more than 4 characters.';
      $msgType = 'danger';
    } elseif (!empty($password) && strcasecmp($password, $username) === 0) {
      $message = 'Password cannot be the same as the username.';
      $msgType = 'danger';
    } else {
      // Check username uniqueness
      $dupSql = "SELECT user_id FROM users WHERE username = ? " . ($action === 'update' ? "AND user_id != " . (int)$_POST['user_id'] : "");
      $dupStmt = $conn->prepare($dupSql);
      $dupStmt->bind_param("s", $username);
      $dupStmt->execute();
      $isDuplicate = $dupStmt->get_result()->num_rows > 0;
      $dupStmt->close();

      // Check employee_id uniqueness
      $empDupSql = "SELECT user_id FROM users WHERE employee_id = ? " . ($action === 'update' ? "AND user_id != " . (int)$_POST['user_id'] : "");
      $empStmt = $conn->prepare($empDupSql);
      if (!$empStmt) {
          die('Prepare failed for empDupSql: ' . $conn->error . ' | SQL: ' . $empDupSql);
      }
      $empStmt->bind_param("s", $employeeId);
      $empStmt->execute();
      $isEmpDuplicate = $empStmt->get_result()->num_rows > 0;
      $empStmt->close();

      if ($isDuplicate) {
        $message = 'Username already exists.'; $msgType = 'danger';
      } elseif ($isEmpDuplicate) {
        $message = 'Employee ID already exists.'; $msgType = 'danger';
      } else {
        if ($action === 'create') {
          $uid4color  = (int)$conn->query("SELECT COALESCE(MAX(user_id),0)+1 FROM users")->fetch_row()[0];
          $avatarColor = autoAvatarColor($uid4color, $avatarPalette);
          $hash = password_hash($password, PASSWORD_BCRYPT);
          $stmt = $conn->prepare(
            "INSERT INTO users (username, password_hash, first_name, last_name, full_name, employee_id, role_id, assigned_branch, avatar_color, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $stmt->bind_param("ssssssisssi", $username, $hash, $firstName, $lastName, $fullName, $employeeId, $roleId, $assignedBranch, $avatarColor, $status, $currentUserId);
          if ($stmt->execute()) {
            $newId = $conn->insert_id;
            // Re-assign color based on real new id
            $realColor = autoAvatarColor($newId, $avatarPalette);
            $conn->query("UPDATE users SET avatar_color='$realColor' WHERE user_id=$newId");
            $message = "User '{$fullName}' created successfully! Employee ID: {$employeeId}";
            logActivity($conn, $currentUserId, 'create', "Created user $username - $fullName", null, $newId);
          } else {
            $message = 'Error creating user: ' . $conn->error;
            $msgType = 'danger';
          }
          $stmt->close();
        } else {
          $uid = (int)$_POST['user_id'];

          // Block changing role/status of main admin
          if ($uid === $MAIN_ADMIN_ID) {
            $roleId = 1;       // force admin role
            $status = 'active'; // force active status
          }

          // Employee ID is intentionally excluded from both UPDATE statements
          // below — it's locked after creation and never changes on edit,
          // regardless of what's posted.
          if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
              "UPDATE users SET username=?, password_hash=?, first_name=?, last_name=?, full_name=?, role_id=?, assigned_branch=?, status=? WHERE user_id=?"
            );
            $stmt->bind_param("sssssissi", $username, $hash, $firstName, $lastName, $fullName, $roleId, $assignedBranch, $status, $uid);
          } else {
            $stmt = $conn->prepare(
              "UPDATE users SET username=?, first_name=?, last_name=?, full_name=?, role_id=?, assigned_branch=?, status=? WHERE user_id=?"
            );
            $stmt->bind_param("ssssisss", $username, $firstName, $lastName, $fullName, $roleId, $assignedBranch, $status, $uid);
          }
          if (isset($_GET['debug_branch'])) {
              echo '<pre style="background:#111;color:#0f0;padding:12px;">';
              echo "About to execute UPDATE.\n";
              echo "assignedBranch right before execute(): "; var_dump($assignedBranch);
              echo '</pre>';
          }
          if ($stmt->execute()) {
            $message = "User updated successfully!";
            logActivity($conn, $currentUserId, 'update', "Updated user $username", null, $uid);
          } else {
            $message = 'Error updating user: ' . $conn->error;
            $msgType = 'danger';
          }
          if (isset($_GET['debug_branch'])) {
              echo '<pre style="background:#111;color:#0f0;padding:12px;">';
              echo "stmt->error after execute(): "; var_dump($stmt->error);
              echo "Row after save: ";
              $chk = $conn->query("SELECT assigned_branch FROM users WHERE user_id = $uid");
              var_dump($chk->fetch_assoc());
              echo '</pre>';
          }
          $stmt->close();
        }
      }
    }
  }

  // Deleting users is intentionally not supported — Admins can only suspend
  // an account (via Status in the Edit form), never remove it outright.
}

// ── Load edit user ────────────────────────────────────────────
if (isset($_GET['edit'])) {
  $eid = (int) $_GET['edit'];
  $stmt = $conn->prepare("SELECT * FROM users WHERE user_id=? LIMIT 1");
  $stmt->bind_param("i", $eid);
  $stmt->execute();
  $editUser = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// ── Fetch Roles for dropdown ──────────────────────────────────
$roles = $conn->query("SELECT * FROM roles ORDER BY role_id");

// ── Fetch Users (with optional search) ───────────────────────
$search = clean($_GET['search'] ?? '');
$sql = "SELECT u.*, r.role_name, creator.username AS creator_username
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN users creator ON u.created_by = creator.user_id";
if ($search) {
  $s = $conn->real_escape_string($search);
  $sql .= " WHERE u.username LIKE '%$s%' OR u.full_name LIKE '%$s%' OR r.role_name LIKE '%$s%'";
}
$sql .= " ORDER BY u.created_at DESC";
$users = $conn->query($sql);

require_once '../includes/header.php';
?>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between">
  <div>
    <h1><i class="fas fa-users text-dha me-2"></i>User Management</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="../dashboard.php" class="text-dha">Dashboard</a></li>
        <li class="breadcrumb-item active">Users</li>
      </ol>
    </nav>
  </div>
  <button class="btn btn-dha" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetForm()">
    <i class="fas fa-user-plus me-2"></i>Add New User
  </button>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?> alert-dismissible fade show alert-auto-dismiss" role="alert">
    <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
    <?= esc($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- ── Search Bar ────────────────────────────────────────────── -->
<div class="dha-card mb-4">
  <div class="dha-card-body py-3">
    <form method="GET" class="d-flex gap-3 align-items-center flex-wrap">
      <div class="flex-grow-1 global-search" style="max-width:400px;">
        <i class="fas fa-search search-icon"></i>
        <input type="text" name="search" class="form-control" placeholder="Search by username, full name, role..."
          value="<?= esc($search) ?>" style="padding-left:32px;">
      </div>
      <button type="submit" class="btn btn-dha"><i class="fas fa-search me-1"></i>Search</button>
      <?php if ($search): ?>
        <a href="users.php" class="btn btn-outline-secondary">Clear</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ── User Table ─────────────────────────────────────────────── -->
<div class="dha-card">
  <div class="dha-card-header">
    <h5><i class="fas fa-table text-dha me-2"></i>All Users
      <?php if ($search): ?><small class="text-secondary ms-2">— results for
          "<?= esc($search) ?>"</small><?php endif; ?>
    </h5>
    <span class="text-secondary small"><?= $users->num_rows ?> user(s) found</span>
  </div>
  <div class="dha-card-body p-0">
    <div class="table-responsive">
      <table class="dha-table" id="usersTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Name / Username</th>
            <th>Employee ID</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created By</th>
            <th>Created At</th>
            <th>Last Login</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          while ($u = $users->fetch_assoc()):
            $avatarCol = $u['avatar_color'] ?? '#1e4db7';
            $isMainAdmin = ((int)$u['user_id'] === $MAIN_ADMIN_ID);
            $roleBadge = match ((int)$u['role_id']) {
              1 => 'badge-admin',
              2 => 'badge-scanner',
              3 => 'badge-validator',
              4 => 'badge-renamer',
              default => 'badge-inactive'
            };
            $statusBadge = match ($u['status']) {
              'active' => 'badge-approved',
              'suspended' => 'badge-changed',
              default => 'badge-pending'
            };
            $initials = makeInitials($u['full_name']);
            ?>
            <tr<?= $isMainAdmin ? ' style="background:rgba(30,77,183,.07);"' : '' ?>>
              <td><?= $i++ ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div
                    style="width:34px;height:34px;border-radius:8px;background:<?= esc($avatarCol) ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0;">
                    <?= esc($initials) ?>
                  </div>
                  <div>
                    <strong><?= esc($u['full_name']) ?></strong>
                    <?php if ($isMainAdmin): ?><span class="badge ms-1" style="background:#dc2626;font-size:.6rem;">MAIN ADMIN</span><?php endif; ?><br>
                    <small class="text-secondary">@<?= esc($u['username']) ?></small>
                  </div>
                </div>
              </td>
              <td><code><?= esc($u['employee_id'] ?? '—') ?></code></td>
              <td>
                <span class="<?= $roleBadge ?>"><?= esc($u['role_name'] ?? 'No Role') ?></span>
                <?php if ($isMainAdmin): ?><i class="fas fa-lock text-secondary ms-1" style="font-size:.7rem;" title="Protected"></i><?php endif; ?>
              </td>
              <td><span class="<?= $statusBadge ?>"><?= ucfirst($u['status']) ?></span></td>
              <td><small><?= esc($u['creator_username'] ?? 'System') ?></small></td>
              <td><small><?= date('d M Y', strtotime($u['created_at'])) ?></small></td>
              <td><small><?= $u['last_login_at'] ? date('d M, H:i', strtotime($u['last_login_at'])) : 'Never' ?></small>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-dha py-1"
                    onclick="editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)" title="Edit">
                    <i class="fas fa-edit"></i>
                  </button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if ($users->num_rows === 0): ?>
            <tr>
              <td colspan="9" class="text-center text-secondary py-4">
                <i class="fas fa-users fa-2x mb-2 d-block opacity-50"></i>No users found.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── User Create/Edit Modal ────────────────────────────────── -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalLabel"><i class="fas fa-user-cog me-2"></i>User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="userForm" novalidate>
        <div class="modal-body">
          <input type="hidden" name="action" id="formAction" value="create">
          <input type="hidden" name="user_id" id="userId" value="">
          <!-- Hidden username always submitted in POST -->
          <input type="hidden" name="username" id="username_hidden" value="">

          <!-- Main Admin protection notice (shown only when editing main admin) -->
          <div id="mainAdminNotice" class="alert alert-warning py-2 px-3 mb-3 d-none">
            <i class="fas fa-shield-alt me-2"></i>
            <strong>Protected Account:</strong> Role and status cannot be changed for the main administrator.
          </div>

          <div class="row g-3">
            <!-- Username -->
            <div class="col-md-6">
              <label class="dha-label">Username *</label>
              <input type="text" class="dha-form-control" id="username_field"
                placeholder="e.g. EMP1002muhammad" required autocomplete="username"
                readonly style="opacity:0.65;cursor:not-allowed;">
              <small class="text-secondary" id="usernameHint">Auto-generated from Employee ID and First Name — cannot be typed directly.</small>
            </div>

            <!-- Employee ID -->
            <div class="col-md-6">
              <label class="dha-label">Employee ID *</label>
              <input type="text" class="dha-form-control" name="employee_id" id="employee_id_field"
                placeholder="e.g. 232" required oninput="updateNamePreview()">
              <small class="text-secondary d-none" id="empIdHint">Employee ID cannot be changed after creation.</small>
            </div>

            <!-- First Name -->
            <div class="col-md-6">
              <label class="dha-label">First Name *</label>
              <input type="text" class="dha-form-control" name="first_name" id="first_name_field"
                placeholder="e.g. Muhammad" required oninput="updateNamePreview()">
            </div>

            <!-- Last Name -->
            <div class="col-md-6">
              <label class="dha-label">Last Name *</label>
              <input type="text" class="dha-form-control" name="last_name" id="last_name_field"
                placeholder="e.g. Ali" required oninput="updateNamePreview()">
            </div>

            <!-- Full Name preview (read-only) -->
            <div class="col-12">
              <label class="dha-label">Employee Display (First Name & Last Name)</label>
              <div class="d-flex align-items-center gap-3">
                <div id="avatarPreview"
                  style="width:42px;height:42px;border-radius:10px;background:#1e4db7;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;flex-shrink:0;transition:background .3s;">
                  ?
                </div>
                <input type="text" class="dha-form-control" id="full_name_preview" readonly
                  placeholder="First Name Last Name" style="background:rgba(255,255,255,.04);cursor:default;">
              </div>
            </div>

            <!-- Role -->
            <div class="col-md-6">
              <label class="dha-label">Role *</label>
              <select class="dha-form-control" name="role_id" id="role_field">
                <?php
                $roles->data_seek(0);
                while ($r = $roles->fetch_assoc()):
                  if (!in_array((int)$r['role_id'], [2, 3, 4])) continue;
                  ?>
                  <option value="<?= $r['role_id'] ?>"><?= esc($r['role_name']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>

            <!-- Branch -->
            <div class="col-md-6">
              <label class="dha-label">Branch(es) *</label>
              <div id="branch_field" class="d-flex flex-column gap-1 dha-form-control" style="height:auto;padding:10px 12px;">
                <div class="form-check">
                  <input class="form-check-input branch-checkbox" type="checkbox" value="Transfer" id="branch_transfer" name="assigned_branch[]">
                  <label class="form-check-label" for="branch_transfer">Transfer</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input branch-checkbox" type="checkbox" value="Building_Control" id="branch_bc" name="assigned_branch[]">
                  <label class="form-check-label" for="branch_bc">Building Control</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input branch-checkbox" type="checkbox" value="Land_Acquisition" id="branch_land" name="assigned_branch[]">
                  <label class="form-check-label" for="branch_land">Land Acquisition</label>
                </div>
              </div>
              <small class="text-secondary">Select one or more. Locks Rename/Verify to only these branches — no switching to an unassigned one.</small>
            </div>

            <!-- Status -->
            <div class="col-md-6">
              <label class="dha-label">Status</label>
              <select class="dha-form-control" name="status" id="status_field">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>

            <!-- Avatar Color (auto-generate, read-only display) -->
            <div class="col-md-6">
              <label class="dha-label">Avatar Color <small class="text-secondary">(auto-assigned)</small></label>
              <div class="d-flex align-items-center gap-2">
                <div id="colorSwatchDisplay"
                  style="width:36px;height:36px;border-radius:8px;background:#1e4db7;flex-shrink:0;border:2px solid rgba(255,255,255,.2);"></div>
                <input type="text" class="dha-form-control" id="colorHexDisplay" readonly
                  value="#1e4db7" style="background:rgba(255,255,255,.04);cursor:default;font-family:monospace;">
              </div>
            </div>

            <!-- Password -->
            <div class="col-md-6">
              <label class="dha-label">Password <span id="pwdRequired">*</span></label>
              <input type="password" class="dha-form-control" name="password" id="pwd_field"
                placeholder="Enter password" autocomplete="new-password">
              <small class="text-secondary" id="pwdHint"></small>
            </div>
            <div class="col-md-6">
              <label class="dha-label">Confirm Password</label>
              <input type="password" class="dha-form-control" name="confirm_password" placeholder="Repeat password"
                autocomplete="new-password">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
            <i class="fas fa-undo me-1"></i>Reset
          </button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dha" id="saveUserBtn">
            <i class="fas fa-save me-1"></i>Save User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  const MAIN_ADMIN_ID = <?= $MAIN_ADMIN_ID ?>;
  const avatarPalette = <?= json_encode($avatarPalette) ?>;
  function autoAvatarColor(userId) {
    return avatarPalette[userId % avatarPalette.length];
  }

  function makeInitialsJS(firstName, lastName) {
    const f = (firstName || '').trim();
    const l = (lastName  || '').trim();
    if (f && l) return (f[0] + l[0]).toUpperCase();
    if (f)      return f.substring(0, 2).toUpperCase();
    return '?';
  }

  function updateNamePreview() {
    const fn = document.getElementById('first_name_field').value.trim();
    const ln = document.getElementById('last_name_field').value.trim();
    const empId = document.getElementById('employee_id_field').value.trim();
    const full = [fn, ln].filter(Boolean).join(' ');

    // Employee Display: First Name & Last Name
    document.getElementById('full_name_preview').value = full;
    const initials = makeInitialsJS(fn, ln);
    document.getElementById('avatarPreview').textContent = initials;

    // Username is always auto-generated from Employee ID + First Name —
    // now also live on edit, since Employee ID is locked there and the
    // only thing that can change it going forward is the name itself.
    {
      const generatedUser = (empId + fn).toLowerCase().replace(/[^a-z0-9]/g, '');
      const usernameField = document.getElementById('username_field');
      if (usernameField) {
        usernameField.value = generatedUser;
        document.getElementById('username_hidden').value = generatedUser;
      }
    }
  }

  function setAvatarColorDisplay(color) {
    document.getElementById('colorSwatchDisplay').style.background = color;
    document.getElementById('colorHexDisplay').value = color;
    document.getElementById('avatarPreview').style.background = color;
  }



  <?php if ($editUser): ?>
    document.addEventListener('DOMContentLoaded', () => editUser(<?= json_encode($editUser) ?>));
  <?php endif; ?>

  function checkPasswordConstraints(password, username) {
    const errors = [];
    if (!/[A-Za-z]/.test(password)) errors.push('at least one letter');
    if (!/[0-9]/.test(password))    errors.push('at least one number');
    if (password.length <= 4)       errors.push('more than 4 characters');
    if (username && password.toLowerCase() === username.toLowerCase()) {
      errors.push('cannot be the same as the username');
    }
    return errors;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('userForm');
    if (!form) return;
    form.addEventListener('submit', (e) => {
      const pwd      = document.getElementById('pwd_field').value;
      const username = document.getElementById('username_hidden').value;
      const isCreate = document.getElementById('formAction').value === 'create';

      // Password is required on create; optional on update (only validated
      // if the admin is actually setting a new one).
      if (isCreate || pwd.length > 0) {
        const errors = checkPasswordConstraints(pwd, username);
        if (errors.length > 0) {
          e.preventDefault();
          const hint = document.getElementById('pwdHint');
          if (hint) {
            hint.textContent = 'Password must have ' + errors.join(', ') + '.';
            hint.style.color = '#dc2626';
          }
          document.getElementById('pwd_field').focus();
        }
      }
    });
  });

  function resetForm() {
    usernameEdited = false;
    document.getElementById('formAction').value = 'create';
    document.getElementById('userId').value = '';
    document.getElementById('username_hidden').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('userModalLabel').innerHTML = '<i class="fas fa-user-plus me-2"></i>Create New User';
    document.getElementById('saveUserBtn').innerHTML = '<i class="fas fa-save me-1"></i>Save User';
    document.getElementById('pwdRequired').textContent = '*';
    document.getElementById('pwdHint').textContent = '';
    document.getElementById('full_name_preview').value = '';
    document.getElementById('avatarPreview').textContent = '?';
    document.getElementById('mainAdminNotice').classList.add('d-none');
    // Username field is always readonly now — nothing to re-enable.
    const empIdFieldReset = document.getElementById('employee_id_field');
    empIdFieldReset.removeAttribute('readonly');
    empIdFieldReset.style.opacity = '';
    empIdFieldReset.style.cursor = '';
    document.getElementById('empIdHint').classList.add('d-none');
    // Re-enable role and status
    document.getElementById('role_field').removeAttribute('disabled');
    document.getElementById('status_field').removeAttribute('disabled');
    // Set default avatar
    setAvatarColorDisplay('#1e4db7');
  }

  function editUser(u) {
    const isMainAdmin = (parseInt(u.user_id) === MAIN_ADMIN_ID);
    document.getElementById('formAction').value = 'update';
    document.getElementById('userId').value = u.user_id;
    document.getElementById('username_hidden').value = u.username;

    // Username display (always readonly now).
    document.getElementById('username_field').value = u.username;

    // Employee ID is locked after creation.
    const empIdField = document.getElementById('employee_id_field');
    empIdField.setAttribute('readonly', true);
    empIdField.style.opacity = '0.65';
    empIdField.style.cursor = 'not-allowed';
    document.getElementById('empIdHint').classList.remove('d-none');

    // Branch
    // Branch(es) — comma-separated string, check the matching boxes.
    const assignedList = (u.assigned_branch || '').split(',').map(s => s.trim()).filter(Boolean);
    document.querySelectorAll('.branch-checkbox').forEach(cb => {
      cb.checked = assignedList.includes(cb.value);
    });

    // Name fields
    document.getElementById('first_name_field').value = u.first_name || '';
    document.getElementById('last_name_field').value  = u.last_name  || '';

    // Employee ID
    document.getElementById('employee_id_field').value = u.employee_id || '';
    updateNamePreview();

    // Role & Status — Role is strictly Admin/Operator (see the server-side
    // filter on $roles above); if this user still has an older role_id
    // (e.g. Validator) from before that restriction, the dropdown simply
    // won't have a matching option and will fall back to its first entry —
    // saving the form will reassign them to that role.
    const roleField = document.getElementById('role_field');
    roleField.value   = u.role_id;
    document.getElementById('status_field').value = u.status;

    // Main Admin protection
    if (isMainAdmin) {
      document.getElementById('role_field').setAttribute('disabled', true);
      document.getElementById('status_field').setAttribute('disabled', true);
      document.getElementById('mainAdminNotice').classList.remove('d-none');
    } else {
      document.getElementById('role_field').removeAttribute('disabled');
      document.getElementById('status_field').removeAttribute('disabled');
      document.getElementById('mainAdminNotice').classList.add('d-none');
    }

    // Avatar color display (read-only, auto-assigned)
    const color = u.avatar_color || autoAvatarColor(parseInt(u.user_id));
    setAvatarColorDisplay(color);

    // Password
    document.getElementById('pwd_field').value = '';
    document.getElementById('pwdRequired').textContent = '';
    document.getElementById('pwdHint').textContent = 'Leave blank to keep existing password';

    document.getElementById('userModalLabel').innerHTML = '<i class="fas fa-user-edit me-2"></i>Edit User — ' + (u.full_name || u.username);
    document.getElementById('saveUserBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update User';
    new bootstrap.Modal(document.getElementById('userModal')).show();
  }

  document.addEventListener('DOMContentLoaded', () => {
    setAvatarColorDisplay('#1e4db7');

    if (typeof initPagination === 'function') {
      initPagination('usersTable', 10);
    } else {
      window.addEventListener('load', () => {
        if (typeof initPagination === 'function') initPagination('usersTable', 10);
      });
    }
    <?php if ($message && $msgType === 'success'): ?>
      showToast('<?= addslashes($message) ?>', 'success');
    <?php endif; ?>
  });
</script>

<?php require_once '../includes/footer.php'; ?>