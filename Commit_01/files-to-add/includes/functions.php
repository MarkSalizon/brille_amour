<?php

function generateOrderNumber(?mysqli $conn = null) {
    // Short, staff-friendly order numbers: BA-001, BA-002, BA-003...
    // If a database connection is supplied, use the next orders.id so the number is easy to search and stays unique.
    if ($conn instanceof mysqli) {
        $next = 1;
        $res = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM orders');
        if ($res) {
            $row = $res->fetch_assoc();
            $next = max(1, (int)($row['next_id'] ?? 1));
            $res->free();
        }
        do {
            $orderNumber = 'BA-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare('SELECT id FROM orders WHERE order_number=? LIMIT 1');
            $stmt->bind_param('s', $orderNumber);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $next++;
        } while ($exists);
        return $orderNumber;
    }

    // Fallback for old callers without a DB connection.
    return 'BA-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
}

function appDateTime(?string $datetime, string $format = 'M j, Y g:i A'): string {
    if (!$datetime) {
        return '-';
    }

    try {
        return (new DateTime($datetime))->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function saveUploadedImage(array $file, string $uploadDir, string &$err, string $prefix = 'upload_'): ?string {
    if (empty($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $err = 'The image could not be uploaded.';
        return null;
    }

    if (!is_uploaded_file((string)$file['tmp_name'])) {
        $err = 'The uploaded image could not be verified.';
        return null;
    }

    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $err = 'The image must be 5 MB or smaller.';
        return null;
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);

    if (!isset($allowedMimeTypes[$mime])) {
        $err = 'Please upload a valid JPG, PNG, GIF, or WEBP image.';
        return null;
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        $err = 'The upload folder could not be created.';
        return null;
    }

    $filename = $prefix . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimeTypes[$mime];
    $target = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        $err = 'The image could not be saved.';
        return null;
    }

    return $filename;
}

function userRoleLimit(string $role): ?int {
    if ($role === 'admin') {
        return defined('MAX_ADMIN_USERS') ? MAX_ADMIN_USERS : 5;
    }

    if ($role === 'staff') {
        return defined('MAX_STAFF_USERS') ? MAX_STAFF_USERS : 10;
    }

    return null;
}

function countUsersByRole(mysqli $conn, string $role, int $excludeUserId = 0): int {
    if ($excludeUserId > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role=? AND id != ?");
        $stmt->bind_param('si', $role, $excludeUserId);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role=?");
        $stmt->bind_param('s', $role);
    }

    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

function roleLimitReached(mysqli $conn, string $role, int $excludeUserId = 0): bool {
    $limit = userRoleLimit($role);

    return $limit !== null && countUsersByRole($conn, $role, $excludeUserId) >= $limit;
}

function roleLimitMessage(string $role): string {
    $label = $role === 'admin' ? 'admin' : 'staff';
    $limit = userRoleLimit($role);

    return "The system allows a maximum of {$limit} {$label} account" . ($limit === 1 ? '.' : 's.');
}

function statusBadge($status) {
    $status = trim((string)$status);
    if ($status === '') {
        $status = 'pending';
    }

    $map = [
        'pending' => 'badge-pending',
        'in_production' => 'badge-production',
        'quality_check' => 'badge-quality',
        'ready' => 'badge-ready',
        'completed' => 'badge-completed',
        'cancelled' => 'badge-cancelled',
        'archived' => 'badge-archived',
    ];

    $label = ucwords(str_replace('_', ' ', $status));
    $cls = $map[$status] ?? 'badge-default';

    return "<span class='badge {$cls}'>{$label}</span>";
}

if (!function_exists('priorityBadge')) {
    function priorityBadge($priority) {
        $priority = trim((string)$priority);
        if ($priority === '') {
            $priority = 'normal';
        }

        $map = [
            'low' => 'priority-low',
            'normal' => 'priority-normal',
            'high' => 'priority-high',
            'urgent' => 'priority-urgent',
        ];

        $cls = $map[$priority] ?? 'priority-normal';

        return "<span class='priority-dot {$cls}'>" . ucfirst($priority) . "</span>";
    }
}


function productStatusBadge($status) {
    $map = [
        'active' => 'badge-ready',
        'archived' => 'badge-archived',
    ];

    $label = ucfirst($status);
    $cls = $map[$status] ?? 'badge-default';

    return "<span class='badge {$cls}'>{$label}</span>";
}

function formatMoney($amount) {
    return 'PHP ' . number_format($amount, 2);
}

function timeAgo($datetime) {
    try {
        $now = new DateTime('now');
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return '-';
    }

    $diff = $now->diff($ago);

    if ($diff->y > 0) {
        return $diff->y . 'y ago';
    }

    if ($diff->m > 0) {
        return $diff->m . 'mo ago';
    }

    if ($diff->d > 0) {
        return $diff->d . 'd ago';
    }

    if ($diff->h > 0) {
        return $diff->h . 'h ago';
    }

    if ($diff->i > 0) {
        return $diff->i . 'm ago';
    }

    return 'just now';
}


/** True when the stored order reference file is an image (shown inline) rather than a document. */
function orderReferenceIsImage(string $filename): bool {
    return (bool)preg_match('/\.(?:jpe?g|png|gif|webp)$/i', $filename);
}

/**
 * Save a file a customer wants printed (Document Printing): images plus
 * PDF / Word / PowerPoint / Excel / plain text, with no app-level size limit
 * (the server's upload_max_filesize / post_max_size still apply). The extension is
 * whitelisted AND the detected content type must match it; the stored name is random.
 */
function saveUploadedPrintFile(array $file, string $uploadDir, string &$err, string $prefix = 'print_'): ?string {
    if (empty($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $upErr = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) { $err = 'The file is larger than the server allows. Please upload a smaller file.'; return null; }
    if ($upErr !== UPLOAD_ERR_OK) { $err = 'The file could not be uploaded.'; return null; }
    if (!is_uploaded_file((string)$file['tmp_name'])) { $err = 'The uploaded file could not be verified.'; return null; }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $office = ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office'];
    $allowed = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'doc' => array_merge($office, ['application/msword']),
        'docx' => array_merge($office, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
        'ppt' => array_merge($office, ['application/vnd.ms-powerpoint']),
        'pptx' => array_merge($office, ['application/vnd.openxmlformats-officedocument.presentationml.presentation']),
        'xls' => array_merge($office, ['application/vnd.ms-excel']),
        'xlsx' => array_merge($office, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
    ];
    if (!isset($allowed[$ext])) { $err = 'Please upload a PDF, Word, PowerPoint, Excel, text or image (JPG, PNG, GIF, WEBP) file.'; return null; }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) { $err = 'The file content does not match its type. Please upload a valid .' . $ext . ' file.'; return null; }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) { $err = 'The upload folder could not be created.'; return null; }
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) { $err = 'The file could not be saved.'; return null; }
    return $filename;
}
