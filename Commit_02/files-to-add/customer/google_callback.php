<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/google_auth.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);

$result = googleAuthExchangeCode('customer');
if (!$result['ok']) {
    $_SESSION['customer_auth_error'] = $result['error'];
    header('Location: ../index.php?auth=login');
    exit;
}

if (empty($result['verified_email'])) {
    $_SESSION['customer_auth_error'] = 'Please use a verified Google account email.';
    header('Location: ../index.php?auth=login');
    exit;
}

$email = strtolower(trim((string)$result['email']));
$name = trim((string)($result['name'] ?? ''));
$googleSub = trim((string)($result['sub'] ?? ''));
$avatar = trim((string)($result['picture'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $googleSub === '') {
    $_SESSION['customer_auth_error'] = 'Google did not return a complete verified customer profile.';
    header('Location: ../index.php?auth=login');
    exit;
}
if ($name === '') {
    $name = strstr($email, '@', true) ?: 'Customer';
}

$createdNewCustomerAccount = false;
// Resolve an established Google identity first. Only link an unlinked account
// with the SAME verified email; do not overwrite a different Google identity.
$stmt = $conn->prepare("SELECT * FROM customers WHERE google_sub = ? LIMIT 1");
$stmt->bind_param('s', $googleSub);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE LOWER(TRIM(email)) = ? ORDER BY (password_hash IS NOT NULL) DESC, id ASC LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($customer) {
    if (($customer['status'] ?? 'active') !== 'active') {
        $_SESSION['customer_auth_error'] = 'This customer account is unavailable. Please contact Brille Amour.';
        header('Location: ../index.php?auth=login');
        exit;
    }
    if (!empty($customer['google_sub']) && !hash_equals((string)$customer['google_sub'], $googleSub)) {
        $_SESSION['customer_auth_error'] = 'This email is linked to a different Google account. Please sign in using the original account.';
        header('Location: ../index.php?auth=login');
        exit;
    }
    $customerId = (int)$customer['id'];
    $stmt = $conn->prepare("UPDATE customers SET name = ?, email = ?, google_sub = ?, google_avatar = ?, google_verified = 1, last_login_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssssi', $name, $email, $googleSub, $avatar, $customerId);
    $stmt->execute();
    $stmt->close();
} else {
    $source = 'Other';
    $notes = 'Customer account created through Google sign-in.';
    $stmt = $conn->prepare("INSERT INTO customers (name, email, google_sub, google_avatar, google_verified, last_login_at, source, notes) VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)");
    $stmt->bind_param('ssssss', $name, $email, $googleSub, $avatar, $source, $notes);
    $stmt->execute();
    $customerId = (int)$conn->insert_id;
    $stmt->close();
    $createdNewCustomerAccount = true;
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    $_SESSION['customer_auth_error'] = 'Unable to finish customer sign in. Please try again.';
    header('Location: ../index.php?auth=login');
    exit;
}

customerLoginSession($customer, 'google');
$returnTo = customerSafeReturn((string)($_SESSION['customer_google_login_return'] ?? 'home.php'));
unset($_SESSION['customer_google_login_return']);
if ($createdNewCustomerAccount) {
    $_SESSION['ba_customer_welcome_pending'] = 'signup';
    if (trim((string)($customer['address'] ?? '')) === '') {
        $_SESSION['ba_customer_address_setup_required'] = 1;
        $_SESSION['ba_customer_address_setup_return'] = $returnTo;
        header('Location: profile.php?setup=address');
        exit;
    }
}
header('Location: ' . $returnTo);
exit;
