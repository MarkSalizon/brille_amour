<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Production Tracker';
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

// No-JavaScript fallback: the same move the AJAX endpoint performs, same rules.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!validateWorkflowCsrfToken($_POST['workflow_csrf_token'] ?? null)) {
        $err = 'Invalid request token. Refresh the page and try again.';
    } else {
        $result = applyProductionBoardMove(
            $conn,
            (int)($_POST['order_id'] ?? 0),
            sanitize($_POST['new_status'] ?? ''),
            $uid,
            null,
            true,
            sanitize($_POST['notes'] ?? '')
        );

        if (!empty($result['ok'])) {
            $msg = $result['message'];
        } else {
            $err = $result['error'];
        }
    }
}

$ctx = buildProductionBoardContext($conn, null, true, $workflowCsrf);
$revision = productionBoardRevision($conn);
$lastLogId = productionBoardLastLogId($conn);

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/production-live.css?v=<?= filemtime(__DIR__ . '/../assets/css/production-live.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-production.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-production.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-production-polished.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-production-polished.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<link rel="stylesheet" href="../assets/css/production-sort.css?v=<?= filemtime(__DIR__ . '/../assets/css/production-sort.css') ?>">
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

.board-summary-pill,
.board-live-pill {
  white-space: nowrap;
}

.production-method-switcher {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}

.production-method-btn {
  appearance: none;
  border: 1px solid rgba(130, 76, 103, 0.18);
  background: #fff;
  color: #6c3d56;
  border-radius: 999px;
  padding: 9px 16px;
  font-size: 0.84rem;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
  transition: background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease;
}

.production-method-btn:hover {
  border-color: rgba(162, 60, 111, 0.32);
  box-shadow: 0 6px 18px rgba(162, 60, 111, 0.08);
}

.production-method-btn.is-active {
  background: linear-gradient(135deg, #b63f74 0%, #8c3158 100%);
  border-color: #8c3158;
  color: #fff;
  box-shadow: 0 10px 24px rgba(140, 49, 88, 0.24);
}

.delivery-progress-switcher {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}
.delivery-progress-switcher[hidden] { display: none !important; }
.delivery-progress-btn {
  appearance: none;
  border: 1px solid rgba(130, 76, 103, 0.16);
  background: #fff8fb;
  color: #744157;
  border-radius: 10px;
  padding: 8px 13px;
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
}
.delivery-progress-btn.is-active {
  background: #f3dbe6;
  border-color: rgba(140, 49, 88, 0.34);
  color: #7e294f;
}

.board-stage-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}

.board-stage-toolbar .board-stage-tabs {
  margin-bottom: 0;
}

.delivery-stage-tab[hidden] {
  display: none !important;
}

@media (max-width: 600px) {
  .production-method-switcher {
    gap: 8px;
  }

  .production-method-btn {
    width: 100%;
    justify-content: center;
    text-align: center;
  }
}

</style>

<div class="production-page admin-production-polished">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="board-toolbar">
    <div class="board-toolbar-left">
        <h1>Production Progress</h1>
        <p>Review pending payments, track order hand-offs, and finish ready orders.</p>
        <div class="production-method-switcher" role="group" aria-label="Filter production progress by fulfillment method">
            <button type="button" class="production-method-btn is-active" data-production-method="pickup" aria-pressed="true">Pickup</button>
            <button type="button" class="production-method-btn" data-production-method="delivery" aria-pressed="false">Delivery</button>
        </div>
    </div>
    <div class="board-summary">
        <span class="board-summary-pill danger" data-live="overdue" data-live-hide-zero <?= $ctx['totals']['overdue'] ? '' : 'hidden' ?>><?= htmlspecialchars($ctx['labels']['overdue']) ?></span>
    </div>
</div>

<section class="production-metrics" aria-label="Production status overview">
    <div class="production-metric is-primary">
        <div class="production-metric-top"><span class="production-metric-label">Pending orders</span></div>
        <strong class="production-metric-value" data-production-metric="pending"><?= (int)($ctx['counts']['pending'] ?? 0) ?></strong>
    </div>
    <div class="production-metric">
        <div class="production-metric-top"><span class="production-metric-label">Ready to complete</span></div>
        <strong class="production-metric-value" data-production-metric="ready"><?= (int)($ctx['counts']['ready'] ?? 0) ?></strong>
    </div>
    <div class="production-metric" data-metric="overdue">
        <div class="production-metric-top"><span class="production-metric-label">Overdue orders</span></div>
        <strong class="production-metric-value <?= $ctx['totals']['overdue'] > 0 ? 'has-overdue' : '' ?>" data-production-metric="overdue"><?= (int)$ctx['totals']['overdue'] ?></strong>
    </div>
    <div class="production-metric">
        <div class="production-metric-top"><span class="production-metric-label">Completed today</span></div>
        <strong class="production-metric-value" data-production-metric="completed"><?= count($ctx['completedToday'] ?? []) ?></strong>
    </div>
</section>

<section class="production-board-shell" aria-label="Production board">
    <div class="production-board-controls">
        <div class="production-board-heading"><h2>Order workflow</h2><p>Choose a stage or search the orders on this board.</p></div>
        <div class="production-board-actions">
            <label class="production-search" aria-label="Search current production orders">
                <input id="adminProductionSearch" type="search" placeholder="Search customer or order #" autocomplete="off">
            </label>
            <label class="production-sort-control">
                <span>Sort orders</span>
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
    </div>
    <div id="productionLive"
        data-api="production_api.php"
        data-csrf="<?= htmlspecialchars($workflowCsrf) ?>"
        data-revision="<?= htmlspecialchars($revision) ?>"
        data-log-id="<?= (int)$lastLogId ?>"><?= renderProductionBoard($ctx) ?></div>
</section>

<div id="completedTodayWrap"><?= renderProductionCompletedToday($ctx) ?></div>
</div><!-- /production-page -->

<script src="../assets/js/realtime.js?v=<?= filemtime(__DIR__ . '/../assets/js/realtime.js') ?>"></script>
<script src="../assets/js/production-board.js?v=<?= filemtime(__DIR__ . '/../assets/js/production-board.js') ?>"></script>
<script src="../assets/js/admin-production-polished.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-production-polished.js') ?>"></script>
<script src="../assets/js/production-sort.js?v=<?= filemtime(__DIR__ . '/../assets/js/production-sort.js') ?>"></script>

<?php include '../includes/footer.php'; ?>