<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'My Profile';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

$msg = $err = '';
$portalCsrf = portalCsrfToken();
$uid = $_SESSION['user_id'];
$userRow = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portalCsrfValid($_POST['portal_csrf_token'] ?? null)) {
        http_response_code(403);
        $err = 'Session expired. Refresh the page and try again.';
    } else {
    $fullname = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$fullname) {
        $err = 'Full name is required.';
    } elseif ($password && strlen($password) < 6) {
        $err = 'New password must be at least 6 characters.';
    } elseif ($password && $password !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name=?,email=?,password=? WHERE id=?");
            $stmt->bind_param('sssi', $fullname, $email, $hash, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?,email=? WHERE id=?");
            $stmt->bind_param('ssi', $fullname, $email, $uid);
        }

        if ($stmt->execute()) {
            $_SESSION['full_name'] = $fullname;
            $msg = 'Profile updated successfully!';
            $userRow = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
        } else {
            $err = 'Error updating profile. Please try again.';
        }
    }
    }
}

$adminTotal = $conn->query("SELECT COUNT(*) FROM orders WHERE created_by=$uid")->fetch_row()[0];
$adminCompleted = $conn->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetch_row()[0];
$adminActive = $conn->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed','cancelled','archived')")->fetch_row()[0];
$adminUrgent = $conn->query("SELECT COUNT(*) FROM orders WHERE priority IN ('urgent','high') AND status NOT IN ('completed','cancelled','archived')")->fetch_row()[0];

include '../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/admin-profile.css">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="margin-bottom:22px;">
  <h1 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--ink)">My Profile</h1>
  <p style="color:var(--ink-muted);font-size:.85rem;margin-top:3px">Manage your account information and password</p>
</div>

<div class="profile-grid">

  <div class="profile-card">
    <div class="avatar-block">
      <div class="avatar-circle"><?= strtoupper(substr($userRow['full_name'], 0, 1)) ?></div>
      <div>
        <div class="avatar-name"><?= htmlspecialchars($userRow['full_name']) ?></div>
        <div class="avatar-meta">
          <span class="badge badge-admin">Admin</span>
          <span class="badge badge-active"><?= ucfirst($userRow['status'] ?? 'active') ?></span>
          <span style="font-size:.78rem;color:var(--ink-muted)">@<?= htmlspecialchars($userRow['username']) ?></span>
        </div>
      </div>
    </div>

    <div class="profile-card-body">
      <form method="post"><?= portalCsrfInput() ?>

        <div class="form-section">Personal Information</div>
        <div class="form-group">
          <label>Full Name <span style="color:var(--rose)">*</span></label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($userRow['full_name']) ?>" required placeholder="Your full name">
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Username</label>
            <input type="text" value="<?= htmlspecialchars($userRow['username']) ?>" disabled style="background:var(--surface);cursor:not-allowed;color:var(--ink-muted)">
            <div class="form-hint">Username cannot be changed.</div>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($userRow['email'] ?? '') ?>" placeholder="your@email.com">
          </div>
        </div>

        <div class="form-section">Change Password</div>
        <div class="password-note">
          Leave password fields blank to keep your current password.
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" placeholder="Min. 6 characters" id="newPw">
          </div>
          <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat new password" id="confirmPw">
          </div>
        </div>
        <div id="pw-match-hint" class="pw-match-hint" style="display:none"></div>

        <button type="submit" class="btn btn-primary profile-save-btn">Save Changes</button>
      </form>
    </div>
  </div>

  <div class="profile-side-column">

    <div class="profile-card">
      <div class="profile-card-header">Account Details</div>
      <div class="account-details-wrap">
        <div class="info-row"><span class="info-label">Member Since</span><span class="info-value"><?= date('M j, Y', strtotime($userRow['created_at'])) ?></span></div>
        <div class="info-row"><span class="info-label">Role</span><span class="info-value"><span class="badge badge-admin">Admin</span></span></div>
        <div class="info-row"><span class="info-label">Status</span><span class="info-value"><span class="badge badge-active"><?= ucfirst($userRow['status'] ?? 'active') ?></span></span></div>
        <div class="info-row"><span class="info-label">Last Updated</span><span class="info-value"><?= date('M j, Y', strtotime($userRow['updated_at'])) ?></span></div>
        <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($userRow['email'] ?: 'Not set') ?></span></div>
      </div>
    </div>

    <div class="profile-card">
      <div class="profile-card-header">Admin Statistics</div>
      <div class="stats-panel">
        <div class="stat-tile">
          <div class="stat-tile-value"><?= $adminTotal ?></div>
          <div class="stat-tile-label">Orders Created</div>
        </div>
        <div class="stat-tile">
          <div class="stat-tile-value green"><?= $adminCompleted ?></div>
          <div class="stat-tile-label">Completed</div>
        </div>
        <div class="stat-tile">
          <div class="stat-tile-value rose"><?= $adminActive ?></div>
          <div class="stat-tile-label">Active</div>
        </div>
        <div class="stat-tile">
          <div class="stat-tile-value gold"><?= $adminUrgent ?></div>
          <div class="stat-tile-label">Urgent</div>
        </div>
      </div>
    </div>

    <div class="profile-card">
      <div class="profile-card-header">Quick Links</div>
      <div class="quick-links-wrap">
        <a href="users.php" class="btn btn-secondary quick-link-btn">User Management</a>
        <a href="reports.php" class="btn btn-secondary quick-link-btn">Reports</a>
        <a href="orders.php" class="btn btn-secondary quick-link-btn">All Orders</a>
      </div>
    </div>

  </div>
</div>

<script>
const pw1 = document.getElementById('newPw');
const pw2 = document.getElementById('confirmPw');
const hint = document.getElementById('pw-match-hint');
function checkMatch() {
  if (!pw2.value) {
    hint.style.display = 'none';
    return;
  }
  hint.style.display = 'block';
  if (pw1.value === pw2.value) {
    hint.textContent = 'Passwords match';
    hint.style.color = '#2D8C56';
  } else {
    hint.textContent = 'Passwords do not match';
    hint.style.color = '#DC2626';
  }
}
pw1.addEventListener('input', checkMatch);
pw2.addEventListener('input', checkMatch);
</script>

<?php include '../includes/footer.php'; ?>
