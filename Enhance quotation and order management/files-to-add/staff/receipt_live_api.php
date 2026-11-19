<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}
require_once '../includes/config.php'; require_once '../includes/db.php'; require_once '../includes/functions.php';
require_once '../includes/payments.php'; require_once '../includes/payment_receipts.php'; require_once '../includes/receipt_live.php';
ensureCheckoutPaymentSchema($conn); ensurePaymentReceiptSchema($conn);
session_write_close(); // polling must never block other requests of the same session
receiptLiveStaffResponse($conn);
