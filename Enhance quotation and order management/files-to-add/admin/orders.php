<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: login.php');
  exit;
}

$pageTitle = 'Orders';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/messaging.php';
require_once '../includes/payments.php';
require_once '../includes/pickup_capacity.php';
require_once '../includes/task_assignments.php';
require_once '../includes/order_workflow.php';
require_once '../includes/staff_roles.php';
require_once '../includes/production_board.php';
require_once '../includes/innovation.php';
require_once '../includes/order_feedback.php';
require_once '../includes/security.php';

$msg = $err = '';
$portalCsrf = portalCsrfToken();
ensureCatalogSchema($conn);
ensureMessagingSchema($conn);
ensurePickupCapacitySchema($conn);
ensureOrderTaskAssignmentSchema($conn);
ensureInnovationSchema($conn);
ensureOrderFeedbackSchema($conn);
$catalogCategories = getActiveCategoriesList($conn);
$catalogProducts   = getActiveProductsList($conn);
$orderTypes = array_column($catalogProducts, 'name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!portalCsrfValid($_POST['portal_csrf_token'] ?? null)) {
    http_response_code(403);
    $err = 'Session expired. Refresh the page and try again.';
  } else {
  $action = $_POST['action'] ?? '';
  $uid = $_SESSION['user_id'];

  if ($action === 'add' || $action === 'edit') {
    $id         = (int)($_POST['id'] ?? 0);
    $productId  = (int)($_POST['product_id'] ?? 0) ?: null;
    $customer   = sanitize($_POST['customer_name'] ?? '');
    $contact    = sanitize($_POST['customer_contact'] ?? '');
    $source     = sanitize($_POST['source'] ?? 'Other');
    $type       = sanitize($_POST['order_type'] ?? '');
    $desc       = sanitize($_POST['description'] ?? '');
    $qty        = (int)($_POST['quantity'] ?? 1);
    $selectedProduct = $productId ? getProductById($conn, $productId) : null;
    if ($selectedProduct) {
      $type = $selectedProduct['name'];
    }
    $unit_price = $selectedProduct ? (float)$selectedProduct['unit_price'] : (float)($_POST['unit_price'] ?? 0);
    $total      = round($qty * $unit_price, 2);
    $deposit    = round($total * 0.25, 2);
    $balance    = max(0, $total - $deposit);
    $due        = $_POST['due_date'] ?: null;
    $pickupTime = $_POST['pickup_time'] ?: null;
    $status     = 'pending';
    $notes      = sanitize($_POST['notes'] ?? '');
    $assigned   = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $taskTitle = sanitize($_POST['task_title'] ?? defaultTaskTitle($type));
    if ($taskTitle === '') {
      $taskTitle = defaultTaskTitle($type);
    }
    $taskInstructions = trim((string)($_POST['task_instructions'] ?? ''));
    $taskDeadline = normalizeTaskDeadlineInput($_POST['task_deadline'] ?? '') ?: taskDeadlineFromPickup($due, $pickupTime);
    $taskStatus = normalizeOrderTaskStatus($_POST['task_status'] ?? 'not_started');
    $assignedBy = $assigned ? (int)$uid : null;
    $assignedAt = $assigned ? date('Y-m-d H:i:s') : null;
    $image = null;
    if ($action === 'edit' && $id) {
      $existing = $conn->query("SELECT image,deposit_paid,total_amount,status,assigned_to,task_title,task_instructions,task_deadline,task_status,assigned_by,assigned_at FROM orders WHERE id=$id")->fetch_assoc();
      $image = $existing['image'] ?? null;
      $status = (string)($existing['status'] ?? 'pending');
      $linkedPayment = paymentForOrder($conn, $id);
      if ($linkedPayment) {
        $deposit = (float)$existing['deposit_paid'];
        $balance = max(0, $total - $deposit);
        if (abs($total - (float)$existing['total_amount']) > 0.001) {
          $err = 'The total of a payment checkout cannot be changed after payment submission. Contact the customer to arrange changes.';
        }
      }
      if (!empty($existing)) {
        $oldAssigned = (int)($existing['assigned_to'] ?? 0);
        if ($oldAssigned === (int)($assigned ?? 0)) {
          $assignedBy = isset($existing['assigned_by']) ? (int)$existing['assigned_by'] : $assignedBy;
          $assignedAt = $existing['assigned_at'] ?? $assignedAt;
        }
      }
    }

    if (!empty($_FILES['order_image']['name'])) {
      $uploadDir = '../uploads/orders/';
      $filename = saveUploadedImage($_FILES['order_image'], $uploadDir, $err, 'order_');
      if ($filename !== null) {
        if ($action === 'edit' && !empty($image)) {
          @unlink($uploadDir . $image);
        }
        $image = $filename;
      }
    }

    if (false) {
      $err = 'This product requires a customer quotation and agreement. Use Pre-order requests to confirm it before payment.';
    }
    if ($productId && !$selectedProduct) {
      $err = 'Selected product was not found.';
    } elseif ($qty < 1) {
      $err = 'Quantity must be at least 1.';
    } elseif (!$customer) {
      $err = 'Customer name is required.';
    } elseif (!$err) {
      try {
        validatePickupDateAvailable($conn, $due, 1, $action === 'edit' ? $id : 0);
      } catch (RuntimeException $e) {
        $err = $e->getMessage();
      }
      if ($err === '' && $action === 'add') {
        $on = generateOrderNumber($conn);
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO orders (order_number,customer_name,customer_contact,source,order_type,product_id,description,quantity,unit_price,total_amount,deposit_paid,balance,due_date,pickup_time,status,notes,assigned_to,task_title,task_instructions,task_deadline,task_status,assigned_by,assigned_at,created_by,image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
          'sssssisiddddssssissssisis',
          $on,
          $customer,
          $contact,
          $source,
          $type,
          $productId,
          $desc,
          $qty,
          $unit_price,
          $total,
          $deposit,
          $balance,
          $due,
          $pickupTime,
          $status,
          $notes,
          $assigned,
          $taskTitle,
          $taskInstructions,
          $taskDeadline,
          $taskStatus,
          $assignedBy,
          $assignedAt,
          $uid,
          $image
        );
        if ($stmt->execute()) {
          $oid = $conn->insert_id;
          $sync = syncOrderProductInventory($conn, $oid, $productId, $status === 'cancelled' ? 0 : $qty, $uid, $on);
          if (!$sync['ok']) {
            $conn->rollback();
            $err = $sync['error'];
          } else {
            $conn->query("INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES ($oid,'','pending','Order created',$uid)");
            if ($assigned || $taskInstructions !== '') {
              logOrderTaskAssignment($conn, $oid, 'pending', (int)$uid, 'Task assigned: ' . $taskTitle);
            }
            $conn->commit();
            notifyNewOrder($conn, $oid, [
              'order_number'  => $on,
              'customer_name' => $customer,
              'order_type'    => $type,
              'quantity'      => $qty,
              'assigned_to'   => $assigned,
            ], (int)$uid);
            notifyOrderAssignment($conn, $oid, $on, $assigned, (int)$uid);
            notifyAssignedStaffTask($conn, $oid, $on, $assigned, (int)$uid, $taskTitle, $taskDeadline);
            $msg = 'Order #' . $on . ' created!';
          }
        } else {
          $conn->rollback();
          $err = 'Error creating order.';
        }
        $stmt->close();
      } elseif ($err === '') {
        $old = $conn->query("SELECT status, order_number, assigned_to, task_title, task_instructions, task_deadline, task_status, assigned_by, assigned_at FROM orders WHERE id=$id")->fetch_assoc();
        $conn->begin_transaction();
        if (!empty($linkedPayment)) {
          $locked = $conn->query("SELECT deposit_paid FROM orders WHERE id=$id FOR UPDATE")->fetch_assoc();
          $deposit = (float)$locked['deposit_paid'];
          $balance = max(0, $total - $deposit);
        }
        $oldAssigned = (int)($old['assigned_to'] ?? 0);
        $assignmentChanged = $oldAssigned !== (int)($assigned ?? 0);
        $taskChanged = ($old['task_title'] ?? '') !== $taskTitle
          || ($old['task_instructions'] ?? '') !== $taskInstructions
          || normalizeTaskDeadlineInput($old['task_deadline'] ?? '') !== $taskDeadline
          || normalizeOrderTaskStatus($old['task_status'] ?? 'not_started') !== $taskStatus;
        if (!$assignmentChanged) {
          $assignedBy = isset($old['assigned_by']) ? (int)$old['assigned_by'] : $assignedBy;
          $assignedAt = $old['assigned_at'] ?? $assignedAt;
        }
        $stmt = $conn->prepare("UPDATE orders SET customer_name=?,customer_contact=?,source=?,order_type=?,product_id=?,description=?,quantity=?,unit_price=?,total_amount=?,deposit_paid=?,balance=?,due_date=?,pickup_time=?,status=?,notes=?,assigned_to=?,task_title=?,task_instructions=?,task_deadline=?,task_status=?,assigned_by=?,assigned_at=?,image=? WHERE id=?");
        $stmt->bind_param(
          'ssssisiddddssssissssissi',
          $customer,
          $contact,
          $source,
          $type,
          $productId,
          $desc,
          $qty,
          $unit_price,
          $total,
          $deposit,
          $balance,
          $due,
          $pickupTime,
          $status,
          $notes,
          $assigned,
          $taskTitle,
          $taskInstructions,
          $taskDeadline,
          $taskStatus,
          $assignedBy,
          $assignedAt,
          $image,
          $id
        );
        if ($stmt->execute()) {
          $sync = syncOrderProductInventory($conn, $id, $productId, $status === 'cancelled' ? 0 : $qty, $uid, $old['order_number']);
          if (!$sync['ok']) {
            $conn->rollback();
            $err = $sync['error'];
          } else {
            if ($old['status'] !== $status)
              $conn->query("INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES ($id,'{$old['status']}','$status','Updated by admin',$uid)");
            if (!empty($assignmentChanged) || !empty($taskChanged)) {
              logOrderTaskAssignment($conn, $id, $status, (int)$uid, 'Task assignment updated: ' . $taskTitle);
            }
            $conn->commit();
            if ($old['status'] !== $status) {
              notifyOrderStageChange($conn, $id, (string)$old['order_number'], (string)$old['status'], $status, (int)$uid);
            }
            if ((int)($old['assigned_to'] ?? 0) !== (int)($assigned ?? 0)) {
              notifyOrderAssignment($conn, $id, (string)$old['order_number'], $assigned, (int)$uid);
            }
            if (!empty($assignmentChanged) || !empty($taskChanged)) {
              notifyAssignedStaffTask($conn, $id, (string)$old['order_number'], $assigned, (int)$uid, $taskTitle, $taskDeadline);
            }
            $msg = 'Order updated!';
          }
        } else {
          $conn->rollback();
          $err = 'Error updating order.';
        }
        $stmt->close();
      }
    }
  }

  if ($action === 'archive') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $conn->query("SELECT status FROM orders WHERE id={$id} LIMIT 1")->fetch_assoc();
    if (!$row) {
      $err = 'Order not found.';
    } elseif (!in_array((string)$row['status'], ['completed', 'cancelled'], true)) {
      $err = 'Only completed or cancelled orders can be archived.';
    } else {
      $previousStatus = (string)$row['status'];
      $conn->begin_transaction();
      try {
        $conn->query("UPDATE orders SET status='archived' WHERE id={$id}");
        $log = $conn->prepare("INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,'archived','Archived by admin',?)");
        $log->bind_param('isi', $id, $previousStatus, $uid);
        $log->execute();
        $log->close();
        $conn->commit();
        $msg = 'Order archived.';
      } catch (Throwable $e) {
        $conn->rollback();
        $err = 'Unable to archive the order.';
      }
    }
  }
  if ($action === 'delete') {
    $err = 'Permanent order deletion is disabled. Archive a completed or cancelled order instead.';
  }
  if ($action === 'status') {
    $result = applyOrderStatusChange(
      $conn,
      (int)($_POST['id'] ?? 0),
      sanitize($_POST['new_status'] ?? ''),
      (int)$uid,
      null,
      true,
      sanitize($_POST['log_notes'] ?? '')
    );
    if (!empty($result['ok'])) {
      $msg = htmlspecialchars($result['message']);
    } else {
      $err = $result['error'] ?? 'Unable to update status.';
    }
  }
  }
}

$statusFilter   = $_GET['status'] ?? '';
$searchQ        = $_GET['q'] ?? '';
$fulfillmentFilter = strtolower(trim((string)($_GET['fulfillment'] ?? 'all')));
if (!in_array($fulfillmentFilter, ['all', 'pickup', 'delivery'], true)) {
  $fulfillmentFilter = 'all';
}
$where = "WHERE o.status NOT IN ('archived')";
if ($statusFilter)   $where .= " AND o.status='"   . $conn->real_escape_string($statusFilter)   . "'";
if ($fulfillmentFilter !== 'all') $where .= " AND COALESCE(NULLIF(o.fulfillment_method,''),'pickup')='" . $conn->real_escape_string($fulfillmentFilter) . "'";
if ($searchQ)        $where .= " AND (o.order_number LIKE '%{$conn->real_escape_string($searchQ)}%' OR o.customer_name LIKE '%{$conn->real_escape_string($searchQ)}%')";

$orders    = $conn->query("
    SELECT
        o.*,
        u.full_name as assigned_name,
        p.name AS product_name,
        i.name AS inventory_name,
        i.quantity AS inventory_quantity,
        i.unit AS inventory_unit,
        c.email AS customer_email,
        dt.status AS delivery_status,
        dt.updated_at AS delivery_updated_at,
        ofb.rating AS feedback_rating,
        ofb.feedback AS customer_feedback
    FROM orders o
    LEFT JOIN users u ON o.assigned_to=u.id
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN products p ON p.id = o.product_id
    LEFT JOIN inventory i ON i.id = p.inventory_id
    LEFT JOIN delivery_tracking dt ON dt.order_id = o.id
    LEFT JOIN order_feedback ofb ON ofb.order_id = o.id
    $where
    ORDER BY o.created_at DESC, o.id DESC
");
// Counts across all active orders (independent of the selected list filter).
$orderStageCounts = array_fill_keys(['pending', 'in_production', 'quality_check', 'ready'], 0);
$orderStageResult = $conn->query("SELECT status, COUNT(*) AS total FROM orders WHERE status IN ('pending','in_production','quality_check','ready') GROUP BY status");
if ($orderStageResult) {
  while ($stage = $orderStageResult->fetch_assoc()) {
    $orderStageCounts[(string)$stage['status']] = (int)$stage['total'];
  }
  $orderStageResult->free();
}
$fulfillmentCounts = ['all' => 0, 'pickup' => 0, 'delivery' => 0];
$fulfillmentResult = $conn->query("SELECT COALESCE(NULLIF(fulfillment_method,''),'pickup') AS method, COUNT(*) AS total FROM orders WHERE status <> 'archived' GROUP BY method");
if ($fulfillmentResult) {
  while ($row = $fulfillmentResult->fetch_assoc()) {
    $method = strtolower((string)($row['method'] ?? 'pickup'));
    if (isset($fulfillmentCounts[$method])) $fulfillmentCounts[$method] = (int)$row['total'];
    $fulfillmentCounts['all'] += (int)$row['total'];
  }
  $fulfillmentResult->free();
}
$staffList = $conn->query("SELECT id, full_name, staff_role FROM users WHERE status='active' AND role='staff' ORDER BY full_name");

$viewOrder = null;
if (isset($_GET['view'])) {
  $vid = (int)$_GET['view'];
  $viewOrder = $conn->query("
        SELECT
            o.*,
            u.full_name as assigned_name,
            p.name AS product_name,
            i.name AS inventory_name,
            i.quantity AS inventory_quantity,
            i.unit AS inventory_unit,
            c.email AS customer_email,
            ofb.rating AS feedback_rating,
            ofb.feedback AS customer_feedback
        FROM orders o
        LEFT JOIN users u ON o.assigned_to=u.id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN products p ON p.id = o.product_id
        LEFT JOIN inventory i ON i.id = p.inventory_id
    LEFT JOIN delivery_tracking dt ON dt.order_id = o.id
    LEFT JOIN order_feedback ofb ON ofb.order_id = o.id
        WHERE o.id=$vid
    ")->fetch_assoc();
  $viewLogs  = $conn->query("SELECT pl.*, u.full_name FROM production_logs pl LEFT JOIN users u ON pl.updated_by=u.id WHERE pl.order_id=$vid ORDER BY pl.created_at DESC");
}
$editOrder = null;
if (isset($_GET['edit'])) {
  $editOrder = $conn->query("SELECT * FROM orders WHERE id=" . (int)$_GET['edit'])->fetch_assoc();
}

$bodyClass = 'admin-orders-page';
include '../includes/header.php';
?>
<style>
/* Admin Orders - compact click-to-view layout */
.page-body{overflow-x:hidden}
.admin-orders-list{padding:10px 14px 14px;display:grid;gap:8px}
.admin-order-row{display:flex;align-items:center;justify-content:space-between;gap:16px;min-width:0;padding:13px 15px;border:1px solid var(--border);border-radius:10px;background:var(--surface);cursor:pointer;transition:background .15s ease,border-color .15s ease,transform .15s ease;outline:none}
.admin-order-row:hover,.admin-order-row:focus-visible{background:var(--surface-2,var(--surface));border-color:var(--rose);transform:translateY(-1px)}
.admin-order-row.overdue-row{border-left:4px solid #DC2626}
.admin-order-row-main{display:flex;align-items:center;gap:16px;min-width:0}
.admin-order-row-id{font-family:'DM Mono',monospace;font-size:.78rem;font-weight:800;color:var(--rose);white-space:nowrap}
.admin-order-row-name{font-size:.9rem;font-weight:700;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-order-row-meta{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex:0 0 auto}
.order-open-hint{font-size:.72rem;color:var(--ink-muted);white-space:nowrap}
.order-open-hint span{font-size:1rem;vertical-align:-1px}
.admin-orders-empty{padding:35px 15px;text-align:center}
.orders-count{font-size:.78rem;color:var(--ink-muted)}
.orders-fulfillment-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;padding:5px;background:var(--surface);border:1px solid var(--border);border-radius:12px;width:max-content;max-width:100%}
.orders-fulfillment-tab{display:inline-flex;align-items:center;gap:7px;min-height:38px;padding:8px 13px;border-radius:9px;color:var(--ink-muted);font-size:.8rem;font-weight:700;text-decoration:none;transition:.15s ease}
.orders-fulfillment-tab:hover{background:var(--white);color:var(--ink)}
.orders-fulfillment-tab.is-active{background:var(--white);color:var(--rose);box-shadow:0 2px 8px rgba(35,20,28,.07)}
.orders-fulfillment-tab .tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:var(--surface-2,var(--surface));font-size:.68rem;color:var(--ink-muted)}
.orders-fulfillment-tab.is-active .tab-count{background:rgba(180,66,100,.1);color:var(--rose)}
.admin-order-method-badge{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:3px 8px;border-radius:999px;font-size:.67rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;white-space:nowrap}
.admin-order-method-badge.pickup{background:#f3eef9;color:#6d4c91}.admin-order-method-badge.delivery{background:#eaf5ef;color:#2e7250}
.admin-order-customer{display:flex;align-items:center;gap:8px;min-width:0}
@media(max-width:700px){.orders-fulfillment-tabs{width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.orders-fulfillment-tab{justify-content:center;padding:8px 6px}.orders-fulfillment-tab .tab-count{display:none}}
.order-details-backdrop{position:fixed;inset:0;background:rgba(20,15,18,.42);z-index:1200;display:none;align-items:center;justify-content:center;padding:20px}
.order-details-backdrop.open{display:flex}
.order-details-modal{width:min(760px,100%);max-height:min(88vh,760px);overflow:auto;background:var(--white);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.18)}
.order-details-header{position:sticky;top:0;z-index:2;display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:17px 20px;border-bottom:1px solid var(--border);background:var(--white)}
.order-details-title{font-size:1rem;font-weight:800}
.order-details-subtitle{margin-top:3px;font-size:.76rem;color:var(--ink-muted)}
.order-details-close{border:0;background:transparent;color:var(--ink-muted);font-size:1.3rem;line-height:1;cursor:pointer;padding:5px 8px;border-radius:7px}
.order-details-close:hover{background:var(--surface)}
.order-details-body{padding:18px 20px}
.order-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.order-detail-item{padding:11px 12px;background:var(--surface);border-radius:9px;min-width:0}
.order-detail-label{display:block;font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-muted);margin-bottom:4px}
.order-detail-value{font-size:.84rem;line-height:1.45;overflow-wrap:anywhere}
.order-detail-wide{grid-column:1/-1}
.order-detail-image{max-width:100%;max-height:240px;border-radius:10px;border:1px solid var(--border);object-fit:contain;margin-top:6px}
.order-details-actions{display:flex;flex-wrap:wrap;gap:8px;padding:14px 20px;border-top:1px solid var(--border);background:var(--surface)}
.order-details-actions .btn,.order-details-actions button{white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
.btn,.btn-icon,button{max-width:100%;box-sizing:border-box}
.btn{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:700px){.admin-order-row{padding:12px;gap:10px}.admin-order-row-main{gap:10px}.admin-order-row-meta .order-open-hint{display:none}.order-details-backdrop{padding:10px}.order-details-grid{grid-template-columns:1fr}.order-detail-wide{grid-column:auto}.order-details-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.order-details-actions .btn,.order-details-actions form,.order-details-actions form button{width:100%}}
@media(max-width:430px){.admin-order-row{display:block}.admin-order-row-meta{justify-content:space-between;margin-top:7px}.order-details-actions{grid-template-columns:1fr}}

/* Legacy selectors retained for the full order-detail page */
.page-body{overflow-x:hidden}
.orders-card{overflow:visible!important}
</style>
<?php
?>
<link rel="stylesheet" href="../assets/css/admin-orders.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-orders.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-orders-professional.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-orders-professional.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-order-view-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-order-view-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($viewOrder): ?>
  <?php
    $isOverdue = $viewOrder['due_date'] && $viewOrder['due_date'] < date('Y-m-d') && !in_array($viewOrder['status'], ['completed', 'cancelled']);
    $customerNotes = trim((string)($viewOrder['notes'] ?? ''));
    $customerDescription = trim((string)($viewOrder['description'] ?? ''));
    if (preg_match('/\ACheckout notes:\s*/i', $customerDescription)) {
      $customerDescription = trim((string)preg_replace('/\ACheckout notes:\s*/i', '', $customerDescription));
    }
    if ($customerDescription !== '' && strcasecmp($customerDescription, $customerNotes) === 0) $customerDescription = '';
    $customerReferenceName = basename(trim((string)($viewOrder['image'] ?? '')));
    $customerReferenceUrl = '';
    if ($customerReferenceName !== ''
        && preg_match('/\A[a-zA-Z0-9_.-]+\.(?:jpe?g|png|gif|webp|pdf|docx?|pptx?|xlsx?|txt)\z/i', $customerReferenceName)
        && is_file(__DIR__ . '/../uploads/orders/' . $customerReferenceName)) {
      $customerReferenceUrl = '../uploads/orders/' . rawurlencode($customerReferenceName);
    }
    $customerHasInstructions = $customerNotes !== '' || $customerDescription !== '' || $customerReferenceUrl !== '' || $customerReferenceName !== '';
  ?>
  <main id="adminProductionOrderView">
  <div class="back-bar">
    <a href="<?= ($_GET['from'] ?? '') === 'production' ? 'production.php' : 'orders.php' ?>" class="btn btn-sm btn-secondary">&larr; <?= ($_GET['from'] ?? '') === 'production' ? 'Production Board' : 'Orders' ?></a>
    <span style="font-family:'DM Mono',monospace;font-size:.88rem;color:var(--ink-muted)"><?= htmlspecialchars($viewOrder['order_number']) ?></span>
    <?= statusBadge($viewOrder['status']) ?>
    <?php if ($isOverdue): ?><span class="late-badge" style="font-size:.75rem">OVERDUE</span><?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:8px">
      <a href="?edit=<?= $viewOrder['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
      <form method="post" style="display:inline"><?= portalCsrfInput() ?>
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="id" value="<?= $viewOrder['id'] ?>">
        <button class="btn btn-sm btn-danger" onclick="return confirm('Archive this order?')">Archive</button>
      </form>
    </div>
  </div>

  <section class="admin-order-customer-brief" aria-labelledby="adminCustomerInstructionsTitle">
    <div class="admin-order-brief-head"><h2 id="adminCustomerInstructionsTitle">Customer instructions</h2><span>Notes &amp; reference</span></div>
    <div class="admin-order-brief-body">
      <?php if (!$customerHasInstructions): ?>
        <p class="admin-order-brief-empty">No customization notes or reference image were provided.</p>
      <?php else: ?>
        <?php if ($customerNotes !== ''): ?>
          <div class="admin-order-brief-text"><strong>Notes / customization</strong><p><?= nl2br(htmlspecialchars($customerNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p></div>
        <?php endif; ?>
        <?php if ($customerDescription !== ''): ?>
          <div class="admin-order-brief-text"><strong>Additional details</strong><p><?= nl2br(htmlspecialchars($customerDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p></div>
        <?php endif; ?>
        <?php if ($customerReferenceUrl !== '' && !orderReferenceIsImage($customerReferenceName)): ?>
          <div class="admin-order-reference"><strong>File to print</strong>
            <a href="<?= htmlspecialchars($customerReferenceUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" download>Download <?= htmlspecialchars(strtoupper(pathinfo($customerReferenceName, PATHINFO_EXTENSION)), ENT_QUOTES, 'UTF-8') ?> file &nearr;</a>
          </div>
        <?php elseif ($customerReferenceUrl !== ''): ?>
          <div class="admin-order-reference"><strong>Reference image</strong>
            <a href="<?= htmlspecialchars($customerReferenceUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
              <img src="<?= htmlspecialchars($customerReferenceUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Reference image for order <?= htmlspecialchars((string)$viewOrder['order_number'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              <span>Open full-size image &nearr;</span>
            </a>
          </div>
        <?php elseif ($customerReferenceName !== ''): ?>
          <p class="admin-order-brief-empty">The uploaded reference image is currently unavailable.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <div class="detail-grid">
    <div class="detail-card">
      <div class="detail-card-hdr">
        <span class="detail-card-title">Order Details</span>
        <span class="text-sm text-muted" title="<?= htmlspecialchars(appDateTime($viewOrder['created_at'])) ?>">
          Created <?= timeAgo($viewOrder['created_at']) ?> (<?= htmlspecialchars(appDateTime($viewOrder['created_at'])) ?>)
        </span>
      </div>
      <div style="padding:18px 20px">
        <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value"><strong><?= htmlspecialchars($viewOrder['customer_name']) ?></strong></span></div>
        <div class="detail-row"><span class="detail-label">Contact</span><span class="detail-value"><?= htmlspecialchars($viewOrder['customer_contact'] ?? '-') ?></span></div>
        <?php if ((int)($viewOrder['feedback_rating'] ?? 0) > 0): ?>
          <div class="detail-row"><span class="detail-label">Customer Rating</span><span class="detail-value"><strong style="color:#D39A1E;letter-spacing:1px"><?= htmlspecialchars(orderFeedbackStars((int)$viewOrder['feedback_rating'])) ?></strong> <?= (int)$viewOrder['feedback_rating'] ?>/5</span></div>
          <?php if (trim((string)($viewOrder['customer_feedback'] ?? '')) !== ''): ?><div class="detail-row"><span class="detail-label">Customer Feedback</span><span class="detail-value"><?= nl2br(htmlspecialchars((string)$viewOrder['customer_feedback'])) ?></span></div><?php endif; ?>
        <?php endif; ?>
        <div class="detail-row"><span class="detail-label">Source</span><span class="detail-value"><?= htmlspecialchars($viewOrder['source']) ?></span></div>
        <div class="detail-row"><span class="detail-label">Receive Method</span><span class="detail-value"><strong><?= ucfirst(htmlspecialchars((string)($viewOrder['fulfillment_method'] ?? 'pickup'))) ?></strong></span></div>
        <?php if (($viewOrder['fulfillment_method'] ?? 'pickup') === 'delivery'): ?>
          <div class="detail-row"><span class="detail-label">Delivery Address</span><span class="detail-value"><?= nl2br(htmlspecialchars((string)($viewOrder['delivery_address'] ?? '-'))) ?></span></div>
        <?php endif; ?>
        <div class="detail-row"><span class="detail-label">Order Type</span><span class="detail-value"><?= htmlspecialchars($viewOrder['order_type'] ?? '-') ?></span></div>
        <div class="detail-row"><span class="detail-label">Linked Product</span><span class="detail-value"><?= htmlspecialchars($viewOrder['product_name'] ?? ($viewOrder['order_type'] ?? 'Unlinked')) ?></span></div>
        <div class="detail-row"><span class="detail-label">Required materials</span><span class="detail-value">See <a href="inventory.php#materialRecipes">Inventory &rarr; Product material recipes</a> for paper, inks and other linked materials.</span></div>
        <div class="detail-row"><span class="detail-label">Quantity</span><span class="detail-value"><?= $viewOrder['quantity'] ?></span></div>
        <div class="detail-row">
          <span class="detail-label"><?= (($viewOrder['fulfillment_method'] ?? 'pickup') === 'delivery') ? 'Delivery' : 'Pickup Schedule' ?></span>
          <span class="detail-value" style="color:<?= $isOverdue ? '#DC2626' : 'inherit' ?>">
            <?=
            (($viewOrder['fulfillment_method'] ?? 'pickup') === 'delivery')
              ? 'Delivery arrangement'
              : ($viewOrder['due_date']
                ? date('M j, Y', strtotime($viewOrder['due_date'])) . (!empty($viewOrder['pickup_time']) ? ' at ' . date('g:i A', strtotime($viewOrder['pickup_time'])) : '')
                : '-')
            ?>
            <?= $isOverdue ? ' Warning' : '' ?>
          </span>
        </div>
        <div class="detail-row"><span class="detail-label">Assigned To</span><span class="detail-value"><?= htmlspecialchars($viewOrder['assigned_name'] ?? 'Unassigned') ?></span></div>
        <div class="detail-row"><span class="detail-label">Task Status</span><span class="detail-value"><?= orderTaskBadge($viewOrder['task_status'] ?? 'not_started') ?></span></div>
        <div class="detail-row"><span class="detail-label">Task Title</span><span class="detail-value"><?= htmlspecialchars($viewOrder['task_title'] ?? defaultTaskTitle($viewOrder['order_type'] ?? '')) ?></span></div>
        <div class="detail-row"><span class="detail-label">Task Deadline</span><span class="detail-value"><?= htmlspecialchars(formatTaskDeadline($viewOrder['task_deadline'] ?? null)) ?></span></div>
        <?php if (!empty($viewOrder['task_instructions'])): ?>
          <div class="detail-row" style="flex-direction:column;gap:5px">
            <span class="detail-label">Task Instructions</span>
            <span style="font-size:.86rem;line-height:1.6;color:var(--ink-muted)"><?= nl2br(htmlspecialchars($viewOrder['task_instructions'])) ?></span>
          </div>
        <?php endif; ?>
        <details class="admin-order-finance"><summary>Payment details <span><?= formatMoney($viewOrder['total_amount']) ?> total</span></summary>
        <div class="finance-box">
          <?php $orderPayment = paymentForOrder($conn, (int)$viewOrder['id']);
          if ($orderPayment): ?>
            <p><?= htmlspecialchars(paymentStatusLabel($orderPayment['status'])) ?> · <a href="payments.php#payment-<?= $orderPayment['id'] ?>">Review Payment</a></p>
          <?php endif; ?>
          <div class="finance-row"><span class="text-muted">Unit Price</span><span><?= formatMoney($viewOrder['unit_price']) ?></span></div>
          <div class="finance-row total"><span>Total</span><strong><?= formatMoney($viewOrder['total_amount']) ?></strong></div>
          <div class="finance-row"><span class="text-muted">Payment credited</span><span style="color:#2D8C56">-<?= formatMoney($viewOrder['deposit_paid']) ?></span></div>
          <div class="finance-row" style="color:var(--rose);font-weight:700"><span>Customer Balance (Auto)</span><strong><?= formatMoney($viewOrder['balance']) ?></strong></div>
        </div>
        </details>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="detail-card">
        <div class="detail-card-hdr"><span class="detail-card-title">Update Status</span></div>
        <div style="padding:16px 18px">
          <?php
            $adminStatusChoices = adminOrderAllowedStatusTargets((string)$viewOrder['status']);
            if (!empty($orderPayment) && ($orderPayment['status'] ?? '') !== 'verified') {
              $adminStatusChoices = array_values(array_filter(
                $adminStatusChoices,
                static fn(string $target): bool => $target !== 'in_production'
              ));
            }
            if (($viewOrder['fulfillment_method'] ?? 'pickup') === 'delivery' && (string)$viewOrder['status'] === 'ready') {
              $adminStatusChoices = array_values(array_filter(
                $adminStatusChoices,
                static fn(string $target): bool => $target !== 'completed'
              ));
            }
          ?>
          <?php if (($viewOrder['fulfillment_method'] ?? 'pickup') === 'delivery' && (string)$viewOrder['status'] === 'ready'): ?>
            <button type="button" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-bottom:10px" onclick="setDeliveryStatus(<?= (int)$viewOrder['id'] ?>,'out_for_delivery')">Move to Out for Delivery</button>
            <div class="text-sm text-muted" style="margin-bottom:12px">Delivery orders must pass through Out for Delivery before they can be marked completed.</div>
          <?php endif; ?>
          <?php if ($adminStatusChoices): ?>
          <form method="post"><?= portalCsrfInput() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= $viewOrder['id'] ?>">
            <div class="form-group"><label>Allowed Admin Action</label>
              <select name="new_status">
                <?php foreach ($adminStatusChoices as $s): ?>
                  <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s === 'in_production' ? 'Start Production' : ($s === 'completed' ? 'Mark Completed' : ucwords(str_replace('_', ' ', $s)))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Note (optional)</label><input type="text" name="log_notes" maxlength="500" placeholder="Reason or note for this action"></div>
            <button class="btn btn-primary btn-sm" style="width:100%;justify-content:center">Apply Action</button>
          </form>
          <?php else: ?>
            <div class="text-sm text-muted">This stage is controlled by the assigned production role. Admin can monitor it here but cannot skip the staff workflow.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="detail-card">
        <div class="detail-card-hdr">
          <span class="detail-card-title">Production Log</span>
          <span class="badge badge-default"><?= $viewLogs->num_rows ?></span>
        </div>
        <div style="padding:14px 18px">
          <?php $li = 0;
          while ($log = $viewLogs->fetch_assoc()): $li++; ?>
            <div class="log-item">
              <div>
                <div class="log-dot" style="background:<?= $li === 1 ? 'var(--rose)' : 'var(--ink-muted)' ?>"></div>
              </div>
              <div>
                <div class="log-status"><?= ucwords(str_replace('_', ' ', $log['status_to'])) ?></div>
                <?php if ($log['notes']): ?><div class="log-notes"><?= htmlspecialchars($log['notes']) ?></div><?php endif; ?>
                <div class="log-meta"><?= $log['full_name'] ? 'by ' . htmlspecialchars($log['full_name']) . ' - ' : '' ?><?= timeAgo($log['created_at']) ?></div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>
  </main>

<?php else: ?>

  <header class="page-toolbar orders-page-heading">
    <div>
      <h1>Orders</h1>
      <p>View customer orders, track progress and manage fulfillment.</p>
    </div>
    <button type="button" class="btn btn-primary orders-new-button" onclick="openModal('orderModal')">
      <span aria-hidden="true">+</span> New order
    </button>
  </header>

  <section class="orders-stage-summary" aria-label="Order status summary">
    <?php foreach ([
      ['pending', 'Pending', 'Awaiting processing'],
      ['in_production', 'In production', 'Currently being made'],
      ['quality_check', 'Quality check', 'Under review'],
      ['ready', 'Ready', 'Ready for fulfillment'],
    ] as [$stageKey, $stageTitle, $stageHint]): ?>
      <a href="orders.php?status=<?= urlencode($stageKey) ?>" class="orders-stage-card orders-stage-<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?><?= $statusFilter === $stageKey ? ' is-current' : '' ?>" aria-label="View <?= htmlspecialchars($stageTitle, ENT_QUOTES, 'UTF-8') ?> orders">
        <span class="orders-stage-label"><?= htmlspecialchars($stageTitle) ?></span>
        <strong class="orders-stage-total"><?= number_format($orderStageCounts[$stageKey]) ?></strong>
        <span class="orders-stage-hint"><?= htmlspecialchars($stageHint) ?></span>
      </a>
    <?php endforeach; ?>
  </section>

  <?php
    $fulfillmentBase = [];
    if ($statusFilter !== '') $fulfillmentBase['status'] = $statusFilter;
    if ($searchQ !== '') $fulfillmentBase['q'] = $searchQ;
    $fulfillmentUrl = static function(string $method) use ($fulfillmentBase): string {
      $params = $fulfillmentBase;
      if ($method !== 'all') $params['fulfillment'] = $method;
      return 'orders.php' . ($params ? '?' . http_build_query($params) : '');
    };
  ?>
  <nav class="orders-fulfillment-tabs" aria-label="Filter orders by pickup or delivery">
    <a class="orders-fulfillment-tab <?= $fulfillmentFilter === 'all' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($fulfillmentUrl('all')) ?>">All Orders <span class="tab-count"><?= number_format($fulfillmentCounts['all']) ?></span></a>
    <a class="orders-fulfillment-tab <?= $fulfillmentFilter === 'pickup' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($fulfillmentUrl('pickup')) ?>">Pickup <span class="tab-count"><?= number_format($fulfillmentCounts['pickup']) ?></span></a>
    <a class="orders-fulfillment-tab <?= $fulfillmentFilter === 'delivery' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($fulfillmentUrl('delivery')) ?>">Delivery <span class="tab-count"><?= number_format($fulfillmentCounts['delivery']) ?></span></a>
  </nav>

  <section class="filter-card orders-filter-card" aria-label="Find orders">
    <form method="get" class="orders-filter-form" role="search">
      <?php if ($fulfillmentFilter !== 'all'): ?><input type="hidden" name="fulfillment" value="<?= htmlspecialchars($fulfillmentFilter) ?>"><?php endif; ?>
      <label class="sw orders-search-field" for="ordersSearch">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.7"></circle><path d="m16 16 4.7 4.7"></path></svg>
        <input id="ordersSearch" type="search" name="q" placeholder="Search order number or customer" value="<?= htmlspecialchars((string)$searchQ, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
      </label>
      <label class="orders-filter-status" for="ordersStatus">
        <span>Status</span>
        <select id="ordersStatus" name="status">
          <option value="">All statuses</option>
          <?php foreach (['pending', 'in_production', 'quality_check', 'ready', 'completed', 'cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary orders-filter-submit">Search orders</button>
      <?php if ($searchQ || $statusFilter || $fulfillmentFilter !== 'all'): ?>
        <a href="orders.php" class="btn btn-ghost orders-filter-reset">Clear</a>
      <?php endif; ?>
    </form>
  </section>

  <section class="orders-card orders-results-card" aria-label="Customer orders">
    <div class="orders-results-head">
      <div>
        <h2>Order list</h2>
        <p class="orders-count"><?= number_format($orders->num_rows) ?> <?= ($searchQ || $statusFilter || $fulfillmentFilter !== 'all') ? 'matching ' : '' ?>order<?= $orders->num_rows === 1 ? '' : 's' ?> · Newest first</p>
      </div>
      <?php if ($searchQ || $statusFilter || $fulfillmentFilter !== 'all'): ?><a href="orders.php" class="orders-show-all">View all orders <span aria-hidden="true">↗</span></a><?php endif; ?>
    </div>
    <div class="admin-orders-list">
      <?php if ($orders->num_rows === 0): ?>
        <div class="admin-orders-empty">
          <span class="orders-empty-icon" aria-hidden="true">⌕</span>
          <strong>No orders found</strong>
          <p><?= ($searchQ || $statusFilter || $fulfillmentFilter !== 'all') ? 'Try another search or clear your filters.' : 'Orders will appear here once they are created.' ?></p>
          <?php if ($searchQ || $statusFilter || $fulfillmentFilter !== 'all'): ?><a href="orders.php" class="btn btn-secondary btn-sm">Clear filters</a><?php endif; ?>
        </div>
      <?php else: while ($o = $orders->fetch_assoc()):
        $isOverdue = $o['due_date'] && $o['due_date'] < date('Y-m-d') && !in_array($o['status'], ['completed', 'cancelled']);
        $pickup = '-';
        $fulfillment = (string)($o['fulfillment_method'] ?? 'pickup') === 'delivery' ? 'Delivery' : 'Pickup';
        if ($o['due_date']) {
          $pickup = date('M j, Y', strtotime($o['due_date']));
          if (!empty($o['pickup_time'])) $pickup .= ' · ' . date('g:i A', strtotime($o['pickup_time']));
        }
        $orderData = [
          'id' => (int)$o['id'],
          'order_number' => (string)$o['order_number'],
          'customer_name' => (string)$o['customer_name'],
          'customer_contact' => (string)($o['customer_contact'] ?? '-'),
          'customer_email' => (string)($o['customer_email'] ?? ''),
          'source' => (string)($o['source'] ?? '-'),
          'order_type' => (string)($o['order_type'] ?? '-'),
          'quantity' => (int)$o['quantity'],
          'total' => formatMoney($o['total_amount']),
          'deposit' => formatMoney($o['deposit_paid']),
          'balance' => formatMoney($o['balance']),
          'pickup' => $pickup,
          'fulfillment_method' => (string)($o['fulfillment_method'] ?? 'pickup'),
          'delivery_address' => (string)($o['delivery_address'] ?? ''),
          'delivery_fee' => formatMoney((float)($o['delivery_fee'] ?? 0)),
          'delivery_status' => strtolower((string)($o['delivery_status'] ?? (($o['status'] ?? '') === 'ready' && $fulfillment === 'Delivery' ? 'ready_for_dispatch' : 'pending'))),
          'assigned' => (string)($o['assigned_name'] ?? 'Unassigned'),
          'task_status' => strip_tags(orderTaskBadge($o['task_status'] ?? 'not_started')),
          'task_title' => (string)($o['task_title'] ?? defaultTaskTitle($o['order_type'] ?? '')),
          'task_deadline' => !empty($o['task_deadline']) ? formatTaskDeadline($o['task_deadline']) : '-',
          'description' => (string)($o['description'] ?? ''),
          'notes' => (string)($o['notes'] ?? ''),
          'status' => (string)$o['status'],
          'overdue' => (bool)$isOverdue,
          'feedback_rating' => (int)($o['feedback_rating'] ?? 0),
          'customer_feedback' => (string)($o['customer_feedback'] ?? ''),
          'image' => (!empty($o['image']) && orderReferenceIsImage((string)$o['image'])) ? '../uploads/orders/' . (string)$o['image'] : '',
          'file' => (!empty($o['image']) && !orderReferenceIsImage((string)$o['image'])) ? '../uploads/orders/' . (string)$o['image'] : ''
        ];
      ?>
        <article class="admin-order-row <?= $isOverdue ? 'overdue-row' : '' ?>" data-order-id="<?= (int)$o['id'] ?>" tabindex="0" role="button"
          aria-label="View order <?= htmlspecialchars((string)$o['order_number'], ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$o['customer_name'], ENT_QUOTES, 'UTF-8') ?>"
          data-order='<?= htmlspecialchars(json_encode($orderData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'
          onclick="openOrderDetails(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openOrderDetails(this)}">
          <div class="admin-order-row-main">
            <span class="admin-order-row-id">#<?= htmlspecialchars((string)$o['order_number']) ?></span>
            <span class="admin-order-customer"><strong class="admin-order-row-name" title="<?= htmlspecialchars((string)$o['customer_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$o['customer_name']) ?></strong><span class="admin-order-method-badge <?= $fulfillment === 'Delivery' ? 'delivery' : 'pickup' ?>"><?= htmlspecialchars($fulfillment) ?></span></span>
            <span class="admin-order-row-subline"><?= htmlspecialchars((string)$o['source']) ?> · <?= date('M j, Y', strtotime((string)$o['created_at'])) ?></span>
          </div>
          <div class="admin-order-product">
            <strong title="<?= htmlspecialchars((string)$o['order_type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$o['order_type']) ?></strong>
            <span>Qty <?= (int)$o['quantity'] ?></span>
          </div>
          <div class="admin-order-schedule">
            <span><?= htmlspecialchars($fulfillment) ?> · <?= htmlspecialchars($pickup) ?></span>
            <?php if ($isOverdue): ?><span class="admin-order-late">Overdue</span><?php endif; ?>
          </div>
          <strong class="admin-order-price"><?= formatMoney((float)$o['total_amount']) ?></strong>
          <div class="admin-order-row-meta">
            <?= statusBadge((string)$o['status']) ?>
            <span class="order-open-hint" aria-hidden="true">›</span>
          </div>
        </article>
      <?php endwhile; endif; ?>
    </div>
  </div>
<?php endif; ?>
<div class="order-details-backdrop" id="orderDetailsModal" aria-hidden="true">
  <div class="order-details-modal" role="dialog" aria-modal="true" aria-labelledby="orderDetailsTitle" onclick="event.stopPropagation()">
    <div class="order-details-header">
      <div>
        <div class="order-details-title" id="orderDetailsTitle">Order Details</div>
        <div class="order-details-subtitle" id="orderDetailsStatus"></div>
      </div>
      <button type="button" class="order-details-close" onclick="closeOrderDetails()" aria-label="Close">×</button>
    </div>
    <div class="order-details-body">
      <div class="order-details-grid" id="orderDetailsGrid"></div>
    </div>
    <div class="order-details-actions" id="orderDetailsActions"></div>
  </div>
</div>
<script>
function escOrder(v){
  return String(v ?? '-').replace(/[&<>'"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];});
}
let lastOrderFocus = null;
function openOrderDetails(row){
  lastOrderFocus = row;
  let data;
  try { data = JSON.parse(row.getAttribute('data-order')); } catch(e) { return; }
  const modal=document.getElementById('orderDetailsModal');
  const grid=document.getElementById('orderDetailsGrid');
  document.getElementById('orderDetailsTitle').textContent='#'+data.order_number+' — '+data.customer_name;
  const modalStatus=(data.fulfillment_method==='delivery' && data.delivery_status && data.status!=='cancelled') ? String(data.delivery_status).replaceAll('_',' ') : data.status.replaceAll('_',' ');
  document.getElementById('orderDetailsStatus').textContent=modalStatus+(data.overdue?' · Overdue':'');
  const items=[
    ['Customer',data.customer_name],...(data.customer_email ? [['Gmail / Email',data.customer_email]] : []),['Contact',data.customer_contact],['Source',data.source],
    ...(Number(data.feedback_rating||0) > 0 ? [['Customer Rating',String(data.feedback_rating)+'/5 '+('★'.repeat(Number(data.feedback_rating||0)))],...(data.customer_feedback ? [['Customer Feedback',data.customer_feedback]] : [])] : []),
    ['Receive Method',data.fulfillment_method === 'delivery' ? 'Delivery' : 'Pickup'],['Order Type',data.order_type],
    ...(data.fulfillment_method === 'delivery' && data.delivery_address ? [['Delivery Address',data.delivery_address]] : []),
    ...(data.fulfillment_method === 'delivery' ? [...(Number(String(data.delivery_fee || '').replace(/[^0-9.-]/g, '')) > 0 ? [['Previously Charged Delivery Fee',data.delivery_fee]] : []),['Delivery Progress',String(data.delivery_status||'ready_for_dispatch').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())]] : []),
    ['Quantity',data.quantity],['Total',data.total],['Payment Credited',data.deposit],['Balance',data.balance],
    [data.fulfillment_method === 'delivery' ? 'Delivery schedule' : 'Pickup schedule',data.pickup],['Assigned To',data.assigned],['Task Status',data.task_status],['Task Deadline',data.task_deadline],
    ['Task',data.task_title],['Description',data.description],['Notes',data.notes]
  ];
  grid.innerHTML=items.filter(x=>x[1]!=='' && x[1]!==null).map(function(x){
    const wide=(x[0]==='Description'||x[0]==='Notes'||x[0]==='Task');
    return '<div class="order-detail-item '+(wide?'order-detail-wide':'')+'"><span class="order-detail-label">'+escOrder(x[0])+'</span><div class="order-detail-value">'+escOrder(x[1]).replace(/\n/g,'<br>')+'</div></div>';
  }).join('')+(data.image?'<div class="order-detail-item order-detail-wide"><span class="order-detail-label">Order Image</span><img class="order-detail-image" src="'+escOrder(data.image)+'" alt="Order image"></div>':'')+(data.file?'<div class="order-detail-item order-detail-wide"><span class="order-detail-label">File to print</span><a href="'+escOrder(data.file)+'" target="_blank" rel="noopener" download>Download file</a></div>':'');
  let deliveryActions='';
  if(data.fulfillment_method==='delivery' && !['completed','cancelled'].includes(data.status)){
    const ds=String(data.delivery_status||'ready_for_dispatch');
    if(data.status==='ready' && !['out_for_delivery','delivered'].includes(ds)) deliveryActions+='<button type="button" class="btn btn-primary btn-sm" onclick="setDeliveryStatus('+data.id+',\'out_for_delivery\')">Mark Out for Delivery</button>';
    if(ds==='out_for_delivery') deliveryActions+='<button type="button" class="btn btn-primary btn-sm" onclick="setDeliveryStatus('+data.id+',\'delivered\')">Mark Delivered</button>';
  }
  document.getElementById('orderDetailsActions').innerHTML=
    '<a class="btn btn-secondary btn-sm" href="?view='+data.id+'">View Details</a>'+
    '<a class="btn btn-primary btn-sm" href="?edit='+data.id+'">Edit</a>'+deliveryActions+
    (['completed','cancelled'].includes(data.status) ? '<form method="post" style="display:contents"><?= portalCsrfInput() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="'+data.id+'"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Archive this order?\')">Archive</button></form>' : '');
  modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden';
  modal.querySelector('.order-details-close')?.focus();
}
async function setDeliveryStatus(orderId,status){
  const row=document.querySelector('.admin-order-row[data-order*="\"id\":'+String(orderId)]');
  const form=new FormData(); form.append('order_id',String(orderId)); form.append('status',status); form.append('portal_csrf_token',<?= json_encode($portalCsrf) ?>);
  try{
    const res=await fetch('delivery_status_api.php',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:form});
    const data=await res.json().catch(()=>({ok:false,error:'Invalid server response.'}));
    if(!res.ok||!data.ok) throw new Error(data.error||'Unable to update delivery status.');
    if(row){
      const current=JSON.parse(row.getAttribute('data-order')||'{}');
      current.delivery_status=data.delivery_status;
      if(data.order_status) current.status=data.order_status;
      row.setAttribute('data-order',JSON.stringify(current));
      const badge=row.querySelector('.admin-order-row-meta .badge');
      if(badge && data.order_status==='completed') badge.textContent='Completed';
      openOrderDetails(row);
    } else {
      window.location.reload();
    }
  }catch(error){ alert(error.message||'Unable to update delivery status.'); }
}

function closeOrderDetails(){
  const modal=document.getElementById('orderDetailsModal'); if(!modal)return;
  modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow='';
  lastOrderFocus?.focus();
}
document.getElementById('orderDetailsModal')?.addEventListener('click',function(e){if(e.target===this)closeOrderDetails();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeOrderDetails();});
</script>

<div class="modal-backdrop <?= $editOrder ? 'open' : '' ?>" id="orderModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><?= $editOrder ? 'Edit Order' : 'New Order' ?></span>
      <button class="close-btn" onclick="closeModal('orderModal')">x</button>
    </div>
    <form method="post" action="orders.php" enctype="multipart/form-data"><?= portalCsrfInput() ?>
      <div class="modal-body">
        <input type="hidden" name="action" value="<?= $editOrder ? 'edit' : 'add' ?>">
        <?php if ($editOrder): ?><input type="hidden" name="id" value="<?= $editOrder['id'] ?>"><?php endif; ?>

        <div class="modal-section">Customer Info</div>
        <div class="form-row form-row-2">
          <div class="form-group"><label>Customer Name <span style="color:var(--rose)">*</span></label><input type="text" name="customer_name" value="<?= htmlspecialchars($editOrder['customer_name'] ?? '') ?>" required></div>
          <div class="form-group"><label>Contact</label><input type="text" name="customer_contact" value="<?= htmlspecialchars($editOrder['customer_contact'] ?? '') ?>"></div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Source</label>
            <select name="source">
              <?php foreach (['Facebook', 'Instagram', 'Walk-in', 'Referral', 'Other'] as $s): ?>
                <option value="<?= $s ?>" <?= (($editOrder['source'] ?? 'Other') === $s) ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Category</label>
            <select name="category_id" id="category_id" onchange="filterProducts()">
              <option value="">All categories</option>
              <?php foreach ($catalogCategories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Order Type</label>
            <select name="product_id" id="product_id" onchange="applySelectedProduct()" required>
              <option value="">Select order type...</option>
              <?php foreach ($catalogProducts as $product): ?>
                <option value="<?= $product['id'] ?>" data-category-id="<?= (int)($product['category_id'] ?? 0) ?>" <?= (int)($editOrder['product_id'] ?? 0) === (int)$product['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($product['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Selected Price</label>
            <input type="text" id="selected_product_price" value="<?= !empty($editOrder['unit_price']) ? formatMoney((float)$editOrder['unit_price']) : '—' ?>" readonly>
          </div>
        </div>
        <div class="form-group">
          <label>Required materials</label>
          <input type="text" id="selected_product_stock" value="Managed per product in Inventory" readonly>
        </div>
        <div class="form-group">
          <label>Material allocation</label>
          <input type="text" id="selected_product_remaining" value="-" readonly>
          <div id="selected_product_stock_note" class="text-sm text-muted" style="margin-top:6px"></div>
        </div>
        <input type="hidden" name="order_type" id="order_type" value="<?= htmlspecialchars($editOrder['order_type'] ?? '') ?>">
        <div class="form-group"><label>Description</label><textarea name="description"><?= htmlspecialchars($editOrder['description'] ?? '') ?></textarea></div>

        <div class="modal-section">Pricing</div>
        <div class="form-row form-row-3">
          <div class="form-group"><label>Qty</label><input type="number" name="quantity" id="quantity" value="<?= $editOrder['quantity'] ?? 1 ?>" min="1" oninput="autoTotal()"></div>
          <div class="form-group"><label>Unit Price (PHP)</label><input type="number" step="0.01" name="unit_price" id="unit_price" value="<?= $editOrder['unit_price'] ?? 0 ?>" readonly></div>
          <div class="form-group"><label>Total (PHP)</label><input type="number" step="0.01" name="total_amount" id="total_amount" value="<?= $editOrder['total_amount'] ?? 0 ?>" readonly></div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group"><label>Payment Credited (Auto)</label><input type="number" step="0.01" name="deposit_paid" id="deposit_paid" value="<?= $editOrder['deposit_paid'] ?? 0 ?>" readonly></div>
          <div class="form-group"><label>Customer Balance (Auto)</label><input type="number" step="0.01" name="balance" id="balance" value="<?= $editOrder['balance'] ?? 0 ?>" readonly style="background:var(--surface);color:var(--rose);font-weight:700"></div>
        </div>
        <div class="text-sm text-muted" style="margin-top:-8px;margin-bottom:8px">Pickup customer checkouts require 25% downpayment; Delivery customer checkouts require full payment. The credited amount is preserved here; verify it under Payments.</div>

        <div class="modal-section">Scheduling & Assignment</div>
        <div class="form-row form-row-3">
          <div class="form-group"><label>Pick-up Date</label><input type="date" name="due_date" value="<?= $editOrder['due_date'] ?? '' ?>"></div>
          <div class="form-group">
            <label>Pick-up Time</label>
            <input type="time" name="pickup_time" value="<?= htmlspecialchars($editOrder['pickup_time'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Status</label>
            <input type="text" value="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $editOrder['status'] ?? 'pending'))) ?>" readonly>
            <input type="hidden" name="status" value="<?= htmlspecialchars($editOrder['status'] ?? 'pending') ?>">
            <div class="text-sm text-muted" style="margin-top:6px">Status changes follow Payment → Production → Quality Check → Ready → Completed.</div>
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Assign To</label>
            <select name="assigned_to">
              <option value="">- Unassigned -</option>
              <?php $staffList->data_seek(0);
              while ($s = $staffList->fetch_assoc()): ?>
                <option value="<?= $s['id'] ?>" <?= (($editOrder['assigned_to'] ?? '') == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?><?= !empty($s['staff_role']) ? ' — ' . htmlspecialchars(staffRoleLabel($s['staff_role'])) : '' ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group"><label>Notes</label><input type="text" name="notes" value="<?= htmlspecialchars($editOrder['notes'] ?? '') ?>"></div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group"><label>Task Title</label><input type="text" name="task_title" value="<?= htmlspecialchars($editOrder['task_title'] ?? defaultTaskTitle($editOrder['order_type'] ?? '')) ?>" placeholder="e.g. Prepare cake design"></div>
          <div class="form-group"><label>Task Deadline</label><input type="datetime-local" name="task_deadline" value="<?= htmlspecialchars(taskDeadlineInputValue($editOrder['task_deadline'] ?? '')) ?>"></div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Task Status</label>
            <select name="task_status">
              <?php foreach (orderTaskStatusOptions() as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>" <?= normalizeOrderTaskStatus($editOrder['task_status'] ?? 'not_started') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Task Instructions</label><input type="text" name="task_instructions" value="<?= htmlspecialchars($editOrder['task_instructions'] ?? '') ?>" placeholder="Specific work for the assigned staff"></div>
        </div>
        <div class="modal-section">Order Image</div>
        <div class="form-group">
          <label>Upload Image <span style="font-size:.75rem;color:var(--ink-muted)">(JPG, PNG, WEBP - max 5MB)</span></label>
          <?php if (!empty($editOrder['image'])): ?>
            <div style="margin-bottom:10px">
              <img src="../uploads/orders/<?= htmlspecialchars($editOrder['image']) ?>"
                style="max-width:100%;max-height:150px;border-radius:8px;border:1px solid var(--border);object-fit:cover">
              <div style="font-size:.75rem;color:var(--ink-muted);margin-top:4px">Current image - upload a new one to replace it</div>
            </div>
          <?php endif; ?>
          <input type="file" name="order_image" accept="image/*"
            style="width:100%;padding:10px;border:1.5px dashed var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:.85rem"
            onchange="previewImage(this)">
          <div id="imagePreview" style="margin-top:10px;display:none">
            <img id="previewImg" style="max-width:100%;max-height:150px;border-radius:8px;border:1px solid var(--border);object-fit:cover">
            <div style="font-size:.75rem;color:var(--ink-muted);margin-top:4px">Preview</div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('orderModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><?= $editOrder ? 'Update Order' : 'Create Order' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
  const productCatalog = <?= json_encode(getProductCatalogMap($conn), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const DOWNPAYMENT_RATE = 0.25;
  const checkoutPaidAmount = <?= $editOrder && paymentForOrder($conn, (int)$editOrder['id']) ? json_encode((float)$editOrder['deposit_paid']) : 'null' ?>;

  function calcBalance() {
    const t = parseFloat(document.getElementById('total_amount').value) || 0;
    const d = checkoutPaidAmount === null ? +(t * DOWNPAYMENT_RATE).toFixed(2) : checkoutPaidAmount;
    document.getElementById('deposit_paid').value = d.toFixed(2);
    document.getElementById('balance').value = Math.max(0, t - d).toFixed(2);
  }

  function autoTotal() {
    const q = parseInt(document.getElementById('quantity').value) || 1;
    const u = parseFloat(document.getElementById('unit_price').value) || 0;
    document.getElementById('total_amount').value = (q * u).toFixed(2);
    calcBalance();
    updateStockPreview();
  }

  function formatMoneyValue(value) {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP'
    }).format(value || 0);
  }

  function filterProducts() {
    const categoryId = document.getElementById('category_id').value;
    const productSelect = document.getElementById('product_id');
    Array.from(productSelect.options).forEach((option, index) => {
      if (index === 0) {
        option.hidden = false;
        return;
      }
      const optionCategory = option.dataset.categoryId || '';
      option.hidden = categoryId !== '' && optionCategory !== categoryId;
    });
    if (productSelect.selectedOptions[0]?.hidden) {
      productSelect.value = '';
    }
  }

  function applySelectedProduct() {
    const productId = document.getElementById('product_id').value;
    const product = productCatalog[productId] || null;
    const typeField = document.getElementById('order_type');
    const priceField = document.getElementById('unit_price');
    const displayField = document.getElementById('selected_product_price');
    const stockField = document.getElementById('selected_product_stock');
    const remainingField = document.getElementById('selected_product_remaining');
    const stockNote = document.getElementById('selected_product_stock_note');

    if (product) {
      typeField.value = product.name;
      priceField.value = Number(product.unit_price).toFixed(2);
      displayField.value = formatMoneyValue(Number(product.unit_price));
      if (product.category_id) {
        document.getElementById('category_id').value = String(product.category_id);
      }
      stockField.value = 'Paper, inks and blanks from the Inventory recipe';
      remainingField.value = 'Checked when saving';
      stockNote.textContent = 'Required raw materials are deducted automatically according to the product recipe.';
    } else {
      typeField.value = '';
      priceField.value = '0.00';
      displayField.value = '—';
      stockField.value = 'Choose a product';
      remainingField.value = '-';
      stockNote.textContent = '';
    }

    autoTotal();
  }

  function updateStockPreview() {
    const chosen = document.getElementById('product_id').value;
    document.getElementById('selected_product_remaining').value = chosen ? 'Checked when saving' : '-';
    document.getElementById('selected_product_stock_note').textContent = chosen ? 'Every required material is checked and deducted atomically on order submission.' : '';
  }

  function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const img = document.getElementById('previewImg');
    if (input.files && input.files[0]) {
      const reader = new FileReader();
      reader.onload = e => {
        img.src = e.target.result;
        preview.style.display = 'block';
      };
      reader.readAsDataURL(input.files[0]);
    } else {
      preview.style.display = 'none';
    }
  }

  function toggleZoom(img) {
    if (img.style.maxHeight === 'none') {
      img.style.maxHeight = '300px';
      img.style.cursor = 'zoom-in';
    } else {
      img.style.maxHeight = 'none';
      img.style.cursor = 'zoom-out';
    }
  }
  <?php if ($editOrder): ?>
    document.addEventListener('DOMContentLoaded', () => {
      openModal('orderModal');
      filterProducts();
      applySelectedProduct();
    });
  <?php else: ?>
    document.addEventListener('DOMContentLoaded', () => {
      filterProducts();
      applySelectedProduct();
    });
  <?php endif; ?>
</script>


<script data-admin-csrf-injector>
document.addEventListener('submit', function (event) {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || String(form.method || '').toLowerCase() !== 'post') return;
  if (form.querySelector('input[name="portal_csrf_token"]')) return;
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'portal_csrf_token';
  input.value = <?= json_encode($portalCsrf) ?>;
  form.appendChild(input);
}, true);
</script>

<?php include '../includes/footer.php'; ?>