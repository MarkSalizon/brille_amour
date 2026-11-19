<?php
/**
 * incoming_orders_api.php (staff)
 * Tiny JSON endpoint: how many new orders are waiting for this staff member.
 * Polled by assets/js/staff-shell.js to keep the sidebar Production badge live.
 */
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'count' => 0]);
    exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/staff_order_acceptance.php';

session_write_close(); // do not block other requests while polling
echo json_encode(['ok' => true, 'count' => staffIncomingOrderCount($conn, (int)$_SESSION['user_id'])]);
