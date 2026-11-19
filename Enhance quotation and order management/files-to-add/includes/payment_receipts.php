<?php
/**
 * Brille Amour payment receipt records, barcode verification, audit trail,
 * receipt email, payment history, and refund/reversal state tracking.
 */

function ensurePaymentReceiptSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS payment_receipts (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL UNIQUE,
        receipt_number VARCHAR(40) NOT NULL UNIQUE,
        barcode_value VARCHAR(80) NOT NULL UNIQUE,
        receipt_status ENUM('paid','partial','refunded','reversed') NOT NULL DEFAULT 'paid',
        status_note VARCHAR(500) NOT NULL DEFAULT '',
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payment_receipts_status (receipt_status),
        INDEX idx_payment_receipts_issued (issued_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS payment_receipt_audit (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        order_id INT NULL,
        actor_type VARCHAR(20) NOT NULL,
        actor_id INT NULL,
        action_key VARCHAR(80) NOT NULL,
        notes VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_receipt_audit_payment (payment_id, created_at),
        INDEX idx_receipt_audit_order (order_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Older builds allowed one payment only per order. Keep the schema compatible
    // with partial/additional payments by removing an order_id-only UNIQUE index.
    $res = $conn->query("SHOW INDEX FROM checkout_payment_orders WHERE Column_name='order_id'");
    if ($res) {
        $drop = [];
        while ($row = $res->fetch_assoc()) {
            if ((int)($row['Non_unique'] ?? 1) === 0 && (string)($row['Key_name'] ?? '') !== 'PRIMARY') {
                $drop[(string)$row['Key_name']] = true;
            }
        }
        $res->free();
        foreach (array_keys($drop) as $idx) {
            $safe = str_replace('`', '``', $idx);
            $conn->query("ALTER TABLE checkout_payment_orders DROP INDEX `{$safe}`");
        }
    }
    $idx = $conn->query("SHOW INDEX FROM checkout_payment_orders WHERE Key_name='idx_checkout_payment_orders_order'");
    $hasOrderIdx = $idx && $idx->num_rows > 0;
    if ($idx) $idx->free();
    if (!$hasOrderIdx) {
        $conn->query('ALTER TABLE checkout_payment_orders ADD INDEX idx_checkout_payment_orders_order (order_id)');
    }
}

function paymentReceiptBusinessDetails(): array
{
    $get = static function(string $key, string $fallback=''): string {
        if (function_exists('config_env')) {
            $v = trim((string)config_env($key, ''));
            if ($v !== '') return $v;
        }
        return $fallback;
    };
    return [
        'name' => $get('BUSINESS_NAME', 'Brille Amour Prints & Crafts'),
        'address' => $get('BUSINESS_ADDRESS', ''),
        'contact' => $get('BUSINESS_CONTACT', ''),
        'email' => $get('BUSINESS_EMAIL', defined('GOOGLE_SMTP_EMAIL') ? GOOGLE_SMTP_EMAIL : ''),
        'facebook' => $get('BUSINESS_FACEBOOK', ''),
    ];
}

function paymentReceiptCreateNumber(int $paymentId): string
{
    return 'BA-R-' . date('Ymd') . '-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
}

function paymentReceiptBarcodeValue(string $receiptNumber): string
{
    $v = strtoupper(preg_replace('/[^A-Z0-9\-]+/', '', $receiptNumber) ?? '');
    return $v !== '' ? $v : 'BA-RECEIPT';
}

function ensureReceiptForPayment(mysqli $conn, int $paymentId): ?array
{
    if ($paymentId <= 0) return null;
    ensurePaymentReceiptSchema($conn);
    $stmt = $conn->prepare("SELECT p.id,p.amount,p.status,g.state FROM checkout_payments p LEFT JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE p.id=? LIMIT 1");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment || !in_array((string)($payment['state'] ?? ''), ['paid','test_paid'], true)) return null;

    $status = 'paid';
    $stmt = $conn->prepare('SELECT COALESCE(SUM(po.amount),0) paid_alloc, COALESCE(SUM(o.total_amount),0) total_due FROM checkout_payment_orders po JOIN orders o ON o.id=po.order_id WHERE po.payment_id=?');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $tot = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if ((float)($tot['paid_alloc'] ?? 0) + 0.001 < (float)($tot['total_due'] ?? 0)) $status = 'partial';

    $receiptNumber = paymentReceiptCreateNumber($paymentId);
    $barcode = paymentReceiptBarcodeValue($receiptNumber);
    $stmt = $conn->prepare("INSERT INTO payment_receipts (payment_id,receipt_number,barcode_value,receipt_status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE receipt_number=VALUES(receipt_number),barcode_value=VALUES(barcode_value),receipt_status=IF(receipt_status IN ('refunded','reversed'),receipt_status,VALUES(receipt_status))");
    $stmt->bind_param('isss', $paymentId, $receiptNumber, $barcode, $status);
    $stmt->execute();
    $stmt->close();
    return paymentReceiptByPayment($conn, $paymentId);
}

function paymentReceiptByPayment(mysqli $conn, int $paymentId): ?array
{
    ensurePaymentReceiptSchema($conn);
    $stmt = $conn->prepare('SELECT * FROM payment_receipts WHERE payment_id=? LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function paymentReceiptFind(mysqli $conn, string $code): ?array
{
    ensurePaymentReceiptSchema($conn);
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $stmt = $conn->prepare("SELECT r.*,p.reference_number,p.amount,p.payment_method,p.payment_method_other,p.status AS payment_status,p.reviewed_by,p.reviewed_at,p.created_at AS payment_created_at,g.state AS gateway_state,g.paid_payment_id,g.payment_intent_id,g.livemode
        FROM payment_receipts r JOIN checkout_payments p ON p.id=r.payment_id LEFT JOIN paymongo_checkouts g ON g.payment_id=p.id
        WHERE UPPER(r.receipt_number)=? OR UPPER(r.barcode_value)=? OR UPPER(p.reference_number)=? OR UPPER(COALESCE(g.paid_payment_id,''))=? LIMIT 1");
    $stmt->bind_param('ssss', $code,$code,$code,$code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function paymentReceiptOrders(mysqli $conn, int $paymentId): array
{
    $stmt = $conn->prepare('SELECT o.*,po.amount AS payment_allocated,c.email AS customer_email FROM checkout_payment_orders po JOIN orders o ON o.id=po.order_id LEFT JOIN customers c ON c.id=o.customer_id WHERE po.payment_id=? ORDER BY o.id');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function paymentHistoryForOrder(mysqli $conn, int $orderId): array
{
    ensurePaymentReceiptSchema($conn);
    $stmt = $conn->prepare("SELECT p.*,po.amount AS allocated,g.state AS gateway_state,g.paid_payment_id,g.payment_intent_id,g.livemode,r.receipt_number,r.barcode_value,r.receipt_status,r.status_note,r.issued_at
        FROM checkout_payment_orders po JOIN checkout_payments p ON p.id=po.payment_id LEFT JOIN paymongo_checkouts g ON g.payment_id=p.id LEFT JOIN payment_receipts r ON r.payment_id=p.id
        WHERE po.order_id=? ORDER BY p.id DESC");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function paymentReceiptAudit(mysqli $conn, int $paymentId, ?int $orderId, string $actorType, ?int $actorId, string $action, string $notes=''): void
{
    ensurePaymentReceiptSchema($conn);
    $notes = mb_substr(trim($notes), 0, 500);
    $stmt = $conn->prepare('INSERT INTO payment_receipt_audit (payment_id,order_id,actor_type,actor_id,action_key,notes) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iisiss', $paymentId, $orderId, $actorType, $actorId, $action, $notes);
    $stmt->execute();
    $stmt->close();
}

function paymentReceiptAuditRows(mysqli $conn, int $paymentId): array
{
    ensurePaymentReceiptSchema($conn);
    $stmt = $conn->prepare('SELECT * FROM payment_receipt_audit WHERE payment_id=? ORDER BY id DESC LIMIT 100');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function paymentReceiptSetState(mysqli $conn, int $paymentId, string $state, string $note, string $actorType, int $actorId): void
{
    if (!in_array($state, ['refunded','reversed','paid','partial'], true)) throw new RuntimeException('Invalid receipt state.');
    $receipt = ensureReceiptForPayment($conn, $paymentId) ?: paymentReceiptByPayment($conn, $paymentId);
    if (!$receipt) throw new RuntimeException('Receipt record was not found.');
    $stmt = $conn->prepare('UPDATE payment_receipts SET receipt_status=?,status_note=? WHERE payment_id=?');
    $stmt->bind_param('ssi', $state, $note, $paymentId);
    $stmt->execute();
    $stmt->close();
    foreach (paymentReceiptOrders($conn, $paymentId) as $order) {
        paymentReceiptAudit($conn, $paymentId, (int)$order['id'], $actorType, $actorId, 'receipt_' . $state, $note);
    }
    paymentReceiptEmail($conn, $paymentId, true);
}

function paymentReceiptEmail(mysqli $conn, int $paymentId, bool $forceStateUpdate=false): bool
{
    try {
        $receipt = ensureReceiptForPayment($conn, $paymentId) ?: paymentReceiptByPayment($conn, $paymentId);
        if (!$receipt) return false;
        $orders = paymentReceiptOrders($conn, $paymentId);
        if (!$orders) return false;
        $email = trim((string)($orders[0]['customer_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (!function_exists('authVerificationSendViaGmail')) require_once __DIR__ . '/auth_verification.php';
        $business = paymentReceiptBusinessDetails();
        $status = strtoupper((string)$receipt['receipt_status']);
        $orderNos = implode(', ', array_map(static fn($o)=>(string)$o['order_number'], $orders));
        $amount = 0.0;
        foreach ($orders as $o) $amount += (float)$o['payment_allocated'];
        $public = function_exists('config_env') ? rtrim((string)config_env('PAYMONGO_PUBLIC_URL',''), '/') : '';
        $link = $public !== '' ? $public . '/customer/payment_receipt.php?order=' . rawurlencode((string)$orders[0]['order_number']) : '';
        $subject = ($forceStateUpdate ? 'Payment receipt updated - ' : 'Payment receipt - ') . (string)$receipt['receipt_number'];
        $body = "Hi " . (string)($orders[0]['customer_name'] ?? 'Customer') . ",\n\nYour Brille Amour payment receipt is ready.\nReceipt: " . $receipt['receipt_number'] . "\nOrders: {$orderNos}\nAmount: PHP " . number_format($amount,2) . "\nStatus: {$status}";
        if ((string)$receipt['status_note'] !== '') $body .= "\nNote: " . $receipt['status_note'];
        if ($link !== '') $body .= "\n\nView / download receipt: {$link}";
        $body .= "\n\nThank you,\n" . $business['name'];
        return authVerificationSendViaGmail($email, $subject, $body);
    } catch (Throwable $e) {
        error_log('Receipt email failed: ' . $e->getMessage());
        return false;
    }
}

function paymentReceiptStatusLabel(string $status): string
{
    return [
        'paid'=>'PAID', 'partial'=>'PARTIALLY PAID', 'refunded'=>'REFUNDED', 'reversed'=>'REVERSED'
    ][$status] ?? strtoupper($status);
}
