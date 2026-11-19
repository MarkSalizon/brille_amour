<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth_redirect.php';
require_once '../includes/auth_verification.php';
require_once '../includes/google_auth.php';
require_once '../includes/attendance.php';

startAuthPageSession('staff');

$error = '';
$successMessage = '';
$loginValue = trim($_POST['login_email'] ?? '');
$password = $_POST['password'] ?? '';
$authStep = $_POST['auth_step'] ?? 'login';
$verificationKey = 'staff_login_verification';
$flashError = $_SESSION['auth_error'] ?? '';
$flashSuccess = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_error'], $_SESSION['auth_success']);

if ($flashError) {
  $error = $flashError;
}
if ($flashSuccess) {
  $successMessage = $flashSuccess;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($authStep === 'verify_login') {
    $verified = authVerificationCheck($verificationKey, (string)($_POST['verification_code'] ?? ''));
    if (!$verified['ok']) {
      $error = $verified['error'];
    } else {
      $user = $verified['payload']['user'] ?? null;
      if (is_array($user) && ($user['role'] ?? '') === 'staff') {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        staffTimeIn($conn, (int)$user['id'], 'login');
        $_SESSION['ba_team_welcome_pending'] = true;
        header('Location: dashboard.php');
        exit;
      }

      $error = 'Verification failed. Please sign in again.';
    }
  } elseif ($authStep === 'google_pending_login') {
    $pending = authVerificationPending($verificationKey);
    $user = $pending['payload']['user'] ?? null;

    if (!is_array($pending) || !is_array($user) || empty($pending['email'])) {
      $error = 'Verification expired. Please sign in again.';
    } else {
      $_SESSION['staff_google_login_pending'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
        'email' => (string)$pending['email'],
      ];

      try {
        googleAuthStart('staff', 'login', (string)$pending['email']);
      } catch (Throwable $e) {
        $error = $e->getMessage();
      }
    }
  } else {
    if ($loginValue && $password) {
      $stmt = $conn->prepare("SELECT * FROM users WHERE (email=? OR username=?) AND role='staff' AND status='active' LIMIT 1");
      $stmt->bind_param('ss', $loginValue, $loginValue);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();

      if ($user && password_verify($password, (string)$user['password'])) {
        $result = authVerificationStart($verificationKey, (string)$user['email'], (string)$user['full_name'], 'staff login', [
          'user' => [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)$user['full_name'],
            'role' => (string)$user['role'],
          ],
        ]);
        $successMessage = $result['sent']
          ? 'We sent a verification code to your Google/Gmail account.'
          : 'Email could not be sent. Please use a valid Google App Password for the Gmail sender in config.php.';
      }

      if (!$successMessage && !$error) {
        $error = 'Invalid email/username or password.';
      }
    } else {
      $error = 'Please enter your Google/Gmail email or username and password.';
    }
  }
}
$pendingVerification = authVerificationPending($verificationKey);
$loginError = $pendingVerification ? '' : $error;
$verificationError = $pendingVerification ? $error : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Staff Login - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design-system.css">
  <link rel="stylesheet" href="../assets/css/auth-luxury.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth-luxury.css') ?>">
  <script src="../assets/js/auth.js" defer></script>
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/../assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="../assets/css/staff-auth-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-auth-final.css') ?>">
</head>

<body class="staff-auth-page">
<?php
$welcomeSplashMode = (($_GET['ba_splash'] ?? '') === 'logout') ? 'logout' : (!empty($_SESSION['ba_team_signup_welcome_pending']) ? 'signup' : 'entry');
unset($_SESSION['ba_team_signup_welcome_pending']);
$welcomeSplashLogo = '../assets/img/image.png';
require __DIR__ . '/../includes/welcome_splash.php';
?>
  <main class="auth-page">
    <div class="auth-shell">
      <div class="topline">
        <a class="brand-link" href="../portal.php" aria-label="Back to Brille Amour team portal">
          <img src="../assets/img/image.png" alt="Brille Amour logo">
          <strong>Brille Amour</strong>
        </a>
        <a class="home-link" href="../portal.php"><span aria-hidden="true">&lt;-</span> Back to team portal</a>
      </div>

      <section class="auth-card" aria-labelledby="auth-title">
        <div class="auth-form-panel">
          <span class="portal-badge">Staff Access</span>
          <h1 id="auth-title">Welcome back.</h1>
          <p>Sign in to see today's priorities and keep the promise moving.</p>

          <?php if ($successMessage): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($successMessage) ?></div><?php endif; ?>
          <?php if ($loginError): ?><div class="alert" role="alert"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>

          <?php if ($pendingVerification): ?>
          <?php if ($verificationError): ?><div class="alert" role="alert"><?= htmlspecialchars($verificationError) ?></div><?php endif; ?>
          <form class="auth-form" method="post" data-auth-form novalidate>
            <input type="hidden" name="auth_step" value="verify_login">
            <div class="field-group">
              <label class="field-label" for="verification_code">Verification code</label>
              <div class="input-wrap">
                <input class="field-input" type="text" id="verification_code" name="verification_code" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" autocomplete="one-time-code" data-required required autofocus>
              </div>
              <div class="field-error" data-error-for="verification_code" aria-live="polite"></div>
              <p class="auth-code-note">Enter the 6-digit code sent to <?= htmlspecialchars($pendingVerification['email'] ?? 'your email') ?>.</p>
            </div>
            <button class="primary-button" type="submit" data-submit>Verify and Sign In</button>
          </form>
          <form class="auth-fallback-form" method="post">
            <input type="hidden" name="auth_step" value="google_pending_login">
            <button class="google-fallback-button" type="submit">
              <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l3.66-2.84z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06L5.84 9.9c.87-2.6 3.3-4.52 6.16-4.52z"/>
              </svg>
              Verify with Google instead
            </button>
          </form>
          <?php else: ?>
          <form class="auth-form" method="post" data-auth-form novalidate>
            <input type="hidden" name="auth_step" value="login">
            <div class="field-group">
              <label class="field-label" for="login_email">Google/Gmail email or username</label>
              <div class="input-wrap">
                <input class="field-input" type="text" id="login_email" name="login_email" value="<?= htmlspecialchars($loginValue) ?>" autocomplete="username" placeholder="name@gmail.com" data-required required autofocus>
              </div>
              <div class="field-error" data-error-for="login_email" aria-live="polite"></div>
            </div>

            <div class="field-group">
              <label class="field-label" for="password">Account password</label>
              <div class="input-wrap">
                <input class="field-input has-toggle" type="password" id="password" name="password" autocomplete="current-password" data-required data-password required>
                <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Toggle password visibility" aria-pressed="false"><span class="sr-only">Show password</span></button>
              </div>
              <div class="field-error" data-error-for="password" aria-live="polite"></div>
            </div>

            <button class="primary-button" type="submit" data-submit>
              <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l3.66-2.84z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06L5.84 9.9c.87-2.6 3.3-4.52 6.16-4.52z"/>
              </svg>
              Send Verification Code
            </button>
          </form>
          <?php endif; ?>

          <p class="footer-prompt">New staff member? <a class="ghost-link" href="signup.php">Create an account</a></p>
        </div>

        <aside class="brand-panel staff-workspace-panel" aria-label="Brille Amour staff workspace">
          <div class="staff-workspace-brand">
            <span class="eyebrow">Brille Amour / Staff Portal</span>
            <div class="staff-workspace-intro">
              <span class="staff-workspace-logo" aria-hidden="true"><img src="../assets/img/image.png" alt=""></span>
              <div>
                <h2>Staff Production Workspace</h2>
                <p>Keep customer orders moving with clear production updates and quality checks.</p>
              </div>
            </div>
          </div>

          <div class="staff-workspace-tools" aria-label="Staff workspace features">
            <span>Assigned Orders</span>
            <span>Production Progress</span>
            <span>Quality Check</span>
            <span>Team Messages</span>
          </div>

          <div class="brand-panel-note staff-workspace-note">
            <span>Secure staff access</span>
            <span><?= date('M d, Y') ?></span>
          </div>
        </aside>
      </section>
    </div>
  </main>
</body>

</html>
