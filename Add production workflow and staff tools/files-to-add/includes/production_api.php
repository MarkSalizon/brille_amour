<?php
/**
 * production_api.php
 * ─────────────────────────────────────────────────────────
 * Shared JSON handler for the live Production Board.
 *
 * Not routable on its own — admin/production_api.php and
 * staff/production_api.php start the right session, check the role, then
 * require this file. Expects $conn, a live $_SESSION, and $portalIsAdmin.
 *
 * Every write re-runs the same authorisation the page does, so a forged or
 * replayed request is rejected exactly like a tampered button would be.
 */

if (!isset($conn) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/production_board.php';

$portalIsAdmin = !empty($portalIsAdmin);
$actorId       = (int)$_SESSION['user_id'];
$action        = $_GET['action'] ?? $_POST['action'] ?? 'board';
$isPost        = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// Read the role from the database, not the session, so an admin's change takes
// effect on the next poll instead of the next login.
$staffRole = $portalIsAdmin ? null : getStaffRoleForUser($conn, $actorId);
if (!$portalIsAdmin) {
    $_SESSION['staff_role'] = $staffRole;
}

function productionApiFail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** Board snapshot plus the numbers the toolbar pills show. */
function productionApiSnapshot(mysqli $conn, ?string $staffRole, bool $isAdmin, bool $withHtml = true): array
{
    $ctx = buildProductionBoardContext($conn, $staffRole, $isAdmin, ensureWorkflowCsrfToken(), (int)$_SESSION['user_id']);

    $snapshot = [
        'revision'    => productionBoardRevision($conn),
        'totals'      => $ctx['totals'],
        'labels'      => $ctx['labels'],
        'last_log_id' => productionBoardLastLogId($conn),
        'server_time' => date('H:i:s'),
    ];

    if ($withHtml) {
        $snapshot['html']           = renderProductionBoard($ctx);
        $snapshot['completed_html'] = renderProductionCompletedToday($ctx);
    }

    return $snapshot;
}

if ($isPost && !validateWorkflowCsrfToken($_POST['workflow_csrf_token'] ?? null)) {
    productionApiFail('Invalid request token. Refresh the page and try again.', 403);
}

switch ($action) {
    case 'decide_order':
        if (!$isPost) productionApiFail('Use POST to decide on an order.', 405);
        if ($portalIsAdmin) productionApiFail('Only staff may accept or decline stage work.', 403);
        $result = decideStaffOrder($conn, (int)($_POST['order_id'] ?? 0), $actorId, $staffRole,
            (string)($_POST['decision'] ?? ''), (string)($_POST['reason'] ?? ''));
        if (empty($result['ok'])) productionApiFail($result['error'] ?? 'Unable to save your decision.', 422);
        echo json_encode(array_merge(productionApiSnapshot($conn, $staffRole, false), $result));
        break;

    case 'hold':
        if (!$isPost) productionApiFail('Use POST to update a production hold.', 405);
        $result = applyProductionHold($conn, (int)($_POST['order_id'] ?? 0), $_POST, $actorId, $staffRole, $portalIsAdmin);
        if (!$result['ok']) productionApiFail($result['error'], 422);
        echo json_encode(array_merge(productionApiSnapshot($conn, $staffRole, $portalIsAdmin), $result, ['changed' => true]));
        break;

    /*
     * Live poll. The caller sends the revision it is showing; when nothing has
     * moved we answer with changed=false and skip rendering the board.
     */
    case 'board':
        $seenRevision = (string)($_GET['revision'] ?? '');
        $sinceLog     = max(0, (int)($_GET['since_log'] ?? 0));
        $revision     = productionBoardRevision($conn);

        if ($seenRevision !== '' && hash_equals($revision, $seenRevision)) {
            echo json_encode([
                'ok'          => true,
                'changed'     => false,
                'revision'    => $revision,
                'last_log_id' => productionBoardLastLogId($conn),
                'server_time' => date('H:i:s'),
            ]);
            break;
        }

        $snapshot = productionApiSnapshot($conn, $staffRole, $portalIsAdmin);
        $snapshot['ok']      = true;
        $snapshot['changed'] = true;
        $snapshot['events']  = productionBoardEventsSince($conn, $sinceLog, $actorId);

        echo json_encode($snapshot);
        break;

    /* Admin delivery hand-off: Ready -> Out for Delivery -> Delivered/Completed. */
    case 'delivery_status':
        if (!$isPost) {
            productionApiFail('Use POST to update delivery status.', 405);
        }
        if (!$portalIsAdmin) {
            productionApiFail('Only an administrator may update delivery progress here.', 403);
        }

        require_once __DIR__ . '/innovation.php';
        require_once __DIR__ . '/customer_email_notifications.php';
        ensureInnovationSchema($conn);

        $orderId = (int)($_POST['order_id'] ?? 0);
        $target = strtolower(trim((string)($_POST['delivery_status'] ?? '')));
        if ($orderId <= 0 || !in_array($target, ['out_for_delivery', 'delivered'], true)) {
            productionApiFail('Invalid delivery update.', 422);
        }

        $stmt = $conn->prepare("SELECT id, order_number, customer_id, fulfillment_method, status FROM orders WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$order) productionApiFail('Order not found.', 404);
        if (strtolower(trim((string)$order['fulfillment_method'])) !== 'delivery') {
            productionApiFail('This order is not for delivery.', 422);
        }

        $current = brilleDeliveryStateForOrder($conn, $orderId, (string)$order['status']);
        if ($target === 'out_for_delivery'
            && !((string)$order['status'] === 'ready' && in_array($current, ['pending', 'ready_for_dispatch'], true))) {
            productionApiFail('The order must be Ready before it can move to Out for Delivery.', 422);
        }
        if ($target === 'delivered' && $current !== 'out_for_delivery') {
            productionApiFail('Move the order to Out for Delivery first.', 422);
        }

        $note = $target === 'out_for_delivery' ? 'Order released for delivery.' : 'Customer delivery completed.';
        $conn->begin_transaction();
        try {
            $save = $conn->prepare("INSERT INTO delivery_tracking(order_id,status,notes,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $save->bind_param('issi', $orderId, $target, $note, $actorId);
            $save->execute();
            $save->close();

            if ($target === 'delivered') {
                $from = (string)$order['status'];
                $update = $conn->prepare("UPDATE orders SET status='completed', updated_at=NOW() WHERE id=?");
                $update->bind_param('i', $orderId);
                $update->execute();
                $update->close();

                $log = $conn->prepare("INSERT INTO production_logs(order_id,status_from,status_to,notes,updated_by) VALUES(?,?,'completed','Delivery confirmed',?)");
                $log->bind_param('isi', $orderId, $from, $actorId);
                $log->execute();
                $log->close();
            } else {
                // Keep the production status at Ready; the delivery_tracking state
                // moves the card into the synthetic Out for Delivery column.
                $update = $conn->prepare('UPDATE orders SET updated_at=NOW() WHERE id=?');
                $update->bind_param('i', $orderId);
                $update->execute();
                $update->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Production delivery update failed: ' . $e->getMessage());
            productionApiFail('Delivery status could not be saved.', 500);
        }

        try {
            $customerId = registeredCustomerIdForOrder($conn, $orderId);
            if ($customerId) {
                $customerUserId = registeredCustomerMessagingUserId($customerId);
                $label = $target === 'out_for_delivery' ? 'Out for Delivery' : 'Delivered';
                $body = $target === 'out_for_delivery'
                    ? 'Your Brille Amour order is now out for delivery.'
                    : 'Your Brille Amour order has been delivered successfully.';
                createNotification($conn, $customerUserId, 'production', 'Order ' . $order['order_number'] . ': ' . $label, $body, 'normal', 'order', $orderId, threadKeyForOrder($orderId), $orderId, 'delivery-' . $target . '-' . $orderId);
                postChatMessage($conn, threadKeyForOrder($orderId), 0, 'system', 'Delivery update: ' . $label);
            }
            $emailBody = $target === 'out_for_delivery'
                ? 'Your order is now out for delivery. Please keep your contact line available for delivery coordination.'
                : 'Your order has been delivered successfully. Thank you for choosing Brille Amour Prints & Crafts.';
            customerEmailSendOnce($conn, $orderId, 'delivery-' . $target . '-' . $orderId,
                'Order update: ' . ($target === 'out_for_delivery' ? 'Out for Delivery' : 'Delivered') . ' - ' . $order['order_number'],
                $emailBody);
        } catch (Throwable $ignored) {
            error_log('Production delivery notification failed: ' . $ignored->getMessage());
        }

        $snapshot = productionApiSnapshot($conn, $staffRole, true);
        $snapshot['ok'] = true;
        $snapshot['changed'] = true;
        $snapshot['message'] = $target === 'out_for_delivery'
            ? 'Order moved to Out for Delivery.'
            : 'Delivery completed and order marked Completed.';
        $snapshot['delivery_status'] = $target;
        echo json_encode($snapshot);
        break;

    /* Board button or drag-and-drop: one step along the pipeline. */
    case 'move':
        if (!$isPost) {
            productionApiFail('Use POST to move an order.', 405);
        }

        $result = applyProductionBoardMove(
            $conn,
            (int)($_POST['order_id'] ?? 0),
            sanitize($_POST['new_status'] ?? ''),
            $actorId,
            $staffRole,
            $portalIsAdmin,
            sanitize($_POST['notes'] ?? '')
        );

        $snapshot = productionApiSnapshot($conn, $staffRole, $portalIsAdmin);
        $snapshot['ok']      = !empty($result['ok']);
        $snapshot['changed'] = true;
        $snapshot['moved']   = $result;

        if (empty($result['ok'])) {
            $snapshot['error'] = $result['error'] ?? 'Unable to update order status.';
            http_response_code(422);
        } else {
            $snapshot['message'] = $result['message'] ?? 'Order updated.';
        }

        echo json_encode($snapshot);
        break;

    /* Order detail card: any status the caller's role is allowed to set. */
    case 'order_status':
        if (!$isPost) {
            productionApiFail('Use POST to update an order.', 405);
        }

        $result = applyOrderStatusChange(
            $conn,
            (int)($_POST['order_id'] ?? 0),
            sanitize($_POST['new_status'] ?? ''),
            $actorId,
            $staffRole,
            $portalIsAdmin,
            sanitize($_POST['notes'] ?? '')
        );

        if (empty($result['ok'])) {
            productionApiFail($result['error'] ?? 'Unable to update order status.', 422);
        }

        echo json_encode([
            'ok'          => true,
            'message'     => $result['message'],
            'status'      => $result['to'],
            'revision'    => productionBoardRevision($conn),
            'last_log_id' => productionBoardLastLogId($conn),
        ]);
        break;

    /* Cheap change check for pages that only need to know "something moved". */
    case 'revision':
        $seenRevision = (string)($_GET['revision'] ?? '');
        $sinceLog     = max(0, (int)($_GET['since_log'] ?? 0));
        $revision     = productionBoardRevision($conn);

        echo json_encode([
            'ok'          => true,
            // The caller decides what to redraw, so it only needs the flag.
            'changed'     => $seenRevision === '' || !hash_equals($revision, $seenRevision),
            'revision'    => $revision,
            'last_log_id' => productionBoardLastLogId($conn),
            'events'      => productionBoardEventsSince($conn, $sinceLog, $actorId),
            'server_time' => date('H:i:s'),
        ]);
        break;

    default:
        productionApiFail('Unknown action.');
}
