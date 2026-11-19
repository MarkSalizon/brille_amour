<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Admin login required.']); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/innovation.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/customer_email_notifications.php';
ensureInnovationSchema($conn); ensureMessagingSchema($conn);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed.']); exit; }
if (!portalCsrfValid($_POST['portal_csrf_token'] ?? null)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']); exit; }
$orderId=(int)($_POST['order_id']??0); $target=strtolower(trim((string)($_POST['status']??'')));
if($orderId<=0 || !in_array($target,['out_for_delivery','delivered'],true)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Invalid delivery update.']); exit; }
$stmt=$conn->prepare("SELECT id,order_number,customer_id,fulfillment_method,status FROM orders WHERE id=? LIMIT 1");$stmt->bind_param('i',$orderId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$order){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Order not found.']);exit;}
if(strtolower((string)$order['fulfillment_method'])!=='delivery'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'This order is not for delivery.']);exit;}
$current=brilleDeliveryStateForOrder($conn,$orderId,(string)$order['status']);
if($target==='out_for_delivery' && !((string)$order['status']==='ready' && in_array($current,['pending','ready_for_dispatch'],true))){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'The order must be Ready for Dispatch before it can go out for delivery.']);exit;}
if($target==='delivered' && $current!=='out_for_delivery'){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Mark the order Out for Delivery first.']);exit;}
$uid=(int)$_SESSION['user_id'];$note=$target==='out_for_delivery'?'Order released for delivery.':'Customer delivery completed.';
$conn->begin_transaction();
try{
  $s=$conn->prepare("INSERT INTO delivery_tracking(order_id,status,notes,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");$s->bind_param('issi',$orderId,$target,$note,$uid);$s->execute();$s->close();
  $orderStatus=(string)$order['status'];
  if($target==='delivered'){
    $from=$orderStatus; $orderStatus='completed';
    $u=$conn->prepare("UPDATE orders SET status='completed',updated_at=NOW() WHERE id=?");$u->bind_param('i',$orderId);$u->execute();$u->close();
    $log=$conn->prepare("INSERT INTO production_logs(order_id,status_from,status_to,notes,updated_by) VALUES(?,?,'completed','Delivery confirmed',?)");$log->bind_param('isi',$orderId,$from,$uid);$log->execute();$log->close();
  } else {
    $u=$conn->prepare('UPDATE orders SET updated_at=NOW() WHERE id=?');$u->bind_param('i',$orderId);$u->execute();$u->close();
  }
  $conn->commit();
} catch(Throwable $e){$conn->rollback();error_log('Delivery status update failed: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Delivery status could not be saved.']);exit;}
try{
  $customerId=registeredCustomerIdForOrder($conn,$orderId);
  if($customerId){
    $customerUserId=registeredCustomerMessagingUserId($customerId);
    $label=$target==='out_for_delivery'?'Out for Delivery':'Delivered';
    $body=$target==='out_for_delivery'?'Your Brille Amour order is now out for delivery.':'Your Brille Amour order has been delivered successfully.';
    createNotification($conn,$customerUserId,'production','Order '.$order['order_number'].': '.$label,$body,'normal','order',$orderId,threadKeyForOrder($orderId),$orderId,'delivery-'.$target.'-'.$orderId);
    postChatMessage($conn,threadKeyForOrder($orderId),0,'system','Delivery update: '.$label);
  }
  $emailBody=$target==='out_for_delivery'?'Your order is now out for delivery. Please keep your contact line available for delivery coordination.':'Your order has been delivered successfully. Thank you for choosing Brille Amour Prints & Crafts.';
  customerEmailSendOnce($conn,$orderId,'delivery-'.$target.'-'.$orderId,'Order update: '.($target==='out_for_delivery'?'Out for Delivery':'Delivered').' - '.$order['order_number'],$emailBody);
}catch(Throwable $ignored){error_log('Delivery notification failed: '.$ignored->getMessage());}
echo json_encode(['ok'=>true,'delivery_status'=>$target,'delivery_label'=>brilleDeliveryStatusLabel($target),'order_status'=>$target==='delivered'?'completed':(string)$order['status']]);
