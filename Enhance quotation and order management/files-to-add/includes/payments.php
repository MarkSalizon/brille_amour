<?php
require_once __DIR__ . '/admin_settings.php';
require_once __DIR__ . '/production_capacity.php';
require_once __DIR__ . '/customer_email_notifications.php';
require_once __DIR__ . '/payment_receipts.php';


function paymentMethodOptions(): array {
    return ['GCash', 'Maya', 'QR Ph', 'Card', 'MariBank', 'GoTyme', 'SeaBank', 'BPI', 'BDO', 'UnionBank', 'LandBank', 'Metrobank', 'Other'];
}

function normalizePaymentMethod(string $method, string $other = ''): array {
    $method = trim($method);
    $other = trim($other);
    $options = paymentMethodOptions();
    if (!in_array($method, $options, true)) {
        $method = 'GCash';
    }
    if ($method === 'Other') {
        $other = preg_replace('/\s+/', ' ', $other);
        if ($other === '') {
            throw new RuntimeException('Please enter the bank or e-wallet name for Other payment method.');
        }
        $other = substr($other, 0, 100);
    } else {
        $other = '';
    }
    return [$method, $other];
}

function paymentMethodLabel(array $payment): string {
    $method = (string)($payment['payment_method'] ?? 'GCash');
    $other = trim((string)($payment['payment_method_other'] ?? ''));
    return $method === 'Other' && $other !== '' ? $other : $method;
}

function ensurePaymentColumn(mysqli $conn, string $table, string $column, string $definition): void {
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$tableEsc` LIKE '$columnEsc'");
    if ($res && $res->num_rows > 0) {
        $res->free();
        return;
    }
    if ($res) {
        $res->free();
    }
    if (!$conn->query("ALTER TABLE `$tableEsc` ADD COLUMN $definition")) {
        throw new RuntimeException('Payment storage could not be upgraded.');
    }
}


function ensureCheckoutPaymentSchema(mysqli $conn): void {
    static $done = false;
    if ($done) { return; }
    $done = true;

    // Keep the manual GCash flow usable on databases created before the
    // payment feature was added. These CREATEs are idempotent and do not
    // modify existing payment records.
    if (!$conn->query("CREATE TABLE IF NOT EXISTS checkout_payments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(30) NOT NULL UNIQUE,
        proof_image VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
        review_note VARCHAR(500) NOT NULL DEFAULT '',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_checkout_payments_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Payment storage could not be initialized.');
    }

    ensurePaymentColumn($conn, 'checkout_payments', 'payment_method', "payment_method VARCHAR(80) NOT NULL DEFAULT 'GCash' AFTER reference_number");
    ensurePaymentColumn($conn, 'checkout_payments', 'payment_method_other', "payment_method_other VARCHAR(100) NULL AFTER payment_method");

    if (!$conn->query("CREATE TABLE IF NOT EXISTS checkout_payment_orders (
        payment_id INT NOT NULL,
        order_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (payment_id, order_id),
        INDEX idx_checkout_payment_orders_payment (payment_id),
        INDEX idx_checkout_payment_orders_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Payment order links could not be initialized.');
    }
    ensurePaymentReceiptSchema($conn);
}

function paymentForOrder(mysqli $conn, int $orderId): ?array {
    ensureCheckoutPaymentSchema($conn);
    $stmt = $conn->prepare('SELECT p.*, po.amount AS order_deposit,g.state AS gateway_state FROM checkout_payments p JOIN checkout_payment_orders po ON po.payment_id=p.id LEFT JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE po.order_id=? ORDER BY p.id DESC LIMIT 1');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function paymentStatusLabel(string $status): string {
    return ['pending' => 'Pending payment verification', 'verified' => 'Payment verified', 'rejected' => 'Payment rejected - contact the shop'][$status] ?? 'Awaiting payment';
}

function reviewCheckoutPayment(mysqli $conn, int $id, string $decision, string $note, int $adminId): void {
    ensureCheckoutPaymentSchema($conn);
    if (!in_array($decision, ['verified', 'rejected'], true) || ($decision === 'rejected' && trim($note) === '')) {
        throw new RuntimeException('Choose a valid decision and give a reason when rejecting payment.');
    }

    $customerStageChanges = [];
    $customerQueuedOrders = [];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM checkout_payments WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        if (!$payment || $payment['status'] === 'verified') {
            throw new RuntimeException('Payment was already verified or does not exist.');
        }

        // PayMongo confirms that the gateway payment happened, but the shop
        // admin is still the final gate that credits the payment and starts
        // production. Manual receipt payments continue to use the old flow.
        $gatewayStmt = $conn->prepare('SELECT * FROM paymongo_checkouts WHERE payment_id=?');
        $gatewayStmt->bind_param('i', $id);
        $gatewayStmt->execute();
        $gateway = $gatewayStmt->get_result()->fetch_assoc() ?: null;

        if ($gateway) {
            if ($decision === 'rejected') {
                throw new RuntimeException('A PayMongo-confirmed payment cannot be rejected here. Reconcile it from the PayMongo record if there is a payment issue.');
            }
            $gatewayState = (string)($gateway['state'] ?? '');
            $paidPaymentId = (string)($gateway['paid_payment_id'] ?? '');
            if (!in_array($gatewayState, ['paid', 'test_paid'], true) || $paidPaymentId === '') {
                if ($gatewayState === 'review') {
                    throw new RuntimeException('This PayMongo payment needs reconciliation before it can be verified.');
                }
                throw new RuntimeException('PayMongo has not confirmed this payment yet. Check PayMongo status first.');
            }
        }

        $stmt = $conn->prepare('SELECT o.*, po.amount AS allocated FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id WHERE po.payment_id=? ORDER BY o.id FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$orders) {
            throw new RuntimeException('No orders remain for this payment.');
        }

        if ($decision === 'verified' && abs(array_sum(array_column($orders, 'allocated')) - (float)$payment['amount']) > 0.001) {
            throw new RuntimeException('The checkout orders have changed. Resolve the payment with the customer before verification.');
        }

        foreach ($orders as $order) {
            $oldStatus = (string)$order['status'];
            $newStatus = $oldStatus;

            if ($decision === 'verified') {
                $allocated = (float)$order['allocated'];
                $depositPaid = (float)$order['deposit_paid'];
                $totalAmount = (float)$order['total_amount'];

                if (in_array($oldStatus, ['cancelled', 'archived'], true) || $totalAmount + 0.001 < $allocated) {
                    throw new RuntimeException('An order is cancelled, archived, or its total has changed. Review the order first.');
                }

                // Older PayMongo builds could credit the deposit before the
                // explicit admin-verification gate was added. Treat an exact
                // existing credit as already credited instead of blocking the
                // admin from completing verification. Any different non-zero
                // amount still requires manual reconciliation.
                if ($depositPaid > 0.001 && abs($depositPaid - $allocated) > 0.01) {
                    throw new RuntimeException('The order already has a different payment amount. Review the order before verification.');
                }

                // Admin verification is the production gate for BOTH manual
                // payments and PayMongo-confirmed payments. This update is
                // idempotent when an older record was already credited.
                // Credit the payment first. Verified Pending orders move straight
                // into Production Waiting; only staff-accepted work uses capacity.
                $newStatus = $oldStatus;
                $update = $conn->prepare('UPDATE orders SET deposit_paid=?, balance=GREATEST(0,total_amount-?), status=? WHERE id=?');
                $update->bind_param('ddsi', $allocated, $allocated, $newStatus, $order['id']);
                $update->execute();
            }

            $message = $gateway
                ? (((string)($gateway['state'] ?? '') === 'test_paid')
                    ? 'Admin verified PayMongo TEST payment and credited the simulated payment'
                    : 'Admin verified PayMongo payment and credited the payment')
                : paymentStatusLabel($decision);

            if ($decision === 'verified' && $oldStatus === 'pending') {
                $message .= '. Order moved to Production Waiting and is ready for staff acceptance';
            }
            if ($note !== '') {
                $message .= ': ' . $note;
            }

            $log = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,?)');
            $log->bind_param('isssi', $order['id'], $oldStatus, $newStatus, $message, $adminId);
            $log->execute();
        }

        $stmt = $conn->prepare('UPDATE checkout_payments SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?');
        $stmt->bind_param('ssii', $decision, $note, $adminId, $id);
        $stmt->execute();

        if ($decision === 'verified') {
            // Move all verified Pending orders into unlimited Production Waiting,
            // including older verified orders left by a previous build.
            $promoted = promoteVerifiedPendingOrdersToProduction($conn, $adminId);
            foreach ($promoted as $move) {
                $customerStageChanges[] = [
                    (int)$move['id'],
                    (string)$move['order_number'],
                    (string)$move['from'],
                    (string)$move['to'],
                ];
            }

            $promotedIds = array_fill_keys(array_map(static fn($m) => (int)$m['id'], $promoted), true);
            foreach ($orders as $order) {
                $orderId = (int)$order['id'];
                if ((string)$order['status'] === 'pending' && !isset($promotedIds[$orderId])) {
                    $customerQueuedOrders[] = [$orderId, (string)$order['order_number']];
                }
            }
        }

        foreach ($orders as $auditOrder) {
            paymentReceiptAudit($conn, $id, (int)$auditOrder['id'], 'admin', $adminId, 'payment_' . $decision, trim($note));
        }
        $conn->commit();

        // Email each affected registered customer about the payment decision.
        foreach ($orders as $reviewedOrder) {
            emailCustomerPaymentReviewed($conn, (int)$reviewedOrder['id'], $id, $decision, $note);
        }

        if ($customerStageChanges || $customerQueuedOrders) {
            try {
                require_once __DIR__ . '/messaging.php';
                foreach ($customerStageChanges as [$changedOrderId, $changedOrderNumber, $fromStatus, $toStatus]) {
                    notifyOrderStageChange($conn, $changedOrderId, $changedOrderNumber, $fromStatus, $toStatus, $adminId);
                }
                foreach ($customerQueuedOrders as [$queuedOrderId, $queuedOrderNumber]) {
                    if (function_exists('notifyOrderProductionQueued')) {
                        notifyOrderProductionQueued(
                            $conn,
                            $queuedOrderId,
                            $queuedOrderNumber,
                            productionQueuePosition($conn, $queuedOrderId)
                        );
                    }
                }
            } catch (Throwable $notificationError) {
                // Payment verification is already committed; notifications are best-effort.
            }
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
