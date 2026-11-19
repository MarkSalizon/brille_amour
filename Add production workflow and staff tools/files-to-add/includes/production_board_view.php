<?php
/**
 * production_board_view.php
 * ─────────────────────────────────────────────────────────
 * The kanban markup itself. Rendered through renderProductionBoard() from
 * includes/production_board.php - never included directly, and never trusted
 * with raw request data.
 *
 * Admin customer groups remain collapsible. Staff customer groups are ordinary
 * always-expanded containers, so order information and actions are visible at once.
 *
 * In scope: $stages, $stageLabels, $stageColors, $dateGroups, $counts,
 *           $workflowCsrf, $staffRole, $isAdmin
 */

$stages       = $stages ?? [];
$stageLabels  = $stageLabels ?? [];
$stageColors  = $stageColors ?? [];
$dateGroups   = $dateGroups ?? [];
$counts       = $counts ?? [];
$capacities   = $capacities ?? [];
$productionQueueCount = (int)($productionQueueCount ?? 0);
$queuePositions = $queuePositions ?? [];
$stageWaitingCounts = $stageWaitingCounts ?? [];
$stageWaitingNext = $stageWaitingNext ?? [];
$isAdmin      = !empty($isAdmin);
$staffRole    = $staffRole ?? null;
$workflowCsrf = $workflowCsrf ?? '';
$actorId = (int)($actorId ?? 0);
$today        = date('Y-m-d');
require_once __DIR__ . '/product_images.php';
?>

<?php
if (!function_exists('productionCardTimeAgo')) {
    function productionCardTimeAgo($datetime): string
    {
        if (!$datetime) return 'Updated recently';
        $ts = strtotime((string)$datetime);
        if (!$ts) return 'Updated recently';
        $diff = time() - $ts;
        if ($diff < 60) return 'Last updated just now';
        if ($diff < 3600) return 'Last updated ' . max(1, (int)floor($diff / 60)) . 'm ago';
        if ($diff < 86400) return 'Last updated ' . max(1, (int)floor($diff / 3600)) . 'h ago';
        if ($diff < 604800) return 'Last updated ' . max(1, (int)floor($diff / 86400)) . 'd ago';
        return 'Last updated ' . date('M j, Y', $ts);
    }
}
if (!function_exists('productionCardImageSrc')) {
    function productionCardImageSrc(array $order): string
    {
        // Use the exact same image resolver as the Customer Shop. If Admin updates
        // a product image, every linked production card uses that current image.
        $orderName = trim((string)($order['order_type'] ?? ''));
        $currentName = trim((string)($order['current_product_name'] ?? ''));
        $productName = $currentName !== '' ? $currentName : $orderName;
        $display = productDisplayImage([
            'name' => $productName,
            'image' => (string)($order['current_product_image'] ?? ''),
        ]);
        if ($display !== null && is_file(__DIR__ . '/../' . $display)) {
            return '../' . $display;
        }

        // Keep historical/unlinked orders useful by falling back to their saved name.
        if ($orderName !== '' && $orderName !== $productName) {
            $fallback = productPreviewImage($orderName);
            if ($fallback !== null && is_file(__DIR__ . '/../' . $fallback)) {
                return '../' . $fallback;
            }
        }
        return '';
    }
}?>

<?php $showAllStagesTab = $isAdmin && count($stages) > 1; ?>
<?php if (!$isAdmin):
    $staffBoardOrders = $board[$stages[0] ?? ''] ?? [];
    $staffWaitingCount = 0;
    $staffAcceptedCount = 0;
    foreach ($staffBoardOrders as $staffBoardOrder) {
        if (($staffBoardOrder['acceptance_decision'] ?? '') === 'accepted') ++$staffAcceptedCount;
        else ++$staffWaitingCount;
    }
?>
<nav class="staff-task-tabs" aria-label="Order acceptance status" role="tablist">
  <button type="button" class="staff-task-tab is-active" role="tab" data-staff-task-tab="waiting" aria-selected="true">
    <span>Waiting</span><span class="staff-task-tab-count"><?= $staffWaitingCount ?></span>
  </button>
  <button type="button" class="staff-task-tab" role="tab" data-staff-task-tab="accepted" aria-selected="false">
    <span><?= $staffRole === 'quality_check' ? 'Quality Check' : 'In production' ?></span><span class="staff-task-tab-count"><?= $staffAcceptedCount ?></span>
  </button>
</nav>
<?php endif; ?>
<?php if ($isAdmin): ?>
<div class="board-stage-toolbar">
  <div class="board-stage-tabs" role="tablist" aria-label="Production stages">
    <?php foreach ($stages as $stageIndex => $stage): ?>
    <button type="button" class="stage-tab <?= $stage === 'out_for_delivery' ? 'delivery-stage-tab ' : '' ?><?= $stageIndex === 0 ? 'is-active' : '' ?>" data-stage-tab="<?= htmlspecialchars($stage) ?>" role="tab" aria-selected="<?= $stageIndex === 0 ? 'true' : 'false' ?>" <?= $stage === 'out_for_delivery' ? 'hidden' : '' ?>>
      <span class="stage-tab-dot" style="background:<?= htmlspecialchars($stageColors[$stage] ?? '#9CA3AF') ?>"></span>
      <?= htmlspecialchars($stageLabels[$stage] ?? $stage) ?>
      <em><?= (int)($counts[$stage] ?? 0) ?></em>
    </button>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="kanban-board <?= count($stages) === 1 ? 'single-stage' : (count($stages) === 2 ? 'two-stage' : 'multi-stage') ?>" id="kanbanBoard">
<?php foreach ($stages as $stage):
    // Boards are intentionally single-stage for staff, while admins only see
    // the two hand-off stages. Do not expose Back actions into hidden columns.
    $prevStage  = null;
    $nextStage  = $stage === 'ready'
        ? null
        : getNextProductionStatus($stage);
    $canAct     = $isAdmin ? in_array($stage, ['pending', 'ready', 'out_for_delivery'], true) : staffRoleOwnsStage($staffRole, $stage);
    $ownerRole  = getStaffRoleForStage($stage);
    $colClass   = $isAdmin ? 'is-open' : ($canAct ? 'is-mine' : 'is-locked');
    $stageColor = $stageColors[$stage] ?? '#9CA3AF';
?>
<div class="kanban-col <?= $colClass ?>"
     data-stage="<?= htmlspecialchars($stage) ?>"
     data-can-act="<?= $canAct ? '1' : '0' ?>"
     data-prev="<?= htmlspecialchars((string)$prevStage) ?>"
     data-next="<?= htmlspecialchars((string)$nextStage) ?>">
  <div class="col-header" style="border-top:3px solid <?= htmlspecialchars($stageColor) ?>;">
    <div class="col-dot" style="background:<?= htmlspecialchars($stageColor) ?>"></div>
    <span class="col-label"><?= htmlspecialchars($stageLabels[$stage] ?? $stage) ?></span>
    <span class="col-count" style="background:<?= htmlspecialchars($stageColor) ?>"><?= (int)($counts[$stage] ?? 0) ?></span>
  </div>
  <div class="col-owner <?= $isAdmin ? 'admin' : ($canAct ? 'mine' : '') ?>">
    <?php if ($isAdmin && $stage === 'out_for_delivery'): ?>
      Delivery in transit
    <?php elseif ($isAdmin): ?>
      <span aria-hidden="true"><?= $ownerRole ? staffRoleIcon($ownerRole) : '•' ?></span>
      <?= $ownerRole ? htmlspecialchars(staffRoleLabel($ownerRole)) : 'Unassigned stage' ?>
    <?php elseif ($canAct): ?>
      <span aria-hidden="true"><?= staffRoleIcon($staffRole) ?></span> Your stage
    <?php else: ?>
      <?= $ownerRole ? htmlspecialchars(staffRoleLabel($ownerRole)) : 'Read only' ?>
    <?php endif; ?>
  </div>
  <?php if (isset($capacities[$stage])):
      $stageCapacity = $capacities[$stage]; ?>
    <div class="stage-capacity-note">
      <strong>Active <?= (int)$stageCapacity['used'] ?> / <?= (int)$stageCapacity['limit'] ?></strong>
      <span><?= !empty($stageCapacity['full']) ? 'Active slots full' : ((int)$stageCapacity['available'] . ' active slot' . ((int)$stageCapacity['available'] === 1 ? '' : 's') . ' available') ?></span>
      <?php $waitingCount = (int)($stageWaitingCounts[$stage] ?? 0); ?>
      <span><b>Waiting: <?= $waitingCount ?></b> · No limit</span>
      <?php if ($waitingCount > 0): ?>
        <small>All waiting orders stay visible. Accepting an order uses one active slot.</small>
      <?php else: ?>
        <small>No orders waiting for acceptance. Waiting itself has no capacity limit.</small>
      <?php endif; ?>
    </div>
  <?php elseif ($isAdmin && $stage === 'pending' && $productionQueueCount > 0): ?>
    <div class="stage-capacity-note"><strong><?= $productionQueueCount ?></strong><span>waiting for Production · FIFO</span></div>
  <?php endif; ?>
  <div class="col-body" data-drop-zone="<?= htmlspecialchars($stage) ?>">
  <?php
    $taskBuckets = $isAdmin ? ['all'] : ['waiting', 'accepted'];
    foreach ($taskBuckets as $taskBucket):
      $visibleDateGroups = $dateGroups[$stage] ?? [];
      if (!$isAdmin) {
          $visibleOrders = array_values(array_filter($board[$stage] ?? [], static function (array $order) use ($taskBucket): bool {
              $accepted = ($order['acceptance_decision'] ?? '') === 'accepted';
              return $taskBucket === 'accepted' ? $accepted : !$accepted;
          }));
          // Staff work on individual orders: never combine multiple orders from
          // the same customer into one frame. Keep Admin's grouped view intact.
          $visibleDateGroups = groupProductionOrdersByDate($visibleOrders);
          foreach ($visibleDateGroups as &$staffDateGroup) {
              $individualOrders = [];
              foreach ($staffDateGroup['groups'] as $staffCustomerGroup) {
                  foreach ($staffCustomerGroup['batches'] as $staffBatch) {
                      foreach ($staffBatch['orders'] as $staffOrder) {
                          $individualGroup = $staffCustomerGroup;
                          $individualGroup['key'] = 'staff-order-' . (int)$staffOrder['id'];
                          $individualGroup['count'] = 1;
                          $individualGroup['quantity'] = (int)($staffOrder['quantity'] ?? 0);
                          $individualGroup['overdue'] = productionOrderIsOverdue($staffOrder, $today) ? 1 : 0;
                          $individualGroup['due_ts'] = productionOrderDueTimestamp($staffOrder);
                          $individualGroup['batches'] = [['label' => $staffBatch['label'], 'orders' => [$staffOrder]]];
                          $individualOrders[] = $individualGroup;
                      }
                  }
              }
              // Keep the queue chronological even when a customer places multiple orders.
              // Newly created orders appear after existing cards, never beside them.
              usort($individualOrders, static function (array $left, array $right): int {
                  $leftOrder = $left['batches'][0]['orders'][0];
                  $rightOrder = $right['batches'][0]['orders'][0];
                  return strcmp((string)($leftOrder['created_at'] ?? ''), (string)($rightOrder['created_at'] ?? ''))
                      ?: ((int)$leftOrder['id'] <=> (int)$rightOrder['id']);
              });
              $staffDateGroup['groups'] = $individualOrders;
          }
          unset($staffDateGroup);
      } else {
          // Admin, like staff, sees one immediately visible card per order.
          foreach ($visibleDateGroups as &$adminDateGroup) {
              $individualOrders = [];
              foreach ($adminDateGroup['groups'] as $customerGroup) {
                  foreach ($customerGroup['batches'] as $checkoutBatch) {
                      foreach ($checkoutBatch['orders'] as $singleOrder) {
                          $singleGroup = $customerGroup;
                          $singleGroup['key'] = 'admin-order-' . (int)$singleOrder['id'];
                          $singleGroup['count'] = 1;
                          $singleGroup['quantity'] = (int)($singleOrder['quantity'] ?? 0);
                          $singleGroup['overdue'] = productionOrderIsOverdue($singleOrder, $today) ? 1 : 0;
                          $singleGroup['due_ts'] = productionOrderDueTimestamp($singleOrder);
                          $singleGroup['batches'] = [['label' => $checkoutBatch['label'], 'orders' => [$singleOrder]]];
                          $individualOrders[] = $singleGroup;
                      }
                  }
              }
              $adminDateGroup['groups'] = $individualOrders;
          }
          unset($adminDateGroup);
      }
  ?>
  <?php if (!$isAdmin): ?>
    <div class="staff-task-pane" data-staff-task-pane="<?= $taskBucket ?>" <?= $taskBucket === 'waiting' ? '' : 'hidden' ?>>
  <?php endif; ?>
    <?php if (empty($visibleDateGroups)): ?>
      <div class="col-empty"><?= $isAdmin ? 'No orders' : ($taskBucket === 'waiting' ? 'No orders waiting for acceptance.' : 'No accepted orders yet.') ?></div>
    <?php else: foreach ($visibleDateGroups as $dateGroup): ?>
    <section class="production-date-group" data-order-date="<?= htmlspecialchars($dateGroup['key']) ?>">
      <div class="production-date-heading">
        <span><?= htmlspecialchars($dateGroup['label']) ?></span>
      </div>
      <div class="production-date-orders">
    <?php foreach ($dateGroup['groups'] as $group):
      $frameOverdue = $group['overdue'] > 0;
      $multiBatch   = count($group['batches']) > 1;
      // Admin production cards are one order per frame. Read the receive method
      // directly from that order so Pickup and Delivery can never share a progress view.
      $firstFulfillmentOrder = $group['batches'][0]['orders'][0] ?? [];
      $groupFulfillment = strtolower(trim((string)($firstFulfillmentOrder['fulfillment_method'] ?? 'pickup'))) === 'delivery'
          ? 'delivery'
          : 'pickup';
      $groupDeliveryProgress = 'pending';
      if ($groupFulfillment === 'delivery') {
          $firstOrder = $group['batches'][0]['orders'][0] ?? [];
          $rawGroupDeliveryStatus = strtolower(trim((string)($firstOrder['delivery_status'] ?? 'pending')));
          if ($rawGroupDeliveryStatus === 'out_for_delivery') {
              $groupDeliveryProgress = 'out_for_delivery';
          } elseif ($rawGroupDeliveryStatus === 'delivered') {
              $groupDeliveryProgress = 'delivered';
          } elseif ((string)($firstOrder['status'] ?? '') === 'ready' || $rawGroupDeliveryStatus === 'ready_for_dispatch') {
              $groupDeliveryProgress = 'ready';
          }
      }
      // Sort metadata is kept on the frame so Admin and Staff can reorder the
      // visible board without changing any workflow/status logic.
      $sortOrder = $group['batches'][0]['orders'][0] ?? [];
      $sortCreatedTs = strtotime((string)($sortOrder['created_at'] ?? '')) ?: 0;
      $sortUpdatedTs = strtotime((string)($sortOrder['updated_at'] ?? $sortOrder['created_at'] ?? '')) ?: $sortCreatedTs;
      $sortDueTs = (int)($group['due_ts'] ?? 0);
      $sortPriority = strtolower(trim((string)($sortOrder['priority'] ?? 'normal')));
      // Staff should see the full order card without opening a customer disclosure.
      // Keep the existing collapsible structure for Admin Production only.
      $frameTag = 'div';
      $frameHeadTag = 'div';
    ?>
    <<?= $frameTag ?> class="customer-frame <?= $frameOverdue ? 'overdue' : '' ?>"
             style="--card-accent:<?= htmlspecialchars($stageColor) ?>"
             data-frame-key="<?= htmlspecialchars($group['key'] . ($isAdmin ? '' : '-' . $taskBucket)) ?>"
             data-customer="<?= htmlspecialchars($group['customer']) ?>"
             data-stage="<?= htmlspecialchars($stage) ?>"
             data-fulfillment="<?= htmlspecialchars($groupFulfillment) ?>"
             data-delivery-progress="<?= htmlspecialchars($groupDeliveryProgress) ?>"
             data-sort-created="<?= $sortCreatedTs ?>"
             data-sort-updated="<?= $sortUpdatedTs ?>"
             data-sort-due="<?= $sortDueTs ?>"
             data-sort-customer="<?= htmlspecialchars(mb_strtolower((string)$group['customer'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>"
             data-sort-order="<?= htmlspecialchars((string)($sortOrder['order_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
             data-sort-quantity="<?= (int)$group['quantity'] ?>"
             data-sort-overdue="<?= $frameOverdue ? '1' : '0' ?>"
             data-sort-priority="<?= htmlspecialchars($sortPriority, ENT_QUOTES, 'UTF-8') ?>">
      <<?= $frameHeadTag ?> class="frame-head">
        <span class="frame-info">
          <span class="frame-title">
            <span class="frame-name"><?= htmlspecialchars($group['customer']) ?></span>
            <span class="frame-count"><?= (int)$group['count'] ?></span>
          </span>
          <span class="frame-stats">
            <span><?= (int)$group['count'] ?> order<?= $group['count'] === 1 ? '' : 's' ?></span>
            <span>Qty <?= (int)$group['quantity'] ?></span>
            <?php if ($multiBatch): ?>
              <span><?= count($group['batches']) ?> checkouts</span>
            <?php endif; ?>
          </span>
          <span class="frame-details">
          <?php if ($group['contact'] !== ''): ?>
            <span class="frame-contact"><?= htmlspecialchars($group['contact']) ?></span>
          <?php endif; ?>
          <?php if ($group['due_ts'] !== null || $frameOverdue): ?>
            <span class="frame-due <?= $frameOverdue ? 'overdue' : '' ?>">
              <?= $group['due_ts'] !== null ? date('M j, Y', $group['due_ts']) : 'No due date' ?>
              <?php if ($frameOverdue): ?>
              <span class="late-chip"><?= $group['overdue'] === $group['count'] ? 'LATE' : $group['overdue'] . ' LATE' ?></span>
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <?php if (!empty($group['assigned'])): ?>
            <span class="frame-assigned"><?= htmlspecialchars(implode(', ', $group['assigned'])) ?></span>
          <?php endif; ?>
          </span>
        </span>
      </<?= $frameHeadTag ?>>
      <div class="frame-body">
        <?php foreach ($group['batches'] as $batch):
          $batchCount = count($batch['orders']);
        ?>
        <?php if ($multiBatch): ?>
        <div class="frame-batch">
          Checkout <?= htmlspecialchars($batch['label']) ?>
          &middot; <?= $batchCount ?> item<?= $batchCount === 1 ? '' : 's' ?>
        </div>
        <?php endif; ?>
        <?php foreach ($batch['orders'] as $order):
          $isOverdue = productionOrderIsOverdue($order, $today);
          $dueTs     = productionOrderDueTimestamp($order);
          $createdTs = strtotime((string)($order['created_at'] ?? ''));
          $decision = (string)($order['acceptance_decision'] ?? '');
          // Use the same server-side eligibility check as Finish Production/QC.
          // Never display a stage-action button from a stale acceptance label.
          $listedAsMine = !$isAdmin && $decision === 'accepted' && (int)($order['accepted_staff_id'] ?? 0) === $actorId;
          $acceptanceEligibility = $listedAsMine
              ? ($order['staff_eligibility'] ?? ['allowed' => false, 'reason' => 'unavailable'])
              : ['allowed' => false, 'reason' => 'not_accepted'];
          $acceptedByMe = $listedAsMine && !empty($acceptanceEligibility['allowed']);
          $awaitingDecision = !$isAdmin && in_array($stage, ['in_production','quality_check'], true) && $decision !== 'accepted';
        ?>
        <?php
          $orderFulfillment = strtolower(trim((string)($order['fulfillment_method'] ?? 'pickup'))) === 'delivery' ? 'delivery' : 'pickup';
          $deliveryProgress = 'pending';
          if ($orderFulfillment === 'delivery') {
              $rawDeliveryStatus = strtolower(trim((string)($order['delivery_status'] ?? 'pending')));
              if ($rawDeliveryStatus === 'out_for_delivery') {
                  $deliveryProgress = 'out_for_delivery';
              } elseif ($rawDeliveryStatus === 'delivered') {
                  $deliveryProgress = 'delivered';
              } elseif ((string)($order['status'] ?? '') === 'ready' || $rawDeliveryStatus === 'ready_for_dispatch') {
                  $deliveryProgress = 'ready';
              }
          }
        ?>
        <div class="order-card <?= $isOverdue ? 'overdue' : '' ?>"
             style="--card-accent:<?= htmlspecialchars($stageColor) ?>"
             data-order-id="<?= (int)$order['id'] ?>"
             data-order-number="<?= htmlspecialchars($order['order_number']) ?>"
             data-stage="<?= htmlspecialchars($stage) ?>"
             data-fulfillment="<?= htmlspecialchars($orderFulfillment) ?>"
             data-delivery-progress="<?= htmlspecialchars($deliveryProgress) ?>"
             <?= $canAct && ($isAdmin || $acceptedByMe) && empty($order['production_hold_reason']) ? 'draggable="true"' : '' ?>>
          <?php if ($isAdmin):
            $adminImage = productionCardImageSrc($order);
            $adminSchedule = (($order['fulfillment_method'] ?? 'pickup') === 'delivery') ? 'Delivery' : 'Pickup';
            $adminScheduleDate = !empty($order['due_date']) && strtotime((string)$order['due_date'])
                ? date('M j, Y', strtotime((string)$order['due_date'])) : 'Not set';
          ?>
          <div class="admin-order-layout">
            <div class="admin-order-thumb<?= $adminImage === '' ? ' is-placeholder' : '' ?>">
              <?php if ($adminImage !== ''): ?>
                <img src="<?= htmlspecialchars($adminImage, ENT_QUOTES, 'UTF-8') ?>" alt="Product image for <?= htmlspecialchars((string)($order['order_type'] ?? 'order'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              <?php else: ?><span aria-hidden="true">BA</span><?php endif; ?>
            </div>
            <div class="admin-order-summary">
              <div class="card-top"><span class="card-order-num">Order #<?= htmlspecialchars($order['order_number']) ?><?= $createdTs ? ' · ' . date('M j, Y', $createdTs) : '' ?></span></div>
              <strong class="admin-order-customer"><?= htmlspecialchars($group['customer']) ?></strong>
              <div class="card-type"><span class="card-product"><?= htmlspecialchars($order['order_type'] ?? 'General') ?></span></div>
              <div class="admin-order-meta">
                <span class="card-qty">Quantity: <?= (int)$order['quantity'] ?></span>
                <span class="card-due <?= $isOverdue ? 'overdue' : '' ?>"><?= $adminSchedule ?>: <?= htmlspecialchars($adminScheduleDate) ?><?= $isOverdue ? ' · Late' : '' ?></span>
              </div>
            </div>
          </div>
          <div class="card-created admin-order-updated"><?= htmlspecialchars(productionCardTimeAgo($order['updated_at'] ?? $order['created_at'] ?? '')) ?></div>
          <?php else:
            $thumbSrc = productionCardImageSrc($order);
            $scheduleLabel = (($order['fulfillment_method'] ?? 'pickup') === 'delivery') ? 'Delivery' : 'Pickup';
            $scheduleDate = !empty($order['due_date']) ? date('M j, Y', strtotime((string)$order['due_date'])) : 'Not set';
          ?>
          <div class="staff-order-layout">
            <div class="staff-order-thumb<?= $thumbSrc === '' ? ' is-placeholder' : '' ?>">
              <?php if ($thumbSrc !== ''): ?>
                <img src="<?= htmlspecialchars($thumbSrc) ?>" alt="<?= htmlspecialchars((string)($order['order_type'] ?? 'Order product')) ?>">
              <?php else: ?>
                <span>BA</span>
              <?php endif; ?>
            </div>
            <div class="staff-order-summary">
              <div class="card-top">
                <span class="card-order-num">Order #<?= htmlspecialchars($order['order_number']) ?><?= $createdTs ? ' · ' . date('M j, Y', $createdTs) : '' ?></span>
              </div>
              <div class="card-type">
                <span class="card-product"><?= htmlspecialchars($order['order_type'] ?? 'General') ?></span>
              </div>
              <div class="staff-order-meta">
                <span class="card-qty">Quantity: <?= (int)$order['quantity'] ?></span>
                <span class="card-due <?= $isOverdue ? 'overdue' : '' ?>"><?= $scheduleLabel ?>: <?= $scheduleDate ?><?php if ($isOverdue): ?><span class="late-chip">LATE</span><?php endif; ?></span>
              </div>
            </div>
          </div>
          <div class="card-created staff-order-updated"><?= htmlspecialchars(productionCardTimeAgo($order['updated_at'] ?? $order['created_at'] ?? '')) ?></div>
          <?php if (in_array($stage, ['in_production','quality_check'], true)): ?>
          <div class="staff-acceptance-status staff-acceptance-status-inline <?= $decision === 'accepted' ? 'is-accepted' : ($decision === 'declined' ? 'is-declined' : 'is-pending') ?>">
            <?php if ($acceptedByMe): ?>Accepted by you
            <?php elseif ($listedAsMine): ?><?= htmlspecialchars(staffOrderActionFailureMessage($acceptanceEligibility['reason'])) ?>
            <?php elseif ($decision === 'accepted'): ?>Assigned to <?= htmlspecialchars((string)($order['accepted_staff_name'] ?? 'another staff member')) ?>
            <?php elseif ($decision === 'declined'): ?>Previously declined
            <?php else: ?>Waiting for your response<?php endif; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($awaitingDecision): ?>
            <?php $activeCapacityFull = !$isAdmin && !empty($capacities[$stage]['full']); ?>
            <div class="staff-acceptance-actions">
              <form method="post" data-board-decision>
                <input type="hidden" name="action" value="decide_order">
                <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="decision" value="accept">
                <button type="submit" class="btn btn-primary" <?= $activeCapacityFull ? 'disabled title="Active slots are full. This order stays in Waiting."' : '' ?>><?= $activeCapacityFull ? 'Active slots full' : 'Accept' ?></button>
              </form>
              <details class="staff-decline-editor">
                <summary>Decline</summary>
                <form method="post" data-board-decision>
                  <input type="hidden" name="action" value="decide_order">
                  <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
                  <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                  <input type="hidden" name="decision" value="decline">
                  <label>Reason for declining<textarea name="reason" maxlength="500" rows="2" required placeholder="Explain why you cannot take this order"></textarea></label>
                  <button class="btn btn-secondary" type="submit">Confirm decline</button>
                </form>
              </details>
              <a href="orders.php?<?= $isAdmin ? 'from=production&amp;' : '' ?>view=<?= (int)$order['id'] ?>" class="btn btn-ghost">View</a>
            </div>
          <?php elseif ($canAct && ($isAdmin || $acceptedByMe)): ?>
          <div class="card-actions">
            <?php if ($isAdmin && $stage === 'pending'): ?>
              <?php if (!empty($order['payment_id']) && ($order['payment_status'] ?? '') !== 'verified'): ?>
                <a href="payments.php#payment-<?= (int)$order['payment_id'] ?>" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:.72rem;">
                  <?= ($order['payment_status'] ?? '') === 'rejected' ? 'Review Rejected Payment' : 'Verify Payment' ?>
                </a>
              <?php elseif (($order['payment_status'] ?? '') === 'verified'): ?>
                <?php $queuePos = $queuePositions[(int)$order['id']] ?? null; ?>
                <span class="production-queue-chip">
                  <?= $queuePos ? 'Queue #' . (int)$queuePos : 'Waiting for Production' ?>
                </span>
              <?php else: ?>
                <span class="production-queue-chip">Awaiting payment</span>
              <?php endif; ?>
            <?php elseif ($isAdmin && $stage === 'ready'): ?>
              <?php if ($orderFulfillment === 'delivery'): ?>
              <form method="post" data-board-delivery>
                <input type="hidden" name="action" value="delivery_status">
                <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="delivery_status" value="out_for_delivery">
                <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:.72rem;">Move to Out for Delivery</button>
              </form>
              <?php else: ?>
              <form method="post" data-board-move>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <button name="new_status" value="completed" class="btn btn-sm btn-gold" style="padding:4px 10px;font-size:.72rem;">Mark Completed</button>
              </form>
              <?php endif; ?>
            <?php elseif ($isAdmin && $stage === 'out_for_delivery'): ?>
            <form method="post" data-board-delivery>
              <input type="hidden" name="action" value="delivery_status">
              <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <input type="hidden" name="delivery_status" value="delivered">
              <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:.72rem;">Mark Delivered</button>
            </form>
            <?php elseif (!$isAdmin && $staffRole === 'production' && $stage === 'in_production'): ?>
            <form method="post" data-board-move>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <?php $qcFull = !empty($capacities['quality_check']['full']); ?>
              <button name="new_status" value="quality_check" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:.72rem;"><?= $qcFull ? 'Finish & Queue for QC' : 'Finish Production' ?></button>
            </form>
            <?php elseif (!$isAdmin && $staffRole === 'quality_check' && $stage === 'quality_check'): ?>
            <form method="post" data-board-move>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <button name="new_status" value="ready" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:.72rem;">Approve &amp; Mark Ready</button>
            </form>
            <?php endif; ?>
            <a href="orders.php?<?= $isAdmin ? 'from=production&amp;' : '' ?>view=<?= (int)$order['id'] ?>" class="btn btn-sm btn-ghost" style="padding:4px 10px;font-size:.72rem;">View</a>
          </div>
          <?php if ($acceptedByMe && $staffRole === 'quality_check' && $stage === 'quality_check'): ?>
          <details class="qc-return-editor" style="margin-top:8px;">
            <summary>Return to Production</summary>
            <form method="post" data-board-move style="display:grid;gap:8px;margin-top:8px;">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <label style="display:grid;gap:4px;font-size:.75rem;">QC rejection reason
                <textarea name="notes" maxlength="500" required placeholder="Describe what must be corrected"></textarea>
              </label>
              <button name="new_status" value="in_production" class="btn btn-sm btn-secondary" type="submit">Return to Production</button>
            </form>
          </details>
          <?php endif; ?>
          <?php else: ?>
          <div class="card-actions view-only">
            <span class="card-locked-inline">
              <?= !$isAdmin && $listedAsMine ? 'Status unavailable — refresh the board' : (!$isAdmin && $decision === 'accepted' ? 'Accepted by another staff member' : ($ownerRole ? htmlspecialchars(staffRoleLabel($ownerRole)) . ' only' : 'Not your stage')) ?>
            </span>
            <a href="orders.php?<?= $isAdmin ? 'from=production&amp;' : '' ?>view=<?= (int)$order['id'] ?>" class="btn btn-sm btn-ghost" style="padding:4px 10px;font-size:.72rem;">View</a>
          </div>
          <?php endif; ?>

          <?php if (!empty($order['production_hold_reason'])): ?>
          <div class="production-hold">
            <strong>On Hold:</strong> <?= htmlspecialchars($order['production_hold_reason']) ?>
          </div>
          <?php endif; ?>

          <?php if ($acceptedByMe && $staffRole === 'production' && $stage === 'in_production' && $canAct): ?>
          <details class="production-hold-editor">
            <summary>Hold / Resume</summary>
            <form method="post" data-board-hold>
              <input type="hidden" name="action" value="hold">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <input type="hidden" name="workflow_csrf_token" value="<?= htmlspecialchars($workflowCsrf) ?>">
              <input type="hidden" name="expected_hold" value="<?= productionHoldVersion($order) ?>">
              <label><input type="checkbox" name="on_hold" value="1" <?= !empty($order['production_hold_reason']) ? 'checked' : '' ?>> Put this order on hold</label>
              <label>Hold reason <textarea name="production_hold_reason" maxlength="500" placeholder="Required only when placing the order on hold"><?= htmlspecialchars($order['production_hold_reason'] ?? '') ?></textarea></label>
              <small>Uncheck the box and save to resume production.</small>
              <button class="btn btn-sm btn-primary" type="submit">Save</button>
              <span data-hold-feedback role="status"></span>
            </form>
          </details>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </<?= $frameTag ?>>
    <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; endif; ?>
  <?php if (!$isAdmin): ?></div><?php endif; ?>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
