<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: login.php'); exit;
}

/* ================================================
   FILE: admin/inventory.php
   ================================================ */
$pageTitle = 'Inventory';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/messaging.php';

$msg = $err = '';
ensureCatalogSchema($conn);
if (empty($_SESSION['material_recipe_csrf'])) $_SESSION['material_recipe_csrf']=bin2hex(random_bytes(24));
$recipeCsrf=(string)$_SESSION['material_recipe_csrf'];

// This stock adjustment can be applied only once. The final database
// is updated on the administrator's explicit click, not just by copying files.
$restockKey = 'low_stock_plus_100_20260927';
$restockReference = 'RESTOCK-LOW-100-20260927';
$conn->query('CREATE TABLE IF NOT EXISTS inventory_batch_restocks (batch_key VARCHAR(80) NOT NULL PRIMARY KEY, applied_by INT NOT NULL, item_count INT NOT NULL DEFAULT 0, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$restockDoneStmt = $conn->prepare('SELECT item_count FROM inventory_batch_restocks WHERE batch_key=? LIMIT 1');
$restockDoneStmt->bind_param('s', $restockKey);
$restockDoneStmt->execute();
$restockRecord = $restockDoneStmt->get_result()->fetch_assoc();
$restockDoneStmt->close();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $uid = $_SESSION['user_id'];

  if ($action === 'restock_low_100') {
    if (!hash_equals($recipeCsrf, (string)($_POST['recipe_csrf'] ?? ''))) {
      $err = 'Session expired. Reload Inventory and try again.';
    } elseif ($restockRecord !== null) {
      $err = 'This +100 restock has already been applied. No materials were added twice.';
    } else {
      try {
        $conn->begin_transaction();
        // PK on the batch key prevents duplicate submissions from adding stock twice.
        $batch=$conn->prepare('INSERT INTO inventory_batch_restocks(batch_key,applied_by,item_count) VALUES(?,?,0)');
        $batch->bind_param('si',$restockKey,$uid);
        if (!$batch->execute()) throw new RuntimeException('This restock was already recorded or could not be started.');
        $batch->close();
        $rows=$conn->query("SELECT id,name,unit FROM inventory WHERE status='active' AND (material_group<>'' OR EXISTS (SELECT 1 FROM product_materials pm WHERE pm.inventory_id=inventory.id)) AND quantity<=reorder_level ORDER BY id FOR UPDATE");
        if (!$rows) throw new RuntimeException('Could not read current low-stock materials.');
        $count=0;
        while ($material=$rows->fetch_assoc()) {
          applyInventoryDelta($conn, (int)$material['id'], 100.0, $restockReference, 'in', 'Administrator stock adjustment: +100 in the inventory item unit', (int)$uid);
          $count++;
        }
        $rows->free();
        if ($count===0) throw new RuntimeException('No active raw materials are currently low stock. Nothing was changed.');
        $update=$conn->prepare('UPDATE inventory_batch_restocks SET item_count=? WHERE batch_key=?');
        $update->bind_param('is',$count,$restockKey);
        if (!$update->execute()) throw new RuntimeException('Could not finalize the restock receipt.');
        $update->close();
        $conn->commit();
        $restockRecord=['item_count'=>$count];
        $msg='Restock completed: +100 in each item unit for '.$count.' low-stock raw material(s). Applied once only.';
      } catch (Throwable $e) {
        $conn->rollback();
        error_log('Low stock restock failed: '.$e->getMessage());
        $err='Restock was not applied. Check that the inventory tables are ready and try again. If already submitted, reload Inventory to confirm the result.';
      }
    }
  }

  if ($action === 'save_material_recipe') {
    $pid=(int)($_POST['recipe_product_id']??0);
    $ids=$_POST['material_ids']??[];
    $amounts=$_POST['material_amounts']??[];
    if (!hash_equals($recipeCsrf,(string)($_POST['recipe_csrf']??''))) $err='Session expired. Reload the page.';
    elseif ($pid<=0 || !is_array($ids) || !is_array($amounts)) $err='Choose a valid product and material quantities.';
    else {
      $product=$conn->prepare("SELECT id FROM products WHERE id=? AND status='active' LIMIT 1");
      $product->bind_param('i',$pid); $product->execute(); $found=(bool)$product->get_result()->fetch_assoc(); $product->close();
      if (!$found) $err='Selected product was not found.';
      else {
        $recipe=[];
        foreach ($ids as $index=>$inputId) {
          $iid=(int)$inputId;
          $raw=$amounts[$index]??'';
          if ($iid===0 && ($raw==='' || (float)$raw===0.0)) continue;
          if ($iid<1 || !is_numeric($raw) || (float)$raw<=0 || (float)$raw>100000 || isset($recipe[$iid])) { $err='Use unique material items and a quantity greater than zero for each row.'; break; }
          $recipe[$iid]=round((float)$raw,2);
          if ($recipe[$iid]<0.01) { $err='Minimum material quantity is 0.01.'; break; }
        }
        if (!$recipe && !$err) $err='A product needs at least one raw material.';
        if (!$err) {
          $allowed=$conn->query("SELECT id FROM inventory WHERE status='active'")->fetch_all(MYSQLI_ASSOC);
          $allowedIds=array_fill_keys(array_map(static fn($row)=>(int)$row['id'],$allowed),true);
          foreach ($recipe as $iid=>$amount) if (!isset($allowedIds[$iid])) { $err='Choose active materials only.'; break; }
        }
        if (!$err) {
          $conn->begin_transaction();
          try {
            $wipe=$conn->prepare('DELETE FROM product_materials WHERE product_id=?');
            $wipe->bind_param('i',$pid); if (!$wipe->execute()) throw new RuntimeException($wipe->error); $wipe->close();
            $ins=$conn->prepare('INSERT INTO product_materials(product_id,inventory_id,qty_per_unit) VALUES(?,?,?)');
            foreach ($recipe as $iid=>$amount) {
              $ins->bind_param('iid',$pid,$iid,$amount);
              if (!$ins->execute()) throw new RuntimeException($ins->error);
            }
            $ins->close(); $conn->commit(); $msg='Product material recipe saved.';
          } catch (Throwable $e) { $conn->rollback(); $err='Could not save the recipe: '.$e->getMessage(); }
        }
      }
    }
  }
  if ($action === 'add' || $action === 'edit') {
    $id     = (int)($_POST['id'] ?? 0);
    $catId  = null; // Raw materials are grouped independently of Shop product categories.
    $materialGroup = trim((string)($_POST['material_group']??'Other Materials'));
    if ($materialGroup==='') $materialGroup='Other Materials';
    $materialGroup=mb_substr($materialGroup,0,70);
    $name   = sanitize($_POST['name'] ?? '');
    $sku    = sanitize($_POST['sku'] ?? '');
    $desc   = sanitize($_POST['description'] ?? '');
    $qty    = max(0,(float)($_POST['quantity'] ?? 0));
    $unit   = sanitize($_POST['unit'] ?? 'pcs');
    $reorder= (int)($_POST['reorder_level'] ?? 10);
    $cost   = (float)($_POST['unit_cost'] ?? 0);
    if (!$name) { $err = 'Item name is required.'; }
    else {
      if ($action === 'add') {
        $stmt = $conn->prepare("INSERT INTO inventory (category_id,material_group,name,sku,description,quantity,unit,reorder_level,unit_cost,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('issssdsidi', $catId,$materialGroup,$name,$sku,$desc,$qty,$unit,$reorder,$cost,$uid);
        if ($stmt->execute()) { $iid=$conn->insert_id; if($qty>0) $conn->query("INSERT INTO inventory_transactions (inventory_id,type,quantity,reference,notes,created_by) VALUES ($iid,'in',$qty,'Initial','Opening stock',$uid)"); $msg = 'Item added!'; }
        else $err = 'Error: '.$conn->error;
      } else {
        $stmt = $conn->prepare("UPDATE inventory SET category_id=?,material_group=?,name=?,sku=?,description=?,unit=?,reorder_level=?,unit_cost=? WHERE id=?");
        $stmt->bind_param('isssssidi',$catId,$materialGroup,$name,$sku,$desc,$unit,$reorder,$cost,$id);
        $stmt->execute() ? $msg = 'Item updated!' : $err = 'Error updating.';
      }
    }
  }
  if ($action === 'stock_in') {
    $id=$_POST['id']|0; $qty=(float)$_POST['qty']; $ref=sanitize($_POST['reference']??'Manual stock in');
    try {
      $conn->begin_transaction();
      applyInventoryDelta($conn, $id, $qty, $ref, 'in', 'Manual stock in', $uid);
      $conn->commit();
      notifyInventoryLevel($conn, $id);
      $msg='Stock added!';
    } catch (Throwable $e) {
      $conn->rollback(); $err = $e->getMessage();
    }
  }
  if ($action === 'stock_out') {
    $id=$_POST['id']|0; $qty=(float)$_POST['qty']; $ref=sanitize($_POST['reference']??'Manual stock out');
    try {
      $conn->begin_transaction();
      applyInventoryDelta($conn, $id, $qty, $ref, 'out', 'Manual stock out', $uid);
      $conn->commit();
      notifyInventoryLevel($conn, $id);
      $msg='Stock deducted!';
    } catch (Throwable $e) {
      $conn->rollback(); $err = $e->getMessage();
    }
  }
  if ($action === 'archive') { $conn->query("UPDATE inventory SET status='archived' WHERE id=".(int)$_POST['id']); $msg='Item archived.'; }
  if ($action === 'restore') { $conn->query("UPDATE inventory SET status='active' WHERE id=".(int)$_POST['id']); $msg='Item restored.'; }
}

$showArchived = isset($_GET['archived']);
$searchQ   = trim((string)($_GET['q'] ?? ''));
$catFilter = (int)($_GET['cat'] ?? 0);
$lowFilter = (string)($_GET['low'] ?? '') === '1';
$statusClause = $showArchived ? "i.status='archived'" : "i.status='active'";
$materialFilter=trim((string)($_GET['mat_group']??''));
$catClause=$materialFilter!=='' ? "AND i.material_group='".$conn->real_escape_string($materialFilter)."'" : '';
$searchClause = $searchQ ? "AND (i.name LIKE '%{$conn->real_escape_string($searchQ)}%' OR i.sku LIKE '%{$conn->real_escape_string($searchQ)}%')" : '';
$lowClause    = $lowFilter ? "AND i.quantity<=i.reorder_level" : '';
$items = $conn->query("
  SELECT
    i.*,
    c.name as cat_name,
    ps.linked_products_count,
    ps.linked_product_names
  FROM inventory i
  LEFT JOIN categories c ON i.category_id=c.id
  LEFT JOIN (
    SELECT
      pm.inventory_id,
      COUNT(*) AS linked_products_count,
      GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS linked_product_names
    FROM product_materials pm
    JOIN products p ON p.id=pm.product_id AND p.status='active'
    GROUP BY pm.inventory_id
  ) ps ON ps.inventory_id = i.id
  WHERE $statusClause $catClause $searchClause $lowClause
  ORDER BY i.name
");
$materialGroups=[];
$groupRows=$conn->query("SELECT DISTINCT material_group FROM inventory WHERE status='active' AND material_group<>'' ORDER BY material_group");
if ($groupRows) while ($group=$groupRows->fetch_assoc()) $materialGroups[]=(string)$group['material_group'];
$activeMaterialRows=$conn->query("SELECT id,name,unit,quantity,material_group FROM inventory WHERE status='active' ORDER BY material_group,name")->fetch_all(MYSQLI_ASSOC);
$activeRecipeProducts=$conn->query("SELECT id,name FROM products WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$recipeMap=[];
$recipeRows=$conn->query('SELECT pm.product_id,pm.inventory_id,pm.qty_per_unit FROM product_materials pm JOIN products p ON p.id=pm.product_id WHERE p.status=\'active\'');
if ($recipeRows) while ($link=$recipeRows->fetch_assoc()) $recipeMap[(int)$link['product_id']][]=['inventory_id'=>(int)$link['inventory_id'],'qty'=>(float)$link['qty_per_unit']];

$lowCount   = $conn->query("SELECT COUNT(*) FROM inventory WHERE quantity<=reorder_level AND status='active'")->fetch_row()[0];
$totalItems = $conn->query("SELECT COUNT(*) FROM inventory WHERE status='active'")->fetch_row()[0];
$totalValue = $conn->query("SELECT SUM(quantity*unit_cost) FROM inventory WHERE status='active'")->fetch_row()[0] ?? 0;

$editItem = null;
if (isset($_GET['edit'])) $editItem = $conn->query("SELECT * FROM inventory WHERE id=".(int)$_GET['edit'])->fetch_assoc();

$bodyClass = 'admin-inventory-page';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-inventory.css?v=<?= filemtime(__DIR__ . "/../assets/css/admin-inventory.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<?php if ($msg): ?><div class="alert alert-success"> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="inv-toolbar">
  <div class="inv-page-heading"><h1>Inventory</h1></div>
  <div class="flex gap-2 inv-toolbar-actions">
    <a href="inventory.php<?= $showArchived ? '' : '?archived=1' ?>" class="btn btn-secondary btn-sm"><?= $showArchived ? 'Active items' : 'Archived items' ?></a>
    <?php if ($editItem): ?><a href="inventory.php" class="btn btn-primary">Add item</a><?php else: ?><button type="button" class="btn btn-primary" onclick="openModal('itemModal')">Add item</button><?php endif; ?>
  </div>
</div>

<?php if (!$showArchived): ?>
<div class="inv-summary" aria-label="Inventory overview">
  <div class="inv-stat"><span class="inv-stat-label">Active items</span><strong class="inv-stat-value"><?= number_format((int)$totalItems) ?></strong></div>
  <div class="inv-stat"><span class="inv-stat-label">Low stock</span><strong class="inv-stat-value <?= $lowCount>0?'danger':'' ?>"><?= number_format((int)$lowCount) ?></strong></div>
  <div class="inv-stat"><span class="inv-stat-label">Inventory value</span><strong class="inv-stat-value inv-money-value"><?= formatMoney((float)$totalValue) ?></strong></div>
</div>
<?php if ($lowCount>0): ?><div class="inv-low-stock-notice"><span><strong><?= (int)$lowCount ?> item<?= $lowCount>1?'s need':' needs' ?> attention.</strong> Check items at or below their reorder level.</span><a href="inventory.php?low=1">View low stock</a></div><?php endif; ?>
<?php if ($restockRecord === null && $lowCount>0): ?>
<div class="inv-low-stock-notice" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
  <span><strong>Stock adjustment: +100 per low-stock material.</strong> The amount uses each item's unit (100 sheets, 100 ml, 100 pcs, etc.). Only active raw materials at or below their reorder level will be updated. This action can be completed once.</span>
  <form method="post" action="inventory.php" onsubmit="return confirm('Add 100 in each item unit to EVERY currently low-stock active material? This will be recorded once in the inventory ledger.');">
    <input type="hidden" name="action" value="restock_low_100">
    <input type="hidden" name="recipe_csrf" value="<?= htmlspecialchars($recipeCsrf,ENT_QUOTES,'UTF-8') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Apply +100 restock once</button>
  </form>
</div>
<?php elseif ($restockRecord !== null): ?>
<div class="inv-low-stock-notice"><span>+100 stock adjustment was applied once to <?= (int)$restockRecord['item_count'] ?> low-stock raw material(s). Further receipts should use Stock In per item.</span></div>
<?php endif; ?>
<?php endif; ?>

<section class="inv-filter-section" aria-label="Search and filter inventory">
  <div class="inv-section-title"><h2>Items</h2></div>
  <form class="filter-card" method="get" id="inventoryFilters">
    <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <label class="inv-filter-field inv-search-field"><span>Search</span><input type="search" name="q" placeholder="Search item or SKU" value="<?= htmlspecialchars($searchQ) ?>"></label>
    <label class="inv-filter-field"><span>Material type</span><select name="mat_group"><option value="">All materials</option><?php foreach($materialGroups as $group): ?><option value="<?= htmlspecialchars($group,ENT_QUOTES) ?>" <?= $materialFilter===$group?'selected':'' ?>><?= htmlspecialchars($group) ?></option><?php endforeach; ?></select></label>
    <?php if (!$showArchived): ?><label class="inv-filter-field"><span>Stock status</span><select name="low"><option value="">All stock</option><option value="1" <?= $lowFilter?'selected':'' ?>>Low stock only</option></select></label><?php endif; ?>
  </form>
</section>

<div class="inv-table-card">
  <div class="inv-list-header"><strong><?= $showArchived ? 'Archived items' : 'Active items' ?></strong><span><?= number_format($items->num_rows) ?> item<?= $items->num_rows!==1?'s':'' ?></span></div>
  <div class="table-wrap"><table class="inventory-desktop-table">
    <thead><tr><th>Item</th><th>Available stock</th><th>Reorder level</th><th>Unit cost</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($items->num_rows === 0): ?>
      <tr class="inv-empty-row"><td colspan="5"><div class="empty-state"><strong>No items found</strong><p>Try another search or filter.</p></div></td></tr>
    <?php else: while ($item = $items->fetch_assoc()):
      $isLow = (float)$item['quantity'] <= (float)$item['reorder_level'];
      $isWarn = !$isLow && (float)$item['quantity'] <= (float)$item['reorder_level'] * 1.5;
      $stockState = $isLow ? 'low' : ($isWarn ? 'warn' : 'ok');
      $stockLabel = $isLow ? 'Low stock' : ($isWarn ? 'Reorder soon' : 'In stock');
      $detail = [
        'id' => (int)$item['id'],
        'name' => (string)$item['name'],
        'sku' => (string)($item['sku'] ?? ''),
        'category' => (string)($item['material_group'] ?: 'Other Materials'),
        'quantity' => (float)$item['quantity'],
        'unit' => (string)$item['unit'],
        'reorder' => (float)$item['reorder_level'],
        'cost' => formatMoney((float)$item['unit_cost']),
        'linked_count' => (int)($item['linked_products_count'] ?? 0),
        'linked_names' => (string)($item['linked_product_names'] ?? ''),
        'status' => (string)$item['status'],
        'stock_label' => $stockLabel,
      ];
    ?>
      <tr>
        <td data-label="Item">
          <div class="inventory-item-copy">
            <strong><?= htmlspecialchars((string)$item['name']) ?></strong>
            <span class="inv-sku"><?= htmlspecialchars((string)($item['material_group'] ?: 'Other Materials')) ?> · SKU: <?= htmlspecialchars((string)($item['sku'] ?: '—')) ?></span>
          </div>
        </td>
        <td data-label="Available stock">
          <div class="inv-stock-brief"><strong class="qty-cell <?= $stockState ?>"><?= htmlspecialchars((string)$item['quantity']) ?> <span class="inv-unit"><?= htmlspecialchars((string)$item['unit']) ?></span></strong><span class="inv-stock-status is-<?= $stockState ?>"><?= htmlspecialchars($stockLabel) ?></span></div>
        </td>
        <td data-label="Reorder at" class="inv-reorder-cell"><?= htmlspecialchars((string)$item['reorder_level']) ?> <span class="inv-unit"><?= htmlspecialchars((string)$item['unit']) ?></span></td>
        <td data-label="Unit cost" class="inv-cost-cell"><?= formatMoney((float)$item['unit_cost']) ?></td>
        <td data-label="Actions" class="inventory-actions-cell">
          <div class="inventory-actions">
            <button type="button" class="btn-icon inv-view-btn" data-inventory-item="<?= htmlspecialchars(json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>" onclick="openInventoryView(this)">View</button>
            <?php if ($item['status'] === 'active'): ?>
            <button type="button" class="btn-icon inv-stock-in-btn" aria-label="Add stock for <?= htmlspecialchars((string)$item['name'], ENT_QUOTES) ?>" onclick="openStockModal('in',<?= (int)$item['id'] ?>,<?= htmlspecialchars(json_encode($item['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES) ?>,<?= (float)$item['quantity'] ?>)">+ Stock</button>
            <?php else: ?>
            <form method="post"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button type="submit" class="btn-icon">Restore</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table></div>
</div>

<section id="materialRecipes" class="inv-table-card" style="padding:20px;margin-bottom:22px">
  <h2 style="font-size:1.15rem;margin:0 0 6px">Product material recipes</h2>
  <p style="font-size:.83rem;margin:0 0 15px">Set the quantity of each material used for <strong>one unit</strong> of a product. Ordering multiple units deducts the corresponding amount of every required item. The initial templates are estimates: adjust for actual print size, ink use, design and wastage before taking orders.</p>
  <form method="post" action="inventory.php#materialRecipes" id="materialRecipeForm">
    <input type="hidden" name="action" value="save_material_recipe"><input type="hidden" name="recipe_csrf" value="<?= htmlspecialchars($recipeCsrf,ENT_QUOTES) ?>">
    <label for="materialRecipeProduct" style="display:block;margin-bottom:6px;font-weight:700">Product</label>
    <select id="materialRecipeProduct" name="recipe_product_id" required style="width:100%;max-width:500px;margin-bottom:12px;padding:10px">
      <option value="">Choose a product...</option>
      <?php foreach($activeRecipeProducts as $prod): ?><option value="<?= (int)$prod['id'] ?>"><?= htmlspecialchars($prod['name']) ?></option><?php endforeach; ?>
    </select>
    <div id="materialRecipeRows" style="display:grid;gap:9px;margin-bottom:12px"></div>
    <div style="display:flex;gap:9px;flex-wrap:wrap"><button class="btn btn-secondary" type="button" id="materialRecipeAdd">+ Add material</button><button class="btn btn-primary" type="submit">Save recipe</button></div>
  </form>
</section>
<script>
(() => {
 const recipes=<?= json_encode($recipeMap,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>;
 const materials=<?= json_encode($activeMaterialRows,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>;
 const choose=document.getElementById('materialRecipeProduct'), rows=document.getElementById('materialRecipeRows');
 function addRow(id='',qty=1){
   const line=document.createElement('div'); line.style.cssText='display:flex;flex-wrap:wrap;gap:9px;align-items:center';
   const select=document.createElement('select');select.name='material_ids[]';select.required=true;select.style.cssText='min-width:210px;flex:2;padding:9px';
   const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose a material';select.append(placeholder);
   materials.forEach(mat=>{const opt=document.createElement('option');opt.value=mat.id;opt.textContent=mat.name+' · '+mat.quantity+' '+mat.unit;opt.selected=String(id)===String(mat.id);select.append(opt)});
   const count=document.createElement('input');count.type='number';count.name='material_amounts[]';count.min='.01';count.max='100000';count.step='.01';count.required=true;count.value=qty;count.setAttribute('aria-label','Material amount per product');count.style.cssText='width:100px;padding:9px';
   const unit=document.createElement('span');unit.style.cssText='width:65px;font-size:.8rem';const updateUnit=()=>{const mat=materials.find(x=>String(x.id)===select.value);unit.textContent=mat?mat.unit:''};select.addEventListener('change',updateUnit);updateUnit();
   const remove=document.createElement('button');remove.type='button';remove.className='btn btn-secondary';remove.textContent='Remove';remove.onclick=()=>line.remove();line.append(select,count,unit,remove);rows.append(line);
 }
 choose.addEventListener('change',()=>{rows.replaceChildren();(recipes[choose.value]||[]).forEach(row=>addRow(row.inventory_id,row.qty));if(choose.value&&!rows.children.length)addRow()});
 document.getElementById('materialRecipeAdd').addEventListener('click',()=>{if(choose.value)addRow()});
 document.getElementById('materialRecipeForm').addEventListener('submit',e=>{if(!choose.value||!rows.children.length){e.preventDefault();alert('Choose a product and at least one material.');}});
})();
</script>

<!-- Read-only item details; changes happen through existing Edit / Stock modals. -->
<div class="modal-backdrop" id="inventoryViewModal" role="dialog" aria-modal="true" aria-labelledby="invViewName">
  <div class="modal">
    <div class="modal-header"><div class="inv-view-heading"><span class="inv-view-eyebrow">Item details</span><h2 class="modal-title" id="invViewName"></h2><small id="invViewSku"></small></div><button type="button" class="close-btn" aria-label="Close item details" onclick="closeModal('inventoryViewModal')">×</button></div>
    <div class="modal-body">
      <div class="inv-view-stock"><span>Available stock</span><strong id="invViewQuantity"></strong><span class="inv-stock-status" id="invViewStockStatus"></span></div>
      <dl class="inv-view-grid">
        <div><dt>Category</dt><dd id="invViewCategory"></dd></div>
        <div><dt>Reorder level</dt><dd id="invViewReorder"></dd></div>
        <div><dt>Unit cost</dt><dd id="invViewCost"></dd></div>
        <div><dt>Status</dt><dd id="invViewStatus"></dd></div>
      </dl>
      <div class="inv-view-products"><strong>Linked products <span id="invViewLinkedCount"></span></strong><p id="invViewLinkedNames"></p></div>
    </div>
    <div class="modal-footer inv-view-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('inventoryViewModal')">Close</button>
      <div class="inv-view-actions" id="invViewActions">
        <a class="btn btn-secondary" id="invViewEdit" href="inventory.php">Edit item</a>
        <button type="button" class="btn btn-secondary" id="invViewDeduct">Deduct stock</button>
        <form method="post" id="invViewArchive" onsubmit="return confirm('Archive this inventory item?')"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" id="invViewArchiveId"><button type="submit" class="btn btn-ghost">Archive</button></form>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-backdrop <?= $editItem?'open':'' ?>" id="itemModal">
  <div class="modal">
  <div class="modal-header"><span class="modal-title"><?= $editItem?'Edit Item':'Add Item' ?></span><button type="button" class="close-btn" aria-label="Close modal" onclick="closeModal('itemModal')">×</button></div>
  <form method="post" action="inventory.php">
    <div class="modal-body">
    <input type="hidden" name="action" value="<?= $editItem?'edit':'add' ?>">
    <?php if ($editItem): ?><input type="hidden" name="id" value="<?= $editItem['id'] ?>"><?php endif; ?>
    <div class="modal-sec">Item Details</div>
    <div class="form-row form-row-2">
      <div class="form-group"><label>Item Name *</label><input type="text" name="name" value="<?= htmlspecialchars($editItem['name']??'') ?>" required></div>
      <div class="form-group"><label>SKU</label><input type="text" name="sku" value="<?= htmlspecialchars($editItem['sku']??'') ?>"></div>
    </div>
    <div class="form-row form-row-2">
      <div class="form-group"><label>Material group</label><input name="material_group" list="inventoryMaterialGroups" maxlength="70" value="<?= htmlspecialchars($editItem['material_group']??'Other Materials',ENT_QUOTES) ?>" required><datalist id="inventoryMaterialGroups"><?php foreach($materialGroups as $group): ?><option value="<?= htmlspecialchars($group,ENT_QUOTES) ?>"></option><?php endforeach; ?></datalist></div>
      <div class="form-group"><label>Unit</label><input type="text" name="unit" value="<?= htmlspecialchars($editItem['unit']??'pcs') ?>" placeholder="pcs, roll, sheet"></div>
    </div>
    <details class="inv-extra-details" <?= !empty($editItem['description']) ? 'open' : '' ?>><summary>Additional details (optional)</summary><div class="form-group"><label>Item description</label><textarea name="description" rows="2"><?= htmlspecialchars($editItem['description']??'') ?></textarea></div></details>
    <div class="modal-sec">Stock & Pricing</div>
    <?php if (!$editItem): ?><div class="form-group"><label>Initial Quantity</label><input type="number" name="quantity" value="0" min="0"></div><?php endif; ?>
    <div class="form-row form-row-2">
      <div class="form-group"><label>Reorder Level</label><input type="number" name="reorder_level" value="<?= $editItem['reorder_level']??10 ?>" min="0"></div>
      <div class="form-group"><label>Unit Cost</label><input type="number" step="0.01" name="unit_cost" value="<?= $editItem['unit_cost']??0 ?>"></div>
    </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('itemModal')">Cancel</button><button type="submit" class="btn btn-primary"><?= $editItem?'Update':'Add Item' ?></button></div>
  </form>
  </div>
</div>

<!-- Stock In Modal -->
<div class="modal-backdrop" id="stockInModal">
  <div class="modal" style="max-width:420px">
  <div class="modal-header"><span class="modal-title">Add Stock - <span id="sin_name"></span></span><button type="button" class="close-btn" aria-label="Close modal" onclick="closeModal('stockInModal')">×</button></div>
  <form method="post" action="inventory.php">
    <div class="modal-body">
    <input type="hidden" name="action" value="stock_in"><input type="hidden" name="id" id="sin_id">
    <div style="background:var(--surface);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:14px;font-size:.86rem">Current stock: <strong id="sin_cur"></strong></div>
    <div class="form-group"><label>Quantity to Add *</label><input type="number" name="qty" min="0.01" step="0.01" required placeholder="Enter amount"></div>
    <div class="form-group"><label>Reference</label><input type="text" name="reference" placeholder="Delivery note, PO number"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockInModal')">Cancel</button><button type="submit" class="btn btn-primary">Add Stock</button></div>
  </form>
  </div>
</div>

<!-- Stock Out Modal -->
<div class="modal-backdrop" id="stockOutModal">
  <div class="modal" style="max-width:420px">
  <div class="modal-header"><span class="modal-title">Deduct Stock - <span id="sout_name"></span></span><button type="button" class="close-btn" aria-label="Close modal" onclick="closeModal('stockOutModal')">×</button></div>
  <form method="post" action="inventory.php">
    <div class="modal-body">
    <input type="hidden" name="action" value="stock_out"><input type="hidden" name="id" id="sout_id">
    <div style="background:var(--surface);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:14px;font-size:.86rem">Available: <strong id="sout_cur"></strong></div>
    <div class="form-group"><label>Quantity to Deduct *</label><input type="number" name="qty" min="0.01" step="0.01" required placeholder="Enter amount"></div>
    <div class="form-group"><label>Order Reference</label><input type="text" name="reference" placeholder="Order # or reason"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockOutModal')">Cancel</button><button type="submit" class="btn btn-danger">Deduct Stock</button></div>
  </form>
  </div>
</div>

<script>
// Apply inventory dropdown selections immediately, keeping the other chosen filters.
const inventoryFilters = document.getElementById('inventoryFilters');
inventoryFilters?.querySelectorAll('select[name="cat"], select[name="low"]').forEach((select) => {
  select.addEventListener('change', () => {
    if (typeof inventoryFilters.requestSubmit === 'function') inventoryFilters.requestSubmit();
    else inventoryFilters.submit();
  });
});
function openInventoryView(button) {
  const raw = button?.getAttribute('data-inventory-item');
  if (!raw) return;
  let item;
  try { item = JSON.parse(raw); } catch (_) { return; }
  const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = String(value ?? ''); };
  const quantity = `${item.quantity} ${item.unit}`;
  setText('invViewName', item.name);
  setText('invViewSku', `SKU: ${item.sku || '—'}`);
  setText('invViewQuantity', quantity);
  setText('invViewCategory', item.category);
  setText('invViewReorder', `${item.reorder} ${item.unit}`);
  setText('invViewCost', item.cost);
  setText('invViewStatus', item.status === 'active' ? 'Active' : 'Archived');
  setText('invViewLinkedCount', item.linked_count ? `(${item.linked_count})` : '');
  setText('invViewLinkedNames', item.linked_names || 'Not linked to any products');
  const status = document.getElementById('invViewStockStatus');
  if (status) {
    status.textContent = item.status === 'archived' ? 'Archived' : item.stock_label;
    status.className = `inv-stock-status is-${item.status === 'archived' ? 'archived' : item.stock_label === 'Low stock' ? 'low' : item.stock_label === 'Reorder soon' ? 'warn' : 'ok'}`;
  }
  const actions = document.getElementById('invViewActions');
  if (actions) actions.hidden = item.status !== 'active';
  const edit = document.getElementById('invViewEdit');
  if (edit) edit.href = `inventory.php?edit=${encodeURIComponent(item.id)}`;
  const archiveId = document.getElementById('invViewArchiveId');
  if (archiveId) archiveId.value = item.id;
  const deduct = document.getElementById('invViewDeduct');
  if (deduct) deduct.onclick = () => { closeModal('inventoryViewModal'); openStockModal('out', item.id, item.name, item.quantity); };
  openModal('inventoryViewModal');
}
function openStockModal(type,id,name,cur){
  if(type==='in'){ document.getElementById('sin_id').value=id; document.getElementById('sin_name').textContent=name; document.getElementById('sin_cur').textContent=cur; openModal('stockInModal'); }
  else { document.getElementById('sout_id').value=id; document.getElementById('sout_name').textContent=name; document.getElementById('sout_cur').textContent=cur; openModal('stockOutModal'); }
}
<?php if ($editItem): ?>document.addEventListener('DOMContentLoaded',()=>openModal('itemModal'));<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
