<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/google_auth.php';
require_once '../includes/auth_verification.php';

startCustomerSession();
$schemaError = '';
try {
    ensureCustomerAuthSchema($conn);
} catch (Throwable $e) {
    error_log('Customer authentication schema error: ' . $e->getMessage());
    $schemaError = 'Customer account setup could not be prepared. Please check the database configuration.';
}

$returnTo = customerSafeReturn((string)($_GET['return'] ?? $_POST['return'] ?? 'home.php'));
if (customerIsLoggedIn()) { header('Location: ' . $returnTo); exit; }

$error = $schemaError !== '' ? $schemaError : (string)($_SESSION['customer_auth_error'] ?? '');
$success = (string)($_SESSION['customer_auth_success'] ?? '');
unset($_SESSION['customer_auth_error'], $_SESSION['customer_auth_success']);

$email = customerNormalizeEmail((string)($_POST['email'] ?? ''));

if (isset($_GET['google'])) {
    $_SESSION['customer_google_login_return'] = $returnTo;
    try { googleAuthStart('customer', 'login'); } catch (Throwable $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaError === '') {
    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your login session expired. Please refresh the page and try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid Google/Gmail email address.';
        } elseif ($password === '') {
            $error = 'Please enter your password.';
        } else {
            $customer = customerPasswordAccountByEmail($conn, $email);
            if (!$customer || !password_verify($password, (string)($customer['password_hash'] ?? ''))) {
                $error = 'Incorrect email or password.';
            } elseif (($customer['status'] ?? 'active') !== 'active') {
                $error = 'This customer account is currently unavailable. Please contact Brille Amour.';
            } else {
                unset($_SESSION['customer_login_verification']);
                $customerId = (int)$customer['id'];
                $stmt = $conn->prepare('UPDATE customers SET last_login_at=NOW() WHERE id=?');
                if ($stmt) { $stmt->bind_param('i', $customerId); $stmt->execute(); $stmt->close(); }
                customerLoginSession($customer, 'password');
                header('Location: ' . $returnTo);
                exit;
            }
        }
    }
}

$csrfToken = customerAuthCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Customer Login - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/password-eye.css?v=<?= filemtime(__DIR__ . '/../assets/css/password-eye.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-mobile-auth-v2.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-mobile-auth-v2.css') ?>">
</head>
<body class="customer-auth-body">
<?php $welcomeSplashMode = 'entry'; $welcomeSplashLogo = '../assets/img/image.png'; require __DIR__ . '/../includes/welcome_splash.php'; ?>
<main class="customer-auth-shell">
  <a class="mobile-auth-back-link" href="../index.php">Back</a>
  <section class="mobile-auth-hero" aria-hidden="true">
    <h1>Welcome Back!</h1>
    <p>Sign in to continue shopping and tracking your Brille Amour orders.</p>
    <div class="mobile-auth-logo-wrap"><img src="../assets/img/image.png" alt=""></div>
  </section>
  <a class="auth-brand" href="../index.php"><img src="../assets/img/image.png" alt="Brille Amour logo"><span><strong>Brille Amour</strong><small>Customer Portal</small></span></a>
  <section class="auth-card"><div class="auth-panel">
    <img class="auth-logo" src="../assets/img/image.png" alt="">
    <div class="auth-panel-heading"><h2>Customer Login</h2><p>Welcome back! Sign in using your email and password.</p></div>
    <?php if ($error): ?><div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-alert auth-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <form class="customer-auth-form" method="post" action="login.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
      <label><span>Google/Gmail Email</span><input type="email" name="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" placeholder="name@gmail.com" required></label>
      <label><span>Password</span><div class="password-field"><input id="loginPassword" type="password" name="password" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="loginPassword" aria-label="Show password"></button></div></label>
      <a class="mobile-forgot-link" href="forgot_password.php?return=<?= urlencode($returnTo) ?>">Forgot Password?</a>
      <button class="auth-submit" type="submit">Log In</button>
    </form>
    <div class="auth-divider"><span>or</span></div>
    <a class="google-signin" href="login.php?google=1&amp;return=<?= urlencode($returnTo) ?>"><span>Continue with Google</span></a>
    <p class="auth-switch">No account yet? <a href="signup.php?return=<?= urlencode($returnTo) ?>">Create account</a></p>
  </div></section>
</main>
<script src="../assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/../assets/js/password-eye.js') ?>"></script>
</body></html>
