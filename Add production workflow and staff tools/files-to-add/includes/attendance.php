<?php

function ensureStaffAttendanceTable(mysqli $conn): bool {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $ready = (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS staff_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            time_in DATETIME NOT NULL,
            time_out DATETIME NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_attendance_day (user_id, attendance_date),
            INDEX idx_staff_attendance_date (attendance_date)
        )
    ");

    return $ready;
}

function staffAttendanceToday(mysqli $conn, int $userId): ?array {
    if ($userId <= 0 || !ensureStaffAttendanceTable($conn)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM staff_attendance
        WHERE user_id = ? AND attendance_date = CURDATE()
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function staffTimeIn(mysqli $conn, int $userId, string $source = 'manual'): array {
    if ($userId <= 0 || !ensureStaffAttendanceTable($conn)) {
        return ['ok' => false, 'error' => 'Attendance is not ready yet.'];
    }

    $existing = staffAttendanceToday($conn, $userId);
    if ($existing) {
        return ['ok' => true, 'attendance' => $existing, 'created' => false];
    }

    $stmt = $conn->prepare("
        INSERT INTO staff_attendance (user_id, attendance_date, time_in, source)
        VALUES (?, CURDATE(), NOW(), ?)
    ");
    $stmt->bind_param('is', $userId, $source);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'error' => 'Unable to record time in.'];
    }

    return ['ok' => true, 'attendance' => staffAttendanceToday($conn, $userId), 'created' => true];
}

function staffTimeOut(mysqli $conn, int $userId): array {
    if ($userId <= 0 || !ensureStaffAttendanceTable($conn)) {
        return ['ok' => false, 'error' => 'Attendance is not ready yet.'];
    }

    $attendance = staffAttendanceToday($conn, $userId);
    if (!$attendance) {
        return ['ok' => false, 'error' => 'Please time in first before timing out.'];
    }

    if (!empty($attendance['time_out'])) {
        return ['ok' => true, 'attendance' => $attendance, 'changed' => false];
    }

    $stmt = $conn->prepare("
        UPDATE staff_attendance
        SET time_out = NOW()
        WHERE user_id = ? AND attendance_date = CURDATE() AND time_out IS NULL
    ");
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'error' => 'Unable to record time out.'];
    }

    return ['ok' => true, 'attendance' => staffAttendanceToday($conn, $userId), 'changed' => true];
}

function staffAttendanceHistory(mysqli $conn, int $userId, int $limit = 14): array {
    if ($userId <= 0 || !ensureStaffAttendanceTable($conn)) {
        return [];
    }

    $limit = max(1, min(60, $limit));
    $stmt = $conn->prepare("
        SELECT *
        FROM staff_attendance
        WHERE user_id = ?
        ORDER BY attendance_date DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function staffAttendanceDuration(?string $timeIn, ?string $timeOut): string {
    if (!$timeIn) {
        return '-';
    }

    $start = strtotime($timeIn);
    $end = $timeOut ? strtotime($timeOut) : time();

    if (!$start || !$end || $end < $start) {
        return '-';
    }

    $minutes = (int)floor(($end - $start) / 60);
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    return $hours . 'h ' . str_pad((string)$mins, 2, '0', STR_PAD_LEFT) . 'm';
}
