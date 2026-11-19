<?php
/** One acceptance decision per order/stage, shared by Production and Quality Check. */
function ensureStaffAcceptanceSchema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;
    $ok = $conn->query("CREATE TABLE IF NOT EXISTS production_stage_acceptance (
        order_id INT NOT NULL,
        stage VARCHAR(32) NOT NULL,
        decision ENUM('accepted','declined') NOT NULL,
        staff_id INT NOT NULL,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (order_id, stage),
        INDEX idx_stage_decision (stage, decision),
        INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$ok) throw new RuntimeException('Unable to set up staff order acceptance. Contact the administrator.');
    $ready = true;
}

function staffAcceptanceStage(?string $staffRole): ?string
{
    return $staffRole === 'production' ? 'in_production' : ($staffRole === 'quality_check' ? 'quality_check' : null);
}

function resetStageAcceptance(mysqli $conn, int $orderId, string $stage): void
{
    if ($orderId <= 0 || !in_array($stage, ['in_production','quality_check'], true)) return;
    ensureStaffAcceptanceSchema($conn);
    $stmt = $conn->prepare('DELETE FROM production_stage_acceptance WHERE order_id=? AND stage=?');
    $stmt->bind_param('is', $orderId, $stage);
    $stmt->execute();
    $stmt->close();
}

/**
 * Re-check the persisted order, acceptance decision and FIFO queue before
 * allowing any stage action.  The board and the POST handler use this same
 * check so an accepted-looking card cannot offer an action the server rejects.
 */
function staffOrderActionEligibility(mysqli $conn, int $orderId, string $stage, int $actorId): array
{
    $result = ['allowed' => false, 'reason' => 'unavailable'];
    if ($orderId <= 0 || $actorId <= 0 || !in_array($stage, ['in_production', 'quality_check'], true)) {
        return ['allowed' => false, 'reason' => 'invalid'];
    }
    try {
        ensureStaffAcceptanceSchema($conn);
        if (!function_exists('ensureProductionStageQueueSchema')) require_once __DIR__ . '/production_capacity.php';
        ensureProductionStageQueueSchema($conn);

        // Keep the lookup simple and identical to the acceptance record joined
        // into the board. A complex multi-table prepare used to fail closed on
        // some deployed MySQL versions even when this staff had accepted.
        $stmt = $conn->prepare('SELECT decision, staff_id FROM production_stage_acceptance WHERE order_id=? AND stage=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Acceptance query could not be prepared: ' . $conn->error);
        $stmt->bind_param('is', $orderId, $stage);
        if (!$stmt->execute()) throw new RuntimeException('Acceptance query failed: ' . $stmt->error);
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$record || (string)$record['decision'] !== 'accepted') {
            return ['allowed' => false, 'reason' => 'not_accepted'];
        }
        if ((int)$record['staff_id'] !== $actorId) {
            return ['allowed' => false, 'reason' => 'other_staff'];
        }

        $stmt = $conn->prepare('SELECT status FROM orders WHERE id=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Order query could not be prepared: ' . $conn->error);
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) throw new RuntimeException('Order query failed: ' . $stmt->error);
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || (string)$order['status'] !== $stage) {
            return ['allowed' => false, 'reason' => 'stage_changed'];
        }

        $stmt = $conn->prepare('SELECT 1 FROM production_stage_queue WHERE order_id=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Queue query could not be prepared: ' . $conn->error);
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) throw new RuntimeException('Queue query failed: ' . $stmt->error);
        $queued = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($queued) return ['allowed' => false, 'reason' => 'queued'];
        return ['allowed' => true, 'reason' => 'accepted_by_me'];
    } catch (Throwable $e) {
        error_log('Staff order eligibility lookup failed: ' . $e->getMessage());
        return $result;
    }
}

/** A staff member may only work on orders they personally accepted in their current stage. */
function staffHasAcceptedOrder(mysqli $conn, int $orderId, string $stage, int $actorId): bool
{
    return staffOrderActionEligibility($conn, $orderId, $stage, $actorId)['allowed'];
}

/** Display an actionable reason without leaking another staff member's account details. */
function staffOrderActionFailureMessage(string $reason): string
{
    switch ($reason) {
        case 'not_accepted': return 'This order is not accepted yet. Open Waiting and select Accept before updating its status.';
        case 'other_staff': return 'This order was accepted by another staff member. Only that staff member can update its status.';
        case 'queued': return 'This order is already queued for its next stage. Refresh the Production Board to see its latest status.';
        case 'stage_changed': return 'This order has moved to another stage. Refresh the Production Board.';
        case 'unavailable': return 'The acceptance record could not be verified. Please refresh and retry; if it persists, contact the administrator.';
        default: return 'Your account cannot update this order. Refresh the Production Board and try again.';
    }
}

/** POST-only + CSRF must be checked by the caller. Lock the order to prevent competing staff decisions. */
function decideStaffOrder(mysqli $conn, int $orderId, int $actorId, ?string $staffRole, string $decision, string $reason = ''): array
{
    $stage = staffAcceptanceStage($staffRole);
    if (!$stage || $orderId <= 0 || $actorId <= 0 || !in_array($decision, ['accept','decline'], true)) {
        return ['ok'=>false,'error'=>'Invalid staff order decision.'];
    }
    $reason = trim($reason);
    if ($decision === 'decline' && ($reason === '' || mb_strlen($reason) > 500)) {
        return ['ok'=>false,'error'=>'Please enter a reason for declining (up to 500 characters).'];
    }
    ensureStaffAcceptanceSchema($conn);
    if (!function_exists('ensureProductionStageQueueSchema')) require_once __DIR__ . '/production_capacity.php';
    ensureProductionStageQueueSchema($conn); // Create both tables before row locks or transactions.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, order_number, status FROM orders WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || $order['status'] !== $stage) throw new RuntimeException('This order is no longer in your production stage.');
        $stmt = $conn->prepare('SELECT 1 FROM production_stage_queue WHERE order_id=? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $queued = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($queued) throw new RuntimeException('This order is still waiting for its next stage.');
        $stmt = $conn->prepare('SELECT decision,staff_id FROM production_stage_acceptance WHERE order_id=? AND stage=? FOR UPDATE');
        $stmt->bind_param('is', $orderId, $stage);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing && $existing['decision']==='accepted') {
            throw new RuntimeException((int)$existing['staff_id'] === $actorId ? 'You already accepted this order.' : 'This order has already been accepted by another staff member.');
        }

        // Waiting is unlimited, but Accepted work still respects the active-order
        // limit. Lock current accepted rows so competing Accept clicks cannot
        // intentionally bypass the capacity check.
        if ($decision === 'accept') {
            $limit = productionWipLimit($conn, $stage);
            if ($limit > 0) {
                $capacityLock = $conn->prepare("SELECT a.order_id
                    FROM production_stage_acceptance a
                    JOIN orders o ON o.id=a.order_id
                    WHERE a.stage=? AND a.decision='accepted' AND o.status=?
                    FOR UPDATE");
                if (!$capacityLock) throw new RuntimeException('Unable to verify active production capacity.');
                $capacityLock->bind_param('ss', $stage, $stage);
                $capacityLock->execute();
                $activeRows = $capacityLock->get_result()->fetch_all(MYSQLI_NUM);
                $capacityLock->close();
                if (count($activeRows) >= $limit) {
                    throw new RuntimeException('Active work is full (' . $limit . ' orders). This order will stay in Waiting until a slot is available.');
                }
            }
        }

        $saved = $decision === 'accept' ? 'accepted' : 'declined';
        $stmt = $conn->prepare('INSERT INTO production_stage_acceptance (order_id, stage, decision, staff_id, reason) VALUES (?,?,?,?,?)
          ON DUPLICATE KEY UPDATE decision=VALUES(decision),staff_id=VALUES(staff_id),reason=VALUES(reason),decided_at=CURRENT_TIMESTAMP');
        $stmt->bind_param('issis', $orderId, $stage, $saved, $actorId, $reason);
        $stmt->execute();
        $stmt->close();
        $logNotes = ($decision === 'accept' ? 'Accepted ' : 'Declined ') . str_replace('_',' ',$stage) . ' assignment' . ($reason !== '' ? ': '.$reason : '');
        $log = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,?)');
        $log->bind_param('isssi', $orderId, $stage, $stage, $logNotes, $actorId);
        $log->execute();
        $log->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok'=>false,'error'=>$e instanceof RuntimeException ? $e->getMessage() : 'Could not save the decision. Please retry.'];
    }
    // A customer-facing stage update should reflect actual acceptance, not merely
    // the earlier automatic admission to this unlimited Waiting stage.
    if ($decision === 'accept') {
        try {
            if (!function_exists('createNotification')) require_once __DIR__ . '/messaging.php';
            $customerId = registeredCustomerIdForOrder($conn, $orderId);
            if ($customerId) {
                createNotification($conn, registeredCustomerMessagingUserId($customerId), 'production',
                    'Order ' . $order['order_number'] . ': ' . ($stage === 'in_production' ? 'Production started' : 'Quality check started'),
                    $stage === 'in_production' ? 'A staff member has accepted your order and begun production.' : 'A staff member has accepted your order for quality inspection.',
                    'normal', 'order', $orderId, null, $orderId,
                    'stage-accepted-' . $stage . '-' . $orderId);
            }
        } catch (Throwable $ignored) { /* Stage acceptance is already committed. */ }
    }

    // Notify admins about declined work without deleting/reassigning any customer order.
    if ($decision === 'decline') {
        try {
            if (!function_exists('createNotification')) require_once __DIR__ . '/messaging.php';
            foreach (activeAdminIds($conn) as $adminId) {
                createNotification($conn, $adminId, 'production', 'Staff declined order ' . $order['order_number'],
                    ($stage === 'in_production' ? 'Production' : 'Quality Check') . ': ' . $reason,
                    'warning', 'order', $orderId, null, $orderId);
            }
        } catch (Throwable $ignored) { /* Acceptance is already committed. */ }
    }
    return ['ok'=>true,'changed'=>true,'message'=>$decision === 'accept'
        ? 'Order accepted. You can now work on it.'
        : 'Order declined. Admin has been notified; the order is awaiting acceptance.'];
}


/**
 * Number of NEW orders waiting for this staff member's stage (shown as the badge
 * on the sidebar "Production" link). An order counts while it sits in the staff
 * role's stage, has been admitted from the waiting queue, and nobody has yet
 * accepted or declined it. Returns 0 for staff without a production role.
 */
function staffIncomingOrderCount(mysqli $conn, int $userId): int
{
    if ($userId <= 0) return 0;
    try {
        if (!function_exists('getStaffRoleForUser')) require_once __DIR__ . '/staff_roles.php';
        $stage = staffAcceptanceStage(getStaffRoleForUser($conn, $userId));
        if (!$stage) return 0;
        ensureStaffAcceptanceSchema($conn);
        if (!function_exists('ensureProductionStageQueueSchema')) require_once __DIR__ . '/production_capacity.php';
        ensureProductionStageQueueSchema($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o
            WHERE o.status = ?
              AND NOT EXISTS (SELECT 1 FROM production_stage_queue psq WHERE psq.order_id = o.id)
              AND NOT EXISTS (SELECT 1 FROM production_stage_acceptance sa WHERE sa.order_id = o.id AND sa.stage = ?)");
        if (!$stmt) return 0;
        $stmt->bind_param('ss', $stage, $stage);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}
