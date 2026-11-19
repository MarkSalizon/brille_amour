<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/auth_verification.php';

startCustomerSession();
$schemaError = '';
try {
    ensureCustomerAuthSchema($conn);
} catch (Throwable $e) {
    error_log('Customer password reset schema error: ' . $e->getMessage());
    $schemaError = 'Customer account setup could not be prepared. Please check the database configuration.';
}

$returnTo = customerSafeReturn((string)($_GET['return'] ?? $_POST['return'] ?? 'home.php'));
if (customerIsLoggedIn()) {
    header('Location: ' . $returnTo);
    exit;
}

$verificationKey = 'customer_password_reset_verification';
$error = $schemaError;
$success = '';
$email = customerNormalizeEmail((string)($_POST['email'] ?? ''));
$step = (string)($_POST['reset_step'] ?? 'request');
$resetComplete = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaError === '') {
    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your password reset session expired. Please refresh the page and try again.';
    } elseif ($step === 'verify') {
        $newPassword = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            $error = 'Password must be 8 to 72 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $verified = authVerificationCheck($verificationKey, (string)($_POST['verification_code'] ?? ''));
            if (!$verified['ok']) {
                $error = (string)($verified['error'] ?? 'Invalid verification code.');
            } else {
                $payload = $verified['payload'] ?? [];
                $customerId = (int)($payload['customer_id'] ?? 0);
                $payloadEmail = customerNormalizeEmail((string)($payload['email'] ?? ''));
                if ($customerId <= 0 || !filter_var($payloadEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Password reset data expired. Please request a new code.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND LOWER(TRIM(email)) = ? AND status = 'active' LIMIT 1");
                    if (!$stmt) {
                        $error = 'Unable to update your password. Please try again.';
                    } else {
                        $stmt->bind_param('sis', $hash, $customerId, $payloadEmail);
                        $stmt->execute();
                        $updated = $stmt->affected_rows;
                        $stmt->close();
                        if ($updated >= 0) {
                            $success = 'Password updated successfully. You can now sign in using your new password.';
                            $resetComplete = true;
                            $email = $payloadEmail;
                        } else {
                            $error = 'Unable to update your password. Please try again.';
                        }
                    }
                }
            }
        }
    } else {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $customer = customerRegisteredAccountByEmail($conn, $email);
            if (!$customer || (($customer['status'] ?? 'active') !== 'active')) {
                $error = 'We could not find an active customer account for that email.';
            } else {
                $name = trim((string)($customer['name'] ?? 'Customer'));
                $result = authVerificationStart(
                    $verificationKey,
                    $email,
                    $name,
                    'password reset',
                    ['customer_id' => (int)$customer['id'], 'email' => $email]
                );
                if (!empty($result['sent'])) {
                    $success = 'We sent a 6-digit password reset code to your email.';
                } else {
                    unset($_SESSION[$verificationKey]);
                    $error = 'Password reset email could not be sent. Please try again later.';
                }
            }
        }
    }
}

$pendingVerification = !$resetComplete ? authVerificationPending($verificationKey) : null;
$csrfToken = customerAuthCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Forgot Password - Brille Amour</title>
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
<main class="customer-auth-shell">
  <a class="mobile-auth-back-link" href="login.php?return=<?= urlencode($returnTo) ?>">Back</a>
  <section class="mobile-auth-hero" aria-hidden="true">
    <h1><?= $resetComplete ? 'Password Updated!' : ($pendingVerification ? 'Check Your Email' : 'Forgot Password?') ?></h1>
    <p><?= $resetComplete ? 'Your Brille Amour password is ready to use.' : ($pendingVerification ? 'Enter the code from your email and choose a new password.' : 'Enter your email and we will send you a secure reset code.') ?></p>
    <div class="mobile-auth-logo-wrap"><img src="../assets/img/image.png" alt=""></div>
  </section>

  <a class="auth-brand" href="../index.php"><img src="../assets/img/image.png" alt="Brille Amour logo"><span><strong>Brille Amour</strong><small>Customer Portal</small></span></a>
  <section class="auth-card"><div class="auth-panel">
    <img class="auth-logo" src="../assets/img/image.png" alt="">
    <div class="auth-panel-heading"><h2><?= $resetComplete ? 'Password Updated' : ($pendingVerification ? 'Reset Password' : 'Forgot Password') ?></h2><p><?= $pendingVerification ? 'Enter the code sent to your email and set a new password.' : 'Enter your customer email to receive a reset code.' ?></p></div>

    <?php if ($error): ?><div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-alert auth-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($resetComplete): ?>
      <a class="auth-submit" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="login.php?return=<?= urlencode($returnTo) ?>">Back to Login</a>
    <?php elseif ($pendingVerification): ?>
      <form class="customer-auth-form" method="post" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
        <input type="hidden" name="reset_step" value="verify">
        <label><span>Verification Code</span><input type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="6-digit code" required autofocus></label>
        <label><span>New Password</span><div class="password-field"><input id="resetPassword" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="button" class="password-toggle" data-password-toggle="resetPassword" aria-label="Show password"></button></div></label>
        <label><span>Confirm New Password</span><div class="password-field"><input id="resetConfirmPassword" type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="button" class="password-toggle" data-password-toggle="resetConfirmPassword" aria-label="Show password"></button></div></label>
        <p class="mobile-reset-help">The verification code expires after <?= authVerificationCodeTtlMinutes() ?> minutes.</p>
        <button class="auth-submit" type="submit">Reset Password</button>
      </form>
      <p class="auth-switch"><a href="forgot_password.php?return=<?= urlencode($returnTo) ?>">Request a new code</a></p>
    <?php else: ?>
      <form class="customer-auth-form" method="post" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
        <input type="hidden" name="reset_step" value="request">
        <label><span>Email Address</span><input type="email" name="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" placeholder="name@gmail.com" required></label>
        <button class="auth-submit" type="submit">Send Reset Code</button>
      </form>
      <p class="auth-switch">Remembered your password? <a href="login.php?return=<?= urlencode($returnTo) ?>">Back to login</a></p>
    <?php endif; ?>
  </div></section>
</main>
<script src="../assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/../assets/js/password-eye.js') ?>"></script>
</body>
</html>
