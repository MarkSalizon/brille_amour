<?php
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/payment_receipts.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
    http_response_code(413);
    exit;
}

$body = file_get_contents('php://input', false, null, 0, 1048577);
if (strlen($body) > 1048576
    || !paymongoSignatureValid(
        $body,
        $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '',
        (string)config_env('PAYMONGO_WEBHOOK_SECRET'),
        paymongoLive()
    )) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}

try {
    $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['data']['attributes'] ?? [];
    $eventType = (string)($event['type'] ?? '');
    $resource = $event['data'] ?? [];

    require_once __DIR__ . '/../includes/db.php';
    ensurePaymongoSchema($conn);

    if ($eventType === 'checkout_session.payment.paid') {
        $reference = (string)($resource['attributes']['reference_number'] ?? '');
        $stmt = $conn->prepare('SELECT g.payment_id FROM paymongo_checkouts g JOIN checkout_payments p ON p.id=g.payment_id WHERE p.reference_number=?');
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        // Other applications may share this merchant account.
        if (!$row) {
            echo '{"ok":true}';
            exit;
        }
        if (!paymongoSettle($conn, (int)$row['payment_id'], $resource)) {
            throw new RuntimeException('Paid checkout event has no matching paid payment.');
        }
        if (ensureReceiptForPayment($conn, (int)$row['payment_id'])) paymentReceiptEmail($conn, (int)$row['payment_id']);
        echo '{"ok":true}';
        exit;
    }

    if ($eventType === 'payment.paid') {
        $intentId = (string)($resource['attributes']['payment_intent_id'] ?? '');
        if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intentId)) {
            echo '{"ok":true}';
            exit;
        }
        $stmt = $conn->prepare("SELECT payment_id FROM paymongo_checkouts WHERE flow_type='wallet' AND payment_intent_id=?");
        $stmt->bind_param('s', $intentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            echo '{"ok":true}';
            exit;
        }
        paymongoSettlePaymentEvent($conn, (int)$row['payment_id'], $resource);
        if (ensureReceiptForPayment($conn, (int)$row['payment_id'])) paymentReceiptEmail($conn, (int)$row['payment_id']);
        echo '{"ok":true}';
        exit;
    }

    // payment.failed and unrelated events are acknowledged but do not credit orders.
    echo '{"ok":true}';
} catch (Throwable $e) {
    error_log('PayMongo webhook processing failed; review provider delivery log.');
    http_response_code(500);
    echo '{"ok":false}';
}
