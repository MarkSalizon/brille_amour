<?php

/**
 * Production hold support.
 *
 * The old per-piece production counter has been retired. We keep only the
 * optional hold reason so a Production Artisan can pause/resume an order
 * without exposing or requiring a production quantity counter anywhere.
 */
function ensureProductionProgressSchema(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;

    $columns = $conn->query('SHOW COLUMNS FROM orders')->fetch_all(MYSQLI_ASSOC);
    $names = array_column($columns, 'Field');

    if (!in_array('production_hold_reason', $names, true)) {
        $conn->query("ALTER TABLE orders ADD production_hold_reason VARCHAR(500) NOT NULL DEFAULT ''");
    }

    $ready = true;
}

function applyProductionHold(mysqli $conn, int $orderId, array $input, int $actorId, ?string $staffRole, bool $isAdmin): array {
    ensureProductionProgressSchema($conn);
    if (!function_exists('ensureStaffAcceptanceSchema')) require_once __DIR__ . '/staff_order_acceptance.php';
    ensureStaffAcceptanceSchema($conn); // DDL must not run inside the hold transaction.

    $reason = $input['production_hold_reason'] ?? '';
    if (!is_string($reason)) {
        return ['ok' => false, 'error' => 'Invalid hold reason.'];
    }

    $reason = trim($reason);
    $onHold = ($input['on_hold'] ?? '') === '1';

    if ($onHold && ($reason === '' || strlen($reason) > 500)) {
        return ['ok' => false, 'error' => 'Enter a hold reason (maximum 500 characters).'];
    }

    if (!$onHold) {
        $reason = '';
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT id, status, order_number, production_hold_reason FROM orders WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || !in_array((string)$order['status'], getProductionStages(), true)) {
            throw new RuntimeException('This order is no longer on the production board.');
        }

        if ((string)$order['status'] !== 'in_production') {
            throw new RuntimeException('A production hold can only be changed while the order is In Production.');
        }

        if ($isAdmin || $staffRole !== 'production' || !staffRoleOwnsStage($staffRole, 'in_production')) {
            throw new RuntimeException('Only the Production Artisan can place an order on hold or resume it.');
        }

        if (!function_exists('staffHasAcceptedOrder')) require_once __DIR__ . '/staff_order_acceptance.php';
        if (!staffHasAcceptedOrder($conn, $orderId, 'in_production', $actorId)) {
            throw new RuntimeException('Accept this order before holding or resuming production.');
        }

        if ((string)($input['expected_hold'] ?? '') !== productionHoldVersion($order)) {
            throw new RuntimeException('This order changed. Refresh the page and try again.');
        }

        $stmt = $conn->prepare('UPDATE orders SET production_hold_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('si', $reason, $orderId);
        $stmt->execute();
        $stmt->close();

        $notes = $reason !== ''
            ? 'Production placed on hold: ' . $reason
            : 'Production resumed.';

        $stmt = $conn->prepare('INSERT INTO production_logs (order_id, status_from, status_to, notes, updated_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('isssi', $orderId, $order['status'], $order['status'], $notes, $actorId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        return [
            'ok' => true,
            'message' => $reason !== '' ? 'Order placed on hold.' : 'Production resumed.',
        ];
    } catch (Throwable $e) {
        $conn->rollback();

        return [
            'ok' => false,
            'error' => $e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)
                ? $e->getMessage()
                : 'Unable to update the production hold.',
        ];
    }
}

function productionHoldVersion(array $order): string {
    return hash('sha256', implode('|', [
        $order['status'] ?? '',
        $order['production_hold_reason'] ?? '',
    ]));
}
