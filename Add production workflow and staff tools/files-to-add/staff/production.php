<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
  header('Location: login.php');
  exit;
}

$pageTitle = 'Production Board';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/order_workflow.php';
require_once '../includes/staff_roles.php';
require_once '../includes/messaging.php';
require_once '../includes/production_board.php';

$msg = '';
$err = '';
$uid = (int)$_SESSION['user_id'];
$workflowCsrf = ensureWorkflowCsrfToken();
$staffRole = getStaffRoleForUser($conn, $uid);
$_SESSION['staff_role'] = $staffRole;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'decide_order') {
  if (!validateWorkflowCsrfToken($_POST['workflow_csrf_token'] ?? null)) {
    $err = 'Invalid request token. Refresh the page and try again.';
  } else {
    $result = decideStaffOrder($conn, (int)($_POST['order_id'] ?? 0), $uid, $staffRole,
      (string)($_POST['decision'] ?? ''), (string)($_POST['reason'] ?? ''));
    if (!empty($result['ok'])) $msg = $result['message'];
    else $err = $result['error'];
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
  if (!validateWorkflowCsrfToken($_POST['workflow_csrf_token'] ?? null)) {
    $err = 'Invalid request token. Refresh the page and try again.';
  } else {
    $result = applyProductionBoardMove(
      $conn,
      (int)($_POST['order_id'] ?? 0),
      sanitize($_POST['new_status'] ?? ''),
      $uid,
      $staffRole,
      false,
      sanitize($_POST['notes'] ?? '')
    );

    if (!empty($result['ok'])) {
      $msg = $result['message'];
    } else {
      $err = $result['error'];
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'hold') {
    if (!validateWorkflowCsrfToken($_POST['workflow_csrf_token'] ?? null)) {
        $err = 'Invalid request token. Refresh the page and try again.';
    } else {
        $result = applyProductionHold($conn, (int)($_POST['order_id'] ?? 0), $_POST, $uid, $staffRole, false);
        if (!empty($result['ok'])) $msg = $result['message'];
        else $err = $result['error'];
    }
}
$ctx = buildProductionBoardContext($conn, $staffRole, false, $workflowCsrf, $uid);
$revision = productionBoardRevision($conn);
$lastLogId = productionBoardLastLogId($conn);

include '../includes/staff_header.php';
?>
<link rel="stylesheet" href="../assets/css/production-live.css?v=<?= filemtime(__DIR__ . '/../assets/css/production-live.css') ?>">
<link rel="stylesheet" href="../assets/css/staff-production.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-production.css') ?>">
<style>

/* ============================================================
   Brille Amour — Production board alignment fixes
   Final layer: keeps labels readable, aligned, and responsive.
   ============================================================ */

#productionLive,
#productionLive .kanban-board,
#productionLive .kanban-col {
  min-width: 0;
  box-sizing: border-box;
}

#productionLive .customer-frame {
  width: 100%;
  box-sizing: border-box;
}

#productionLive .customer-frame > .frame-head {
  width: 100%;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  gap: 10px;
}

#productionLive .customer-frame > .frame-head > .frame-info {
  min-width: 0;
  width: 100%;
}

#productionLive .frame-title {
  min-width: 0;
  align-items: center;
}

#productionLive .frame-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

#productionLive .frame-stats,
#productionLive .frame-details {
  min-width: 0;
}

#productionLive .frame-stats > span,
#productionLive .frame-details > span {
  min-width: 0;
}

#productionLive .frame-contact,
#productionLive .frame-due,
#productionLive .frame-assigned {
  overflow-wrap: anywhere;
  word-break: break-word;
}

/* Order rows: each information group gets a predictable area. */
#productionLive .order-card {
  min-width: 0;
  width: 100%;
  box-sizing: border-box;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto !important;
}

#productionLive .card-top,
#productionLive .card-type,
#productionLive .card-due,
#productionLive .card-assigned {
  min-width: 0;
  max-width: 100%;
}

#productionLive .card-type,
#productionLive .card-due,
#productionLive .card-assigned {
  white-space: normal !important;
  overflow-wrap: anywhere;
  word-break: break-word;
}

#productionLive .card-product,
#productionLive .card-order-num,
#productionLive .card-assigned {
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
}

#productionLive .card-qty {
  white-space: nowrap;
}

#productionLive .card-actions {
  min-width: 0;
  max-width: 100%;
}

#productionLive .card-actions form {
  min-width: 0;
}

#productionLive .card-actions .btn {
  max-width: 100%;
  min-height: 30px;
  text-align: center;
  white-space: normal !important;
  overflow-wrap: anywhere;
  line-height: 1.15 !important;
}

/* Hold/editor rows should not inherit the compact card grid's squeeze. */
#productionLive .production-hold,
#productionLive .production-hold-editor,
#productionLive .production-hold {
  min-width: 0;
}

#productionLive .production-hold-editor label {
  min-width: 0;
}

#productionLive .production-hold-editor textarea {
  max-width: 100%;
  box-sizing: border-box;
}

/* Keep the stage header and owner label vertically aligned. */
#productionLive .col-header,
#productionLive .col-owner {
  box-sizing: border-box;
}

#productionLive .col-label {
  min-width: 0;
  overflow-wrap: anywhere;
}

@media (max-width: 850px) {
  #productionLive .order-card {
    grid-template-columns: minmax(0, 1fr) auto !important;
  }

  #productionLive .card-actions {
    align-self: center;
  }

  #productionLive .card-actions .btn {
    max-width: 170px;
  }
}

@media (max-width: 600px) {
  #productionLive .frame-stats,
  #productionLive .frame-details {
    gap: 5px 7px !important;
  }

  #productionLive .order-card {
    grid-template-columns: minmax(0, 1fr) !important;
    grid-template-rows: auto !important;
    row-gap: 4px !important;
  }

  #productionLive .card-top,
  #productionLive .card-type,
  #productionLive .card-due,
  #productionLive .card-assigned,
  #productionLive .card-actions,
  #productionLive .card-task,
  #productionLive .production-hold,
  #productionLive .production-hold-editor {
    grid-column: 1 !important;
    grid-row: auto !important;
  }

  #productionLive .card-actions {
    justify-content: flex-start !important;
    flex-wrap: wrap !important;
    margin-top: 5px !important;
    padding-top: 6px !important;
    border-top: 1px solid var(--border) !important;
  }

  #productionLive .card-actions .btn {
    max-width: none;
  }
}

/* Toolbar text must never collide with the summary pills. */
.board-toolbar-left {
  min-width: 0;
  flex: 1 1 360px;
}

.board-toolbar-left h1,
.board-toolbar-left p {
  overflow-wrap: anywhere;
}

.board-summary {
  min-width: 0;
  align-items: center;
}

.board-summary-pill {
  white-space: nowrap;
}

</style>
<link rel="stylesheet" href="../assets/css/staff-portal-professional.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-portal-professional.css') ?>">
<link rel="stylesheet" href="../assets/css/staff-order-acceptance.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-order-acceptance.css') ?>">
<link rel="stylesheet" href="../assets/css/staff-production-tabs.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-production-tabs.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<link rel="stylesheet" href="../assets/css/production-sort.css?v=<?= filemtime(__DIR__ . '/../assets/css/production-sort.css') ?>">

<div class="production-page staff-production-page">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="board-toolbar">
  <div class="board-toolbar-left">
    <h1>Production Board</h1>
    <p>
      <?php if ($staffRole): ?>
        Review incoming orders. Accept a task to begin, or decline with a reason.
      <?php else: ?>
        Ask an admin to assign your production role before moving orders.
      <?php endif; ?>
    </p>
  </div>
  <div class="board-summary">
    <?php if ($staffRole): ?>
      <div class="board-summary-pill"><?= htmlspecialchars(staffRoleLabel($staffRole)) ?></div>
    <?php endif; ?>
    <div class="board-summary-pill" data-live="active"><?= htmlspecialchars($ctx['labels']['active']) ?></div>
    <div class="board-summary-pill danger" data-live="overdue" data-live-hide-zero <?= $ctx['totals']['overdue'] ? '' : 'hidden' ?>><?= htmlspecialchars($ctx['labels']['overdue']) ?></div>
  </div>
</div>

<?php if ($ctx['totals']['overdue'] > 0): ?>
<div class="alert alert-error" style="margin-bottom:18px">
  <strong><?= $ctx['totals']['overdue'] ?> order<?= $ctx['totals']['overdue'] > 1 ? 's are' : ' is' ?> overdue</strong> - please review and update orders assigned to your stage.
</div>
<?php endif; ?>

<div class="production-sort-toolbar" aria-label="Production sorting controls">
  <div>
    <strong>Sort orders</strong>
    <span>Arrange your waiting and accepted tasks.</span>
  </div>
  <label class="production-sort-control">
    <span class="sr-only">Sort production orders</span>
    <select data-production-sort aria-label="Sort production orders">
      <option value="queue">Queue order</option>
      <option value="overdue">Overdue first</option>
      <option value="priority">Priority: high first</option>
      <option value="due_asc">Schedule: earliest first</option>
      <option value="due_desc">Schedule: latest first</option>
      <option value="newest">Newest order first</option>
      <option value="oldest">Oldest order first</option>
      <option value="customer_asc">Customer: A–Z</option>
      <option value="qty_desc">Quantity: high to low</option>
    </select>
  </label>
</div>

<div id="productionLive"
     data-api="production_api.php"
     data-csrf="<?= htmlspecialchars($workflowCsrf) ?>"
     data-revision="<?= htmlspecialchars($revision) ?>"
     data-log-id="<?= (int)$lastLogId ?>"><?= renderProductionBoard($ctx) ?></div>

<div id="completedTodayWrap"><?= renderProductionCompletedToday($ctx) ?></div>
</div><!-- /production-page -->

<script src="../assets/js/realtime.js?v=<?= filemtime(__DIR__ . '/../assets/js/realtime.js') ?>"></script>
<script src="../assets/js/production-board.js?v=<?= filemtime(__DIR__ . '/../assets/js/production-board.js') ?>"></script>
<script src="../assets/js/production-sort.js?v=<?= filemtime(__DIR__ . '/../assets/js/production-sort.js') ?>"></script>

<?php include '../includes/staff_footer.php'; ?>
