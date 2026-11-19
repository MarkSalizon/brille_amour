<?php
require_once __DIR__ . '/customer_email_notifications.php';
/**
 * messaging.php
 * ─────────────────────────────────────────────────────────
 * Notifications and per-order chat shared by both portals.
 *
 * A thread is identified by a single string key: 'general',
 * 'order-<id>' for order chats, 'user-<id>-<id>' for team DMs,
 * or 'customer-<session>' for public/customer chatbot inquiries.
 */

require_once __DIR__ . '/functions.php'; // timeAgo(), appDateTime()
if (file_exists(__DIR__ . '/staff_roles.php')) {
    require_once __DIR__ . '/staff_roles.php';
}

function ensureMessagingSchema(mysqli $conn): void {
    static $ready = false;

    if ($ready) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'order',
            title VARCHAR(190) NOT NULL,
            body VARCHAR(255) NULL,
            thread_key VARCHAR(40) NULL,
            order_id INT NULL,
            priority ENUM('normal','warning','urgent') NOT NULL DEFAULT 'normal',
            reference_type VARCHAR(40) NULL,
            reference_id INT NULL,
            unique_key VARCHAR(120) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_notifications_unread (user_id, is_read),
            INDEX idx_notifications_recent (user_id, created_at),
            INDEX idx_notifications_filter (user_id, type, priority, is_deleted),
            UNIQUE KEY uniq_notification_user_event (user_id, unique_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensureMessagingColumn($conn, 'notifications', 'user_id', "ALTER TABLE notifications ADD COLUMN user_id INT NOT NULL AFTER id");
    ensureMessagingColumn($conn, 'notifications', 'type', "ALTER TABLE notifications ADD COLUMN type VARCHAR(40) NOT NULL DEFAULT 'order' AFTER user_id");
    ensureMessagingColumn($conn, 'notifications', 'title', "ALTER TABLE notifications ADD COLUMN title VARCHAR(190) NOT NULL DEFAULT '' AFTER type");
    ensureMessagingColumn($conn, 'notifications', 'body', "ALTER TABLE notifications ADD COLUMN body VARCHAR(255) NULL AFTER title");
    ensureMessagingColumn($conn, 'notifications', 'thread_key', "ALTER TABLE notifications ADD COLUMN thread_key VARCHAR(40) NULL AFTER body");
    ensureMessagingColumn($conn, 'notifications', 'order_id', "ALTER TABLE notifications ADD COLUMN order_id INT NULL AFTER thread_key");
    ensureMessagingColumn($conn, 'notifications', 'priority', "ALTER TABLE notifications ADD COLUMN priority ENUM('normal','warning','urgent') NOT NULL DEFAULT 'normal' AFTER order_id");
    ensureMessagingColumn($conn, 'notifications', 'reference_type', "ALTER TABLE notifications ADD COLUMN reference_type VARCHAR(40) NULL AFTER priority");
    ensureMessagingColumn($conn, 'notifications', 'reference_id', "ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER reference_type");
    ensureMessagingColumn($conn, 'notifications', 'unique_key', "ALTER TABLE notifications ADD COLUMN unique_key VARCHAR(120) NULL AFTER reference_id");
    ensureMessagingColumn($conn, 'notifications', 'is_read', "ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER unique_key");
    ensureMessagingColumn($conn, 'notifications', 'is_deleted', "ALTER TABLE notifications ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
    ensureMessagingColumn($conn, 'notifications', 'created_at', "ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_deleted");
    ensureMessagingColumn($conn, 'notifications', 'read_at', "ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER created_at");
    ensureMessagingIndex($conn, 'notifications', 'idx_notifications_unread', "ALTER TABLE notifications ADD INDEX idx_notifications_unread (user_id, is_read)");
    ensureMessagingIndex($conn, 'notifications', 'idx_notifications_recent', "ALTER TABLE notifications ADD INDEX idx_notifications_recent (user_id, created_at)");
    ensureMessagingIndex($conn, 'notifications', 'idx_notifications_filter', "ALTER TABLE notifications ADD INDEX idx_notifications_filter (user_id, type, priority, is_deleted)");
    ensureMessagingIndex($conn, 'notifications', 'uniq_notification_user_event', "ALTER TABLE notifications ADD UNIQUE KEY uniq_notification_user_event (user_id, unique_key)");

    ensureMessagingColumn($conn, 'orders', 'assigned_to', "ALTER TABLE orders ADD COLUMN assigned_to INT NULL AFTER notes");
    ensureMessagingColumn($conn, 'orders', 'created_by', "ALTER TABLE orders ADD COLUMN created_by INT NULL AFTER assigned_to");

    $conn->query("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_key VARCHAR(40) NOT NULL,
            order_id INT NULL,
            sender_id INT NOT NULL,
            sender_role ENUM('admin','staff','customer','system','bot') NOT NULL DEFAULT 'staff',
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat_thread (thread_key, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ensureMessagingColumn($conn, 'chat_messages', 'sender_role', "ALTER TABLE chat_messages ADD COLUMN sender_role ENUM('admin','staff','customer','system','bot') NOT NULL DEFAULT 'staff' AFTER sender_id");
    $roleType = $conn->query("SHOW COLUMNS FROM chat_messages LIKE 'sender_role'");
    $roleColumn = $roleType ? $roleType->fetch_assoc() : null;
    if ($roleColumn && strpos((string)$roleColumn['Type'], "'bot'") === false) {
        $conn->query("ALTER TABLE chat_messages MODIFY sender_role ENUM('admin','staff','customer','system','bot') NOT NULL DEFAULT 'staff'");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS chat_reads (
            user_id INT NOT NULL,
            thread_key VARCHAR(40) NOT NULL,
            last_read_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, thread_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS chat_thread_states (
            user_id INT NOT NULL,
            thread_key VARCHAR(40) NOT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, thread_key),
            INDEX idx_chat_thread_states_user (user_id, is_archived, is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

function ensureMessagingColumn(mysqli $conn, string $table, string $column, string $alterSql): void {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $db = $conn->real_escape_string(DB_NAME);
    $exists = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$table}' AND COLUMN_NAME='{$column}' LIMIT 1");
    if ($exists && $exists->num_rows === 0) {
        $conn->query($alterSql);
    }
}

function ensureMessagingIndex(mysqli $conn, string $table, string $index, string $alterSql): void {
    $table = $conn->real_escape_string($table);
    $index = $conn->real_escape_string($index);
    $db = $conn->real_escape_string(DB_NAME);
    $exists = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$table}' AND INDEX_NAME='{$index}' LIMIT 1");
    if ($exists && $exists->num_rows === 0) {
        $conn->query($alterSql);
    }
}

/* ───────────────── thread keys ───────────────── */

function threadKeyForOrder(int $orderId): string {
    return 'order-' . $orderId;
}

function orderIdFromThreadKey(string $threadKey): ?int {
    if (preg_match('/^order-(\d+)$/', $threadKey, $m)) {
        return (int)$m[1];
    }

    return null;
}

function threadKeyForUsers(int $firstUserId, int $secondUserId): string {
    $ids = [$firstUserId, $secondUserId];
    sort($ids, SORT_NUMERIC);

    return 'user-' . $ids[0] . '-' . $ids[1];
}

function userIdsFromThreadKey(string $threadKey): array {
    if (preg_match('/^user-(\d+)-(\d+)$/', $threadKey, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }

    return [];
}

function threadKeyForCustomerSession(int $customerSessionId): string {
    return 'customer-' . abs($customerSessionId);
}

function customerSessionIdFromThreadKey(string $threadKey): ?int {
    if (preg_match('/^customer-(\d{6,12})$/', $threadKey, $m)) {
        return -(int)$m[1];
    }

    return null;
}


/** Legacy Brille Bot inbox mirrors only; never a registered customer account. */
function isLegacyBotInquiryThreadKey(string $threadKey): bool {
    $sessionId = customerSessionIdFromThreadKey($threadKey);
    if ($sessionId === null) return false;
    $raw = abs($sessionId);
    return $raw >= 700000001 && $raw < 1000000000;
}

function registeredCustomerIdFromMessagingUserId(int $messagingUserId): ?int {
    $raw = abs($messagingUserId);
    if ($messagingUserId < 0 && $raw >= 1000000001 && $raw < 1500000000) {
        return $raw - 1000000000;
    }
    return null;
}

function registeredCustomerIdFromThreadKey(string $threadKey): ?int {
    $messagingUserId = customerSessionIdFromThreadKey($threadKey);
    return $messagingUserId ? registeredCustomerIdFromMessagingUserId($messagingUserId) : null;
}

function registeredCustomerMessagingUserId(int $customerId): int {
    return -(1000000000 + max(1, $customerId));
}

function registeredCustomerIdForOrder(mysqli $conn, int $orderId): ?int {
    $stmt = $conn->prepare("SELECT customer_id, customer_name, customer_contact FROM orders WHERE id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) return null;
    $customerId = (int)($order['customer_id'] ?? 0);
    if ($customerId > 0) return $customerId;

    $name = trim((string)($order['customer_name'] ?? ''));
    $contact = trim((string)($order['customer_contact'] ?? ''));
    if ($name === '' || $contact === '') return null;
    $table = $conn->query("SHOW TABLES LIKE 'customers'");
    if (!$table || $table->num_rows === 0) return null;
    $stmt = $conn->prepare("SELECT id FROM customers WHERE status='active' AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND contact=? AND (password_hash IS NOT NULL OR google_sub IS NOT NULL) ORDER BY id ASC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('ss', $name, $contact);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

/** Rejects anything that is not 'general', 'order-<id>', 'user-<id>-<id>', or 'customer-<session>'. */
function validThreadKey(string $threadKey): ?string {
    if ($threadKey === 'general') {
        return 'general';
    }

    $orderId = orderIdFromThreadKey($threadKey);
    if ($orderId) {
        return threadKeyForOrder($orderId);
    }

    $userIds = userIdsFromThreadKey($threadKey);
    if (count($userIds) === 2 && $userIds[0] > 0 && $userIds[1] > 0 && $userIds[0] !== $userIds[1]) {
        return threadKeyForUsers($userIds[0], $userIds[1]);
    }

    $customerSessionId = customerSessionIdFromThreadKey($threadKey);
    if ($customerSessionId) {
        return threadKeyForCustomerSession($customerSessionId);
    }

    return null;
}

/* ───────────────── notifications ───────────────── */

/**
 * Fan a new-order notification out to every active user except the one who
 * recorded it. Best-effort: the order is already committed by the time this
 * runs, so a messaging failure must not surface as an order failure.
 */
function createNotification(
    mysqli $conn,
    int $userId,
    string $type,
    string $title,
    string $body = '',
    string $priority = 'normal',
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $threadKey = null,
    ?int $orderId = null,
    ?string $uniqueKey = null
): void {
    ensureMessagingSchema($conn);

    $priority = in_array($priority, ['normal', 'warning', 'urgent'], true) ? $priority : 'normal';
    $body = mb_substr(trim($body), 0, 255);
    $title = mb_substr(trim($title), 0, 190);

    if ($title === '') {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications
            (user_id, type, title, body, thread_key, order_id, priority, reference_type, reference_id, unique_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title=VALUES(title),
            body=VALUES(body),
            priority=VALUES(priority),
            is_deleted=0,
            created_at=CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('issssissis', $userId, $type, $title, $body, $threadKey, $orderId, $priority, $referenceType, $referenceId, $uniqueKey);
    $stmt->execute();
    $stmt->close();
}

function activeAdminIds(mysqli $conn, int $excludeUserId = 0): array {
    $exclude = $excludeUserId > 0 ? "AND id <> {$excludeUserId}" : '';
    $rows = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' {$exclude}");
    $ids = [];
    while ($rows && $row = $rows->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }

    return $ids;
}

function notificationsEnabled(mysqli $conn, string $settingKey): bool {
    if (!function_exists('getSettingBool') && file_exists(__DIR__ . '/admin_settings.php')) {
        require_once __DIR__ . '/admin_settings.php';
    }

    return function_exists('getSettingBool') ? getSettingBool($conn, $settingKey, true) : true;
}

function notifyNewOrder(mysqli $conn, int $orderId, array $order, int $actorId): void {
    ensureMessagingSchema($conn);
    if (!notificationsEnabled($conn, 'notify_new_order')) {
        return;
    }

    $title = 'New order ' . ($order['order_number'] ?? '');

    // Order fields arrive already run through sanitize(), so decode back to
    // plain text — the widget escapes at output.
    $parts = array_filter([
        html_entity_decode((string)($order['customer_name'] ?? ''), ENT_QUOTES),
        html_entity_decode((string)($order['order_type'] ?? ''), ENT_QUOTES),
    ]);
    $body = implode(' - ', $parts);

    if (!empty($order['quantity'])) {
        $body .= ' x' . (int)$order['quantity'];
    }

    $threadKey = threadKeyForOrder($orderId);

    $recipientIds = activeAdminIds($conn, $actorId);
    $assignedTo = !empty($order['assigned_to']) ? (int)$order['assigned_to'] : 0;
    if ($assignedTo > 0 && $assignedTo !== $actorId) {
        $recipientIds[] = $assignedTo;
    }

    foreach (array_unique($recipientIds) as $userId) {
        createNotification($conn, $userId, 'order', $title, $body, 'normal', 'order', $orderId, $threadKey, $orderId);
    }

    // Seed the thread so the conversation opens with the order on record.
    postChatMessage($conn, $threadKey, 0, 'system', $title . ($body ? ' - ' . $body : ''));

    // The registered customer also receives an email receipt for the new order.
    emailCustomerNewOrder($conn, $orderId);
}

/** Notify every active admin that a manual payment receipt is ready for review. */
function notifyGcashPaymentSubmission(
    mysqli $conn,
    int $paymentId,
    string $referenceNumber,
    float $amount,
    string $customerName
): void {
    ensureMessagingSchema($conn);

    $title = 'Payment submitted';
    $body = trim($customerName) . ' - Ref ' . $referenceNumber . ' - PHP ' . number_format($amount, 2);
    $uniqueKey = 'gcash-payment-' . $paymentId;

    foreach (activeAdminIds($conn) as $userId) {
        createNotification(
            $conn,
            $userId,
            'payment',
            $title,
            $body,
            'warning',
            'payment',
            $paymentId,
            null,
            null,
            $uniqueKey . '-admin-' . $userId
        );
    }

    // Customer receives a Gmail confirmation that the payment is pending review.
    emailCustomerPaymentSubmitted($conn, $paymentId);
}

/**
 * Announce a production stage change to everyone else. Drives the topbar bell
 * and the live toast on the Production Board. Best-effort, exactly like
 * notifyNewOrder: the move is already committed by the time this runs.
 */
function notifyOrderStageChange(
    mysqli $conn,
    int $orderId,
    string $orderNumber,
    string $fromStatus,
    string $toStatus,
    int $actorId
): void {
    ensureMessagingSchema($conn);
    if (!notificationsEnabled($conn, 'notify_status_change')) {
        return;
    }

    $labels = function_exists('getProductionStageLabels') ? getProductionStageLabels() : [];
    $label = static function (string $status) use ($labels): string {
        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    };

    $actorName = 'Someone';
    $who = $conn->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
    if ($who) {
        $who->bind_param('i', $actorId);
        $who->execute();
        $actorName = (string)($who->get_result()->fetch_assoc()['full_name'] ?? 'Someone');
        $who->close();
    }

    $title     = ($orderNumber !== '' ? $orderNumber : 'Order') . ' moved to ' . $label($toStatus);
    $body      = $actorName . ' - from ' . $label($fromStatus);
    $threadKey = threadKeyForOrder($orderId);
    $priority  = in_array($toStatus, ['cancelled'], true) ? 'urgent' : ($toStatus === 'quality_check' ? 'warning' : 'normal');

    $recipientIds = activeAdminIds($conn, $actorId);
    $assigned = $conn->prepare("SELECT assigned_to FROM orders WHERE id=? LIMIT 1");
    if ($assigned) {
        $assigned->bind_param('i', $orderId);
        $assigned->execute();
        $assignedTo = (int)($assigned->get_result()->fetch_assoc()['assigned_to'] ?? 0);
        $assigned->close();
        if ($assignedTo > 0 && $assignedTo !== $actorId) {
            $recipientIds[] = $assignedTo;
        }
    }

    foreach (array_unique($recipientIds) as $userId) {
        createNotification($conn, $userId, 'production', $title, $body, $priority, 'order', $orderId, $threadKey, $orderId);
    }

    // Incoming work appears in the eligible staff's inbox as an invitation,
    // not as a task automatically accepted on their behalf.
    $targetRole = $toStatus === 'in_production' ? 'production' : ($toStatus === 'quality_check' ? 'quality_check' : null);
    if ($targetRole) {
        if (function_exists('ensureStaffRoleColumn')) ensureStaffRoleColumn($conn);
        $invite = $conn->prepare("SELECT id FROM users WHERE role='staff' AND status='active' AND staff_role=?");
        if ($invite) {
            $invite->bind_param('s', $targetRole);
            $invite->execute();
            foreach ($invite->get_result()->fetch_all(MYSQLI_ASSOC) as $staffRecipient) {
                createNotification($conn, (int)$staffRecipient['id'], 'production',
                    'Order awaiting acceptance: ' . $orderNumber,
                    'A new ' . $label($toStatus) . ' order is in your stage. Open the Production Board to accept or decline it.',
                    'normal', 'order', $orderId, $threadKey, $orderId,
                    'stage-invite-' . $toStatus . '-' . $orderId . '-' . (int)$staffRecipient['id']);
            }
            $invite->close();
        }
    }

    // Registered customers receive the same production movement in their
    // account notification center in near real time.
    $customerId = registeredCustomerIdForOrder($conn, $orderId);
    if ($customerId) {
        $customerUserId = registeredCustomerMessagingUserId($customerId);
        $customerTitle = 'Order ' . ($orderNumber !== '' ? $orderNumber : '#' . $orderId) . ': ' . $label($toStatus);
        $fulfillment = 'pickup';
        $deliveryCheck = $conn->prepare('SELECT fulfillment_method FROM orders WHERE id=? LIMIT 1');
        if ($deliveryCheck) { $deliveryCheck->bind_param('i', $orderId); $deliveryCheck->execute(); $fulfillment = strtolower((string)($deliveryCheck->get_result()->fetch_assoc()['fulfillment_method'] ?? 'pickup')); $deliveryCheck->close(); }
        $customerBody = match ($toStatus) {
            'in_production' => 'Your order has entered the Production stage and is awaiting staff acceptance before work begins.',
            'quality_check' => 'Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.',
            'ready' => $fulfillment === 'delivery' ? 'Your order passed Quality Check and is ready for dispatch.' : 'Your order is ready for pickup.',
            'completed' => 'Your order is completed. Your final bill is now available in My Orders.',
            'cancelled' => 'Your order status was changed to cancelled. Open Messages if you need assistance.',
            default => 'Your order progress has been updated from ' . $label($fromStatus) . ' to ' . $label($toStatus) . '.',
        };
        createNotification($conn, $customerUserId, 'production', $customerTitle, $customerBody, $priority, 'order', $orderId, $threadKey, $orderId);
    }

    // Customer Gmail mirrors the important progress update.
    emailCustomerOrderStage($conn, $orderId, $fromStatus, $toStatus);

    // Keep the order conversation readable as a timeline.
    postChatMessage($conn, $threadKey, 0, 'system', $title . ' (' . $actorName . ')');
}



function notifyOrderStageQueued(
    mysqli $conn,
    int $orderId,
    string $orderNumber,
    string $targetStage,
    ?int $queuePosition = null
): void {
    ensureMessagingSchema($conn);
    $customerId = registeredCustomerIdForOrder($conn, $orderId);
    if (!$customerId) return;

    $labels = [
        'in_production' => 'Production',
        'quality_check' => 'Quality Check',
    ];
    $label = $labels[$targetStage] ?? ucwords(str_replace('_', ' ', $targetStage));
    $positionText = $queuePosition ? ' You are currently #' . $queuePosition . ' in the waiting queue.' : '';
    $customerUserId = registeredCustomerMessagingUserId($customerId);
    createNotification(
        $conn,
        $customerUserId,
        'production',
        'Order ' . ($orderNumber !== '' ? $orderNumber : '#' . $orderId) . ': Waiting for ' . $label,
        'The previous stage is complete. ' . $label . ' is currently at capacity, so your order will enter automatically when the next slot opens (first come, first serve).' . $positionText,
        'normal',
        'order',
        $orderId,
        threadKeyForOrder($orderId),
        $orderId,
        'stage-queue-' . $targetStage . '-' . $orderId
    );
    emailCustomerOrderQueued($conn, $orderId, $targetStage, $queuePosition);
}

function notifyOrderProductionQueued(
    mysqli $conn,
    int $orderId,
    string $orderNumber,
    ?int $queuePosition = null
): void {
    ensureMessagingSchema($conn);
    $customerId = registeredCustomerIdForOrder($conn, $orderId);
    if (!$customerId) {
        return;
    }
    $customerUserId = registeredCustomerMessagingUserId($customerId);
    $positionText = $queuePosition ? ' You are currently #' . $queuePosition . ' in the production queue.' : '';
    createNotification(
        $conn,
        $customerUserId,
        'production',
        'Order ' . ($orderNumber !== '' ? $orderNumber : '#' . $orderId) . ': Waiting for Production',
        'Your payment is verified. Production is currently at capacity, so your order is waiting first come, first serve.' . $positionText,
        'normal',
        'order',
        $orderId,
        threadKeyForOrder($orderId),
        $orderId,
        'production-queue-' . $orderId
    );
    emailCustomerOrderQueued($conn, $orderId, 'in_production', $queuePosition);
}

function notifyOrderAssignment(mysqli $conn, int $orderId, string $orderNumber, ?int $staffId, int $actorId): void {
    if (!$staffId || $staffId === $actorId) {
        return;
    }

    createNotification(
        $conn,
        $staffId,
        'assignment',
        'You have been assigned to ' . ($orderNumber ?: 'an order'),
        'Open the order to review production details and due date.',
        'warning',
        'order',
        $orderId,
        threadKeyForOrder($orderId),
        $orderId
    );
}

function notifyInventoryLevel(mysqli $conn, int $inventoryId): void {
    ensureMessagingSchema($conn);
    if (!notificationsEnabled($conn, 'notify_low_stock')) {
        return;
    }

    $stmt = $conn->prepare("SELECT name, quantity, unit, reorder_level FROM inventory WHERE id=? AND status='active' LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $inventoryId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        return;
    }

    $qty = (float)$item['quantity'];
    $priority = $qty <= 0 ? 'urgent' : ($qty <= (float)$item['reorder_level'] ? 'warning' : null);
    if (!$priority) {
        return;
    }

    $title = $qty <= 0 ? 'Out of stock: ' . $item['name'] : 'Low stock: ' . $item['name'];
    $body = $qty <= 0
        ? 'This inventory item is unavailable.'
        : $item['name'] . ' has only ' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' ' . $item['unit'] . ' remaining.';
    $unique = ($qty <= 0 ? 'out-stock-' : 'low-stock-') . $inventoryId . '-' . date('Ymd');

    foreach (activeAdminIds($conn) as $adminId) {
        createNotification($conn, $adminId, 'inventory', $title, $body, $priority, 'inventory', $inventoryId, null, null, $unique);
    }
}

function notifyAccessRequest(mysqli $conn, int $requestUserId): void {
    ensureMessagingSchema($conn);
    if (!notificationsEnabled($conn, 'notify_access_request')) {
        return;
    }

    $stmt = $conn->prepare("SELECT full_name, email, staff_role FROM users WHERE id=? AND role='staff' AND status='inactive' LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $requestUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return;
    }

    $title = 'Staff access request';
    $body = trim((string)$user['full_name']);
    if (!empty($user['staff_role'])) {
        $body .= ' - ' . ucwords(str_replace('_', ' ', (string)$user['staff_role']));
    }

    foreach (activeAdminIds($conn) as $adminId) {
        createNotification($conn, $adminId, 'access', $title, $body, 'warning', 'access_request', $requestUserId, null, null, 'access-request-' . $requestUserId);
    }
}

function scanDueNotifications(mysqli $conn, int $userId, string $role): void {
    ensureMessagingSchema($conn);

    $scope = $role === 'staff' ? "AND assigned_to={$userId}" : '';
    $orders = $conn->query("
        SELECT id, order_number, assigned_to, due_date
        FROM orders
        WHERE due_date IS NOT NULL
          AND status NOT IN ('completed','cancelled','archived')
          {$scope}
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        LIMIT 80
    ");

    while ($orders && $order = $orders->fetch_assoc()) {
        $due = (string)$order['due_date'];
        $isOverdue = $due < date('Y-m-d');
        $isTomorrow = $due === date('Y-m-d', strtotime('+1 day'));
        $priority = $isOverdue ? 'urgent' : 'warning';
        $title = ($isOverdue ? 'Order overdue: ' : 'Order due soon: ') . $order['order_number'];
        $body = $isOverdue ? 'This order is past its due date.' : ($isTomorrow ? 'This order is due tomorrow.' : 'This order is due today.');
        $unique = ($isOverdue ? 'overdue-' : 'due-') . (int)$order['id'] . '-' . date('Ymd');

        createNotification($conn, $userId, 'deadline', $title, $body, $priority, 'order', (int)$order['id'], threadKeyForOrder((int)$order['id']), (int)$order['id'], $unique);
    }
}

function notifyChatMessage(mysqli $conn, string $threadKey, int $senderId, string $body): void {
    ensureMessagingSchema($conn);

    if ($senderId === 0 || trim($body) === '') {
        return;
    }

    $isCustomerSender = $senderId < 0;
    $senderName = $isCustomerSender ? 'Customer' : 'Team member';
    if ($isCustomerSender) {
        $registeredCustomerId = registeredCustomerIdFromMessagingUserId($senderId);
        if ($registeredCustomerId) {
            $who = $conn->prepare("SELECT name FROM customers WHERE id=? LIMIT 1");
            if ($who) {
                $who->bind_param('i', $registeredCustomerId);
                $who->execute();
                $senderName = (string)($who->get_result()->fetch_assoc()['name'] ?? 'Customer');
                $who->close();
            }
        }
    } else {
        $who = $conn->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
        if ($who) {
            $who->bind_param('i', $senderId);
            $who->execute();
            $senderName = (string)($who->get_result()->fetch_assoc()['full_name'] ?? 'Team member');
            $who->close();
        }
    }

    $meta = chatThreadMeta($conn, $threadKey);
    $orderId = $meta['order_id'] ? (int)$meta['order_id'] : null;
    $title = $senderName . ' sent a message';
    $bodyText = ($meta['label'] ?? 'Conversation') . ' - ' . mb_substr(trim($body), 0, 120);

    $directUserIds = userIdsFromThreadKey($threadKey);
    if (count($directUserIds) === 2) {
        $recipientId = $directUserIds[0] === $senderId ? $directUserIds[1] : $directUserIds[0];
        createNotification($conn, $recipientId, 'chat', $title, $bodyText, 'normal', 'message', null, $threadKey, $orderId);
        return;
    }

    // Team replies to a registered customer's support/order conversation go
    // straight to that customer's account notification center.
    if (!$isCustomerSender) {
        $customerRecipient = null;
        $supportUserId = customerSessionIdFromThreadKey($threadKey);
        if ($supportUserId && registeredCustomerIdFromMessagingUserId($supportUserId)) {
            $customerRecipient = $supportUserId;
        } elseif ($orderId) {
            $customerId = registeredCustomerIdForOrder($conn, $orderId);
            if ($customerId) $customerRecipient = registeredCustomerMessagingUserId($customerId);
        }
        if ($customerRecipient) {
            createNotification($conn, $customerRecipient, 'chat', 'New message from Brille Amour', $bodyText, 'normal', 'message', null, $threadKey, $orderId);
        }
    }

    // Preserve the existing team alert behavior: customer messages alert all
    // admins, while team messages alert the other active admins.
    foreach (activeAdminIds($conn, $isCustomerSender ? 0 : $senderId) as $adminId) {
        createNotification($conn, $adminId, 'chat', $title, $bodyText, 'normal', 'message', null, $threadKey, $orderId);
    }
}

function unreadNotificationCount(mysqli $conn, int $userId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND is_read=0 AND is_deleted=0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

function notificationFeed(mysqli $conn, int $userId, int $limit = 10, array $filters = []): array {
    $limit = max(1, min(100, $limit));

    $where = ["user_id=? ", "is_deleted=0"];
    $types = 'i';
    $values = [$userId];

    if (!empty($filters['unread'])) {
        $where[] = 'is_read=0';
    }
    if (!empty($filters['type'])) {
        $where[] = 'type=?';
        $types .= 's';
        $values[] = (string)$filters['type'];
    }
    if (!empty($filters['priority'])) {
        $where[] = 'priority=?';
        $types .= 's';
        $values[] = (string)$filters['priority'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(title LIKE ? OR body LIKE ?)';
        $types .= 'ss';
        $like = '%' . (string)$filters['q'] . '%';
        $values[] = $like;
        $values[] = $like;
    }

    $stmt = $conn->prepare(
        "SELECT id, type, title, body, thread_key, order_id, priority, reference_type, reference_id, is_read, created_at, read_at
         FROM notifications
         WHERE " . implode(' AND ', $where) . "
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $result = $stmt->get_result();

    $feed = [];
    while ($row = $result->fetch_assoc()) {
        $feed[] = [
            'id'         => (int)$row['id'],
            'type'       => (string)$row['type'],
            'title'      => (string)$row['title'],
            'body'       => (string)($row['body'] ?? ''),
            'thread_key' => (string)($row['thread_key'] ?? ''),
            'order_id'   => $row['order_id'] ? (int)$row['order_id'] : null,
            'priority'   => (string)($row['priority'] ?? 'normal'),
            'reference_type' => (string)($row['reference_type'] ?? ''),
            'reference_id' => $row['reference_id'] ? (int)$row['reference_id'] : null,
            'is_read'    => (int)$row['is_read'] === 1,
            'ago'        => timeAgo($row['created_at']),
            'at'         => appDateTime($row['created_at']),
            'read_at'    => $row['read_at'] ? appDateTime($row['read_at']) : '',
            'url'        => notificationUrl($row),
        ];
    }
    $stmt->close();

    return $feed;
}

function notificationUrl(array $row): string {
    $referenceType = (string)($row['reference_type'] ?? '');
    $referenceId = (int)($row['reference_id'] ?? 0);
    $type = (string)($row['type'] ?? '');

    if (($type === 'chat' || $referenceType === 'message') && !empty($row['thread_key'])) {
        return 'messaging.php?thread=' . rawurlencode((string)$row['thread_key']);
    }

    if ($referenceType === 'order' && $referenceId > 0) {
        return 'orders.php?view=' . $referenceId;
    }
    if ($referenceType === 'inventory' && $referenceId > 0) {
        return 'inventory.php?edit=' . $referenceId;
    }
    if ($referenceType === 'payment' && $referenceId > 0) {
        return 'payments.php#payment-' . $referenceId;
    }
    if ($referenceType === 'access_request' && $referenceId > 0) {
        return 'roles.php';
    }
    if (!empty($row['thread_key'])) {
        return 'messaging.php?thread=' . rawurlencode((string)$row['thread_key']);
    }

    return 'notifications.php';
}

/** Marks the given notification ids read, or all of them when $ids is empty. */
function markNotificationsRead(mysqli $conn, int $userId, array $ids = []): void {
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if (!$ids) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1, read_at=COALESCE(read_at, NOW()) WHERE user_id=? AND is_read=0 AND is_deleted=0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read=1, read_at=COALESCE(read_at, NOW()) WHERE user_id=? AND id IN ({$placeholders})"
    );
    $stmt->bind_param(str_repeat('i', count($ids) + 1), $userId, ...$ids);
    $stmt->execute();
    $stmt->close();
}

function deleteNotifications(mysqli $conn, int $userId, array $ids = []): void {
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if (!$ids) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE notifications SET is_deleted=1 WHERE user_id=? AND id IN ({$placeholders})");
    $stmt->bind_param(str_repeat('i', count($ids) + 1), $userId, ...$ids);
    $stmt->execute();
    $stmt->close();
}

/* ───────────────── chat ───────────────── */

function chatThreadMeta(mysqli $conn, string $threadKey): array {
    if ($threadKey === 'general') {
        return [
            'key'      => 'general',
            'label'    => 'General',
            'sublabel' => 'Everyone in the shop',
            'role'     => 'Team',
            'status'   => 'Online',
            'avatar'   => 'G',
            'order_id' => null,
        ];
    }

    $customerSessionId = customerSessionIdFromThreadKey($threadKey);
    if ($customerSessionId) {
        $label = 'Customer inquiry';
        $sublabel = 'Direct customer support';
        $status = 'Active';
        $avatar = 'C';

        $rawSession = abs((int)$customerSessionId);
        $registeredCustomerId = registeredCustomerIdFromMessagingUserId((int)$customerSessionId);
        if ($registeredCustomerId) {
            $stmt = $conn->prepare("SELECT name, email, contact, status FROM customers WHERE id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $registeredCustomerId);
                $stmt->execute();
                $accountCustomer = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($accountCustomer) {
                    $label = trim((string)$accountCustomer['name']) ?: ('Customer #' . $registeredCustomerId);
                    $parts = array_filter([trim((string)($accountCustomer['email'] ?? '')), trim((string)($accountCustomer['contact'] ?? ''))]);
                    $sublabel = $parts ? implode(' - ', $parts) : 'Registered customer account';
                    $status = ((string)($accountCustomer['status'] ?? 'active')) === 'active' ? 'Active' : 'Archived';
                    $avatar = strtoupper(substr($label, 0, 1)) ?: 'C';
                }
            }
        }

        // Chatbot conversations are mirrored to customer-* threads using the
        // same 700M offset defined in includes/chatbot_conversations.php.
        $botConversationId = (!$registeredCustomerId && $rawSession >= 700000001 && $rawSession < 1000000000) ? ($rawSession - 700000000) : 0;
        if ($botConversationId > 0) {
            $tableExists = $conn->query("SHOW TABLES LIKE 'chatbot_conversations'");
            if ($tableExists && $tableExists->num_rows > 0) {
                $stmt = $conn->prepare('SELECT customer_name, customer_contact, order_number, last_intent, status FROM chatbot_conversations WHERE id=? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $botConversationId);
                    $stmt->execute();
                    $bot = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($bot) {
                        $name = trim((string)($bot['customer_name'] ?? ''));
                        $contact = trim((string)($bot['customer_contact'] ?? ''));
                        $orderNumber = trim((string)($bot['order_number'] ?? ''));
                        $intent = trim((string)($bot['last_intent'] ?? ''));

                        if ($orderNumber !== '') {
                            $label = 'Bot inquiry - ' . $orderNumber;
                        } elseif ($contact !== '') {
                            $label = 'Bot inquiry - ' . $contact;
                        } elseif ($name !== '') {
                            $label = $name;
                        } else {
                            $label = 'Bot inquiry #' . $botConversationId;
                        }

                        $bits = array_filter([
                            'Brille Bot',
                            $intent !== '' ? ucwords(str_replace('_', ' ', $intent)) : '',
                            $contact !== '' && strpos($label, $contact) === false ? $contact : '',
                        ]);
                        $sublabel = implode(' - ', $bits) ?: $sublabel;
                        $status = ((string)($bot['status'] ?? 'active')) === 'archived' ? 'Archived' : 'Active';
                        $avatar = $orderNumber !== '' ? '#' : strtoupper(substr($name ?: $contact ?: 'C', 0, 1));
                    }
                }
            }
        }

        return [
            'key'      => $threadKey,
            'label'    => $label,
            'sublabel' => $sublabel,
            'role'     => 'Customer',
            'status'   => $status,
            'avatar'   => $avatar,
            'order_id' => null,
        ];
    }

    $userIds = userIdsFromThreadKey($threadKey);
    if (count($userIds) === 2) {
        $stmt = $conn->prepare("
            SELECT id, full_name, username, role, staff_role, status
            FROM users
            WHERE id IN (?, ?)
            ORDER BY full_name
        ");
        $stmt->bind_param('ii', $userIds[0], $userIds[1]);
        $stmt->execute();
        $rows = $stmt->get_result();
        $people = [];
        while ($row = $rows->fetch_assoc()) {
            $people[(int)$row['id']] = $row;
        }
        $stmt->close();

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $otherUserId = $userIds[0] === $currentUserId ? $userIds[1] : $userIds[0];
        $other = $people[$otherUserId] ?? $people[$userIds[0]] ?? $people[$userIds[1]] ?? null;
        if ($other) {
            $roleLabel = ucfirst((string)$other['role']);
            if (($other['role'] ?? '') === 'staff' && !empty($other['staff_role']) && function_exists('staffRoleLabel')) {
                $roleLabel = staffRoleLabel((string)$other['staff_role']);
            }

            $name = (string)($other['full_name'] ?: $other['username'] ?: 'Team member');

            return [
                'key'      => $threadKey,
                'label'    => $name,
                'sublabel' => '@' . (string)$other['username'],
                'role'     => $roleLabel,
                'account_role' => (string)$other['role'],
                'status'   => (string)($other['status'] === 'active' ? 'Online' : 'Inactive'),
                'avatar'   => strtoupper(substr($name, 0, 1)) ?: 'U',
                'order_id' => null,
            ];
        }

        return [
            'key'      => $threadKey,
            'label'    => 'Removed user',
            'sublabel' => 'This account is no longer available',
            'role'     => 'User',
            'status'   => 'Unavailable',
            'avatar'   => 'U',
            'order_id' => null,
        ];
    }

    $orderId = orderIdFromThreadKey($threadKey);

    $stmt = $conn->prepare("SELECT order_number, customer_name, order_type, status FROM orders WHERE id=?");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return [
            'key'      => $threadKey,
            'label'    => 'Order #' . $orderId,
            'sublabel' => 'Order no longer available',
            'role'     => 'Customer',
            'status'   => 'Unavailable',
            'avatar'   => '#',
            'order_id' => $orderId,
        ];
    }

    $sub = array_filter([
        html_entity_decode((string)$order['customer_name'], ENT_QUOTES),
        html_entity_decode((string)$order['order_type'], ENT_QUOTES),
    ]);

    return [
        'key'      => $threadKey,
        'label'    => (string)$order['order_number'],
        'sublabel' => implode(' - ', $sub),
        'role'     => 'Customer',
        'status'   => ucwords(str_replace('_', ' ', (string)$order['status'])),
        'avatar'   => strtoupper(substr((string)$order['customer_name'], 0, 1)) ?: 'C',
        'order_id' => $orderId,
    ];
}

/**
 * Threads worth showing: every order with chat activity, the most recent orders,
 * direct team chats, customer inquiries, and always the General channel.
 */
function chatThreadList(mysqli $conn, int $userId, int $limit = 20, string $role = 'admin', string $search = '', bool $includeArchived = false, string $threadGroup = ''): array {
    $limit = max(1, min(60, $limit));
    $search = trim($search);

    if ($role === 'customer') {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['customer_order_ids'] ?? []))));
        $threads = [];
        $supportThread = validThreadKey((string)($_SESSION['customer_support_thread_key'] ?? ''));
        if ($supportThread && canAccessThread($conn, $userId, $role, $supportThread)) {
            $meta = chatThreadMeta($conn, $supportThread);
            $meta['label'] = 'Brille Amour Admin';
            $meta['sublabel'] = 'Direct customer support';
            $meta['role'] = 'Admin Support';
            $meta['status'] = 'Available';
            $meta['avatar'] = 'BA';
            $last = lastChatMessage($conn, $supportThread);
            $threads[] = $meta + [
                'unread' => unreadChatCount($conn, $userId, $supportThread),
                'preview' => $last ? $last['preview'] : 'Ask us about a product or your order.',
                'ago' => $last ? timeAgo($last['created_at']) : '',
                'last_id' => $last ? (int)$last['id'] : 0,
                'is_archived' => false,
            ];
        }

        foreach ($orderIds as $orderId) {
            $key = threadKeyForOrder($orderId);
            if (!canAccessThread($conn, $userId, $role, $key)) {
                continue;
            }
            $meta = chatThreadMeta($conn, $key);
            $last = lastChatMessage($conn, $key);
            $threads[] = $meta + [
                'unread' => unreadChatCount($conn, $userId, $key),
                'preview' => $last ? $last['preview'] : '',
                'ago' => $last ? timeAgo($last['created_at']) : '',
                'last_id' => $last ? (int)$last['id'] : 0,
                'is_archived' => false,
            ];
        }

        usort($threads, static fn(array $a, array $b): int => $b['last_id'] <=> $a['last_id']);
        return array_slice($threads, 0, $limit);
    }

    // Group filtering is applied before querying and limiting candidates.
    // 'general' and 'user-*' are team chats; 'order-*' and 'customer-*'
    // are customer conversations, regardless of who sent a message.
    $teamOnly = $role === 'admin' && $threadGroup === 'team';
    $customerOnly = $role === 'admin' && $threadGroup === 'customers';
    $keys = $customerOnly ? [] : ['general'];

    $customerThreadScope = $role === 'admin' && !$teamOnly
        ? "OR (m.thread_key LIKE 'customer-%' AND m.thread_key NOT REGEXP '^customer-[789][0-9]{8}$')"
        : '';
    $recentOrderScope = $role === 'admin' && !$teamOnly ? '' : 'AND 1=0';
    $orderThreadScope = $role === 'admin' && !$teamOnly ? 'OR (o.id IS NOT NULL %s)' : '';
    $searchSql = '';
    $recentSearchSql = '';
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $searchSql = "AND (o.order_number LIKE '%{$like}%' OR o.customer_name LIKE '%{$like}%' OR o.order_type LIKE '%{$like}%')";
        $recentSearchSql = "AND (order_number LIKE '%{$like}%' OR customer_name LIKE '%{$like}%' OR order_type LIKE '%{$like}%')";
    }

    $peopleSearchSql = '';
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $peopleSearchSql = "AND (full_name LIKE '%{$like}%' OR username LIKE '%{$like}%' OR email LIKE '%{$like}%' OR role LIKE '%{$like}%')";
    }

    if ($role === 'admin' && !$teamOnly) {
        $customerInquiries = $conn->query("
            SELECT thread_key, MAX(id) AS last_id
            FROM chat_messages
            WHERE thread_key LIKE 'customer-%'
              AND thread_key NOT REGEXP '^customer-[789][0-9]{8}$'
            GROUP BY thread_key
            ORDER BY last_id DESC
            LIMIT {$limit}
        ");
        while ($customerInquiries && $row = $customerInquiries->fetch_assoc()) {
            $keys[] = (string)$row['thread_key'];
        }
    }

    $active = $conn->query("
        SELECT m.thread_key, MAX(m.id) AS last_id
        FROM chat_messages
        m
        LEFT JOIN orders o ON o.id = m.order_id
        WHERE (
            m.thread_key='general'
            {$customerThreadScope}
            " . ($orderThreadScope ? sprintf($orderThreadScope, $searchSql) : '') . "
        )
        GROUP BY m.thread_key
        ORDER BY last_id DESC
        LIMIT {$limit}
    ");
    while ($active && $row = $active->fetch_assoc()) {
        $keys[] = (string)$row['thread_key'];
    }

    $recent = $teamOnly ? false : $conn->query("
        SELECT id FROM orders
        WHERE status NOT IN ('archived','cancelled') {$recentOrderScope} {$recentSearchSql}
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    while ($recent && $row = $recent->fetch_assoc()) {
        $keys[] = threadKeyForOrder((int)$row['id']);
    }

    $people = $customerOnly ? false : $conn->query("
        SELECT id
        FROM users
        WHERE status='active'
          AND id <> {$userId}
          AND role IN ('admin','staff')
          {$peopleSearchSql}
        ORDER BY role='staff' DESC, full_name
        LIMIT {$limit}
    ");
    while ($people && $row = $people->fetch_assoc()) {
        $keys[] = threadKeyForUsers($userId, (int)$row['id']);
    }

    $threads = [];
    foreach (array_unique($keys) as $key) {
        if (isLegacyBotInquiryThreadKey($key)) continue;
        if ($teamOnly && $key !== 'general' && strpos($key, 'user-') !== 0) continue;
        if ($customerOnly && strpos($key, 'customer-') !== 0 && strpos($key, 'order-') !== 0) continue;
        if (!canAccessThread($conn, $userId, $role, $key)) {
            continue;
        }

        $state = chatThreadState($conn, $userId, $key);
        if (!empty($state['is_deleted']) || (!$includeArchived && !empty($state['is_archived']))) {
            continue;
        }

        $meta = chatThreadMeta($conn, $key);
        $last = lastChatMessage($conn, $key);

        $threads[] = $meta + [
            'unread'      => unreadChatCount($conn, $userId, $key),
            'preview'     => $last ? $last['preview'] : '',
            'ago'         => $last ? timeAgo($last['created_at']) : '',
            'last_id'     => $last ? (int)$last['id'] : 0,
            'is_archived' => !empty($state['is_archived']),
        ];
    }

    usort($threads, static function (array $a, array $b) use ($role): int {
        if ($role === 'admin') {
            $priority = static function (array $thread): int {
                $key = (string)($thread['key'] ?? '');

                if ($key === 'general') {
                    return 0;
                }

                if (strpos($key, 'customer-') === 0) {
                    return 1;
                }

                if (strpos($key, 'user-') === 0) {
                    return (($thread['account_role'] ?? '') === 'staff') ? 2 : 3;
                }

                return 4;
            };

            $groupA = $priority($a);
            $groupB = $priority($b);
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }
        }

        if ($a['last_id'] === $b['last_id']) {
            return strcmp($a['label'], $b['label']);
        }

        return $b['last_id'] <=> $a['last_id'];
    });

    return array_slice($threads, 0, $limit);
}

function canAccessThread(mysqli $conn, int $userId, string $role, string $threadKey): bool {
    // Bot-only mirrors are no longer part of the Admin inbox, even when a
    // previously bookmarked key is passed directly to the messaging API.
    if ($role === 'admin' && isLegacyBotInquiryThreadKey($threadKey)) return false;
    if ($role === 'customer') {
        $supportThread = validThreadKey((string)($_SESSION['customer_support_thread_key'] ?? ''));
        if ($supportThread && $threadKey === $supportThread) {
            return customerSessionIdFromThreadKey($threadKey) === $userId;
        }

        $orderId = orderIdFromThreadKey($threadKey);
        $allowedOrderIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['customer_order_ids'] ?? []))));

        return $orderId && in_array($orderId, $allowedOrderIds, true);
    }

    if ($threadKey === 'general') {
        return true;
    }

    $directUserIds = userIdsFromThreadKey($threadKey);
    if (count($directUserIds) === 2) {
        if (!in_array($userId, $directUserIds, true)) {
            return false;
        }

        $otherUserId = $directUserIds[0] === $userId ? $directUserIds[1] : $directUserIds[0];
        $stmt = $conn->prepare("SELECT id FROM users WHERE id=? AND role IN ('admin','staff') AND status='active' LIMIT 1");
        $stmt->bind_param('i', $otherUserId);
        $stmt->execute();
        $canAccess = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $canAccess;
    }

    if ($role === 'admin') {
        return true;
    }

    return false;
}

function chatThreadState(mysqli $conn, int $userId, string $threadKey): array {
    $stmt = $conn->prepare("SELECT is_archived, is_deleted FROM chat_thread_states WHERE user_id=? AND thread_key=? LIMIT 1");
    $stmt->bind_param('is', $userId, $threadKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['is_archived' => 0, 'is_deleted' => 0];
    $stmt->close();

    return [
        'is_archived' => (int)$row['is_archived'] === 1,
        'is_deleted' => (int)$row['is_deleted'] === 1,
    ];
}

function setChatThreadArchived(mysqli $conn, int $userId, string $threadKey, bool $archived): void {
    $flag = $archived ? 1 : 0;
    $stmt = $conn->prepare("
        INSERT INTO chat_thread_states (user_id, thread_key, is_archived, is_deleted)
        VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE is_archived=VALUES(is_archived), is_deleted=0
    ");
    $stmt->bind_param('isi', $userId, $threadKey, $flag);
    $stmt->execute();
    $stmt->close();
}

function deleteChatThreadForUser(mysqli $conn, int $userId, string $threadKey): void {
    $stmt = $conn->prepare("
        INSERT INTO chat_thread_states (user_id, thread_key, is_archived, is_deleted)
        VALUES (?, ?, 0, 1)
        ON DUPLICATE KEY UPDATE is_deleted=1
    ");
    $stmt->bind_param('is', $userId, $threadKey);
    $stmt->execute();
    $stmt->close();
}

function lastChatMessage(mysqli $conn, string $threadKey): ?array {
    $stmt = $conn->prepare(
        "SELECT m.id, m.body, m.created_at, m.sender_role, u.full_name
         FROM chat_messages m
         LEFT JOIN users u ON u.id = m.sender_id
         WHERE m.thread_key=?
         ORDER BY m.id DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $threadKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

        $role = (string)($row['sender_role'] ?? '');
    if ($role === 'customer') {
        $who = 'Customer: ';
    } elseif ($role === 'bot') {
        $who = 'Brille Bot: ';
    } elseif ($row['full_name']) {
        $who = explode(' ', $row['full_name'])[0] . ': ';
    } else {
        $who = '';
    }

    return [
        'id'         => (int)$row['id'],
        'created_at' => $row['created_at'],
        'preview'    => $who . mb_substr((string)$row['body'], 0, 80),
    ];
}

function chatMessagesSince(mysqli $conn, string $threadKey, int $afterId, int $userId, int $limit = 60, int $beforeId = 0): array {
    $limit = max(1, min(200, $limit));

    if ($beforeId > 0) {
        $stmt = $conn->prepare(
            "SELECT m.id, m.sender_id, m.sender_role, m.body, m.created_at, u.full_name
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.thread_key=? AND m.id < ?
             ORDER BY m.id DESC
             LIMIT {$limit}"
        );
        $stmt->bind_param('si', $threadKey, $beforeId);
    } elseif ($afterId > 0) {
        $stmt = $conn->prepare(
            "SELECT m.id, m.sender_id, m.sender_role, m.body, m.created_at, u.full_name
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.thread_key=? AND m.id > ?
             ORDER BY m.id ASC
             LIMIT {$limit}"
        );
        $stmt->bind_param('si', $threadKey, $afterId);
    } else {
        // Newest slice, then flipped back into reading order.
        $stmt = $conn->prepare(
            "SELECT m.id, m.sender_id, m.sender_role, m.body, m.created_at, u.full_name
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.thread_key=?
             ORDER BY m.id DESC
             LIMIT {$limit}"
        );
        $stmt->bind_param('s', $threadKey);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $isSystem = $row['sender_role'] === 'system';
        $isBot = $row['sender_role'] === 'bot';
        $isCustomer = $row['sender_role'] === 'customer';

        $messages[] = [
            'id'     => (int)$row['id'],
            'body'   => (string)$row['body'],
            'author' => $isSystem ? 'System' : ($isBot ? 'Brille Bot' : ($isCustomer ? 'Customer' : (string)($row['full_name'] ?? 'Removed user'))),
            'role'   => $isSystem ? 'system' : ($isBot ? 'bot' : ($isCustomer ? 'customer' : (string)$row['sender_role'])),
            'mine'   => !$isSystem && !$isBot && (int)$row['sender_id'] === $userId,
            'system' => $isSystem,
            'bot'    => $isBot,
            'ago'    => timeAgo($row['created_at']),
            'at'     => appDateTime($row['created_at'], 'M j, g:i A'),
            'date'   => appDateTime($row['created_at'], 'Y-m-d'),
            'time'   => appDateTime($row['created_at'], 'g:i A'),
            'seen'   => !$isSystem && !$isBot && (int)$row['sender_id'] === $userId,
        ];
    }
    $stmt->close();

    if ($afterId <= 0) {
        $messages = array_reverse($messages);
    }

    return $messages;
}

function postChatMessage(mysqli $conn, string $threadKey, int $senderId, string $senderRole, string $body): ?int {
    $body = trim($body);

    if ($body === '') {
        return null;
    }

    if (mb_strlen($body) > 2000) {
        $body = mb_substr($body, 0, 2000);
    }

    $orderId = orderIdFromThreadKey($threadKey);

    $stmt = $conn->prepare(
        "INSERT INTO chat_messages (thread_key, order_id, sender_id, sender_role, body)
         VALUES (?,?,?,?,?)"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('siiss', $threadKey, $orderId, $senderId, $senderRole, $body);
    $ok = $stmt->execute();
    $id = $ok ? $conn->insert_id : null;
    $stmt->close();

    return $id;
}

/** Human-friendly label for an order's production status. */
function chatOrderStatusLabel(string $status): string {
    $labels = [
        'pending'        => 'Pending confirmation',
        'in_production'  => 'In production',
        'quality_check'  => 'Quality check',
        'ready'          => 'Ready for pickup',
        'completed'      => 'Completed',
        'cancelled'      => 'Cancelled',
        'archived'       => 'Archived',
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Builds an automatic "Brille Bot" reply for a customer message.
 * Looks up live order data when the customer names an order number or
 * asks for tracking/status, and otherwise falls back to FAQ-style answers.
 * Returns null when no reply should be posted.
 */
function customerBotReply(mysqli $conn, string $message, array $sessionOrderIds = [], string $threadKey = ''): ?string {
    require_once __DIR__ . '/customer_bot.php';
    $reply = customerBotReplyEnglish($conn, $message, $sessionOrderIds, $threadKey);
    return $reply;
}

function customerBotReplyEnglish(mysqli $conn, string $message, array $sessionOrderIds = [], string $threadKey = ''): ?string {
    $message = trim($message);
    if ($message === '') {
        return null;
    }

    require_once __DIR__ . '/customer_bot.php';
    require_once __DIR__ . '/catalog.php';
    $contextKey = $threadKey ?: 'default';
    $context = $_SESSION['customer_bot_context'][$contextKey] ?? [];
    if (($context['updated_at'] ?? 0) < time() - 1800) $context = [];
    $intent = customerBotIntent($message);
    $hasOrderNumber = preg_match('/\bBA-(?:\d{3,6}|\d{8}-\d{3,6})\b/i', $message);
    $local = false;
    $context['local'] = $local;
    $context['updated_at'] = time();
    if (!$hasOrderNumber && !in_array($intent, ['cancel', 'human', 'payment', 'tracking', 'pickup', 'design'], true)) {
        $catalogReply = customerBotCatalogAnswer($message, getActiveProductsList($conn), $context);
        $_SESSION['customer_bot_context'][$contextKey] = $context;
        if ($catalogReply !== null) return $catalogReply;
    }
    $_SESSION['customer_bot_context'][$contextKey] = $context;
    $answers = [
        'cancel' => ['Share your request here for staff to review. I cannot cancel or change orders myself.', 'Isulti dinhi ang imong ipa-cancel o ipa-change aron ma-review sa staff. Dili ko mismo maka-usab sa order.'],
        'human' => ['Please leave your question here for staff to read and reply. Their response may take time.', 'Ibilin dinhi ang imong pangutana aron mabasa ug matubag sa staff. Mahimong maghulat pa sa ilang reply.'],
        'payment' => ['Pickup orders require a 25% downpayment. Delivery orders require full payment at checkout. Available online methods are shown during checkout.', 'Ang Pickup orders kay 25% downpayment. Ang Delivery orders kay full payment diretso sa checkout. Ang available online payment methods makita sa checkout.'],
        'pickup' => ['Set your preferred pickup date and time at checkout. Closed or fully booked dates cannot be selected. Staff must confirm any later schedule changes.', 'Ibutang ang gusto nimong pickup date ug time sa checkout. Ang closed or fully booked dates dili na mapili. Kinahanglan i-confirm sa staff ang schedule changes.'],
        'design' => ['Upload a reference image at checkout and add instructions for text, size, color, or theme.', 'Puwede mag-upload og reference image sa checkout. Ibutang ang text, size, color, o theme sa instructions.'],
        'order' => ['Choose products from the menu, add quantities to your cart, and complete checkout. Staff will confirm your pending order.', 'Pili og product sa menu, i-add ang quantity sa cart, ug kompletuha ang checkout. Hulata ang staff confirmation.'],
        'thanks' => ["You're welcome!", 'Walay sapayan!'],
        'hello' => ['Hello! Ask about products, prices, budget suggestions, payment, or your order status.', 'Hello! Puwede mangutana sa products, presyo, budget suggestions, bayad, o order status.'],
    ];
    if (!$hasOrderNumber && $intent === 'pickup') {
        require_once __DIR__ . '/pickup_capacity.php';
        $pickupDate = '';
        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $message, $dateMatch)) {
            $pickupDate = $dateMatch[1];
        } elseif (preg_match('/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{1,2}(?:,?\s+20\d{2})?|\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*(?:,?\s+20\d{2})?)\b/i', $message, $dateMatch)) {
            $rawDate = $dateMatch[1];
            if (!preg_match('/20\d{2}/', $rawDate)) $rawDate .= ' ' . date('Y');
            $timestamp = strtotime($rawDate);
            if ($timestamp !== false) $pickupDate = date('Y-m-d', $timestamp);
        }
        if ($pickupDate !== '') {
            $capacity = pickupCapacityForDate($conn, $pickupDate);
            $pretty = appDateTime($pickupDate, 'M j, Y');
            if (!empty($capacity['is_closed'])) return $pretty . ' is closed for pickup. Please choose another date.';
            if (!empty($capacity['fully_booked'])) return $pretty . ' is already fully booked. Please choose another pickup date.';
            return $pretty . ' is available for pickup. ' . (int)$capacity['remaining'] . ' slot' . ((int)$capacity['remaining'] === 1 ? '' : 's') . ' left.';
        }
    }
    if (!$hasOrderNumber && isset($answers[$intent])) return $answers[$intent][$local ? 1 : 0];
    if (!$hasOrderNumber && $intent === 'tracking') $message = 'tracking';
    $text = mb_strtolower($message);
    $has = static function (array $terms) use ($text): bool {
        foreach ($terms as $term) {
            if (customerBotHas($text, [$term])) {
                return true;
            }
        }
        return false;
    };

    // A specific order number was mentioned (e.g. BA-001 or old BA-20260502-6739).
    if (preg_match('/\bBA-(?:\d{3,6}|\d{8}-\d{3,6})\b/i', $message, $match)) {
        $orderNumber = strtoupper($match[0]);
        $stmt = $conn->prepare("SELECT id, order_number, status, due_date, balance FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->bind_param('s', $orderNumber);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || !in_array((int)$order['id'], array_map('intval', $sessionOrderIds), true)) {
            return 'I cannot access that order in this session. Open My Orders in your customer account, or ask staff for help.';
        }

        $dueNote = (!empty($order['due_date']) && $order['due_date'] !== '0000-00-00')
            ? ' Target pickup: ' . appDateTime($order['due_date'], 'M j, Y') . '.'
            : '';
        $balanceNote = ((float)$order['balance'] > 0)
            ? ' Remaining balance: ' . formatMoney((float)$order['balance']) . '.'
            : ' This order is fully paid.';

        return "Order {$order['order_number']} is currently \"" . chatOrderStatusLabel((string)$order['status']) . "\"." . $dueNote . $balanceNote . ' Ask me anything else, or a staff member will jump in.';
    }

    // General tracking/status question tied to this browser's own orders.
    if ($has(['track', 'tracking', 'status', 'progress', 'where is my order', 'update on my order']) && $sessionOrderIds) {
        $orderIds = array_values(array_unique(array_map('intval', $sessionOrderIds)));
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $types = str_repeat('i', count($orderIds));
        $stmt = $conn->prepare("SELECT order_number, status, due_date, balance FROM orders WHERE id IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->bind_param($types, ...$orderIds);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order) {
            $dueNote = (!empty($order['due_date']) && $order['due_date'] !== '0000-00-00')
                ? ' Target pickup: ' . appDateTime($order['due_date'], 'M j, Y') . '.'
                : '';
            return "Your latest order {$order['order_number']} is currently \"" . chatOrderStatusLabel((string)$order['status']) . "\"." . $dueNote . ' You can also check My Orders in your customer account anytime.';
        }
    }

    return $intent === 'tracking'
        ? 'Open My Orders in your customer account, or share an order number from this session here.'
        : 'Ask about products, prices, payment, pickup, or your order status. Staff can help with other questions here.';
}
function markThreadRead(mysqli $conn, int $userId, string $threadKey): void {
    $stmt = $conn->prepare(
        "INSERT INTO chat_reads (user_id, thread_key, last_read_at)
         VALUES (?,?,NOW())
         ON DUPLICATE KEY UPDATE last_read_at=NOW()"
    );
    $stmt->bind_param('is', $userId, $threadKey);
    $stmt->execute();
    $stmt->close();
}

/**
 * Messages from other people newer than this user's last read of the thread.
 * System lines are skipped — the new-order notification already covers those.
 */
function unreadChatCount(mysqli $conn, int $userId, string $threadKey): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM chat_messages m
        LEFT JOIN chat_reads r ON r.user_id=? AND r.thread_key=m.thread_key
        WHERE m.thread_key=?
          AND m.sender_id <> ?
          AND m.sender_role NOT IN ('system','bot')
          AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
    ");
    $stmt->bind_param('isi', $userId, $threadKey, $userId);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

/** Unread messages in conversations actually available to this user. */
function unreadChatTotal(mysqli $conn, int $userId): int {
    // Deleted threads are absent from the Inbox and must not leave an
    // un-clearable red badge behind. Archived threads still count because
    // the current Inbox deliberately includes them.
    $stmt = $conn->prepare("
        SELECT m.thread_key, COUNT(*) AS total
        FROM chat_messages m
        LEFT JOIN chat_reads r ON r.user_id=? AND r.thread_key=m.thread_key
        LEFT JOIN chat_thread_states s ON s.user_id=? AND s.thread_key=m.thread_key
        WHERE m.sender_id <> ?
          AND m.sender_role NOT IN ('system','bot')
          AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
          AND COALESCE(s.is_deleted, 0) = 0
        GROUP BY m.thread_key
    ");
    $stmt->bind_param('iii', $userId, $userId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $role = in_array(($_SESSION['role'] ?? ''), ['admin', 'staff', 'customer'], true) ? $_SESSION['role'] : 'staff';
    $total = 0;
    foreach ($rows as $row) {
        $key = (string)$row['thread_key'];
        // Ignore orphan/legacy thread keys that cannot appear in the Inbox.
        // Legacy bot inquiries are already excluded by canAccessThread().
        if ($key !== 'general' && !preg_match('/^(?:user-\d+-\d+|order-\d+|customer-\d{6,12})$/', $key)) {
            continue;
        }
        if (canAccessThread($conn, $userId, $role, $key)) {
            $total += (int)$row['total'];
        }
    }

    return $total;
}
