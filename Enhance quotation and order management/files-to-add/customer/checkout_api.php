<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/customer_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/product_sizes.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/pickup_capacity.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/innovation.php';

startCustomerSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function checkoutJson(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    checkoutJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    checkoutJson(['ok' => false, 'error' => 'Your login session expired. Please sign in again.', 'auth_required' => true], 401);
}

try {
    ensureCatalogSchema($conn);
    ensureCheckoutPaymentSchema($conn);
    ensurePaymongoSchema($conn);
    ensurePickupCapacitySchema($conn);
    ensureInnovationSchema($conn);
} catch (Throwable $setupError) {
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log('Customer checkout setup error ref=' . $reference . ': ' . $setupError->getMessage());
    checkoutJson([
        'ok' => false,
        'code' => 'DATABASE_SETUP',
        'error' => 'The shop checkout setup could not be loaded. Please contact the shop and mention reference ' . $reference . '. No order was submitted by this attempt.',
    ], 503);
}

if (empty($_SESSION['customer_order_csrf'])) {
    $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['customer_order_csrf'];
if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
    checkoutJson(['ok' => false, 'error' => 'Your checkout session expired. Refresh the Shop and try again.'], 419);
}
if (!customerHasAcceptedTerms($customer)) {
    checkoutJson(['ok' => false, 'error' => 'Please complete your one-time account agreement before checkout.', 'agreement_required' => true], 403);
}
if (!paymongoReady()) {
    checkoutJson(['ok' => false, 'error' => 'Online payment is temporarily unavailable. Please contact Brille Amour.'], 503);
}

$name = trim((string)($customer['name'] ?? ''));
$email = trim((string)($customer['email'] ?? ''));
$contact = trim((string)($customer['contact'] ?? ''));
$savedAddress = trim((string)($customer['address'] ?? ''));
if ($name === '' || $contact === '') {
    checkoutJson([
        'ok' => false,
        'error' => 'Please complete your name and contact number using Edit details in checkout before continuing.',
        'profile_required' => true,
    ], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    checkoutJson(['ok' => false, 'error' => 'Your saved email address is invalid. Please update your Profile.'], 422);
}

$fulfillmentMethod = strtolower(trim((string)($_POST['fulfillment_method'] ?? 'pickup')));
if (!in_array($fulfillmentMethod, ['pickup', 'delivery'], true)) {
    $fulfillmentMethod = 'pickup';
}
$deliveryAddress = $fulfillmentMethod === 'delivery' ? $savedAddress : null;
if ($fulfillmentMethod === 'delivery' && $savedAddress === '') {
    checkoutJson([
        'ok' => false,
        'error' => 'Please save a delivery address from checkout before choosing Delivery.',
        'profile_required' => true,
    ], 422);
}

$scheduleType = $fulfillmentMethod === 'delivery' ? 'delivery' : 'pickup';
$dueDate = trim((string)($_POST['due_date'] ?? ''));
$pickupTime = trim((string)($_POST['pickup_time'] ?? ''));
if ($fulfillmentMethod === 'delivery') {
    $dueDate = trim((string)($_POST['delivery_date'] ?? $dueDate));
    $pickupTime = trim((string)($_POST['delivery_time'] ?? $pickupTime));
}
$dueDate = $dueDate !== '' ? $dueDate : null;
$pickupTime = $pickupTime !== '' ? $pickupTime : null;
if ($dueDate === null) {
    checkoutJson(['ok' => false, 'error' => 'Please choose a ' . $scheduleType . ' date.'], 422);
}
$dueDate = normalizePickupDate($dueDate);
if ($dueDate === null) {
    checkoutJson(['ok' => false, 'error' => 'Please choose a valid ' . $scheduleType . ' date.'], 422);
}
if ($pickupTime !== null && preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/i', $pickupTime, $parts)) {
    $hour = (int)$parts[1] % 12 + (strtoupper($parts[3]) === 'PM' ? 12 : 0);
    $pickupTime = sprintf('%02d:%s', $hour, $parts[2]);
}
if ($pickupTime === null || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $pickupTime)) {
    checkoutJson(['ok' => false, 'error' => 'Please choose a ' . $scheduleType . ' time.'], 422);
}
try {
    if ($fulfillmentMethod === 'delivery') validateDeliveryDateAvailable($conn, $dueDate, 1);
    else validatePickupDateAvailable($conn, $dueDate, 1);
} catch (RuntimeException $e) {
    checkoutJson(['ok' => false, 'error' => $e->getMessage()], 422);
}

$paymentMethod = trim((string)($_POST['payment_method_choice'] ?? 'GCash'));
if (!in_array($paymentMethod, ['GCash', 'Maya', 'Card'], true)) {
    checkoutJson(['ok' => false, 'error' => 'Please choose GCash, Maya, or Card as your payment method.'], 422);
}
try {
    paymongoMethodFromChoice($paymentMethod);
} catch (RuntimeException $e) {
    checkoutJson(['ok' => false, 'error' => $e->getMessage()], 422);
}

$payload = json_decode((string)($_POST['cart_payload'] ?? '[]'), true);
if (!is_array($payload)) {
    checkoutJson(['ok' => false, 'error' => 'Your checkout items could not be read.'], 422);
}

$preorderId = filter_var($_POST['preorder_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$preorderQuote = null;
if ($preorderId) {
    $query = $conn->prepare("SELECT r.*,p.status AS product_status,p.order_mode FROM preorder_requests r JOIN products p ON p.id=r.product_id WHERE r.id=? AND r.customer_id=? LIMIT 1");
    $cid = (int)$customer['id']; $query->bind_param('ii',$preorderId,$cid); $query->execute();
    $preorderQuote=$query->get_result()->fetch_assoc(); $query->close();
    if (!$preorderQuote || $preorderQuote['status'] !== 'confirmed' || $preorderQuote['product_status'] !== 'active' || (float)$preorderQuote['quoted_total'] <= 0)
        checkoutJson(['ok'=>false,'error'=>'This quotation is not yet confirmed or has already been checked out.'],422);
}
if ($preorderQuote && $preorderQuote['estimated_date'] && $dueDate < $preorderQuote['estimated_date'])
    checkoutJson(['ok'=>false,'error'=>'Choose a date on or after the agreed estimated completion date.'],422);
$cartItems = [];
foreach ($payload as $item) {
    $productId = (int)($item['id'] ?? 0);
    $qty = max(1, min(999, (int)($item['qty'] ?? 1)));
    $product = getProductById($conn, $productId);
    if (!$product) {
        continue;
    }
    if (false) checkoutJson(['ok'=>false,'error'=>'Please request a quotation and wait for confirmation before paying for this product.'],422);
    if ($preorderQuote && ((int)$preorderQuote['product_id'] !== $productId || (int)$preorderQuote['quantity'] !== $qty || count($payload) !== 1)) checkoutJson(['ok'=>false,'error'=>'Quotation product or quantity does not match.'],422);
    if (!$preorderQuote && (float)$product['unit_price'] <= 0) {
        checkoutJson(['ok' => false, 'error' => $product['name'] . ' is awaiting a price. Please contact the shop before ordering.'], 422);
    }
    // Material allocation below locks and validates every required material.
    // Legacy finished-goods links must not override that source of truth.
    $size = trim((string)($item['size'] ?? ''));
    try {
        $chosenSize = $preorderQuote ? ['size'=>(string)$preorderQuote['requested_size'],'price'=>round((float)$preorderQuote['quoted_total']/$qty, 4)] : printshopPriceForSize((string)$product['name'], (float)$product['unit_price'], $size, $conn, $productId);
    } catch (InvalidArgumentException $e) {
        checkoutJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    $cartItems[] = ['product' => $product, 'qty' => $qty, 'size' => $chosenSize['size'], 'unit_price' => $chosenSize['price']];
}
if (!$cartItems) {
    checkoutJson(['ok' => false, 'error' => 'Please choose at least one product.'], 422);
}

// This checkout creates a separate order per cart line; reserve enough date slots for all lines.
$ordersToCreate = count($cartItems);
try {
    if ($fulfillmentMethod === 'delivery') validateDeliveryDateAvailable($conn, $dueDate, $ordersToCreate);
    else validatePickupDateAvailable($conn, $dueDate, $ordersToCreate);
} catch (RuntimeException $e) {
    checkoutJson(['ok' => false, 'error' => $e->getMessage()], 422);
}

$notes = trim((string)($_POST['notes'] ?? ''));
$referenceImage = $preorderQuote ? (string)$preorderQuote['reference_image'] : null;
$newReferenceUploaded = false;
if (!empty($_FILES['reference_image']['name'])) {
    $uploadError = '';
    // Document Printing orders may attach a file to print (PDF/Word/etc.);
    // every other order keeps the original images-only rule.
    $hasDocumentPrinting = false;
    foreach ($cartItems as $cartItemCheck) {
        if (strtolower(trim((string)($cartItemCheck['product']['name'] ?? ''))) === 'document printing') { $hasDocumentPrinting = true; break; }
    }
    $referenceImage = $hasDocumentPrinting
        ? saveUploadedPrintFile($_FILES['reference_image'], '../uploads/orders/', $uploadError, 'order_')
        : saveUploadedImage($_FILES['reference_image'], '../uploads/orders/', $uploadError, 'order_');
    $newReferenceUploaded = $referenceImage !== null;
    if ($uploadError !== '') {
        checkoutJson(['ok' => false, 'error' => $uploadError], 422);
    }
}

function checkoutActorId(mysqli $conn): int {
    $result = $conn->query("SELECT id FROM users WHERE status='active' ORDER BY role='admin' DESC, id ASC LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return (int)($row['id'] ?? 0);
}

function checkoutUniqueOrderNumber(mysqli $conn): string {
    do {
        $number = generateOrderNumber($conn);
        $stmt = $conn->prepare('SELECT id FROM orders WHERE order_number=? LIMIT 1');
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);
    return $number;
}

$merchandiseGrandTotal = 0.0;
foreach ($cartItems as $item) {
    $merchandiseGrandTotal += $preorderQuote ? round((float)$preorderQuote['quoted_total'], 2) : round((int)$item['qty'] * (float)$item['unit_price'], 2);
}
$merchandiseGrandTotal = round($merchandiseGrandTotal, 2);
// Delivery has no extra charge; retain the database column for older orders.
$deliveryFeeTotal = 0.0;
$grandTotal = 0.0;
$grandDeposit = 0.0;
$paymentRate = $fulfillmentMethod === 'delivery' ? 1.0 : 0.25;
foreach ($cartItems as &$item) {
    $lineMerchandise = $preorderQuote ? round((float)$preorderQuote['quoted_total'], 2) : round((int)$item['qty'] * (float)$item['unit_price'], 2);
    $item['merchandise_total'] = $lineMerchandise;
    $item['delivery_fee'] = 0.0;
    $item['order_total'] = $lineMerchandise;
    $item['required_deposit'] = round($item['order_total'] * $paymentRate, 2);
    $grandTotal += $item['order_total'];
    $grandDeposit += $item['required_deposit'];
}
unset($item);
$grandTotal = round($grandTotal, 2);
$grandDeposit = round($grandDeposit, 2);
if ($grandDeposit < 1 || $grandDeposit > 100000) {
    $paymentRule = $fulfillmentMethod === 'delivery' ? 'full payment' : '25% downpayment';
    checkoutJson(['ok' => false, 'error' => 'Online checkout supports a ' . $paymentRule . ' from PHP 1 to PHP 100,000.'], 422);
}

$paymentReference = 'PM' . bin2hex(random_bytes(12));
$actorId = checkoutActorId($conn);
$createdBy = $actorId ?: null;
$customerId = (int)$customer['id'];
$createdOrders = [];

$conn->begin_transaction();
try {
    if ($preorderId) {
        $lock=$conn->prepare("SELECT status,quoted_total FROM preorder_requests WHERE id=? AND customer_id=? FOR UPDATE");
        $lock->bind_param('ii',$preorderId,$customerId); $lock->execute(); $locked=$lock->get_result()->fetch_assoc(); $lock->close();
        if (!$locked || $locked['status'] !== 'confirmed' || abs((float)$locked['quoted_total'] - $merchandiseGrandTotal) > 0.01)
            throw new RuntimeException('This quotation is no longer available for payment.');
    }
    if ($fulfillmentMethod === 'delivery') validateDeliveryDateAvailable($conn, $dueDate, $ordersToCreate);
    else validatePickupDateAvailable($conn, $dueDate, $ordersToCreate);

    foreach ($cartItems as $cartItem) {
        $product = $cartItem['product'];
        $qty = (int)$cartItem['qty'];
        $productId = (int)$product['id'];
        $type = (string)$product['name'];
        $unitPrice = $preorderQuote ? round((float)$preorderQuote['quoted_total'] / $qty, 2) : (float)$cartItem['unit_price'];
        $size = (string)$cartItem['size'];
        $deliveryFee = round((float)($cartItem['delivery_fee'] ?? 0), 2);
        $total = round((float)($cartItem['order_total'] ?? ($qty * $unitPrice + $deliveryFee)), 2);
        $requiredDeposit = round((float)($cartItem['required_deposit'] ?? ($total * $paymentRate)), 2);
        $orderNumber = checkoutUniqueOrderNumber($conn);
        $description = $preorderQuote ? 'Pre-order #'.$preorderId.'; '.(string)$preorderQuote['details'].'; '.(string)$preorderQuote['quoted_details'].'; Size: '.$size : ($size !== '' ? 'Selected size: ' . $size : ($notes !== '' ? 'Checkout notes: ' . $notes : ''));
        $priority = 'normal';
        $status = 'pending';
        $source = 'Other';
        $depositPaid = 0.0;
        $balance = $total;
        $assignedTo = null;

        $stmt = $conn->prepare("\n            INSERT INTO orders\n                (order_number, customer_name, customer_contact, customer_id, source, order_type, product_id,\n                 description, quantity, unit_price, total_amount, delivery_fee, deposit_paid, balance,\n                 due_date, pickup_time, fulfillment_method, delivery_address, priority, status, notes, assigned_to, created_by, image)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->bind_param(
            'sssissisidddddsssssssiis',
            $orderNumber,
            $name,
            $contact,
            $customerId,
            $source,
            $type,
            $productId,
            $description,
            $qty,
            $unitPrice,
            $total,
            $deliveryFee,
            $depositPaid,
            $balance,
            $dueDate,
            $pickupTime,
            $fulfillmentMethod,
            $deliveryAddress,
            $priority,
            $status,
            $notes,
            $assignedTo,
            $createdBy,
            $referenceImage
        );
        $stmt->execute();
        $orderId = (int)$conn->insert_id;
        $stmt->close();
        if ($preorderId) {
            $up=$conn->prepare("UPDATE preorder_requests SET status='payment_started',order_id=? WHERE id=? AND status='confirmed'");
            $up->bind_param('ii',$orderId,$preorderId); $up->execute(); if ($up->affected_rows!==1) throw new RuntimeException('Quotation has already been checked out.'); $up->close();
        }

        $sync = syncOrderProductInventory($conn, $orderId, $productId, $qty, $createdBy, $orderNumber);
        if (!$sync['ok']) {
            throw new RuntimeException((string)$sync['error']);
        }

        $log = $conn->prepare("INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?, '', 'pending', 'Customer order submitted', ?)");
        $log->bind_param('ii', $orderId, $createdBy);
        $log->execute();
        $log->close();

        $createdOrders[] = [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'product' => $type,
            'quantity' => $qty,
            'deposit' => $requiredDeposit,
        ];
    }

    $paymentProof = '';
    $paymentOther = '';
    $paymentStmt = $conn->prepare('INSERT INTO checkout_payments (reference_number,payment_method,payment_method_other,proof_image,amount) VALUES (?,?,?,?,?)');
    $paymentStmt->bind_param('ssssd', $paymentReference, $paymentMethod, $paymentOther, $paymentProof, $grandDeposit);
    $paymentStmt->execute();
    $paymentId = (int)$conn->insert_id;
    $paymentStmt->close();

    foreach ($createdOrders as $createdOrder) {
        $link = $conn->prepare('INSERT INTO checkout_payment_orders (payment_id,order_id,amount) VALUES (?,?,?)');
        $link->bind_param('iid', $paymentId, $createdOrder['id'], $createdOrder['deposit']);
        $link->execute();
        $link->close();
    }

    $returnToken = bin2hex(random_bytes(32));
    $live = (int)paymongoLive();
    $gateway = $conn->prepare('INSERT INTO paymongo_checkouts (payment_id,return_token,livemode) VALUES (?,?,?)');
    $gateway->bind_param('isi', $paymentId, $returnToken, $live);
    $gateway->execute();
    $gateway->close();

    $conn->commit();

    foreach ($createdOrders as $createdOrder) {
        try {
            notifyNewOrder($conn, (int)$createdOrder['id'], [
                'order_number' => $createdOrder['order_number'],
                'customer_name' => $name,
                'order_type' => $createdOrder['product'],
                'quantity' => $createdOrder['quantity'],
            ], 0);
        } catch (Throwable $ignored) {
        }
    }

    $_SESSION['customer_order_ids'] = array_values(array_map(static fn(array $row): int => (int)$row['id'], $createdOrders));
    $_SESSION['csrf_token'] = $csrf;
    $_SESSION['paymongo_return_token'] = $returnToken;

    try {
        $checkoutUrl = paymongoDirectWalletChoice($paymentMethod)
            ? paymongoStartWallet($conn, $paymentId)
            : paymongoStart($conn, $paymentId);
    } catch (Throwable $gatewayError) {
        error_log('PayMongo checkout start failed for payment ' . $paymentId . ': ' . $gatewayError->getMessage());
        $checkoutUrl = 'paymongo-return.php?token=' . $returnToken;
    }

    checkoutJson([
        'ok' => true,
        'checkout_url' => $checkoutUrl,
        'order_numbers' => array_column($createdOrders, 'order_number'),
        'total' => round($grandTotal, 2),
        'downpayment' => $grandDeposit,
        'payment_due' => $grandDeposit,
        'payment_type' => $fulfillmentMethod === 'delivery' ? 'full_payment' : 'downpayment',
        'delivery_fee' => $deliveryFeeTotal,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    if ($newReferenceUploaded && $referenceImage) {
        @unlink(__DIR__ . '/../uploads/orders/' . $referenceImage);
    }
    // Show genuine business-rule failures to the customer, not raw SQL or server paths.
    // Unexpected errors receive a short reference that the shop can match in its PHP log.
    $reason = trim($e->getMessage());
    $code = 'CHECKOUT_SYSTEM';
    $publicReason = '';
    if ($e instanceof RuntimeException) {
        if (str_starts_with($reason, 'Insufficient material:')) {
            $code = 'MATERIAL_STOCK';
            $publicReason = $reason;
        } elseif (str_starts_with($reason, 'Required material is archived:') || $reason === 'Order material is missing from inventory.') {
            $code = 'MATERIAL_UNAVAILABLE';
            $publicReason = $reason . ' Please ask the shop to check its inventory.';
        } elseif (str_starts_with($reason, 'This product has no material recipe.')) {
            $code = 'MATERIAL_RECIPE';
            $publicReason = 'This product does not have a material recipe yet. Please ask the shop to configure it in Admin > Inventory.';
        } elseif (preg_match('/(?:\bunknown column\b|\btable\s+.{1,160}\s+doesn.t exist\b)/i', $reason)) {
            $code = 'DATABASE_SETUP';
            $publicReason = 'The shop database is missing a table or field needed to save this order. Please ask the shop to check its system setup.';
        }
    }
    if ($publicReason === '' && $e instanceof mysqli_sql_exception && in_array((int)$e->getCode(), [1054, 1146, 1932], true)) {
        $code = 'DATABASE_SETUP';
        $publicReason = 'The shop database is missing a table or field needed to save this order. Please ask the shop to check its system setup.';
    }
    if ($publicReason !== '') {
        error_log('Modal customer checkout rejected (' . $code . '): ' . $reason);
        checkoutJson(['ok' => false, 'code' => $code, 'error' => $publicReason], 422);
    }
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log('Modal customer checkout error ref=' . $reference . ': ' . $e->getMessage());
    checkoutJson([
        'ok' => false,
        'code' => $code,
        'error' => 'The shop system could not save this order due to an unexpected error. Please contact the shop and mention error reference ' . $reference . '. Check My Orders before retrying or making another payment.',
    ], 500);
}
