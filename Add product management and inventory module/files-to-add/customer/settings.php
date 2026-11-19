<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=settings.php');
    exit;
}

$error = '';
$success = '';
$hasPassword = !empty($customer['password_hash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($hasPassword && !password_verify($currentPassword, (string)$customer['password_hash'])) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        $error = 'New password must be between 8 and 72 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hash === false) {
            $error = 'Unable to update your password right now.';
        } else {
            $customerId = (int)$customer['id'];
            $stmt = $conn->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $customerId);
            $stmt->execute();
            $stmt->close();
            $customer = loadSignedInCustomer($conn);
            $hasPassword = true;
            $success = 'Your password has been updated successfully.';
        }
    }
}

$csrfToken = customerAuthCsrfToken();
$activePage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Settings - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/password-eye.css?v=<?= filemtime(__DIR__ . '/../assets/css/password-eye.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="customer-account-body customer-portal-body">
  <?php $portalActive = 'profile'; require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-account-content">

      <section class="account-page-heading compact-heading">
        <div><span class="auth-eyebrow">Settings</span><h1>Account & Security</h1><p>Manage how you sign in to your Brille Amour customer account.</p></div>
      </section>

      <section class="account-settings-grid">
        <article class="account-settings-card">
          <div class="settings-card-heading"><span>Sign-in Account</span><h2><?= customerAuthMethod() === 'google' ? 'Google connected' : 'Email & password' ?></h2></div>
          <div class="settings-detail-row"><span>Email</span><strong><?= htmlspecialchars((string)$customer['email']) ?></strong></div>
          <div class="settings-detail-row"><span>Google</span><strong><?= !empty($customer['google_sub']) ? 'Connected' : 'Not connected' ?></strong></div>
          <div class="settings-detail-row"><span>Password login</span><strong><?= $hasPassword ? 'Enabled' : 'Not set' ?></strong></div>
        </article>

        <article class="account-settings-card">
          <div class="settings-card-heading"><span>Security</span><h2><?= $hasPassword ? 'Change Password' : 'Create Password' ?></h2></div>
          <?php if ($error): ?><div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
          <?php if ($success): ?><div class="auth-alert auth-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>
          <?php if (!$hasPassword): ?><p class="form-help">You signed in with Google. You can create a password here if you also want to log in using email and password.</p><?php endif; ?>
          <form class="account-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <?php if ($hasPassword): ?>
              <label><span>Current Password</span><div class="password-field"><input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="currentPassword"></button></div></label>
            <?php endif; ?>
            <label><span>New Password</span><div class="password-field"><input id="newPassword" type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required><button type="button" class="password-toggle" data-password-toggle="newPassword"></button></div></label>
            <label><span>Confirm New Password</span><div class="password-field"><input id="confirmPassword" type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required><button type="button" class="password-toggle" data-password-toggle="confirmPassword"></button></div></label>
            <button class="account-save-button" type="submit"><?= $hasPassword ? 'Update Password' : 'Create Password' ?></button>
          </form>
        </article>

        <article class="account-settings-card danger-card">
          <div class="settings-card-heading"><span>Session</span><h2>Log Out</h2></div>
          <p>Sign out of your customer account on this browser.</p>
          <a class="settings-logout-button" href="logout.php">Log Out of Customer Account</a>
        </article>
      </section>
  </main>
<script src="../assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/../assets/js/password-eye.js') ?>"></script>
</body>
</html>
