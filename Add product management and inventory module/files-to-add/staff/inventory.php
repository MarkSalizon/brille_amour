<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
  header('Location: login.php'); exit;
}
$pageTitle = 'Inventory';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/security.php';
ensureCatalogSchema($conn);

// rest of your inventory code below...
$msg = $err = '';
$portalCsrf = portalCsrfToken();
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!portalCsrfValid($_POST['portal_csrf_token'] ?? null)) {
    http_response_code(403);
    $err = 'Session expired. Refresh the page and try again.';
  } else {
  $action = $_POST['action'] ?? '';

  if ($action === 'stock_out') {
    $id  = (int)$_POST['id'];
    $qty = (float)$_POST['qty'];
    $ref = sanitize($_POST['reference'] ?? '');
    $current = $conn->query("SELECT quantity, name FROM inventory WHERE id=$id")->fetch_assoc();
    if ($qty <= 0) { $err = 'Quantity must be greater than zero.'; }
    elseif ($qty > $current['quantity']) { $err = 'Insufficient stock. Only ' . $current['quantity'] . ' available.'; }
    else {
      try { $conn->begin_transaction(); applyInventoryDelta($conn,$id,$qty,$ref,'out','Manual stock out by staff',$uid); $conn->commit(); $msg='Material usage recorded.'; } catch(Throwable $e) { $conn->rollback(); $err=$e->getMessage(); }
    }
  }

  if ($action === 'stock_in') {
    $id  = (int)$_POST['id'];
    $qty = (float)$_POST['qty'];
    $ref = sanitize($_POST['reference'] ?? '');
    if ($qty <= 0) { $err = 'Quantity must be greater than zero.'; }
    else {
      $item = $conn->query("SELECT name FROM inventory WHERE id=$id")->fetch_assoc();
      try { $conn->begin_transaction(); applyInventoryDelta($conn,$id,$qty,$ref,'in','Manual restock by staff',$uid); $conn->commit(); $msg='Material stock added.'; } catch(Throwable $e) { $conn->rollback(); $err=$e->getMessage(); }
    }
  }
  }
}

$searchQ   = $_GET['q'] ?? '';
$materialFilter=trim((string)($_GET['mat_group']??''));
$stockFilter = $_GET['stock'] ?? ''; // 'low' filter

$catClause=$materialFilter!=='' ? "AND i.material_group='".$conn->real_escape_string($materialFilter)."'" : '';
$searchClause= $searchQ     ? "AND i.name LIKE '%{$conn->real_escape_string($searchQ)}%'" : '';
$stockClause = $stockFilter === 'low' ? "AND i.quantity <= i.reorder_level" : '';

$items      = $conn->query("SELECT i.*, COALESCE(NULLIF(i.material_group,''),'Other Materials') AS cat_name FROM inventory i WHERE i.status='active' $catClause $searchClause $stockClause ORDER BY i.material_group,i.name");
$categories = $conn->query("SELECT DISTINCT material_group AS name FROM inventory WHERE status='active' AND material_group<>'' ORDER BY material_group");
$catArr = []; while ($c = $categories->fetch_assoc()) $catArr[] = $c;

$transactions = $conn->query("SELECT it.*, i.name as item_name, u.full_name FROM inventory_transactions it LEFT JOIN inventory i ON it.inventory_id=i.id LEFT JOIN users u ON it.created_by=u.id ORDER BY it.created_at DESC LIMIT 15");

$lowCount  = $conn->query("SELECT COUNT(*) FROM inventory WHERE quantity<=reorder_level AND status='active'")->fetch_row()[0];
$totalItems = $conn->query("SELECT COUNT(*) FROM inventory WHERE status='active'")->fetch_row()[0];
$totalValue = $conn->query("SELECT SUM(quantity * unit_cost) FROM inventory WHERE status='active'")->fetch_row()[0];

include '../includes/staff_header.php';
?>
<script>document.body.classList.add('staff-inventory-page');</script>

<style>
/* ── Inventory styles ───────────────────────────── */
.inv-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
}
.inv-toolbar-left h1 { font-family: 'Playfair Display', serif; font-size: 1.5rem; color: var(--ink); }
.inv-toolbar-left p  { font-size: .83rem; color: var(--ink-muted); margin-top: 2px; }

/* Summary mini-stats */
.inv-summary {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-bottom: 18px;
}
.inv-stat {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 18px;
  box-shadow: var(--shadow-sm);
  display: flex; align-items: center; gap: 12px;
}
.inv-stat-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.inv-stat-value { font-size: 1.4rem; font-weight: 700; color: var(--ink); line-height: 1; }
.inv-stat-value.danger { color: #DC2626; }
.inv-stat-label { font-size: .72rem; color: var(--ink-muted); margin-top: 2px; text-transform: uppercase; letter-spacing: .5px; }

/* Filter bar */
.filter-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px 16px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  box-shadow: var(--shadow-sm);
}
.filter-card .search-wrap {
  display: flex; align-items: center; gap: 8px;
  background: var(--surface); border: 1.5px solid var(--border);
  border-radius: var(--radius-sm); padding: 7px 14px;
  flex: 1; min-width: 200px;
  transition: border-color .2s;
}
.filter-card .search-wrap:focus-within { border-color: var(--rose); }
.filter-card .search-wrap input {
  border: none; box-shadow: none; padding: 0;
  background: transparent; font-size: .88rem; width: 100%;
}
.filter-card .search-wrap input:focus { box-shadow: none; }
.filter-card select { width: auto; font-size: .83rem; padding: 7px 10px; }

/* Table card */
.inv-table-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  margin-bottom: 20px;
}
.inv-table-card table { font-size: .85rem; }
.item-name-cell strong { font-size: .88rem; }
.item-sku { font-size: .72rem; color: var(--ink-muted); font-family: 'DM Mono', monospace; }

/* Quantity cell */
.qty-cell { font-weight: 700; font-size: .95rem; }
.qty-cell.ok     { color: #2D8C56; }
.qty-cell.low    { color: #DC2626; }
.qty-cell.warn   { color: #D97706; }
.low-indicator {
  display: inline-flex; align-items: center; gap: 3px;
  background: #FEE2E2; color: #DC2626;
  font-size: .65rem; font-weight: 700;
  padding: 2px 6px; border-radius: 4px;
}

/* Progress bar for stock level */
.stock-bar-wrap { margin-top: 4px; }
.stock-bar {
  height: 4px; border-radius: 2px;
  background: var(--border); overflow: hidden; width: 80px;
}
.stock-fill { height: 100%; border-radius: 2px; transition: width .3s; }

/* Action buttons in table */
.action-btns { display: flex; gap: 6px; }

/* Transactions section */
.tx-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.tx-card-header {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  font-weight: 600; font-size: .93rem; color: var(--ink);
  display: flex; align-items: center; justify-content: space-between;
}
.tx-type-in  { background: #D1FAE5; color: #065F46; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:99px; }
.tx-type-out { background: #FEE2E2; color: #991B1B; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:99px; }
.tx-type-adj { background: #DBEAFE; color: #1E40AF; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:99px; }

/* Responsive */
@media (max-width: 900px) { .inv-summary { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .inv-summary { grid-template-columns: 1fr; } }
</style>
<link rel="stylesheet" href="../assets/css/staff-portal-professional.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-portal-professional.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="inv-toolbar">
  <div class="inv-toolbar-left">
  <h1>Inventory</h1>
  <p>Check stock levels and record material movement.</p>
  </div>
</div>

<!-- Summary Stats -->
<div class="inv-summary">
  <div class="inv-stat">
 
  <div>
    <div class="inv-stat-label">Total Items</div>
    <div class="inv-stat-value"><?= $totalItems ?></div>
  </div>
  </div>
  <div class="inv-stat">
 
  <div>
    <div class="inv-stat-label">Low Stock</div>
    <div class="inv-stat-value <?= $lowCount > 0 ? 'danger' : '' ?>"><?= $lowCount ?></div>
  </div>
  </div>
  <div class="inv-stat">
  
  <div>
    <div class="inv-stat-label">Stock Value</div>
    <div class="inv-stat-value" style="font-size:1.15rem">₱<?= number_format($totalValue ?? 0, 0) ?></div>
  </div>
  </div>
</div>

<?php if ($lowCount > 0): ?>
<div class="alert alert-warning" style="margin-bottom:16px">
  <strong><?= $lowCount ?> item<?= $lowCount > 1 ? 's are' : ' is' ?> at or below reorder level.</strong>
  <a href="?stock=low" style="margin-left:8px;font-weight:700;color:#92400E;text-decoration:underline">View low stock</a>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="filter-card">
  <form method="get" class="staff-inv-filter-form" role="search" aria-label="Filter inventory">
  <label class="search-wrap"><span>Search items</span><input type="search" name="q" placeholder="Item or material name" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>"></label>
  <label class="staff-inv-select"><span>Material type</span><select name="mat_group">
    <option value="">All materials</option>
    <?php foreach ($catArr as $c): ?>
    <option value="<?= htmlspecialchars($c['name'],ENT_QUOTES) ?>" <?= $materialFilter===$c['name']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select></label>
  <label class="staff-inv-select"><span>Stock level</span><select name="stock">
    <option value="">All Stock Levels</option>
    <option value="low" <?= $stockFilter==='low'?'selected':'' ?>>Low Stock Only</option>
  </select></label>
  <button type="submit" class="btn btn-primary btn-sm staff-inv-apply">Apply filters</button>
  <?php if ($searchQ || $materialFilter || $stockFilter): ?>
    <a href="inventory.php" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
  </form>
</div>

<!-- Inventory Table -->
<div class="inv-table-card">
  <div class="staff-inv-table-head"><strong>Stock items</strong><span><?= $items->num_rows ?> item<?= $items->num_rows !== 1 ? 's' : '' ?></span></div>
  <div class="table-wrap">
  <table class="staff-inv-items-table">
    <thead><tr><th>Item</th><th>Category</th><th>Available stock</th><th>Unit cost</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($items->num_rows === 0): ?>
    <tr class="staff-inv-empty"><td colspan="5">
      <div class="empty-state">
      <p>No items found</p>
      <p style="font-size:.8rem;margin-top:4px"><?= ($searchQ || $materialFilter || $stockFilter) ? 'Try adjusting your filters.' : '' ?></p>
      </div>
    </td></tr>
    <?php else: while ($item = $items->fetch_assoc()):
    $isLow    = $item['quantity'] <= $item['reorder_level'];
    $isWarn   = $item['quantity'] <= $item['reorder_level'] * 1.5 && !$isLow;
    $pct      = $item['reorder_level'] > 0 ? min(100, round(($item['quantity'] / ($item['reorder_level'] * 3)) * 100)) : 100;
    $barColor = $isLow ? '#DC2626' : ($isWarn ? '#D97706' : '#2D8C56');
    $qtyClass = $isLow ? 'low' : ($isWarn ? 'warn' : 'ok');
    ?>
    <tr>
      <td class="item-name-cell" data-label="Item">
      <strong><?= htmlspecialchars($item['name']) ?></strong>
      <?php if ($item['sku']): ?><br><span class="item-sku"><?= htmlspecialchars($item['sku']) ?></span><?php endif; ?>
      </td>
      <td data-label="Category"><span><?= htmlspecialchars($item['cat_name'] ?? '—') ?></span></td>
      <td data-label="Stock">
      <div class="qty-cell <?= $qtyClass ?>">
        <?= $item['quantity'] ?>
        <?php if ($isLow): ?><span class="low-indicator">LOW</span><?php endif; ?>
      </div>
      <div class="stock-bar-wrap"><div class="stock-bar"><div class="stock-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div></div>
      <small class="staff-inv-stock-detail"><?= htmlspecialchars($item['unit']) ?> · Reorder at <?= htmlspecialchars((string)$item['reorder_level']) ?></small>
      </td>
      <td data-label="Unit cost"><strong class="staff-inv-cost"><?= formatMoney($item['unit_cost']) ?></strong></td>
      <td data-label="Actions"><div class="action-btns">
        <button class="btn btn-sm btn-danger"
        onclick="openStockModal('out', <?= $item['id'] ?>, <?= htmlspecialchars(json_encode((string)$item['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= $item['quantity'] ?>)">
        Use
        </button>
        <button class="btn btn-sm btn-secondary"
        onclick="openStockModal('in', <?= $item['id'] ?>, <?= htmlspecialchars(json_encode((string)$item['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= $item['quantity'] ?>)">
        Add
        </button>
      </div>
      </td>
    </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Recent Transactions -->
<div class="tx-card">
  <div class="tx-card-header">
  <strong>Recent stock activity</strong>
  <span>Last 15 transactions</span>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr>
    <th>Item</th>
    <th>Type</th>
    <th>Quantity</th>
    <th>Reference</th>
    <th>By</th>
    <th>When</th>
    </tr></thead>
    <tbody>
    <?php $txCount = 0; while ($t = $transactions->fetch_assoc()): $txCount++; ?>
    <tr>
      <td data-label="Item"><strong><?= htmlspecialchars($t['item_name'] ?? '—') ?></strong></td>
      <td data-label="Type">
      <?php if ($t['type'] === 'in'): ?><span class="tx-type-in">In</span>
      <?php elseif ($t['type'] === 'out'): ?><span class="tx-type-out">Out</span>
      <?php else: ?><span class="tx-type-adj">Adj</span><?php endif; ?>
      </td>
      <td data-label="Quantity"><strong><?= $t['quantity'] ?></strong></td>
      <td data-label="Reference" class="text-sm"><?= htmlspecialchars($t['reference'] ?? '—') ?></td>
      <td data-label="By" class="text-sm"><?= htmlspecialchars($t['full_name'] ?? '—') ?></td>
      <td data-label="When" class="text-sm text-muted"><?= timeAgo($t['created_at']) ?></td>
    </tr>
    <?php endwhile;
    if ($txCount === 0): ?>
    <tr><td colspan="6"><div class="empty-state" style="padding:24px"><p>No transactions yet</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ══ Stock Out Modal ══ -->
<div class="modal-backdrop" id="stockOutModal">
  <div class="modal" style="max-width:420px">
  <div class="modal-header">
    <span class="modal-title">Record Usage</span>
    <button class="close-btn" onclick="closeModal('stockOutModal')">×</button>
  </div>
  <form method="post" action="inventory.php"><?= portalCsrfInput() ?>
    <div class="modal-body">
    <input type="hidden" name="action" value="stock_out">
    <input type="hidden" name="id" id="sout_id">
    <div style="background:var(--surface);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-size:.87rem;">
      <strong id="sout_name">Item</strong>
      <span style="color:var(--ink-muted);margin-left:8px">Available: <strong id="sout_avail" style="color:var(--ink)">—</strong></span>
    </div>
    <div class="form-group">
      <label>Quantity Used <span style="color:var(--rose)">*</span></label>
      <input type="number" name="qty" id="sout_qty" min="0.01" step="0.01" placeholder="Enter amount" required>
    </div>
    <div class="form-group">
      <label>Order Reference</label>
      <input type="text" name="reference" placeholder="Order # or reason">
    </div>
    </div>
    <div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal('stockOutModal')">Cancel</button>
    <button type="submit" class="btn btn-primary">Record Usage</button>
    </div>
  </form>
  </div>
</div>

<!-- ══ Stock In Modal ══ -->
<div class="modal-backdrop" id="stockInModal">
  <div class="modal" style="max-width:420px">
  <div class="modal-header">
    <span class="modal-title">Add Stock</span>
    <button class="close-btn" onclick="closeModal('stockInModal')">×</button>
  </div>
  <form method="post" action="inventory.php"><?= portalCsrfInput() ?>
    <div class="modal-body">
    <input type="hidden" name="action" value="stock_in">
    <input type="hidden" name="id" id="sin_id">
    <div style="background:var(--surface);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-size:.87rem;">
      <strong id="sin_name">Item</strong>
      <span style="color:var(--ink-muted);margin-left:8px">Current: <strong id="sin_avail" style="color:var(--ink)">—</strong></span>
    </div>
    <div class="form-group">
      <label>Quantity to Add <span style="color:var(--rose)">*</span></label>
      <input type="number" name="qty" min="0.01" step="0.01" placeholder="Enter amount" required>
    </div>
    <div class="form-group">
      <label>Reference</label>
      <input type="text" name="reference" placeholder="Delivery note, supplier…">
    </div>
    </div>
    <div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal('stockInModal')">Cancel</button>
    <button type="submit" class="btn btn-primary">Add Stock</button>
    </div>
  </form>
  </div>
</div>

<script>
function openStockModal(type, id, name, available) {
  if (type === 'out') {
  document.getElementById('sout_id').value   = id;
  document.getElementById('sout_name').textContent  = name;
  document.getElementById('sout_avail').textContent = available;
  openModal('stockOutModal');
  } else {
  document.getElementById('sin_id').value   = id;
  document.getElementById('sin_name').textContent  = name;
  document.getElementById('sin_avail').textContent = available;
  openModal('stockInModal');
  }
}
</script>

<?php include '../includes/staff_footer.php'; ?>
