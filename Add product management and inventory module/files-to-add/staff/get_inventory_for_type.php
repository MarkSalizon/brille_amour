<?php
/** Raw materials needed for a printing-shop product and requested quantity. */
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/catalog.php';
ensureCatalogSchema($conn);
$name=trim((string)($_GET['order_type']??''));
$qty=max(1,min(999,(int)($_GET['qty']??1)));
if ($name==='') { echo '[]'; exit; }
$st=$conn->prepare("SELECT pm.product_id AS mapping_id,pm.inventory_id,pm.qty_per_unit,'' AS mapping_notes,
    i.name AS item_name,i.quantity AS current_stock,i.unit,i.reorder_level,i.sku,
    COALESCE(NULLIF(i.material_group,''),'Other Materials') AS category
    FROM products p JOIN product_materials pm ON pm.product_id=p.id
    JOIN inventory i ON i.id=pm.inventory_id
    WHERE p.name=? AND p.status='active' ORDER BY i.name");
$st->bind_param('s',$name);
$st->execute();
$materials=[];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $row['qty_needed']=round($qty*(float)$row['qty_per_unit'],2);
    $row['sufficient']=(float)$row['current_stock']+0.0001 >= $row['qty_needed'];
    $materials[]=$row;
}
$st->close();
echo json_encode($materials,JSON_UNESCAPED_UNICODE);
