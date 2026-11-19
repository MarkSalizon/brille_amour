<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
session_start();

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php'); exit;
}

$error = $success = '';
$shake = false;
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['full_name'] = trim($_POST['full_name'] ?? '');
    $data['username']  = trim($_POST['username'] ?? '');
    $data['email']     = trim($_POST['email'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm           = $_POST['confirm_password'] ?? '';
    $secret            = $_POST['admin_secret'] ?? '';

    if (!$data['full_name'] || !$data['username'] || !$password || !$confirm) {
        $error = 'All fields marked * are required.';
        $shake = true;
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
        $shake = true;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
        $shake = true;
    } elseif ($secret !== 'BRILLEARN2024') {
        $error = 'Invalid admin registration key. Contact the system owner.';
        $shake = true;
    } elseif (roleLimitReached($conn, 'admin')) {
        $error = roleLimitMessage('admin');
        $shake = true;
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $chk->bind_param('s', $data['username']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'Username already taken. Choose another.';
            $shake = true;
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username,password,full_name,email,role,status) VALUES (?,?,?,?,'admin','active')");
            $stmt->bind_param('ssss', $data['username'], $hash, $data['full_name'], $data['email']);
            if ($stmt->execute()) {
                $success = true;
                $data = [];
            } else {
                $error = 'Error creating account. Please try again.';
                $shake = true;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Sign Up — Brille Amour</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin-signup.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-signup.css') ?>">
<link rel="stylesheet" href="../assets/css/auth-pink-theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth-pink-theme.css') ?>">
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<style>
body.admin-signup-centered {
  display: grid !important;
  place-items: center !important;
  min-height: 100vh !important;
  min-height: 100dvh !important;
  background: #ffffff !important;
  color: #111111 !important;
}

body.admin-signup-centered .bg-scene {
  background:
    radial-gradient(circle at 16% 18%, rgba(200, 80, 124, 0.08), transparent 28%),
    radial-gradient(circle at 86% 78%, rgba(199, 160, 77, 0.1), transparent 30%),
    #ffffff !important;
}

body.admin-signup-centered .bg-grid {
  background-image:
    linear-gradient(rgba(33, 26, 32, 0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(33, 26, 32, 0.035) 1px, transparent 1px) !important;
}

body.admin-signup-centered .bg-orb {
  opacity: 0 !important;
}

body.admin-signup-centered .page {
  position: fixed !important;
  inset: 0 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 100vw !important;
  max-width: none !important;
  min-height: 100vh !important;
  min-height: 100dvh !important;
  margin: 0 !important;
  padding: 20px !important;
  gap: 0 !important;
  grid-template-columns: none !important;
  overflow-y: auto !important;
}

body.admin-signup-centered .card {
  width: min(460px, 100%) !important;
  max-width: 460px !important;
  margin: auto !important;
  padding: 52px 48px !important;
  background: rgba(255, 255, 255, 0.96) !important;
  border-color: rgba(33, 26, 32, 0.1) !important;
  box-shadow: 0 28px 80px rgba(33, 26, 32, 0.18) !important;
}

body.admin-signup-centered .card > form,
body.admin-signup-centered .card > .form-header,
body.admin-signup-centered .card > .key-notice,
body.admin-signup-centered .card > .alert,
body.admin-signup-centered .card > .divider,
body.admin-signup-centered .card > .btn-ghost {
  width: 100% !important;
  max-width: 360px !important;
  margin-left: auto !important;
  margin-right: auto !important;
}

body.admin-signup-centered .brand-mark-center {
  display: block !important;
  width: 76px !important;
  height: 76px !important;
  object-fit: contain !important;
  margin: 0 auto 22px !important;
  padding: 8px !important;
  border: 1px solid rgba(33, 26, 32, 0.12) !important;
  border-radius: 20px !important;
  background: #ffffff !important;
}

body.admin-signup-centered .field-row {
  grid-template-columns: 1fr !important;
  gap: 0 !important;
}

body.admin-signup-centered .form-title,
body.admin-signup-centered .success-title {
  color: #111111 !important;
}

body.admin-signup-centered .form-sub,
body.admin-signup-centered .field-label,
body.admin-signup-centered .key-notice,
body.admin-signup-centered .success-msg,
body.admin-signup-centered .divider-text,
body.admin-signup-centered .strength-label {
  color: #2f2a2e !important;
}

body.admin-signup-centered .field-input {
  color: #111111 !important;
  background: #ffffff !important;
  border-color: rgba(33, 26, 32, 0.16) !important;
}

body.admin-signup-centered .field-input::placeholder {
  color: #777077 !important;
}

body.admin-signup-centered .field-icon,
body.admin-signup-centered .field-toggle {
  color: #5f5960 !important;
}

body.admin-signup-centered .btn-ghost {
  color: #111111 !important;
  border-color: rgba(33, 26, 32, 0.14) !important;
}

body.admin-signup-centered .key-notice-icon,
body.admin-signup-centered .form-eyebrow {
  color: #111111 !important;
}

@media (max-width: 520px) {
  body.admin-signup-centered .card {
    padding: 40px 28px !important;
  }
}
</style>
<link rel="stylesheet" href="../assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/../assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
<link rel="stylesheet" href="../assets/css/password-eye.css?v=<?= filemtime(__DIR__ . '/../assets/css/password-eye.css') ?>">
</head>
<body class="admin-signup-centered">

<div class="bg-scene">
  <div class="bg-grid"></div>
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>
  <div class="bg-orb bg-orb-3"></div>
</div>

<div class="page">
  <div class="card">

    <div class="form-header">
      <a href="../index.php" class="brand-home-link" aria-label="Back to landing page">
        <img src="../assets/img/image.png" alt="Brille Amour logo" class="brand-mark-center">
      </a>
      <div class="form-eyebrow">Admin Registration</div>
      <div class="form-title">Create Account</div>
      <div class="form-sub">Register a new administrator account</div>
    </div>

    <?php if ($success): ?>

      <div class="alert alert-success">
        <span class="success-icon">OK</span>
        <div class="success-title">Account Created!</div>
        <div class="success-msg">Your administrator account is ready. You can now sign in and access the full system.</div>
        <a href="login.php" class="btn-submit" style="text-decoration:none; max-width:220px;">
          Sign In Now <span class="arrow">→</span>
        </a>
      </div>

    <?php else: ?>

      <?php if ($error): ?>
      <div class="alert alert-error">
        <span class="alert-icon">!</span>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="post" autocomplete="off" novalidate>

        <!-- Row 1: Full Name + Username -->
        <div class="field-row">
          <div class="field-group">
            <label class="field-label" for="full_name">Full Name <span>*</span></label>
            <div class="field-wrap">
              <input class="field-input" type="text" id="full_name" name="full_name"
                placeholder="Your full name"
                value="<?= htmlspecialchars($data['full_name'] ?? '') ?>"
                required autofocus>
              <span class="field-icon">N</span>
            </div>
          </div>
          <div class="field-group">
            <label class="field-label" for="username">Username <span>*</span></label>
            <div class="field-wrap">
              <input class="field-input" type="text" id="username" name="username"
                placeholder="Choose a username"
                value="<?= htmlspecialchars($data['username'] ?? '') ?>"
                required autocomplete="off">
              <span class="field-icon">⌘</span>
            </div>
          </div>
        </div>

        <!-- Email -->
        <div class="field-group">
          <label class="field-label" for="email">Email Address</label>
          <div class="field-wrap">
            <input class="field-input" type="email" id="email" name="email"
              placeholder="admin@brilleamour.com"
              value="<?= htmlspecialchars($data['email'] ?? '') ?>">
            <span class="field-icon">E</span>
          </div>
        </div>

        <!-- Row 2: Password + Confirm -->
        <div class="field-row">
          <div class="field-group">
            <label class="field-label" for="pw">Password <span>*</span></label>
            <div class="field-wrap">
              <input class="field-input has-toggle" type="password" id="pw" name="password"
                placeholder="Min. 6 characters"
                required oninput="checkStrength(this.value)">
              <span class="field-icon">P</span>
              <button type="button" class="field-toggle" id="pwToggle1" aria-label="Toggle password visibility" data-password-toggle="pw"></button>
            </div>
            <div class="strength-wrap">
              <div class="strength-bar">
                <div class="strength-seg" id="s1"></div>
                <div class="strength-seg" id="s2"></div>
                <div class="strength-seg" id="s3"></div>
                <div class="strength-seg" id="s4"></div>
                <div class="strength-seg" id="s5"></div>
              </div>
              <div class="strength-label" id="slabel"></div>
            </div>
          </div>
          <div class="field-group">
            <label class="field-label" for="confirm_password">Confirm Password <span>*</span></label>
            <div class="field-wrap">
              <input class="field-input has-toggle" type="password" id="confirm_password" name="confirm_password"
                placeholder="Repeat password" required>
              <span class="field-icon">C</span>
              <button type="button" class="field-toggle" id="pwToggle2" aria-label="Toggle password visibility" data-password-toggle="confirm_password"></button>
            </div>
          </div>
        </div>

        <!-- Registration Key -->
        <div class="key-notice">
          <span class="key-notice-icon">Key</span>
          An admin registration key is required to prevent unauthorized sign-ups. Contact the system owner to obtain it.
        </div>
        <div class="field-group">
          <label class="field-label" for="admin_secret">Registration Key <span>*</span></label>
          <div class="field-wrap">
            <input class="field-input has-toggle" type="password" id="admin_secret" name="admin_secret"
              placeholder="Enter your registration key" required autocomplete="off">
            <span class="field-icon">K</span>
            <button type="button" class="field-toggle" id="pwToggle3" aria-label="Toggle password visibility" data-password-toggle="admin_secret"></button>
          </div>
        </div>

        <button type="submit" class="btn-submit">
          Create Admin Account
          <span class="arrow">→</span>
        </button>

      </form>

      <div class="divider">
        <div class="divider-line"></div>
        <span class="divider-text">already registered?</span>
        <div class="divider-line"></div>
      </div>

      <a href="login.php" class="btn-ghost">Sign in to your account</a>

    <?php endif; ?>

  </div>
</div>

<script>
// ── Strength meter ──────────────────────────────
const segs   = [1,2,3,4,5].map(i => document.getElementById('s' + i));
const slabel = document.getElementById('slabel');
const levels = [
  { color: '#e74c3c', label: 'Too weak' },
  { color: '#e67e22', label: 'Weak' },
  { color: '#f1c40f', label: 'Fair' },
  { color: '#2ecc71', label: 'Strong' },
  { color: '#27ae60', label: 'Very strong' },
];

function checkStrength(v) {
  let score = 0;
  if (v.length >= 6)          score++;
  if (v.length >= 10)         score++;
  if (/[A-Z]/.test(v))        score++;
  if (/[0-9]/.test(v))        score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;

  segs.forEach((seg, i) => {
    seg.style.background = i < score ? levels[score - 1].color : 'rgba(255,255,255,.08)';
  });
  if (v.length > 0 && score > 0) {
    slabel.textContent = levels[score - 1].label;
    slabel.style.color = levels[score - 1].color;
  } else {
    slabel.textContent = '';
  }
}


// ── Field nudge on focus ────────────────────────
document.querySelectorAll('.field-input').forEach(input => {
  input.addEventListener('focus', () => {
    input.closest('.field-group').style.cssText = 'transform:translateX(2px);transition:transform .2s';
  });
  input.addEventListener('blur', () => {
    input.closest('.field-group').style.transform = 'translateX(0)';
  });
});
</script>
<script src="../assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/../assets/js/password-eye.js') ?>"></script>
</html>
