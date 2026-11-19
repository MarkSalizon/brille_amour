<?php

function startCustomerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('customer_order_session');
    $sessionPath = dirname(__DIR__) . '/.customer_sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0755, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    session_start();
}

function customerAuthColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function customerAuthIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function ensureCustomerAuthSchema(mysqli $conn): void
{
    // Authentication should only require the customer account schema. Keep this
    // migration defensive so login/signup is not blocked by unrelated order-table
    // differences from older Brille Amour database versions.
    $createCustomers = "
        CREATE TABLE IF NOT EXISTS customers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact VARCHAR(100) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'Other',
            notes TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            archived_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customers_status (status),
            INDEX idx_customers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!$conn->query($createCustomers)) {
        throw new RuntimeException('Unable to prepare customer accounts table: ' . $conn->error);
    }

    // Add only the columns used directly by customer authentication. Avoid AFTER
    // clauses because older copies can have a different customer-column order.
    $customerColumns = [
        'email' => "ALTER TABLE customers ADD COLUMN email VARCHAR(150) DEFAULT NULL",
        'password_hash' => "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL",
        'registered_at' => "ALTER TABLE customers ADD COLUMN registered_at DATETIME DEFAULT NULL",
        'google_sub' => "ALTER TABLE customers ADD COLUMN google_sub VARCHAR(255) DEFAULT NULL",
        'google_avatar' => "ALTER TABLE customers ADD COLUMN google_avatar VARCHAR(500) DEFAULT NULL",
        'google_verified' => "ALTER TABLE customers ADD COLUMN google_verified TINYINT(1) NOT NULL DEFAULT 0",
        'last_login_at' => "ALTER TABLE customers ADD COLUMN last_login_at DATETIME DEFAULT NULL",
        'terms_version' => "ALTER TABLE customers ADD COLUMN terms_version VARCHAR(32) DEFAULT NULL",
        'terms_accepted_at' => "ALTER TABLE customers ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL",
        'privacy_version' => "ALTER TABLE customers ADD COLUMN privacy_version VARCHAR(32) DEFAULT NULL",
        'privacy_acknowledged_at' => "ALTER TABLE customers ADD COLUMN privacy_acknowledged_at DATETIME DEFAULT NULL",
        'source' => "ALTER TABLE customers ADD COLUMN source VARCHAR(50) DEFAULT 'Other'",
        'notes' => "ALTER TABLE customers ADD COLUMN notes TEXT DEFAULT NULL",
        'status' => "ALTER TABLE customers ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'",
    ];
    foreach ($customerColumns as $column => $sql) {
        if (!customerAuthColumnExists($conn, 'customers', $column)) {
            if (!$conn->query($sql)) {
                throw new RuntimeException('Unable to add customer account field ' . $column . ': ' . $conn->error);
            }
        }
    }

    if (!customerAuthIndexExists($conn, 'customers', 'idx_customers_google_sub')) {
        if (!$conn->query("ALTER TABLE customers ADD INDEX idx_customers_google_sub (google_sub)")) {
            throw new RuntimeException('Unable to add Google customer index: ' . $conn->error);
        }
    }
    if (!customerAuthIndexExists($conn, 'customers', 'idx_customers_email')) {
        if (!$conn->query("ALTER TABLE customers ADD INDEX idx_customers_email (email)")) {
            throw new RuntimeException('Unable to add customer email index: ' . $conn->error);
        }
    }

    // Order linkage is useful elsewhere but it must never stop login/signup.
    // Apply it only when the orders table exists, and add at the end rather than
    // assuming a specific neighboring column from one database version.
    try {
        if (customerAuthColumnExists($conn, 'orders', 'id')) {
            if (!customerAuthColumnExists($conn, 'orders', 'customer_id')) {
                @$conn->query("ALTER TABLE orders ADD COLUMN customer_id INT(11) DEFAULT NULL");
            }
            if (customerAuthColumnExists($conn, 'orders', 'customer_id') && !customerAuthIndexExists($conn, 'orders', 'idx_orders_customer_id')) {
                @$conn->query("ALTER TABLE orders ADD INDEX idx_orders_customer_id (customer_id)");
            }
            if (!customerAuthColumnExists($conn, 'orders', 'fulfillment_method')) {
                @$conn->query("ALTER TABLE orders ADD COLUMN fulfillment_method VARCHAR(20) NOT NULL DEFAULT 'pickup'");
            }
            if (!customerAuthColumnExists($conn, 'orders', 'delivery_address')) {
                @$conn->query("ALTER TABLE orders ADD COLUMN delivery_address TEXT DEFAULT NULL");
            }
        }
    } catch (Throwable $ignored) {
        error_log('Non-blocking customer order-link schema update skipped: ' . $ignored->getMessage());
    }
}

/** Increment version only when the published legal documents materially change. */
function customerTermsVersion(): string { return '2026-10-01'; }

function customerHasAcceptedTerms(array $customer): bool
{
    $version = customerTermsVersion();
    return (string)($customer['terms_version'] ?? '') === $version
        && !empty($customer['terms_accepted_at'])
        && (string)($customer['privacy_version'] ?? '') === $version
        && !empty($customer['privacy_acknowledged_at']);
}

function customerRecordTermsAcceptance(mysqli $conn, int $customerId): bool
{
    if ($customerId <= 0) return false;
    $version = customerTermsVersion();
    $stmt = $conn->prepare("UPDATE customers SET terms_version = ?, terms_accepted_at = NOW(), privacy_version = ?, privacy_acknowledged_at = NOW() WHERE id = ? AND status = 'active'");
    if (!$stmt) throw new RuntimeException('Customer terms update unavailable.');
    $stmt->bind_param('ssi', $version, $version, $customerId);
    $ok = $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $ok && $changed === 1;
}

function customerRequireTermsRedirect(array $customer): void
{
    if (customerHasAcceptedTerms($customer)) return;
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Redirect customer-facing HTML pages only; API endpoints return JSON errors
    // independently. Do not redirect the agreement or authentication callbacks.
    $customerPages = [
        'home.php', 'shop.php', 'cart.php', 'orders.php', 'order.php',
        'preorders.php', 'profile.php', 'settings.php', 'notifications.php',
        'messaging.php', 'bill.php', 'payment_receipt.php'
    ];
    $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($page, $customerPages, true) && strpos($path, '/customer/') !== false && !headers_sent()) {
        header('Location: terms-accept.php', true, 303);
        exit;
    }
}

function customerIsLoggedIn(): bool
{
    $method = (string)($_SESSION['customer_auth'] ?? '');
    return !empty($_SESSION['customer_id']) && in_array($method, ['password', 'google'], true);
}

function customerAuthMethod(): string
{
    return customerIsLoggedIn() ? (string)($_SESSION['customer_auth'] ?? '') : '';
}

function customerSessionProfile(): ?array
{
    if (!customerIsLoggedIn()) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['customer_id'],
        'name' => (string)($_SESSION['customer_name'] ?? ''),
        'email' => (string)($_SESSION['customer_email'] ?? ''),
        'avatar' => (string)($_SESSION['customer_avatar'] ?? ''),
        'verified' => !empty($_SESSION['customer_google_verified']),
        'auth_method' => (string)($_SESSION['customer_auth'] ?? ''),
    ];
}

function loadSignedInCustomer(mysqli $conn): ?array
{
    if (!customerIsLoggedIn()) {
        return null;
    }

    $id = (int)$_SESSION['customer_id'];
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        customerLogoutSession();
        return null;
    }

    $_SESSION['customer_name'] = (string)$customer['name'];
    $_SESSION['customer_email'] = (string)($customer['email'] ?? '');
    $_SESSION['customer_avatar'] = (string)($customer['google_avatar'] ?? '');
    $_SESSION['customer_google_verified'] = !empty($customer['google_verified']);
    customerRequireTermsRedirect($customer);
    return $customer;
}

function customerLoginSession(array $customer, string $method = 'google'): void
{
    if (!in_array($method, ['password', 'google'], true)) {
        $method = 'password';
    }
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$customer['id'];
    $_SESSION['customer_name'] = (string)$customer['name'];
    $_SESSION['customer_email'] = (string)($customer['email'] ?? '');
    $_SESSION['customer_avatar'] = (string)($customer['google_avatar'] ?? '');
    $_SESSION['customer_google_verified'] = !empty($customer['google_verified']);
    $_SESSION['customer_auth'] = $method;
    $_SESSION['ba_customer_welcome_pending'] = true;
    $_SESSION['role'] = 'customer';

    // A stable negative messaging id keeps customer notifications/messages
    // attached to the account across logins without colliding with staff ids.
    $chatUserId = customerMessagingUserId((int)$customer['id']);
    $_SESSION['customer_chat_user_id'] = $chatUserId;
    $_SESSION['customer_support_thread_key'] = 'customer-' . abs($chatUserId);
    $_SESSION['customer_direct_admin_chat'] = true;
    if (empty($_SESSION['customer_order_csrf'])) {
        $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
    }
}

function customerRememberRecentVerification(array $customer, int $ttlSeconds = 1800): void
{
    $customerId = (int)($customer['id'] ?? 0);
    $email = customerNormalizeEmail((string)($customer['email'] ?? ''));
    if ($customerId <= 0 || $email === '') {
        return;
    }

    $ttlSeconds = max(60, min(1800, $ttlSeconds));
    $_SESSION['customer_recent_verification'] = [
        'customer_id' => $customerId,
        'email' => $email,
        'verified_at' => time(),
        'expires_at' => time() + $ttlSeconds,
    ];
}

function customerHasRecentVerification(array $customer): bool
{
    $trusted = $_SESSION['customer_recent_verification'] ?? null;
    if (!is_array($trusted)) {
        return false;
    }

    if ((int)($trusted['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['customer_recent_verification']);
        return false;
    }

    $customerId = (int)($customer['id'] ?? 0);
    $email = customerNormalizeEmail((string)($customer['email'] ?? ''));
    $trustedEmail = customerNormalizeEmail((string)($trusted['email'] ?? ''));

    return $customerId > 0
        && $customerId === (int)($trusted['customer_id'] ?? 0)
        && $email !== ''
        && $trustedEmail !== ''
        && hash_equals($trustedEmail, $email);
}

function customerMessagingUserId(int $customerId): int
{
    $customerId = max(1, $customerId);
    return -(1000000000 + $customerId);
}

function customerSyncRealtimeContext(mysqli $conn, array $customer): array
{
    require_once __DIR__ . '/messaging.php';
    ensureMessagingSchema($conn);

    $customerId = (int)($customer['id'] ?? 0);
    $chatUserId = customerMessagingUserId($customerId);
    $_SESSION['role'] = 'customer';
    $_SESSION['customer_chat_user_id'] = $chatUserId;
    $_SESSION['customer_support_thread_key'] = threadKeyForCustomerSession($chatUserId);
    $_SESSION['customer_direct_admin_chat'] = true;
    if (empty($_SESSION['customer_order_csrf'])) {
        $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
    }

    $orderIds = [];
    $name = trim((string)($customer['name'] ?? ''));
    $contact = trim((string)($customer['contact'] ?? ''));
    if ($customerId > 0) {
        if ($contact !== '' && $name !== '') {
            $stmt = $conn->prepare("SELECT id FROM orders WHERE status <> 'archived' AND (customer_id=? OR (customer_id IS NULL AND LOWER(TRIM(customer_name))=LOWER(TRIM(?)) AND customer_contact=?)) ORDER BY id DESC LIMIT 200");
            $stmt->bind_param('iss', $customerId, $name, $contact);
        } else {
            $stmt = $conn->prepare("SELECT id FROM orders WHERE status <> 'archived' AND customer_id=? ORDER BY id DESC LIMIT 200");
            $stmt->bind_param('i', $customerId);
        }
        $stmt->execute();
        $rows = $stmt->get_result();
        while ($row = $rows->fetch_assoc()) {
            $orderIds[] = (int)$row['id'];
        }
        $stmt->close();
    }
    $_SESSION['customer_order_ids'] = array_values(array_unique($orderIds));
    $_SESSION['csrf_token'] = (string)$_SESSION['customer_order_csrf'];

    return [
        'user_id' => $chatUserId,
        'csrf' => (string)$_SESSION['customer_order_csrf'],
        'notification_unread' => unreadNotificationCount($conn, $chatUserId),
        'message_unread' => unreadChatTotal($conn, $chatUserId),
    ];
}

function customerLogoutSession(): void
{
    foreach ([
        'customer_id', 'customer_name', 'customer_email', 'customer_avatar',
        'customer_google_verified', 'customer_auth', 'customer_google_login_return',
        'customer_auth_error', 'customer_auth_success', 'customer_auth_csrf',
        'ba_customer_welcome_pending', 'customer_chat_user_id', 'customer_support_thread_key', 'customer_order_ids',
        'customer_order_csrf', 'customer_direct_admin_chat', 'csrf_token',
        'google_oauth_state'
    ] as $key) {
        unset($_SESSION[$key]);
    }
}

function customerSafeReturn(string $target, string $fallback = 'home.php'): string
{
    $target = trim($target);
    if ($target === '' || preg_match('#^(?:https?:)?//#i', $target) || str_contains($target, "\n") || str_contains($target, "\r")) {
        return $fallback;
    }
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+(?:\?[a-zA-Z0-9_\-\.&=%]*)?$/', $target)) {
        return $fallback;
    }
    return $target;
}

function customerAuthCsrfToken(): string
{
    if (empty($_SESSION['customer_auth_csrf'])) {
        $_SESSION['customer_auth_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['customer_auth_csrf'];
}

function customerAuthCsrfValid(?string $token): bool
{
    $stored = (string)($_SESSION['customer_auth_csrf'] ?? '');
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

function customerNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function customerRegisteredAccountByEmail(mysqli $conn, string $email): ?array
{
    $email = customerNormalizeEmail($email);
    $stmt = $conn->prepare("\n        SELECT * FROM customers\n        WHERE LOWER(TRIM(email)) = ?\n          AND (password_hash IS NOT NULL OR google_sub IS NOT NULL)\n        ORDER BY (google_sub IS NOT NULL) DESC, (password_hash IS NOT NULL) DESC, id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $customer;
}

function customerPasswordAccountByEmail(mysqli $conn, string $email): ?array
{
    $email = customerNormalizeEmail($email);
    $stmt = $conn->prepare("\n        SELECT * FROM customers\n        WHERE LOWER(TRIM(email)) = ?\n          AND password_hash IS NOT NULL\n          AND password_hash <> ''\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $customer;
}
