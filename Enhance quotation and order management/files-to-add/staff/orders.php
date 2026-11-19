<?php
/** Staff order view. Production and QC share this read-only order/customer detail page. */
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: login.php');
    exit;
}
$pageTitle = 'Order details';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_workflow.php';
require_once __DIR__ . '/../includes/staff_roles.php';
require_once __DIR__ . '/../includes/production_board.php';
require_once __DIR__ . '/../includes/task_assignments.php';
require_once __DIR__ . '/../includes/staff_order_acceptance.php';
require_once __DIR__ . '/../includes/product_images.php';

$uid = (int)$_SESSION['user_id'];
$staffRole = getStaffRoleForUser($conn, $uid);
$_SESSION['staff_role'] = $staffRole;
$stage = staffAcceptanceStage($staffRole);
$orderId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) ?: 0;
if (!$stage || $orderId <= 0) {
    header('Location: production.php');
    exit;
}

ensureOrderTaskAssignmentSchema($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];
$msg = '';
$err = '';

// View/detail actions are subject to the same acceptance and stage checks as the board.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'Your session expired. Refresh the page and try again.';
    } elseif ((int)($_POST['id'] ?? 0) !== $orderId) {
        $err = 'The order could not be verified. Return to the Production Board.';
    } elseif ($action === 'task_status') {
        $newStatus = (string)($_POST['task_status'] ?? '');
        $note = trim((string)($_POST['task_note'] ?? ''));
        if (!isValidOrderTaskStatus($newStatus) || mb_strlen($note) > 500) {
            $err = 'Please select a valid task status and keep notes under 500 characters.';
        } elseif (!staffHasAcceptedOrder($conn, $orderId, $stage, $uid)) {
            $err = 'Only the staff member who accepted this order can update its task.';
        } else {
            $stmt = $conn->prepare('UPDATE orders SET task_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?');
            if (!$stmt) {
                error_log('Staff order task update could not be prepared: ' . $conn->error);
                $err = 'Could not update the task. Please try again.';
            } else {
                $stmt->bind_param('sis', $newStatus, $orderId, $stage);
                if ($stmt->execute()) {
                    // Affected rows may be zero when saving an unchanged value in the same second.
                    $msg = 'Task status saved.';
                    if ($stmt->affected_rows > 0) {
                        logOrderTaskAssignment($conn, $orderId, $stage, $uid,
                            'Task marked as ' . orderTaskStatusLabel($newStatus) . ($note !== '' ? ': ' . $note : ''));
                    }
                } else {
                    $err = 'Could not update the task. Please try again.';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'status') {
        $nextStatus = (string)($_POST['new_status'] ?? '');
        $notes = trim((string)($_POST['log_notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $err = 'Please keep the quality-check reason under 500 characters.';
        } else {
            $result = applyOrderStatusChange($conn, $orderId, $nextStatus, $uid, $staffRole, false, $notes);
            if (!empty($result['ok'])) {
                header('Location: production.php');
                exit;
            }
            $err = (string)($result['error'] ?? 'Unable to update the order.');
        }
    } else {
        $err = 'This action is not available on the staff order details page.';
    }
}

// Limit the detail page to the stage assigned to the signed-in staff account.
$stmt = $conn->prepare('SELECT o.*, p.name AS product_name
    FROM orders o LEFT JOIN products p ON p.id=o.product_id
    WHERE o.id=? AND o.status=? LIMIT 1');
if (!$stmt) {
    error_log('Staff order detail could not be prepared: ' . $conn->error);
    http_response_code(500);
    exit('Order details are temporarily unavailable. Please contact the administrator.');
}
$stmt->bind_param('is', $orderId, $stage);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if (!$order) {
    header('Location: production.php');
    exit;
}

$canUpdateTask = staffHasAcceptedOrder($conn, $orderId, $stage, $uid);
$isOverdue = !empty($order['due_date']) && (string)$order['due_date'] < date('Y-m-d');
$isDelivery = (string)($order['fulfillment_method'] ?? '') === 'delivery';
$productName = trim((string)($order['product_name'] ?? '')) ?: trim((string)($order['order_type'] ?? '')) ?: 'Custom order';
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Both signed-in and legacy customer checkouts save their reference to orders.image.
// Only load actual image files from the order-uploads directory, never arbitrary paths.
$hasUploadedReference = trim((string)($order['image'] ?? '')) !== '';
$referenceFile = basename(trim((string)($order['image'] ?? '')));
$referenceUrl = '';
$referenceIsImage = orderReferenceIsImage($referenceFile);
if ($referenceFile !== '' && preg_match('/\A[a-zA-Z0-9_.-]+\.(?:jpe?g|png|gif|webp|pdf|docx?|pptx?|xlsx?|txt)\z/i', $referenceFile)
    && is_file(__DIR__ . '/../uploads/orders/' . $referenceFile)) {
    $referenceUrl = '../uploads/orders/' . rawurlencode($referenceFile);
}
$notes = trim((string)($order['notes'] ?? ''));
$description = trim((string)($order['description'] ?? ''));
// Customer checkout also mirrors the same notes in description; show them once.
if (preg_match('/\ACheckout notes:\s*/i', $description)) {
    $description = trim((string)preg_replace('/\ACheckout notes:\s*/i', '', $description));
    if ($notes === '') {
        $notes = $description;
        $description = '';
    } elseif ($description === $notes) {
        $description = '';
    }
}
$taskInstructions = trim((string)($order['task_instructions'] ?? ''));
$hasInstructions = $notes !== '' || $description !== '' || $hasUploadedReference || $taskInstructions !== '';
$scheduleDate = !empty($order['due_date']) && strtotime((string)$order['due_date'])
    ? date('M j, Y', strtotime((string)$order['due_date'])) : 'Not scheduled';
$scheduleTime = !empty($order['pickup_time']) && strtotime((string)$order['pickup_time'])
    ? date('g:i A', strtotime((string)$order['pickup_time'])) : '';
$orderedProductPreview = productPreviewImage($productName);
$orderedProductPreviewUrl = $orderedProductPreview ? '../' . $orderedProductPreview : '';
$referenceDownloadName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($order['order_number'] ?? 'order')) . '-reference.' . pathinfo($referenceFile, PATHINFO_EXTENSION);

include __DIR__ . '/../includes/staff_header.php';
?>
<link rel="stylesheet" href="../assets/css/staff-order-detail-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-order-detail-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<main id="staffOrderView" class="sov-page">
    <header class="sov-heading">
        <div class="sov-heading-main">
            <a href="production.php" class="sov-back" aria-label="Back to Production Board">&larr; <span>Production Board</span></a>
            <div class="sov-heading-line">
                <h1>Order #<?= $e($order['order_number']) ?></h1>
                <span class="sov-stage"><?= $e($stage === 'quality_check' ? 'Quality Check' : 'In Production') ?></span>
                <?php if ($isOverdue): ?><span class="sov-overdue">Overdue</span><?php endif; ?>
            </div>
            <p>Review the order and customer instructions before working on it.</p>
        </div>
    </header>
    <?php if ($msg !== ''): ?><p class="sov-feedback sov-success" role="status"><?= $e($msg) ?></p><?php endif; ?>
    <?php if ($err !== ''): ?><p class="sov-feedback sov-error" role="alert"><?= $e($err) ?></p><?php endif; ?>

    <div class="sov-layout">
        <div class="sov-main-column">
            <section class="sov-panel" aria-labelledby="sovSummaryTitle">
                <div class="sov-panel-heading"><h2 id="sovSummaryTitle">Order overview</h2><span>Placed <?= $e(!empty($order['created_at']) ? date('M j, Y', strtotime((string)$order['created_at'])) : 'recently') ?></span></div>
                <div class="sov-overview">
                    <?php if ($orderedProductPreviewUrl !== ''): ?>
                        <div class="sov-reference sov-product-preview">
                            <span>Ordered product</span>
                            <div class="sov-reference-card">
                                <img src="<?= $e($orderedProductPreviewUrl) ?>" alt="<?= $e($productName) ?> product sample" loading="lazy">
                                <small>Catalog product image</small>
                            </div>
                        </div>
                    <?php endif; ?>
                    <h3><?= $e($productName) ?></h3>
                    <div class="sov-overview-grid">
                        <div><span>Customer</span><strong><?= $e($order['customer_name'] ?? 'Customer') ?></strong></div>
                        <div><span>Quantity</span><strong><?= (int)($order['quantity'] ?? 0) ?></strong></div>
                        <div><span><?= $isDelivery ? 'Delivery schedule' : 'Pickup schedule' ?></span><strong><?= $e($scheduleDate) ?><?= $scheduleTime !== '' ? ' · ' . $e($scheduleTime) : '' ?></strong></div>
                        <div><span>Task</span><strong><?= $e(orderTaskStatusLabel((string)($order['task_status'] ?? 'not_started'))) ?></strong></div>
                    </div>
                </div>
            </section>

            <section class="sov-panel" aria-labelledby="sovInstructionsTitle">
                <div class="sov-panel-heading"><h2 id="sovInstructionsTitle">Customer instructions</h2></div>
                <div class="sov-instructions">
                    <?php if (!$hasInstructions): ?>
                        <p class="sov-empty">No notes, customization details, or reference image were provided for this order.</p>
                    <?php else: ?>
                        <?php if ($notes !== ''): ?>
                            <div class="sov-note"><span>Notes / customization</span><p><?= nl2br($e($notes)) ?></p></div>
                        <?php endif; ?>
                        <?php if ($description !== ''): ?>
                            <div class="sov-note"><span>Additional order details</span><p><?= nl2br($e($description)) ?></p></div>
                        <?php endif; ?>
                        <?php if ($referenceUrl !== '' && !$referenceIsImage): ?>
                            <div class="sov-reference">
                                <span>File to print</span>
                                <a class="sov-download-link" href="<?= $e($referenceUrl) ?>" target="_blank" rel="noopener noreferrer" download="<?= $e($referenceDownloadName) ?>">Download <?= $e(strtoupper(pathinfo($referenceFile, PATHINFO_EXTENSION))) ?> file</a>
                            </div>
                        <?php elseif ($referenceUrl !== ''): ?>
                            <div class="sov-reference">
                                <span>Customer reference image</span>
                                <a href="<?= $e($referenceUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open full-size customer reference image">
                                    <img src="<?= $e($referenceUrl) ?>" alt="Customer reference image for order <?= $e($order['order_number']) ?>" loading="lazy">
                                    <small>Open full-size image &nearr;</small>
                                </a>
                                <a class="sov-download-link" href="<?= $e($referenceUrl) ?>" download="<?= $e($referenceDownloadName) ?>">Download original image</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($hasUploadedReference && $referenceUrl === ''): ?>
                            <p class="sov-empty">The uploaded reference image is currently unavailable. Contact the administrator to recover the file.</p>
                        <?php endif; ?>
                        <?php if ($taskInstructions !== ''): ?>
                            <div class="sov-note"><span>Production instructions</span><p><?= nl2br($e($taskInstructions)) ?></p></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="sov-side-column" aria-label="Staff order actions">
            <section class="sov-panel" aria-labelledby="sovActionTitle">
                <div class="sov-panel-heading"><h2 id="sovActionTitle">My task</h2></div>
                <div class="sov-action-content">
                    <?php if (!$canUpdateTask): ?>
                        <p class="sov-notice">Accept this order in your <strong>Waiting</strong> tab first. Only the staff member who accepted it can update this stage.</p>
                        <a href="production.php" class="sov-button sov-button-secondary">Back to Production Board</a>
                    <?php else: ?>
                        <form method="post" class="sov-task-form">
                            <input type="hidden" name="action" value="task_status">
                            <input type="hidden" name="id" value="<?= $orderId ?>">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                            <label for="sovTaskStatus">Task status</label>
                            <select id="sovTaskStatus" name="task_status">
                                <?php foreach (orderTaskStatusOptions() as $value => $label): ?>
                                    <option value="<?= $e($value) ?>" <?= normalizeOrderTaskStatus((string)($order['task_status'] ?? 'not_started')) === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="sovTaskNote">Task note <span>(optional)</span></label>
                            <textarea id="sovTaskNote" name="task_note" rows="2" maxlength="500" placeholder="Add a brief progress note"></textarea>
                            <button class="sov-button sov-button-secondary" type="submit">Save task update</button>
                        </form>
                        <div class="sov-action-divider"></div>
                        <?php if ($stage === 'in_production'): ?>
                            <form method="post" onsubmit="return confirm('Finish production and send this order to Quality Check?');">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="id" value="<?= $orderId ?>">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="new_status" value="quality_check">
                                <button type="submit" class="sov-button sov-button-primary">Finish production</button>
                            </form>
                        <?php else: ?>
                            <form method="post" onsubmit="return confirm('Approve this order and mark it Ready?');">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="id" value="<?= $orderId ?>">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="new_status" value="ready">
                                <button type="submit" class="sov-button sov-button-primary">Approve &amp; mark ready</button>
                            </form>
                            <details class="sov-reject">
                                <summary>Return to Production</summary>
                                <form method="post" onsubmit="return confirm('Return this order to Production?');">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= $orderId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="new_status" value="in_production">
                                    <label for="sovQcReason">What needs to be corrected?</label>
                                    <textarea id="sovQcReason" name="log_notes" rows="3" maxlength="500" required placeholder="Explain the issue for Production"></textarea>
                                    <button type="submit" class="sov-button sov-button-secondary">Confirm return</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <details class="sov-panel sov-history">
                <summary>Order activity <span>View history</span></summary>
                <div class="sov-history-body">
                    <?php
                    $logs = $conn->prepare('SELECT pl.status_to, pl.notes, pl.created_at, u.full_name
                        FROM production_logs pl LEFT JOIN users u ON pl.updated_by=u.id
                        WHERE pl.order_id=? ORDER BY pl.created_at DESC LIMIT 15');
                    if ($logs) {
                        $logs->bind_param('i', $orderId);
                        $logs->execute();
                        $history = $logs->get_result()->fetch_all(MYSQLI_ASSOC);
                        $logs->close();
                    } else {
                        $history = [];
                    }
                    ?>
                    <?php if (!$history): ?><p class="sov-empty">No activity recorded yet.</p><?php endif; ?>
                    <?php foreach ($history as $row): ?>
                        <div class="sov-history-entry">
                            <strong><?= $e(ucwords(str_replace('_', ' ', (string)$row['status_to']))) ?></strong>
                            <?php if (trim((string)$row['notes']) !== ''): ?><p><?= $e($row['notes']) ?></p><?php endif; ?>
                            <small><?= $e($row['full_name'] ?: 'System') ?> · <?= $e(date('M j, Y · g:i A', strtotime((string)$row['created_at']))) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/../includes/staff_footer.php'; ?>
