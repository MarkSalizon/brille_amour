<?php
/** Incremental pre-order messages restricted to the signed-in customer. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/customer_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
startCustomerSession();
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in to receive updates.']);
    exit;
}
$customerId = (int)$customer['id'];
session_write_close();
$threadId = max(0, filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: 0);
$sinceThread = max(0, filter_input(INPUT_GET, 'since_thread', FILTER_VALIDATE_INT) ?: 0);
if ($threadId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Request ID is required.']);
    exit;
}
try {
    $stmt = $conn->prepare('SELECT status,updated_at FROM preorder_requests WHERE id=? AND customer_id=? LIMIT 1');
    $stmt->bind_param('ii', $threadId, $customerId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$request) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found.']);
        exit;
    }
    $stmt = $conn->prepare('SELECT id,request_id,sender_role,message,created_at FROM preorder_messages
        WHERE request_id=? AND id>? ORDER BY id ASC LIMIT 80');
    $stmt->bind_param('ii', $threadId, $sinceThread);
    $stmt->execute();
    $thread = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['thread' => $thread, 'status' => (string)$request['status'],
        'updated_at' => (string)$request['updated_at']], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Customer preorder live updates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Messages are temporarily unavailable.']);
}
