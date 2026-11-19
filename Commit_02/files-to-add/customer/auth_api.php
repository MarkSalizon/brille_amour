<?php
// Customer AJAX authentication: direct password sign-in for existing accounts;
// new customer accounts still require Gmail code verification during signup.
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function customerAuthJson(bool $ok, string $message = '', array $extra = [], int $status = 0): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status > 0 ? $status : ($ok ? 200 : 422));
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) || headers_sent()) {
        return;
    }
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    error_log('Customer auth fatal error: ' . ($error['message'] ?? 'Unknown error'));
    echo json_encode(['ok' => false, 'message' => 'Customer authentication encountered a server error. Please check the PHP error log.']);
});

require_once '../includes/config.php';

if (!defined('DB_HOST')) define('DB_HOST', (string)config_env('DB_HOST', 'localhost'));
if (!defined('DB_USER')) define('DB_USER', (string)config_env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', (string)config_env('DB_PASS', ''));
if (!defined('DB_NAME')) define('DB_NAME', (string)config_env('DB_NAME', 'brille_amour'));

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    customerAuthJson(false, 'Unable to connect to the database. Check your .env database settings.', ['database_error' => true], 500);
}
$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

require_once '../includes/customer_auth.php';
require_once '../includes/auth_verification.php';

try {
    startCustomerSession();
    ensureCustomerAuthSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        customerAuthJson(false, 'This action only accepts form submissions.', [], 405);
    }

    if (customerIsLoggedIn()) {
        customerAuthJson(true, 'You are already signed in.', ['redirect' => 'customer/home.php']);
    }

    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        customerAuthJson(false, 'Your session expired. Please refresh the page and try again.', ['session_expired' => true], 419);
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $signupKey = 'customer_signup_verification';

    if ($action === 'login') {
        $email = customerNormalizeEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            customerAuthJson(false, 'Please enter a valid Google/Gmail email address.', ['mode' => 'login']);
        }
        if ($password === '') {
            customerAuthJson(false, 'Please enter your password.', ['mode' => 'login']);
        }

        $customer = customerPasswordAccountByEmail($conn, $email);
        if (!$customer || !password_verify($password, (string)($customer['password_hash'] ?? ''))) {
            customerAuthJson(false, 'Incorrect email or password.', ['mode' => 'login']);
        }
        if (($customer['status'] ?? 'active') !== 'active') {
            customerAuthJson(false, 'This customer account is currently unavailable. Please contact Brille Amour.', ['mode' => 'login']);
        }

        // The email was verified when this password account was created.
        unset($_SESSION['customer_login_verification']);
        $customerId = (int)$customer['id'];
        $stmt = $conn->prepare('UPDATE customers SET last_login_at = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();
        }
        customerLoginSession($customer, 'password');
        customerAuthJson(true, 'Login successful.', ['redirect' => 'customer/home.php']);
    }

    if ($action === 'signup') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = customerNormalizeEmail((string)($_POST['email'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($nameLength < 2 || $nameLength > 150) {
            customerAuthJson(false, 'Please enter your full name.', ['mode' => 'signup']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            customerAuthJson(false, 'Please enter a valid Google/Gmail email address.', ['mode' => 'signup']);
        }
        $addressLength = function_exists('mb_strlen') ? mb_strlen($address) : strlen($address);
        if ($addressLength < 5 || $addressLength > 1000) {
            customerAuthJson(false, 'Please enter your complete delivery address.', ['mode' => 'signup']);
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            customerAuthJson(false, 'Password must be 8 to 72 characters.', ['mode' => 'signup']);
        }
        if ($password !== $confirmPassword) {
            customerAuthJson(false, 'Passwords do not match.', ['mode' => 'signup']);
        }
        if (($_POST['accept_terms'] ?? '') !== '1') {
            customerAuthJson(false, 'Please accept the Terms and Conditions and acknowledge the Privacy Policy.', ['mode' => 'signup']);
        }
        if (customerRegisteredAccountByEmail($conn, $email)) {
            customerAuthJson(false, 'An account already exists for this email. Please log in instead.', ['mode' => 'login']);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            customerAuthJson(false, 'Unable to prepare your account. Please try again.', ['mode' => 'signup']);
        }

        $result = authVerificationStart($signupKey, $email, $name, 'customer signup', [
            'name' => $name,
            'email' => $email,
            'address' => $address,
            'password_hash' => $hash,
            'terms_version' => customerTermsVersion(),
        ]);

        if (!$result['sent']) {
            unset($_SESSION[$signupKey]);
            customerAuthJson(false, 'Verification email could not be sent. Please check the configured Gmail App Password.', ['mode' => 'signup'], 500);
        }

        customerAuthJson(true, 'We sent a 6-digit verification code to your Google/Gmail account.', [
            'pending_verification' => true,
            'verification_action' => 'verify_signup',
            'verification_email' => $email,
        ]);
    }

    if ($action === 'verify_signup') {
        $verified = authVerificationCheck($signupKey, (string)($_POST['verification_code'] ?? ''));
        if (!$verified['ok']) {
            customerAuthJson(false, $verified['error'], [
                'mode' => 'verify',
                'verification_action' => 'verify_signup',
            ]);
        }

        $payload = $verified['payload'] ?? [];
        $name = trim((string)($payload['name'] ?? ''));
        $email = customerNormalizeEmail((string)($payload['email'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $hash = (string)($payload['password_hash'] ?? '');
        $addressLength = function_exists('mb_strlen') ? mb_strlen($address) : strlen($address);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $addressLength < 5 || $addressLength > 1000 || $hash === '' || (string)($payload['terms_version'] ?? '') !== customerTermsVersion()) {
            customerAuthJson(false, 'Verification data expired. Please submit the signup form again.', ['mode' => 'signup']);
        }
        if (customerRegisteredAccountByEmail($conn, $email)) {
            customerAuthJson(false, 'An account already exists for this email. Please log in instead.', ['mode' => 'login']);
        }

        $source = 'Website Account';
        $notes = 'Customer account verified through Gmail code.';
        $stmt = $conn->prepare("INSERT INTO customers (name, email, address, password_hash, registered_at, last_login_at, source, notes, status, terms_version, terms_accepted_at, privacy_version, privacy_acknowledged_at) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, 'active', ?, NOW(), ?, NOW())");
        if (!$stmt) throw new RuntimeException('Signup query could not be prepared: ' . $conn->error);
        $termsVersion = customerTermsVersion();
        $stmt->bind_param('ssssssss', $name, $email, $address, $hash, $source, $notes, $termsVersion, $termsVersion);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Signup insert failed: ' . $message);
        }
        $customerId = (int)$conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            customerAuthJson(false, 'Your account was created, but sign-in could not be completed. Please log in.', ['mode' => 'login']);
        }

        customerLoginSession($customer, 'password');
        $_SESSION['ba_customer_welcome_pending'] = 'signup';
        customerAuthJson(true, 'Account verified and created successfully.', ['redirect' => 'customer/home.php']);
    }

    if ($action === 'cancel_verification') {
        unset($_SESSION['customer_login_verification'], $_SESSION[$signupKey]);
        customerAuthJson(true, 'Verification cancelled.', ['cancelled' => true]);
    }

    customerAuthJson(false, 'Unknown customer account action.');
} catch (Throwable $e) {
    error_log('Customer auth exception: ' . $e->getMessage());
    $message = 'Customer authentication is temporarily unavailable. Please try again.';
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $message .= ' Server detail: ' . $e->getMessage();
    }
    customerAuthJson(false, $message, ['server_error' => true], 500);
}
