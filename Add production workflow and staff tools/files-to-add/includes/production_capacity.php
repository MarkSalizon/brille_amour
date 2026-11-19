<?php
/**
 * Production active-work capacity.
 *
 * Waiting is intentionally unlimited. Every eligible order is admitted to its
 * staff stage immediately and remains in Waiting until a staff member accepts
 * it. The admin-controlled limit applies only to accepted/active work.
 * production_stage_queue is retained only to migrate older queued records.
 */

function ensureProductionStageQueueSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS production_stage_queue (
        order_id INT NOT NULL PRIMARY KEY,
        source_stage VARCHAR(40) NOT NULL,
        target_stage VARCHAR(40) NOT NULL,
        queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        queued_by INT NULL,
        INDEX idx_target_fifo (target_stage, queued_at, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function productionWipLimit(mysqli $conn, string $stage): int
{
    // One shared admin setting controls both active stages.
    if (in_array($stage, ['in_production', 'quality_check'], true)) {
        return getSettingInt($conn, 'production_wip_limit', 5);
    }
    return 0;
}

/** Only accepted orders consume active-work capacity; Waiting never consumes a slot. */
function productionStageWipCount(mysqli $conn, string $stage): int
{
    if (!in_array($stage, ['in_production', 'quality_check'], true)) return 0;
    if (!function_exists('ensureStaffAcceptanceSchema')) require_once __DIR__ . '/staff_order_acceptance.php';
    ensureStaffAcceptanceSchema($conn);
    $stmt = $conn->prepare("SELECT COUNT(*) AS total
                            FROM orders o
                            JOIN production_stage_acceptance a
                              ON a.order_id=o.id AND a.stage=? AND a.decision='accepted'
                            WHERE o.status=?");
    $stmt->bind_param('ss', $stage, $stage);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function productionStageCapacityState(mysqli $conn, string $stage): array
{
    $limit = productionWipLimit($conn, $stage);
    $used = productionStageWipCount($conn, $stage);
    return [
        'stage' => $stage,
        'used' => $used,
        'limit' => $limit,
        'available' => max(0, $limit - $used),
        'full' => $limit > 0 && $used >= $limit,
    ];
}

function productionStageHasSlot(mysqli $conn, string $stage): bool
{
    $capacity = productionStageCapacityState($conn, $stage);
    return $capacity['limit'] <= 0 || !$capacity['full'];
}

/** Oldest verified Pending customer orders first. */
function productionQueueRows(mysqli $conn, int $limit = 0): array
{
    if (function_exists('ensureCheckoutPaymentSchema')) ensureCheckoutPaymentSchema($conn);
    $limitSql = $limit > 0 ? ' LIMIT ' . max(1, min(5000, $limit)) : '';
    $sql = "SELECT o.id, o.order_number, o.created_at,
                   cp.reviewed_at AS payment_verified_at
            FROM orders o
            JOIN checkout_payment_orders cpo ON cpo.order_id=o.id
            JOIN checkout_payments cp ON cp.id=cpo.payment_id
            WHERE o.status='pending' AND cp.status='verified'
            ORDER BY COALESCE(cp.reviewed_at, cp.created_at) ASC,
                     o.created_at ASC,
                     o.id ASC{$limitSql}";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function productionQueueCount(mysqli $conn): int
{
    if (function_exists('ensureCheckoutPaymentSchema')) ensureCheckoutPaymentSchema($conn);
    $res = $conn->query("SELECT COUNT(*)
                         FROM orders o
                         JOIN checkout_payment_orders cpo ON cpo.order_id=o.id
                         JOIN checkout_payments cp ON cp.id=cpo.payment_id
                         WHERE o.status='pending' AND cp.status='verified'");
    return $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
}

function productionQueuePosition(mysqli $conn, int $orderId): ?int
{
    if ($orderId <= 0) return null;
    foreach (productionQueueRows($conn, 0) as $index => $row) {
        if ((int)$row['id'] === $orderId) return $index + 1;
    }
    return null;
}

/** FIFO queue used when a completed stage cannot enter the next stage yet. */
function stageQueueRows(mysqli $conn, string $targetStage, int $limit = 0): array
{
    ensureProductionStageQueueSchema($conn);
    if (!in_array($targetStage, ['in_production', 'quality_check'], true)) return [];
    $limitSql = $limit > 0 ? ' LIMIT ' . max(1, min(5000, $limit)) : '';
    $stmt = $conn->prepare("SELECT q.order_id AS id, q.source_stage, q.target_stage, q.queued_at,
                                   o.order_number, o.created_at, o.status
                            FROM production_stage_queue q
                            JOIN orders o ON o.id=q.order_id
                            WHERE q.target_stage=?
                            ORDER BY q.queued_at ASC, q.order_id ASC{$limitSql}");
    $stmt->bind_param('s', $targetStage);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function stageQueueCount(mysqli $conn, string $targetStage): int
{
    ensureProductionStageQueueSchema($conn);
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM production_stage_queue WHERE target_stage=?');
    $stmt->bind_param('s', $targetStage);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function stageQueuePosition(mysqli $conn, int $orderId, string $targetStage): ?int
{
    foreach (stageQueueRows($conn, $targetStage, 0) as $i => $row) {
        if ((int)$row['id'] === $orderId) return $i + 1;
    }
    return null;
}

function queuedTargetForOrder(mysqli $conn, int $orderId): ?string
{
    ensureProductionStageQueueSchema($conn);
    $stmt = $conn->prepare('SELECT target_stage FROM production_stage_queue WHERE order_id=? LIMIT 1');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc()['target_stage'] ?? null;
    $stmt->close();
    return is_string($target) && $target !== '' ? $target : null;
}

function enqueueOrderForStage(mysqli $conn, int $orderId, string $sourceStage, string $targetStage, ?int $actorId = null): bool
{
    ensureProductionStageQueueSchema($conn);
    if ($orderId <= 0 || !in_array($targetStage, ['in_production', 'quality_check'], true)) return false;
    $stmt = $conn->prepare("INSERT INTO production_stage_queue (order_id,source_stage,target_stage,queued_by)
                            VALUES (?,?,?,?)
                            ON DUPLICATE KEY UPDATE source_stage=VALUES(source_stage), target_stage=VALUES(target_stage), queued_by=VALUES(queued_by), queued_at=CURRENT_TIMESTAMP");
    $stmt->bind_param('issi', $orderId, $sourceStage, $targetStage, $actorId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function removeOrderFromStageQueue(mysqli $conn, int $orderId): void
{
    ensureProductionStageQueueSchema($conn);
    $stmt = $conn->prepare('DELETE FROM production_stage_queue WHERE order_id=?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
}

/** Migrate every legacy queued order into the unlimited Waiting stage. */
function promoteStageQueue(mysqli $conn, string $targetStage, ?int $actorId = null): array
{
    $queue = stageQueueRows($conn, $targetStage, 0);
    $promoted = [];
    foreach ($queue as $row) {
        $orderId = (int)$row['id'];
        $source = (string)$row['source_stage'];

        $stmt = $conn->prepare('UPDATE orders SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->bind_param('si', $targetStage, $orderId);
        $stmt->execute();
        $changed = $stmt->affected_rows >= 0;
        $stmt->close();
        if (!$changed) continue;

        if (!function_exists('resetStageAcceptance')) require_once __DIR__ . '/staff_order_acceptance.php';
        resetStageAcceptance($conn, $orderId, $targetStage);
        removeOrderFromStageQueue($conn, $orderId);
        $note = 'Moved from the legacy capacity queue into unlimited staff Waiting for ' . str_replace('_', ' ', $targetStage) . '.';
        $log = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,?)');
        $log->bind_param('isssi', $orderId, $source, $targetStage, $note, $actorId);
        $log->execute();
        $log->close();
        $promoted[] = [
            'id' => $orderId,
            'order_number' => (string)$row['order_number'],
            'from' => $source,
            'to' => $targetStage,
        ];
    }
    return $promoted;
}

/** Send every verified Pending order to unlimited Production Waiting immediately. */
function promoteVerifiedPendingOrdersToProduction(mysqli $conn, ?int $actorId = null): array
{
    $queue = productionQueueRows($conn, 0);
    if (!$queue) return [];

    $promoted = [];
    foreach ($queue as $row) {
        $orderId = (int)$row['id'];
        $stmt = $conn->prepare("UPDATE orders SET status='in_production', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) continue;

        if (!function_exists('resetStageAcceptance')) require_once __DIR__ . '/staff_order_acceptance.php';
        resetStageAcceptance($conn, $orderId, 'in_production');
        $note = 'Payment verified; order moved to Production Waiting. Waiting has no capacity limit.';
        $from = 'pending';
        $to = 'in_production';
        $log = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,?)');
        $log->bind_param('isssi', $orderId, $from, $to, $note, $actorId);
        $log->execute();
        $log->close();
        $promoted[] = ['id'=>$orderId,'order_number'=>(string)$row['order_number'],'from'=>$from,'to'=>$to];
    }
    return $promoted;
}

/**
 * Reconcile legacy queues and move every verified order into staff Waiting.
 * Waiting has no capacity limit; active capacity is enforced only on Accept.
 */
function reconcileProductionQueues(mysqli $conn, ?int $actorId = null): array
{
    $moves = [];
    foreach (promoteStageQueue($conn, 'quality_check', $actorId) as $move) $moves[] = $move;
    foreach (promoteStageQueue($conn, 'in_production', $actorId) as $move) $moves[] = $move;
    foreach (promoteVerifiedPendingOrdersToProduction($conn, $actorId) as $move) $moves[] = $move;
    return $moves;
}
