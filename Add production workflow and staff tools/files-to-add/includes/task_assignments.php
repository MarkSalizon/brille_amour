<?php
/**
 * Staff task assignment helpers for Brille Amour orders.
 * Adds a lightweight task layer on top of the existing order status workflow.
 */

function orderTaskDatabaseName(mysqli $conn): string
{
    if (defined('DB_NAME') && DB_NAME !== '') {
        return (string)DB_NAME;
    }

    $result = $conn->query('SELECT DATABASE() AS db_name');
    $row = $result ? $result->fetch_assoc() : null;
    return (string)($row['db_name'] ?? '');
}

function orderTaskColumnExists(mysqli $conn, string $table, string $column): bool
{
    $db = $conn->real_escape_string(orderTaskDatabaseName($conn));
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    if ($db === '') {
        return false;
    }

    $exists = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$table}' AND COLUMN_NAME='{$column}' LIMIT 1");
    return $exists && $exists->num_rows > 0;
}

function orderTaskIndexExists(mysqli $conn, string $table, string $index): bool
{
    $db = $conn->real_escape_string(orderTaskDatabaseName($conn));
    $table = $conn->real_escape_string($table);
    $index = $conn->real_escape_string($index);

    if ($db === '') {
        return false;
    }

    $exists = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$table}' AND INDEX_NAME='{$index}' LIMIT 1");
    return $exists && $exists->num_rows > 0;
}

function ensureOrderTaskAssignmentSchema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $table = $conn->query("SHOW TABLES LIKE 'orders'");
    if (!$table || $table->num_rows === 0) {
        return;
    }

    if (!orderTaskColumnExists($conn, 'orders', 'assigned_to')) {
        $conn->query("ALTER TABLE orders ADD COLUMN assigned_to INT NULL AFTER notes");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'created_by')) {
        $conn->query("ALTER TABLE orders ADD COLUMN created_by INT NULL AFTER assigned_to");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'task_title')) {
        $after = orderTaskColumnExists($conn, 'orders', 'assigned_to') ? ' AFTER assigned_to' : '';
        $conn->query("ALTER TABLE orders ADD COLUMN task_title VARCHAR(160) NULL" . $after);
    }
    if (!orderTaskColumnExists($conn, 'orders', 'task_instructions')) {
        $conn->query("ALTER TABLE orders ADD COLUMN task_instructions TEXT NULL AFTER task_title");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'task_deadline')) {
        $conn->query("ALTER TABLE orders ADD COLUMN task_deadline DATETIME NULL AFTER task_instructions");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'task_status')) {
        $conn->query("ALTER TABLE orders ADD COLUMN task_status ENUM('not_started','in_progress','done','on_hold') NOT NULL DEFAULT 'not_started' AFTER task_deadline");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'assigned_by')) {
        $conn->query("ALTER TABLE orders ADD COLUMN assigned_by INT NULL AFTER task_status");
    }
    if (!orderTaskColumnExists($conn, 'orders', 'assigned_at')) {
        $conn->query("ALTER TABLE orders ADD COLUMN assigned_at DATETIME NULL AFTER assigned_by");
    }

    if (!orderTaskIndexExists($conn, 'orders', 'idx_orders_task_status')) {
        $conn->query("ALTER TABLE orders ADD INDEX idx_orders_task_status (task_status)");
    }
    if (!orderTaskIndexExists($conn, 'orders', 'idx_orders_task_deadline')) {
        $conn->query("ALTER TABLE orders ADD INDEX idx_orders_task_deadline (task_deadline)");
    }

    $ready = true;
}

function orderTaskStatusOptions(): array
{
    return [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'done'        => 'Done',
        'on_hold'     => 'On Hold',
    ];
}

function isValidOrderTaskStatus(?string $status): bool
{
    return is_string($status) && array_key_exists($status, orderTaskStatusOptions());
}

function normalizeOrderTaskStatus(?string $status): string
{
    $status = trim((string)$status);
    return isValidOrderTaskStatus($status) ? $status : 'not_started';
}

function orderTaskStatusLabel(?string $status): string
{
    $status = normalizeOrderTaskStatus($status);
    return orderTaskStatusOptions()[$status] ?? 'Not Started';
}

function orderTaskBadge(?string $status): string
{
    $status = normalizeOrderTaskStatus($status);
    $labels = orderTaskStatusOptions();
    $styles = [
        'not_started' => 'background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;',
        'in_progress' => 'background:#DBEAFE;color:#1D4ED8;border:1px solid #93C5FD;',
        'done'        => 'background:#D1FAE5;color:#047857;border:1px solid #6EE7B7;',
        'on_hold'     => 'background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5;',
    ];

    $label = htmlspecialchars($labels[$status] ?? 'Not Started', ENT_QUOTES, 'UTF-8');
    $style = $styles[$status] ?? $styles['not_started'];
    return "<span class=\"task-badge task-{$status}\" style=\"display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:.72rem;font-weight:700;{$style}\">{$label}</span>";
}

function normalizeTaskDeadlineInput(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function taskDeadlineFromPickup(?string $pickupDate, ?string $pickupTime): ?string
{
    $pickupDate = trim((string)$pickupDate);
    $pickupTime = trim((string)$pickupTime);
    if ($pickupDate === '' || $pickupDate === '0000-00-00') {
        return null;
    }

    $time = $pickupTime !== '' ? substr($pickupTime, 0, 5) : '17:00';
    return normalizeTaskDeadlineInput($pickupDate . ' ' . $time);
}

function taskDeadlineInputValue(?string $value): string
{
    $value = normalizeTaskDeadlineInput($value);
    if ($value === null) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($value));
}

function formatTaskDeadline(?string $value): string
{
    $value = normalizeTaskDeadlineInput($value);
    if ($value === null) {
        return 'No deadline';
    }

    return date('M j, Y g:i A', strtotime($value));
}

function defaultTaskTitle(?string $orderType = null): string
{
    $orderType = trim((string)$orderType);
    return $orderType !== '' ? 'Prepare ' . $orderType : 'Production Task';
}

function logOrderTaskAssignment(mysqli $conn, int $orderId, string $currentStatus, int $actorId, string $note): void
{
    $note = trim($note);
    if ($orderId <= 0 || $note === '') {
        return;
    }

    $status = $currentStatus !== '' ? $currentStatus : 'pending';
    $stmt = $conn->prepare("INSERT INTO production_logs (order_id, status_from, status_to, notes, updated_by) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isssi', $orderId, $status, $status, $note, $actorId);
    $stmt->execute();
    $stmt->close();
}

function notifyAssignedStaffTask(mysqli $conn, int $orderId, string $orderNumber, ?int $staffId, int $actorId, string $taskTitle, ?string $deadline): void
{
    if (!$staffId || $staffId <= 0 || !function_exists('createNotification')) {
        return;
    }

    $taskTitle = trim($taskTitle) !== '' ? trim($taskTitle) : 'Production Task';
    $body = 'Task: ' . $taskTitle;
    if ($deadline) {
        $body .= ' • Due ' . formatTaskDeadline($deadline);
    }

    createNotification(
        $conn,
        $staffId,
        'task',
        'Task assigned: ' . $orderNumber,
        $body,
        'normal',
        'order',
        $orderId,
        threadKeyForOrder($orderId),
        $orderId,
        'task-assignment-' . $orderId . '-' . $staffId
    );
}
