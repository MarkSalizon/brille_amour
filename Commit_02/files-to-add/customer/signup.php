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

$error = $schemaError;
$success = '';
$verificationKey = 'customer_signup_verification';
$authStep = (string)($_POST['auth_step'] ?? 'signup');
$name = trim((string)($_POST['name'] ?? ''));
$email = customerNormalizeEmail((string)($_POST['email'] ?? ''));
$contact = trim((string)($_POST['contact'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));

if (isset($_GET['google'])) {
    $_SESSION['customer_google_login_return'] = $returnTo;
    try { googleAuthStart('customer', 'login'); } catch (Throwable $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaError === '') {
    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your signup session expired. Please refresh the page and try again.';
    } elseif ($authStep === 'verify_signup') {
        $verified = authVerificationCheck($verificationKey, (string)($_POST['verification_code'] ?? ''));
        if (!$verified['ok']) {
            $error = $verified['error'];
        } else {
            $payload = $verified['payload'] ?? [];
            $name = trim((string)($payload['name'] ?? ''));
            $email = customerNormalizeEmail((string)($payload['email'] ?? ''));
            $contact = trim((string)($payload['contact'] ?? ''));
            $address = trim((string)($payload['address'] ?? ''));
            $hash = (string)($payload['password_hash'] ?? '');
            $addressLength = function_exists('mb_strlen') ? mb_strlen($address) : strlen($address);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contact) || $addressLength < 5 || $addressLength > 1000 || $hash === '' || (string)($payload['terms_version'] ?? '') !== customerTermsVersion()) {
                $error = 'Verification data expired. Please submit the signup form again.';
            } elseif (customerRegisteredAccountByEmail($conn, $email)) {
                $error = 'An account already exists for this email. Please log in instead.';
            } else {
                $source = 'Website Account';
                $notes = 'Customer account verified through Gmail code.';
                $stmt = $conn->prepare("INSERT INTO customers (name,email,contact,address,password_hash,registered_at,last_login_at,source,notes,status,terms_version,terms_accepted_at,privacy_version,privacy_acknowledged_at) VALUES (?,?,?,?,?,NOW(),NOW(),?,?,'active',?,NOW(),?,NOW())");
                $termsVersion = customerTermsVersion();
                $stmt->bind_param('sssssssss', $name, $email, $contact, $address, $hash, $source, $notes, $termsVersion, $termsVersion);
                if ($stmt->execute()) {
                    $customerId = (int)$conn->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');
                    $stmt->bind_param('i', $customerId);
                    $stmt->execute();
                    $customer = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($customer) { customerLoginSession($customer, 'password'); $_SESSION['ba_customer_welcome_pending'] = 'signup'; header('Location: ' . $returnTo); exit; }
                    $error = 'Your account was created, but sign-in could not be completed. Please log in.';
                } else {
                    $error = 'Error creating account. Please try again.';
                }
            }
        }
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength < 2 || $nameLength > 150) $error = 'Please enter your full name.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid Google/Gmail email address.';
        elseif (!preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contact)) $error = 'Please enter a valid contact number.';
        elseif ((function_exists('mb_strlen') ? mb_strlen($address) : strlen($address)) < 5 || (function_exists('mb_strlen') ? mb_strlen($address) : strlen($address)) > 1000) $error = 'Please enter your complete delivery address.';
        elseif (strlen($password) < 8 || strlen($password) > 72) $error = 'Password must be 8 to 72 characters.';
        elseif ($password !== $confirm) $error = 'Passwords do not match.';
        elseif (($_POST['accept_terms'] ?? '') !== '1') $error = 'Please accept the Terms and Conditions and acknowledge the Privacy Policy.';
        elseif (customerRegisteredAccountByEmail($conn, $email)) $error = 'An account already exists for this email. Please log in instead.';
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $result = authVerificationStart($verificationKey, $email, $name, 'customer signup', ['name'=>$name,'email'=>$email,'contact'=>$contact,'address'=>$address,'password_hash'=>$hash,'terms_version'=>customerTermsVersion()]);
            if ($result['sent']) $success = 'We sent a 6-digit verification code to your Google/Gmail account.';
            else { unset($_SESSION[$verificationKey]); $error = 'Verification email could not be sent. Please check the configured Gmail App Password.'; }
        }
    }
}
$pendingVerification = authVerificationPending($verificationKey);
$csrfToken = customerAuthCsrfToken();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><title>Customer Sign Up - Brille Amour</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>"><link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916"><link rel="stylesheet" href="../assets/css/password-eye.css?v=<?= filemtime(__DIR__ . '/../assets/css/password-eye.css') ?>"><style>.ba-signup-consent{display:flex!important;flex-direction:row!important;align-items:flex-start;gap:10px;margin:6px 0;color:#654655;line-height:1.6;text-align:left}.ba-signup-consent input{flex:none;width:18px;min-width:18px;height:18px;min-height:18px;margin:3px 0 0;accent-color:#a83862}.ba-signup-consent a{text-decoration:underline;color:#912849}</style><link rel="stylesheet" href="../assets/css/customer-terms-modal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-terms-modal.css') ?>">  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>"><link rel="stylesheet" href="../assets/css/customer-terms-mobile-compact.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-terms-mobile-compact.css') ?>"><link rel="stylesheet" href="../assets/css/customer-mobile-auth-v2.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-mobile-auth-v2.css') ?>">
</head>
<body class="customer-auth-body customer-auth-signup"><main class="customer-auth-shell"><a class="mobile-auth-back-link" href="../index.php">Back</a><section class="mobile-auth-hero" aria-hidden="true"><h1><?= $pendingVerification ? 'Verify Account' : 'Create an Account' ?></h1><p><?= $pendingVerification ? 'Enter the verification code sent to your email.' : 'Set up your Brille Amour customer account.' ?></p><div class="mobile-auth-logo-wrap"><img src="../assets/img/image.png" alt=""></div></section><a class="auth-brand" href="../index.php"><img src="../assets/img/image.png" alt="Brille Amour logo"><span><strong>Brille Amour</strong><small>Customer Portal</small></span></a><section class="auth-card"><div class="auth-panel">
<img class="auth-logo" src="../assets/img/image.png" alt=""><div class="auth-panel-heading"><h2><?= $pendingVerification ? 'Verify Account' : 'Create Account' ?></h2><p><?= $pendingVerification ? 'Enter the code sent to your Gmail account.' : 'Create your customer account, then verify your Gmail.' ?></p></div>
<?php if ($error): ?><div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($success): ?><div class="auth-alert auth-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($pendingVerification): ?>
<form class="customer-auth-form" method="post" action="signup.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>"><input type="hidden" name="auth_step" value="verify_signup"><label><span>Verification Code</span><input type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="6-digit code" required autofocus></label><button class="auth-submit" type="submit">Verify and Create Account</button></form>
<?php else: ?>
<form class="customer-auth-form" method="post" action="signup.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>"><input type="hidden" name="auth_step" value="signup"><label><span>Full Name</span><input type="text" name="name" value="<?= htmlspecialchars($name) ?>" autocomplete="name" maxlength="150" required></label><label><span>Google/Gmail Email</span><input type="email" name="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" placeholder="name@gmail.com" required></label><label><span>Contact Number</span><input type="tel" name="contact" value="<?= htmlspecialchars($contact) ?>" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+()\-\s]{7,30}" placeholder="09XX XXX XXXX" required></label><label><span>Delivery Address</span><textarea name="address" rows="3" minlength="5" maxlength="1000" autocomplete="street-address" placeholder="House no., street, barangay, city / municipality, province" required><?= htmlspecialchars($address) ?></textarea><small class="auth-field-note">Saved to your profile and automatically filled during delivery checkout.</small></label><label><span>Password</span><div class="password-field"><input id="signupPassword" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="button" class="password-toggle" data-password-toggle="signupPassword" aria-label="Show password"></button></div></label><label><span>Confirm Password</span><div class="password-field"><input id="confirmPassword" type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="button" class="password-toggle" data-password-toggle="confirmPassword" aria-label="Show password"></button></div></label><label class="ba-signup-consent"><input type="checkbox" name="accept_terms" data-terms-consent value="1" required><span>I agree to the Terms &amp; Conditions and acknowledge the Privacy Policy.</span></label><button type="button" class="ba-terms-read-link" data-terms-open>Read Terms &amp; Privacy</button><small class="ba-terms-read-note" id="baTermsReviewHelp">Tap the checkbox to open the review modal; after reading both documents, tap it again to agree.</small><button class="auth-submit" type="submit">Send Verification Code</button></form>
<div class="auth-divider"><span>or</span></div><a class="google-signin" href="signup.php?google=1&amp;return=<?= urlencode($returnTo) ?>"><span>Continue with Google</span></a><p class="auth-switch">Already registered? <a href="login.php?return=<?= urlencode($returnTo) ?>">Login</a></p>
<?php endif; ?>
<?php $termsDocumentPrefix = '../'; require __DIR__ . '/../includes/customer_terms_modal.php'; ?>
<script src="../assets/js/customer-terms-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-terms-modal.js') ?>"></script><script src="../assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/../assets/js/password-eye.js') ?>"></script></body></html>
