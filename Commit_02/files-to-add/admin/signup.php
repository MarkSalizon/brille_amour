<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth_redirect.php';
require_once '../includes/auth_verification.php';
require_once '../includes/google_auth.php';

startAuthPageSession('admin');

$error = '';
$successMessage = '';
$success = false;
$authStep = $_POST['auth_step'] ?? 'signup';
$verificationKey = 'admin_signup_verification';
$flashError = $_SESSION['auth_error'] ?? '';
$flashSuccess = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_error'], $_SESSION['auth_success']);

if ($flashError) {
    $error = $flashError;
}
if ($flashSuccess) {
    $successMessage = $flashSuccess;
}
$data = [
    'full_name' => trim($_POST['full_name'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($authStep === 'verify_signup') {
        $verified = authVerificationCheck($verificationKey, (string)($_POST['verification_code'] ?? ''));
        if (!$verified['ok']) {
            $error = $verified['error'];
        } else {
            $payload = $verified['payload'] ?? [];
            $email = trim((string)($payload['email'] ?? ''));
            $fullName = trim((string)($payload['full_name'] ?? ''));
            $hash = (string)($payload['password_hash'] ?? '');

            $chk = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
            $chk->bind_param('ss', $email, $email);
            $chk->execute();

            if ($chk->get_result()->num_rows > 0) {
                $error = 'An account with this email already exists.';
            } elseif ($email === '' || $fullName === '' || $hash === '') {
                $error = 'Verification failed. Please submit the signup form again.';
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username,password,full_name,email,role,status) VALUES (?,?,?,?,'admin','active')");
                $stmt->bind_param('ssss', $email, $hash, $fullName, $email);
                if ($stmt->execute()) {
                    $success = true;
                    $data = ['full_name' => '', 'email' => ''];
                } else {
                    $error = 'Error creating account. Please try again.';
                }
            }
    }
    } elseif ($authStep === 'google_pending_signup') {
        $pending = authVerificationPending($verificationKey);
        $payload = $pending['payload'] ?? null;

        if (!is_array($pending) || !is_array($payload) || empty($payload['email'])) {
            $error = 'Verification expired. Please submit the signup form again.';
        } else {
            $_SESSION['admin_google_signup_pending'] = [
                'full_name' => (string)($payload['full_name'] ?? ''),
                'email' => (string)$payload['email'],
                'password_hash' => (string)($payload['password_hash'] ?? ''),
            ];

            try {
                googleAuthStart('admin', 'signup', (string)$payload['email']);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
} else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $terms = isset($_POST['terms']);

        if (!$data['full_name'] || !$data['email'] || !$password || !$confirm) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid work email.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (!$terms) {
            $error = 'Please agree to the terms and privacy policy.';
        } else {
            $chk = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
            $chk->bind_param('ss', $data['email'], $data['email']);
            $chk->execute();

            if ($chk->get_result()->num_rows > 0) {
                $error = 'An account with this email already exists.';
            } else {
                $result = authVerificationStart($verificationKey, $data['email'], $data['full_name'], 'admin signup', [
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $successMessage = $result['sent']
                    ? 'We sent a verification code to your Google/Gmail account.'
                    : 'Email could not be sent. Please use a valid Google App Password for the Gmail sender in config.php.';
            }
        }
    }
}

$pendingVerification = authVerificationPending($verificationKey);
$formError = $pendingVerification ? '' : $error;
$verificationError = $pendingVerification ? $error : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Signup - Brille Amour</title>
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
  <link rel="stylesheet" href="../assets/css/admin-auth-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-auth-mobile-polish.css') ?>">
</head>
<body class="admin-auth-page">
<?php if ($success):
    $welcomeSplashMode = 'signup';
    $welcomeSplashLogo = '../assets/img/image.png';
    require __DIR__ . '/../includes/welcome_splash.php';
endif; ?>
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
        <span class="portal-badge">Admin Signup</span>
        <h1 id="auth-title">Build the beautiful overview.</h1>
        <p>Create your administrator workspace and bring every operation into focus.</p>

        <?php if ($success): ?>
          <div class="alert alert-success" role="status">Account verified and created. You can now sign in.</div>
        <?php elseif ($successMessage): ?>
          <div class="alert alert-success" role="status"><?= htmlspecialchars($successMessage) ?></div>
        <?php elseif ($formError): ?>
          <div class="alert" role="alert"><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>

        <?php if ($pendingVerification): ?>
        <?php if ($verificationError): ?><div class="alert" role="alert"><?= htmlspecialchars($verificationError) ?></div><?php endif; ?>
        <form class="auth-form" method="post" data-auth-form novalidate>
          <input type="hidden" name="auth_step" value="verify_signup">
          <div class="field-group">
            <label class="field-label" for="verification_code">Verification code</label>
            <div class="input-wrap">
              <input class="field-input" type="text" id="verification_code" name="verification_code" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" autocomplete="one-time-code" data-required required autofocus>
            </div>
            <div class="field-error" data-error-for="verification_code" aria-live="polite"></div>
            <p class="auth-code-note">Enter the 6-digit code sent to <?= htmlspecialchars($pendingVerification['email'] ?? 'your email') ?>.</p>
          </div>
          <button class="primary-button" type="submit" data-submit>Verify and Create Account</button>
        </form>
        <form class="auth-fallback-form" method="post">
          <input type="hidden" name="auth_step" value="google_pending_signup">
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
          <input type="hidden" name="auth_step" value="signup">
          <div class="field-grid">
            <div class="field-group">
              <label class="field-label" for="full_name">Full name</label>
              <div class="input-wrap"><input class="field-input" type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($data['full_name']) ?>" autocomplete="name" data-required required autofocus></div>
              <div class="field-error" data-error-for="full_name" aria-live="polite"></div>
            </div>
            <div class="field-group">
              <label class="field-label" for="email">Work email</label>
              <div class="input-wrap"><input class="field-input" type="email" id="email" name="email" value="<?= htmlspecialchars($data['email']) ?>" autocomplete="email" data-required required></div>
              <div class="field-error" data-error-for="email" aria-live="polite"></div>
            </div>
          </div>

          <div class="field-grid">
            <div class="field-group">
              <label class="field-label" for="password">Account password</label>
              <div class="input-wrap">
                <input class="field-input has-toggle" type="password" id="password" name="password" autocomplete="new-password" data-required data-password required>
                <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Toggle password visibility" aria-pressed="false"><span class="sr-only">Show password</span></button>
              </div>
              <div class="field-error" data-error-for="password" aria-live="polite"></div>
            </div>
            <div class="field-group">
              <label class="field-label" for="confirm_password">Confirm account password</label>
              <div class="input-wrap">
                <input class="field-input has-toggle" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" data-required data-confirm-password required>
                <button class="password-toggle" type="button" data-password-toggle="confirm_password" aria-label="Toggle password visibility" aria-pressed="false"><span class="sr-only">Show password</span></button>
              </div>
              <div class="field-error" data-error-for="confirm_password" aria-live="polite"></div>
            </div>
          </div>

          <label class="checkbox-row"><input type="checkbox" name="terms" value="1" data-terms> <span>I agree to the <a href="../terms-and-conditions.php" target="_blank" rel="noopener noreferrer">Terms and Conditions (opens in a new tab)</a> and <a href="../privacy-policy.php" target="_blank" rel="noopener noreferrer">Privacy Policy (opens in a new tab)</a></span></label>
          <button class="primary-button" type="submit" data-submit>
            <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l3.66-2.84z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06L5.84 9.9c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            Send Google Verification Code
          </button>
        </form>
        <?php endif; ?>

        <p class="footer-prompt">Already have an account? <a class="ghost-link" href="login.php">Sign in</a></p>
      </div>

      <aside class="brand-panel" aria-label="Admin portal brand statement">
        <div>
          <span class="eyebrow">Brille Amour / Admin</span>
          <h2>The complete picture, beautifully organized.</h2>
        </div>
        <div>
          <p>Inventory, teams, reporting, and approvals-held in one calm command center.</p>
          <div class="brand-panel-note"><span>Protected creation</span><span>Admin workspace</span></div>
        </div>
      </aside>
    </section>
  </div>
</main>
</body>
</html>
