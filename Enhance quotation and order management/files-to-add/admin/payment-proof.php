<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payments.php';
ensureCheckoutPaymentSchema($conn);
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT proof_image FROM checkout_payments WHERE id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$path = __DIR__ . '/../uploads/payment-proofs/' . basename($row['proof_image'] ?? '');
if (!$row || !is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
