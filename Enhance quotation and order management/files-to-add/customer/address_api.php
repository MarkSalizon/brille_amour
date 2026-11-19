<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/customer_auth.php';
startCustomerSession();
function respondAddress(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondAddress(['ok'=>false,'error'=>'Method not allowed.'],405);
if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) respondAddress(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.'],403);
$customer = loadSignedInCustomer($conn);
if (!$customer) respondAddress(['ok'=>false,'error'=>'Sign in to update your checkout details.'],401);
$contact = trim((string)($_POST['contact'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
if (!preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contact)) respondAddress(['ok'=>false,'error'=>'Enter a valid contact number.'],422);
if ($address !== '' && (mb_strlen($address) < 5 || mb_strlen($address) > 1000)) respondAddress(['ok'=>false,'error'=>'Enter a complete delivery address (5–1000 characters), or leave it blank for pickup.'],422);
try {
    $customerId = (int)$customer['id'];
    $stmt = $conn->prepare("UPDATE customers SET contact=?, address=? WHERE id=? AND status='active'");
    $stmt->bind_param('ssi', $contact, $address, $customerId);
    $stmt->execute();
    $stmt->close();
    respondAddress(['ok'=>true,'contact'=>$contact,'address'=>$address]);
} catch (Throwable $e) {
    error_log('Customer checkout details save failed: ' . $e->getMessage());
    respondAddress(['ok'=>false,'error'=>'Unable to save your checkout details right now. Please try again.'],500);
}
