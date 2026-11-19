<?php
/**
 * production_board.php
 * ─────────────────────────────────────────────────────────
 * One source of truth for the Production Board: the data it needs, the markup
 * it renders, the rule check behind a move, and a cheap revision fingerprint
 * the real-time poller uses to detect change.
 *
 * Used by staff/production.php, admin/production.php and the AJAX endpoints
 * (staff/production_api.php, admin/production_api.php) so the board a page
 * prints and the board a poll returns can never drift apart.
 */

require_once __DIR__ . '/order_workflow.php';
require_once __DIR__ . '/staff_roles.php';
require_once __DIR__ . '/task_assignments.php';
require_once __DIR__ . '/staff_order_acceptance.php';

/** Statuses that can change what the board shows. */
function productionBoardWatchedStatuses(): array
{
    return array_merge(getProductionStages(), ['completed', 'cancelled']);
}

/**
 * Short fingerprint of everything the board renders. The poller sends back the
 * last revision it saw; when it still matches we reply "nothing changed"
 * instead of re-rendering and shipping the whole board over the wire.
 */
function productionBoardRevision(mysqli $conn): string
{
    ensureProductionProgressSchema($conn);
    ensureOrderTaskAssignmentSchema($conn);
    ensureProductionStageQueueSchema($conn);
    ensureStaffAcceptanceSchema($conn);
    $statuses = "'" . implode("','", productionBoardWatchedStatuses()) . "'";

    $orders = $conn->query("
        SELECT COUNT(*) AS row_count,
               COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS last_touch,
               COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, priority, COALESCE(due_date, ''), COALESCE(assigned_to, 0), COALESCE(task_status, ''), COALESCE(task_deadline, ''), COALESCE(task_title, '')))), 0) AS fingerprint
        FROM orders
        WHERE status IN ({$statuses})
    ");

    $row = $orders ? $orders->fetch_assoc() : null;
    $products = $conn->query("
        SELECT COUNT(*) AS row_count,
               COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS last_touch,
               COALESCE(SUM(CRC32(CONCAT_WS('|', id, COALESCE(name, ''), COALESCE(image, ''), COALESCE(status, '')))), 0) AS fingerprint
        FROM products
    ");
    $productRow = $products ? $products->fetch_assoc() : null;
    $parts = [
        (string)($row['row_count'] ?? '0'),
        (string)($row['last_touch'] ?? '0'),
        (string)($row['fingerprint'] ?? '0'),
        (string)($productRow['row_count'] ?? '0'),
        (string)($productRow['last_touch'] ?? '0'),
        (string)($productRow['fingerprint'] ?? '0'),
        date('Y-m-d'),                      // overdue / completed-today roll over at midnight
        (string)productionBoardLastLogId($conn),
        (string)productionWipLimit($conn, 'in_production'),
        (string)productionWipLimit($conn, 'quality_check'),
        (string)stageQueueCount($conn, 'in_production'),
        (string)stageQueueCount($conn, 'quality_check'),
        (string)productionQueueCount($conn),
    ];

    return substr(sha1(implode('|', $parts)), 0, 16);
}

function productionBoardLastLogId(mysqli $conn): int
{
    $result = $conn->query("SELECT COALESCE(MAX(id), 0) AS last_log FROM production_logs");

    return (int)($result ? ($result->fetch_assoc()['last_log'] ?? 0) : 0);
}

/**
 * Everything the board view needs. $staffRole is ignored for admins, who can
 * act on every column.
 */
function buildProductionBoardContext(mysqli $conn, ?string $staffRole, bool $isAdmin, string $workflowCsrf, int $actorId = 0): array
{
    ensureProductionProgressSchema($conn);
    ensureOrderTaskAssignmentSchema($conn);
    ensureProductionStageQueueSchema($conn);
    ensureStaffAcceptanceSchema($conn);
    // Waiting admission is unlimited; active work requires a staff acceptance decision.
    $autoMoves = reconcileProductionQueues($conn, null);
    if ($autoMoves) {
        if (!function_exists('notifyOrderStageChange')) require_once __DIR__ . '/messaging.php';
        foreach ($autoMoves as $move) {
            notifyOrderStageChange($conn, (int)$move['id'], (string)$move['order_number'], (string)$move['from'], (string)$move['to'], 0);
        }
    }
    // Each view only receives the stages that belong on that role's board.
    // Admins handle the hand-off points (Pending and Ready); staff handle their
    // single production stage.
    $stages = $isAdmin
        ? ['pending', 'ready']
        : staffRoleStages($staffRole);
    $boardData = getProductionBoardColumns($conn, $stages);
    if ($isAdmin) {
        $readyOrders = $boardData['board']['ready'] ?? [];
        $filteredReady = [];
        $outForDelivery = [];
        foreach ($readyOrders as $readyOrder) {
            $fulfillment = strtolower(trim((string)($readyOrder['fulfillment_method'] ?? 'pickup')));
            $deliveryState = strtolower(trim((string)($readyOrder['delivery_status'] ?? 'pending')));
            if ($fulfillment === 'delivery' && $deliveryState === 'out_for_delivery') {
                $outForDelivery[] = $readyOrder;
            } else {
                $filteredReady[] = $readyOrder;
            }
        }
        $boardData['board']['ready'] = $filteredReady;
        $boardData['counts']['ready'] = count($filteredReady);
        $boardData['board']['out_for_delivery'] = $outForDelivery;
        $boardData['counts']['out_for_delivery'] = count($outForDelivery);
        $stages[] = 'out_for_delivery';
    }
    // A single, authoritative eligibility check feeds BOTH the staff order
    // cards and the move/hold handlers. Never infer permission from a label.
    if (!$isAdmin) {
        foreach ($boardData['board'] as $boardStage => &$stageOrders) {
            foreach ($stageOrders as &$boardOrder) {
                $listedAsMine = ($boardOrder['acceptance_decision'] ?? '') === 'accepted'
                    && (int)($boardOrder['accepted_staff_id'] ?? 0) === $actorId;
                if ($listedAsMine) {
                    $boardOrder['staff_eligibility'] = staffOrderActionEligibility(
                        $conn, (int)$boardOrder['id'], (string)$boardStage, $actorId
                    );
                }
            }
            unset($boardOrder);
        }
        unset($stageOrders);
    }
    $counts    = $boardData['counts'];
    $myStages  = $stages;

    $myQueue = 0;
    foreach ($myStages as $stage) {
        $myQueue += $counts[$stage] ?? 0;
    }

    $active  = array_sum($counts);
    $overdue = getOverdueProductionCount($conn);
    $capacities = [
        'in_production' => productionStageCapacityState($conn, 'in_production'),
        'quality_check' => productionStageCapacityState($conn, 'quality_check'),
    ];
    $queueRows = productionQueueRows($conn, 0);
    $queuePositions = [];
    foreach ($queueRows as $queueIndex => $queueRow) {
        $queuePositions[(int)$queueRow['id']] = $queueIndex + 1;
    }

    // Waiting is unlimited and lives directly inside each staff stage. Only
    // accepted orders consume active capacity, so derive Waiting from the board
    // itself instead of the legacy capacity queue.
    $stageWaitingRows = ['in_production' => [], 'quality_check' => []];
    foreach (['in_production', 'quality_check'] as $waitingStage) {
        foreach (($boardData['board'][$waitingStage] ?? []) as $waitingOrder) {
            if (($waitingOrder['acceptance_decision'] ?? '') !== 'accepted') {
                $stageWaitingRows[$waitingStage][] = $waitingOrder;
            }
        }
    }
    $stageWaitingCounts = [
        'in_production' => count($stageWaitingRows['in_production']),
        'quality_check' => count($stageWaitingRows['quality_check']),
    ];
    $stageWaitingNext = [
        'in_production' => $stageWaitingRows['in_production'][0]['order_number'] ?? null,
        'quality_check' => $stageWaitingRows['quality_check'][0]['order_number'] ?? null,
    ];

    // Production entries are grouped by order date first, then by customer.
    // getProductionBoardColumns() already returns orders newest-first, so dates
    // and orders inside each date stay in descending order naturally.
    $dateGroups = [];
    foreach ($stages as $stage) {
        $stageOrders = $boardData['board'][$stage] ?? [];
        $dateGroups[$stage] = groupProductionOrdersByDate($stageOrders);
    }

    $stageLabels = getProductionStageLabels();
    $stageColors = getProductionStageColors();
    if ($isAdmin) {
        $stageLabels['out_for_delivery'] = 'Out for Delivery';
        $stageColors['out_for_delivery'] = '#8C3158';
    }

    return [
        'stages'         => $stages,
        'stageLabels'    => $stageLabels,
        'stageColors'    => $stageColors,
        'board'          => $boardData['board'],
        'dateGroups'     => $dateGroups,
        'counts'         => $counts,
        'capacities'     => $capacities,
        'productionQueueCount' => count($queueRows),
        'queuePositions' => $queuePositions,
        'stageWaitingRows' => $stageWaitingRows,
        'stageWaitingCounts' => $stageWaitingCounts,
        'stageWaitingNext' => $stageWaitingNext,
        'workflowCsrf'   => $workflowCsrf,
        'actorId'        => $actorId,
        'staffRole'      => $isAdmin ? null : $staffRole,
        'isAdmin'        => $isAdmin,
        'myStages'       => $myStages,
        'completedToday' => getCompletedOrdersToday($conn),
        'totals'         => [
            'active'   => $active,
            'overdue'  => $overdue,
            'my_queue' => $myQueue,
        ],
        'labels'         => [
            'active'   => $active . ' active order' . ($active === 1 ? '' : 's'),
            'overdue'  => $overdue . ' overdue',
            'my_queue' => $myQueue . ' in your queue',
        ],
    ];
}

/**
 * Same overdue test the board has always used, pulled into one place so a
 * customer frame can never disagree with a card folded inside it. Kept string
 * based to match the SQL behind the "N overdue" pill, where a zero date counts.
 */
function productionOrderIsOverdue(array $order, string $today): bool
{
    $due = (string)($order['due_date'] ?? '');

    return $due !== '' && $due < $today;
}

/** NULL and '0000-00-00' both mean "no date worth printing". */
function productionOrderDueTimestamp(array $order): ?int
{
    $due = (string)($order['due_date'] ?? '');
    $ts  = $due === '' ? false : strtotime($due);

    return ($ts && $ts > 0) ? $ts : null;
}

/**
 * Orders written by one checkout share a customer, a pickup slot and an insert
 * second. Bucketing created_at to the minute keeps a basket together even if
 * its inserts straddle a tick.
 */
function productionCheckoutBatchKey(array $order): string
{
    return implode('|', [
        substr((string)($order['created_at'] ?? ''), 0, 16),
        (string)($order['due_date'] ?? ''),
        (string)($order['pickup_time'] ?? ''),
    ]);
}

function productionCheckoutBatchLabel(array $order): string
{
    $ts = strtotime((string)($order['created_at'] ?? ''));

    return $ts ? date('M j, Y g:i A', $ts) : 'Earlier checkout';
}

/**
 * Groups a production column by the calendar date the order was created.
 * Each date then uses the existing customer grouping so the board stays tidy
 * while making the work queue much easier to scan day by day.
 */
function groupProductionOrdersByDate(array $orders): array
{
    $dates = [];

    foreach ($orders as $order) {
        $created = (string)($order['created_at'] ?? '');
        $ts = $created !== '' ? strtotime($created) : false;
        $key = $ts ? date('Y-m-d', $ts) : 'unknown';

        if (!isset($dates[$key])) {
            $dates[$key] = [
                'key'    => $key,
                'label'  => $ts ? date('F j, Y', $ts) : 'Date not available',
                'orders' => [],
            ];
        }

        $dates[$key]['orders'][] = $order;
    }

    foreach ($dates as $key => $dateGroup) {
        $dates[$key]['groups'] = groupProductionOrdersByCustomer($dateGroup['orders'], $key);
        unset($dates[$key]['orders']);
    }

    return array_values($dates);
}

/**
 * Folds a column's orders into one frame per customer, so somebody who checked
 * out five items reads as one entry you can open instead of five loose cards.
 *
 * Identity is name + contact: two walk-ins can share a name, and the checkout
 * form stamps the same contact onto every order in a basket. Frames come back
 * in the column's own sort order, so the most urgent customer stays on top, and
 * each frame carries its orders split into the checkouts they arrived in.
 */
function groupProductionOrdersByCustomer(array $orders, string $scopeKey = ''): array
{
    $today  = date('Y-m-d');
    $groups = [];

    foreach ($orders as $order) {
        $name    = trim((string)($order['customer_name'] ?? ''));
        $contact = trim((string)($order['customer_contact'] ?? ''));
        $key     = strtolower($name) . '|' . strtolower($contact);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                // Stable across re-renders, so the poller can keep a frame open.
                'key'      => substr(sha1($scopeKey . '|' . $key), 0, 12),
                'customer' => $name !== '' ? $name : 'Unnamed customer',
                'contact'  => $contact,
                'count'    => 0,
                'quantity' => 0,
                'overdue'  => 0,
                'due_ts'   => null,
                'assigned'       => [],
                'batches'        => [],
            ];
        }

        $group = &$groups[$key];
        $group['count']++;
        $group['quantity'] += max(0, (int)($order['quantity'] ?? 0));

        if (productionOrderIsOverdue($order, $today)) {
            $group['overdue']++;
        }

        $dueTs = productionOrderDueTimestamp($order);
        if ($dueTs !== null && ($group['due_ts'] === null || $dueTs < $group['due_ts'])) {
            $group['due_ts'] = $dueTs;   // the frame answers for the soonest item in it
        }

        $assigned = trim((string)($order['assigned_name'] ?? ''));
        if ($assigned !== '' && !in_array($assigned, $group['assigned'], true)) {
            $group['assigned'][] = $assigned;
        }

        $batchKey = productionCheckoutBatchKey($order);
        if (!isset($group['batches'][$batchKey])) {
            $group['batches'][$batchKey] = [
                'label'  => productionCheckoutBatchLabel($order),
                'orders' => [],
            ];
        }

        $group['batches'][$batchKey]['orders'][] = $order;
        unset($group);
    }

    foreach ($groups as $key => $group) {
        $groups[$key]['batches'] = array_values($group['batches']);
    }

    return array_values($groups);
}

/** Renders a board partial to a string so the same markup can be echoed or returned as JSON. */
function renderProductionPartial(string $file, array $ctx): string
{
    ob_start();
    extract($ctx, EXTR_SKIP);
    include $file;

    return (string)ob_get_clean();
}

function renderProductionBoard(array $ctx): string
{
    return renderProductionPartial(__DIR__ . '/production_board_view.php', $ctx);
}

function renderProductionCompletedToday(array $ctx): string
{
    return renderProductionPartial(__DIR__ . '/production_completed_view.php', $ctx);
}


/**
 * Admins manage payment release, cancellation, and final completion. They do
 * not perform the Production or Quality Check staff stages from the Orders page.
 */
function adminOrderAllowedStatusTargets(string $fromStatus): array
{
    switch ($fromStatus) {
        case 'pending':
            return ['in_production', 'cancelled'];
        case 'in_production':
        case 'quality_check':
            return ['cancelled'];
        case 'ready':
            return ['completed', 'cancelled'];
        default:
            return [];
    }
}

/**
 * Applies one board move after re-checking every rule server side, so a forged
 * request gets the same answer as a tampered button. Returns
 * ['ok' => bool, 'error' => string, 'from' => string, 'to' => string, ...].
 */
function applyProductionBoardMove(
    mysqli $conn,
    int $orderId,
    string $newStatus,
    int $actorId,
    ?string $staffRole,
    bool $isAdmin,
    string $notes = ''
): array {
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    if (!$isAdmin && !isValidStaffRole($staffRole)) {
        return [
            'ok'    => false,
            'error' => 'No production role is assigned to your account yet. Ask an administrator to set one before moving orders.',
        ];
    }

    ensureProductionProgressSchema($conn);
    $stmt = $conn->prepare("SELECT status, order_number, quantity, production_hold_reason, fulfillment_method FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $from        = (string)$order['status'];
    $stageLabels = getProductionStageLabels();
    $fromLabel   = $stageLabels[$from] ?? ucwords(str_replace('_', ' ', $from));

    if (!$isAdmin && !staffRoleOwnsStage($staffRole, $from)) {
        $ownerRole = getStaffRoleForStage($from);

        return [
            'ok'    => false,
            'error' => $ownerRole
                ? "That order sits in {$fromLabel}, which only the " . staffRoleLabel($ownerRole) . ' can move.'
                : "That order sits in {$fromLabel}, which is outside your role.",
        ];
    }

    if (!$isAdmin) {
        $eligibility = staffOrderActionEligibility($conn, $orderId, $from, $actorId);
        if (!$eligibility['allowed']) {
            return ['ok' => false, 'error' => staffOrderActionFailureMessage($eligibility['reason'])];
        }
    }

    // An order on hold must be resumed before it can move forward.
    if (!$isAdmin && $staffRole === 'production' && $from === 'in_production' && $newStatus === 'quality_check'
        && trim((string)($order['production_hold_reason'] ?? '')) !== '') {
        return ['ok' => false, 'error' => 'Resume this order before sending it to Quality Check.'];
    }

    // A failed quality inspection must explain what needs to be corrected.
    if (!$isAdmin && $staffRole === 'quality_check' && $from === 'quality_check' && $newStatus === 'in_production' && trim($notes) === '') {
        return ['ok' => false, 'error' => 'Enter a QC rejection reason before returning the order to Production.'];
    }

    // Delivery orders must use the delivery pipeline after Ready. They cannot
    // skip directly from Ready to Completed like pickup orders can.
    if ($isAdmin && $from === 'ready' && $newStatus === 'completed'
        && strtolower(trim((string)($order['fulfillment_method'] ?? 'pickup'))) === 'delivery') {
        return ['ok' => false, 'error' => 'Delivery orders must move to Out for Delivery before they can be completed.'];
    }

    // Admins only work the two visible hand-off stages. They can send Pending
    // into production and close Ready orders; hidden stages stay outside their
    // board and cannot be changed through this action.
    $allowed = $isAdmin
        ? (($from === 'pending' && $newStatus === 'in_production')
            || ($from === 'ready' && $newStatus === 'completed'))
        : canStaffRoleMoveOrder($staffRole, $from, $newStatus);

    if (!$allowed) {
        return ['ok' => false, 'error' => 'Invalid production stage transition.'];
    }

    $result = updateOrderWorkflowStatus($conn, $orderId, $newStatus, $actorId, $notes);

    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => $result['error'] ?? 'Unable to update order status.'];
    }

    $toLabel = $stageLabels[$newStatus] ?? ucwords(str_replace('_', ' ', $newStatus));

    if (!function_exists('notifyOrderStageChange')) require_once __DIR__ . '/messaging.php';
    if (!empty($result['queued'])) {
        if (function_exists('notifyOrderStageQueued')) {
            notifyOrderStageQueued($conn, $orderId, (string)$order['order_number'], $newStatus, $result['queue_position'] ?? null);
        }
        return [
            'ok' => true,
            'changed' => true,
            'queued' => true,
            'from' => $from,
            'to' => $newStatus,
            'order_number' => (string)$order['order_number'],
            'message' => $toLabel . ' is currently full. Work is finished in this stage and the order was queued automatically (FIFO).',
        ];
    }

    if (!empty($result['changed'])) {
        notifyOrderStageChange($conn, $orderId, (string)$order['order_number'], $from, $newStatus, $actorId);
    }

    return [
        'ok'           => true,
        'changed'      => !empty($result['changed']),
        'from'         => $from,
        'to'           => $newStatus,
        'order_number' => (string)$order['order_number'],
        'message'      => 'Order moved to ' . $toLabel . '.',
    ];
}

/**
 * Status change from the order detail card, which may also cancel or complete.
 * Staff are held to their assigned stage transitions; admins are limited to payment release, cancellation, and final completion targets.
 */
function applyOrderStatusChange(
    mysqli $conn,
    int $orderId,
    string $newStatus,
    int $actorId,
    ?string $staffRole,
    bool $isAdmin,
    string $notes = ''
): array {
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $stmt = $conn->prepare("SELECT status, order_number, fulfillment_method FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $from = (string)$order['status'];

    // Staff detail/API updates must pass through the exact same guarded board
    // move used by drag-and-drop and the stage buttons.
    if (!$isAdmin) {
        return applyProductionBoardMove($conn, $orderId, $newStatus, $actorId, $staffRole, false, $notes);
    }

    if ($from === 'ready' && $newStatus === 'completed'
        && strtolower(trim((string)($order['fulfillment_method'] ?? 'pickup'))) === 'delivery') {
        return [
            'ok' => false,
            'error' => 'Delivery orders must move to Out for Delivery before they can be completed.',
        ];
    }

    if (!in_array($newStatus, adminOrderAllowedStatusTargets($from), true)) {
        return [
            'ok' => false,
            'error' => 'That status change is reserved for the staff member responsible for the current production stage.',
        ];
    }

    $result = updateOrderWorkflowStatus($conn, $orderId, $newStatus, $actorId, $notes);
    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => $result['error'] ?? 'Unable to update order status.'];
    }

    if (!empty($result['changed']) && function_exists('notifyOrderStageChange')) {
        notifyOrderStageChange($conn, $orderId, (string)$order['order_number'], $from, $newStatus, $actorId);
    }

    return [
        'ok'           => true,
        'changed'      => !empty($result['changed']),
        'from'         => $from,
        'to'           => $newStatus,
        'order_number' => (string)$order['order_number'],
        'message'      => 'Order status updated to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.',
    ];
}

/**
 * Moves made by other people since the poller last looked, so the board can
 * announce them instead of silently redrawing.
 */
function productionBoardEventsSince(mysqli $conn, int $sinceLogId, int $excludeUserId, int $limit = 6): array
{
    if ($sinceLogId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT pl.id, pl.status_from, pl.status_to, o.order_number, u.full_name AS actor
        FROM production_logs pl
        LEFT JOIN orders o ON o.id = pl.order_id
        LEFT JOIN users u ON u.id = pl.updated_by
        WHERE pl.id > ?
          AND pl.status_from <> pl.status_to
          AND (pl.updated_by IS NULL OR pl.updated_by <> ?)
        ORDER BY pl.id DESC
        LIMIT ?
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $sinceLogId, $excludeUserId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $labels = getProductionStageLabels();
    $events = [];

    foreach (array_reverse($rows) as $row) {
        $to = (string)$row['status_to'];

        $events[] = [
            'id'    => (int)$row['id'],
            'order' => (string)($row['order_number'] ?? ''),
            'actor' => (string)($row['actor'] ?? 'Someone'),
            'to'    => $to,
            'text'  => sprintf(
                '%s moved %s to %s',
                $row['actor'] ?: 'Someone',
                $row['order_number'] ?: 'an order',
                $labels[$to] ?? ucwords(str_replace('_', ' ', $to))
            ),
        ];
    }

    return $events;
}
