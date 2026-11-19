<?php
require_once __DIR__ . "/production_progress.php";
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/innovation.php';

function ensureWorkflowCsrfToken(): string {
    if (empty($_SESSION['workflow_csrf_token'])) {
        $_SESSION['workflow_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['workflow_csrf_token'];
}

function validateWorkflowCsrfToken(?string $token): bool {
    return isset($_SESSION['workflow_csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['workflow_csrf_token'], $token);
}

function getProductionStages(): array {
    return ['pending', 'in_production', 'quality_check', 'ready'];
}

function getProductionStageLabels(): array {
    return [
        'pending' => 'Pending',
        'in_production' => 'In Production',
        'quality_check' => 'Quality Check',
        'ready' => 'Ready',
    ];
}

function getProductionStageColors(): array {
    return [
        'pending' => '#F59E0B',
        'in_production' => '#3B82F6',
        'quality_check' => '#8B5CF6',
        'ready' => '#10B981',
    ];
}

function getTrackableOrderStatuses(): array {
    return ['pending', 'in_production', 'quality_check', 'ready', 'completed', 'cancelled'];
}

function getNextProductionStatus(string $status): ?string {
    $stages = getProductionStages();
    $index = array_search($status, $stages, true);

    if ($index === false) {
        return null;
    }

    return $stages[$index + 1] ?? null;
}

function getPreviousProductionStatus(string $status): ?string {
    $stages = getProductionStages();
    $index = array_search($status, $stages, true);

    if ($index === false || $index === 0) {
        return null;
    }

    return $stages[$index - 1];
}

function canMoveThroughProductionBoard(string $fromStatus, string $toStatus): bool {
    if (!in_array($fromStatus, getProductionStages(), true)) {
        return false;
    }

    if ($toStatus === 'completed') {
        return $fromStatus === 'ready';
    }

    return $toStatus === getNextProductionStatus($fromStatus)
        || $toStatus === getPreviousProductionStatus($fromStatus);
}

function updateOrderWorkflowStatus(mysqli $conn, int $orderId, string $newStatus, int $userId, string $notes = ''): array {
    ensureProductionProgressSchema($conn);
    ensureProductionStageQueueSchema($conn);
    if (!function_exists('ensureStaffAcceptanceSchema')) require_once __DIR__ . '/staff_order_acceptance.php';
    ensureStaffAcceptanceSchema($conn); // Prepare schema before opening an order transaction.
    $allowedStatuses = getTrackableOrderStatuses();
    if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        return ['ok' => false, 'error' => 'Invalid order status update.'];
    }

    $stmt = $conn->prepare("SELECT id, status, product_id, quantity, order_number, production_hold_reason FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$order) return ['ok' => false, 'error' => 'Order not found.'];

    $payment = paymentForOrder($conn, $orderId);
    if ($payment && $payment['status'] !== 'verified' && !in_array($newStatus, ['pending', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'The admin must verify the required payment before production starts.'];
    }
    if ($order['production_hold_reason'] !== '' && $newStatus !== 'cancelled') {
        return ['ok' => false, 'error' => 'Resume this order before changing its production stage.'];
    }

    $oldStatus = (string)$order['status'];
    if (in_array($newStatus, ['in_production','quality_check','ready'], true) && innovationDesignApprovalBlocksProduction($conn, $orderId)) {
        return ['ok' => false, 'error' => 'Customer design approval is still pending or changes were requested.'];
    }
    if ($oldStatus === $newStatus) {
        return ['ok' => true, 'status' => $oldStatus, 'changed' => false];
    }

    // Waiting has no capacity limit. Orders enter the next staff stage
    // immediately; only Accepted/active work consumes the configured capacity.

    $conn->begin_transaction();
    try {
        removeOrderFromStageQueue($conn, $orderId);
        $update = $conn->prepare('UPDATE orders SET status = ?, updated_at=CURRENT_TIMESTAMP WHERE id = ?');
        $update->bind_param('si', $newStatus, $orderId);
        $update->execute();
        $update->close();
        if (in_array($newStatus, ['in_production','quality_check'], true)) {
            if (!function_exists('resetStageAcceptance')) require_once __DIR__ . '/staff_order_acceptance.php';
            resetStageAcceptance($conn, $orderId, $newStatus);
        }

        if (function_exists('syncOrderProductInventory')) {
            $sync = syncOrderProductInventory(
                $conn,
                $orderId,
                (int)($order['product_id'] ?? 0),
                $newStatus === 'cancelled' ? 0 : (int)$order['quantity'],
                $userId,
                (string)$order['order_number']
            );
            if (empty($sync['ok'])) throw new RuntimeException((string)($sync['error'] ?? 'Unable to sync order inventory.'));
        }

        $logNotes = trim($notes) !== '' ? $notes : 'Status updated from production board';
        $log = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,?)');
        $log->bind_param('isssi', $orderId, $oldStatus, $newStatus, $logNotes, $userId);
        $log->execute();
        $log->close();
        $conn->commit();

        // Reconcile any legacy queue records and verified Pending orders.
        // New Waiting orders are never blocked by active-work capacity.
        $promoted = reconcileProductionQueues($conn, $userId);
        if ($promoted) {
            if (!function_exists('notifyOrderStageChange')) {
                require_once __DIR__ . '/messaging.php';
            }
            foreach ($promoted as $move) {
                notifyOrderStageChange($conn, (int)$move['id'], (string)$move['order_number'], (string)$move['from'], (string)$move['to'], $userId);
            }
        }

        return ['ok' => true, 'status' => $newStatus, 'changed' => true, 'promoted' => $promoted];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getProductionBoardColumns(mysqli $conn, array $stages): array {
    $board = [];
    $counts = [];
    ensureCheckoutPaymentSchema($conn);
    ensureInnovationSchema($conn);
    ensureProductionStageQueueSchema($conn);
    if (!function_exists('ensureStaffAcceptanceSchema')) require_once __DIR__ . '/staff_order_acceptance.php';
    ensureStaffAcceptanceSchema($conn);

    foreach ($stages as $stage) {
        $escapedStage = $conn->real_escape_string($stage);
        $queueFilter = in_array($stage, ['in_production', 'quality_check'], true)
            ? ' AND NOT EXISTS (SELECT 1 FROM production_stage_queue psq WHERE psq.order_id=o.id) '
            : '';
        $result = $conn->query("\n            SELECT o.*, u.full_name AS assigned_name,\n                   p.name AS current_product_name, p.image AS current_product_image,\n                   sa.decision AS acceptance_decision, sa.staff_id AS accepted_staff_id, sa.reason AS acceptance_reason,\n                   su.full_name AS accepted_staff_name,\n                   cp.id AS payment_id, cp.status AS payment_status,\n                   COALESCE(dt.status, CASE WHEN LOWER(COALESCE(o.fulfillment_method,'pickup'))='delivery' AND o.status='ready' THEN 'ready_for_dispatch' ELSE 'pending' END) AS delivery_status\n            FROM orders o\n            LEFT JOIN products p ON p.id = o.product_id\n            LEFT JOIN users u ON o.assigned_to = u.id\n            LEFT JOIN production_stage_acceptance sa ON sa.order_id=o.id AND sa.stage='{$escapedStage}'\n            LEFT JOIN users su ON su.id=sa.staff_id\n            LEFT JOIN checkout_payment_orders cpo ON cpo.order_id = o.id\n            LEFT JOIN checkout_payments cp ON cp.id = cpo.payment_id\n            LEFT JOIN delivery_tracking dt ON dt.order_id = o.id\n            WHERE o.status = '{$escapedStage}' {$queueFilter}\n            ORDER BY CASE o.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 2 END ASC, CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END ASC, o.due_date ASC, o.created_at ASC, o.id ASC\n        ");

        $board[$stage] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $counts[$stage] = count($board[$stage]);
    }

    return ['board' => $board, 'counts' => $counts];
}

function getOverdueProductionCount(mysqli $conn): int {
    $result = $conn->query("
        SELECT COUNT(*)
        FROM orders
        WHERE due_date < CURDATE()
          AND status NOT IN ('completed', 'cancelled', 'archived')
    ");

    return (int)($result->fetch_row()[0] ?? 0);
}

function getCompletedOrdersToday(mysqli $conn): array {
    $result = $conn->query("
        SELECT o.*, u.full_name AS assigned_name
        FROM orders o
        LEFT JOIN users u ON o.assigned_to = u.id
        WHERE o.status = 'completed'
          AND DATE(o.updated_at) = CURDATE()
        ORDER BY o.updated_at DESC
    ");

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
