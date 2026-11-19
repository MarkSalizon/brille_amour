<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/staff_roles.php';
require_once '../includes/auth_redirect.php';
require_once '../includes/messaging.php';
require_once '../includes/google_auth.php';
require_once '../includes/attendance.php';

ensureStaffRoleColumn($conn);
startPortalSession('staff');

$result = googleAuthExchangeCode('staff');
if (!$result['ok']) {
    $_SESSION['auth_error'] = $result['error'];
    header('Location: login.php');
    exit;
}

$googleEmail = strtolower(trim((string)$result['email']));

if (empty($result['verified_email'])) {
    $_SESSION['auth_error'] = 'Please use a verified Google account email.';
    header('Location: ' . (($result['mode'] ?? '') === 'signup' ? 'signup.php' : 'login.php'));
    exit;
}

if (($result['mode'] ?? '') === 'signup') {
    $pending = $_SESSION['staff_google_signup_pending'] ?? null;
    unset($_SESSION['staff_google_signup_pending']);

    if (!is_array($pending) || $googleEmail !== strtolower((string)($pending['email'] ?? ''))) {
        $_SESSION['auth_error'] = 'Please verify using the same Google account email you entered in the signup form.';
        header('Location: signup.php');
        exit;
    }

    $email = trim((string)$pending['email']);
    $fullName = trim((string)$pending['full_name']);
    $staffRole = trim((string)$pending['staff_role']);
    $hash = (string)($pending['password_hash'] ?? '');
    if ($hash === '') {
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    if (!isValidStaffRole($staffRole)) {
        $_SESSION['auth_error'] = 'Verification failed. Please submit the signup form again.';
        header('Location: signup.php');
        exit;
    }

    $chk = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
    $chk->bind_param('ss', $email, $email);
    $chk->execute();

    if ($chk->get_result()->num_rows > 0) {
        $_SESSION['auth_error'] = 'An account with this email already exists.';
        header('Location: signup.php');
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO users (username,password,full_name,email,role,staff_role,status) VALUES (?,?,?,?,'staff',?,'inactive')");
    $stmt->bind_param('sssss', $email, $hash, $fullName, $email, $staffRole);

    if (!$stmt->execute()) {
        $_SESSION['auth_error'] = 'Error submitting registration. Please try again.';
        header('Location: signup.php');
        exit;
    }

    notifyAccessRequest($conn, (int)$conn->insert_id);
    $_SESSION['ba_team_signup_welcome_pending'] = true;
    $_SESSION['auth_success'] = 'Google account verified. Your staff account is waiting for admin approval.';
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['staff_google_login_pending'] ?? null;
unset($_SESSION['staff_google_login_pending']);

if (!is_array($pending) || $googleEmail !== strtolower((string)($pending['email'] ?? ''))) {
    $_SESSION['auth_error'] = 'Please verify using the same Google account email on your staff account.';
    header('Location: login.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$pending['id'];
$_SESSION['username'] = (string)$pending['username'];
$_SESSION['full_name'] = (string)$pending['full_name'];
$_SESSION['role'] = 'staff';
staffTimeIn($conn, (int)$pending['id'], 'login');

$_SESSION['ba_team_welcome_pending'] = true;
        header('Location: dashboard.php');
exit;
