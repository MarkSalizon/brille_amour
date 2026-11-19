<?php
function employeeIdFromUserId(int $userId): string
{
    return 'BA-STF-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
}

function ensureEmployeeIdColumn(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN employee_id VARCHAR(32) DEFAULT NULL AFTER username");
    }

    $index = $conn->query("SHOW INDEX FROM users WHERE Key_name='idx_users_employee_id'");
    if ($index && $index->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD UNIQUE KEY idx_users_employee_id (employee_id)");
    }

    $missing = $conn->query("SELECT id FROM users WHERE role='staff' AND (employee_id IS NULL OR employee_id='')");
    if ($missing) {
        while ($row = $missing->fetch_assoc()) {
            assignEmployeeId($conn, (int)$row['id']);
        }
    }
}

function assignEmployeeId(mysqli $conn, int $userId): string
{
    $employeeId = employeeIdFromUserId($userId);
    $stmt = $conn->prepare("UPDATE users SET employee_id=? WHERE id=? AND role='staff'");
    $stmt->bind_param('si', $employeeId, $userId);
    $stmt->execute();

    return $employeeId;
}
