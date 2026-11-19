<?php
session_name('customer_order_session');
$sessionPath = __DIR__ . '/../.customer_sessions';
if (!is_dir($sessionPath)) {
  mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_start();

require_once '../includes/customer_auth.php';
if (!customerIsLoggedIn()) {
  header('Location: ../index.php?auth=login');
  exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/messaging.php';
require_once '../includes/payments.php';
require_once '../includes/pickup_capacity.php';
require_once '../includes/paymongo.php';
ensureCustomerAuthSchema($conn);
$signedInCustomer = loadSignedInCustomer($conn);
if (!$signedInCustomer) {
  header('Location: ../index.php?auth=login');
  exit;
}
$customer = $signedInCustomer;
$portalActive = 'cart';
ensureCheckoutPaymentSchema($conn);
ensurePaymongoSchema($conn);
ensurePickupCapacitySchema($conn);
$usePaymongo = paymongoReady();

ensureCatalogSchema($conn);

if (empty($_SESSION['customer_order_csrf'])) {
  $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['customer_chat_user_id'])) {
  $_SESSION['customer_chat_user_id'] = -random_int(100000000, 999999999);
}
$_SESSION['role'] = 'customer';
$_SESSION['customer_support_thread_key'] = threadKeyForCustomerSession((int)$_SESSION['customer_chat_user_id']);

$csrfToken = $_SESSION['customer_order_csrf'];
$catalogProducts = getActiveProductsList($conn);
$catalogCategories = getActiveCategoriesList($conn);
$productMap = getProductCatalogMap($conn);
$allowedSources = ['Facebook', 'Instagram', 'Walk-in', 'Referral', 'Other'];
$pickupCalendar = pickupCalendarSnapshot($conn, 180);
$pickupDefaultCapacity = pickupDefaultCapacity($conn);
$msg = '';
$err = '';
$receipt = null;

function ensurePublicCustomerSchema(mysqli $conn): void
{
  $conn->query("
        CREATE TABLE IF NOT EXISTS customers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact VARCHAR(100) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'Other',
            notes TEXT DEFAULT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            archived_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customers_status (status),
            INDEX idx_customers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function publicOrderActorId(mysqli $conn): int
{
  $result = $conn->query("SELECT id FROM users WHERE status='active' ORDER BY role='admin' DESC, id ASC LIMIT 1");
  $row = $result ? $result->fetch_assoc() : null;

  return (int)($row['id'] ?? 0);
}

function publicGenerateUniqueOrderNumber(mysqli $conn): string
{
  do {
    $orderNumber = generateOrderNumber($conn);
    $stmt = $conn->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1");
    $stmt->bind_param('s', $orderNumber);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  } while ($exists);

  return $orderNumber;
}

function publicGenerateUniquePaymentReference(mysqli $conn): string
{
  do {
    $reference = 'BA-PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $conn->prepare('SELECT id FROM checkout_payments WHERE reference_number = ? LIMIT 1');
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  } while ($exists);

  return $reference;
}

function publicUploadOrderImage(array $file, string &$err, bool $allowDocuments = false): ?string
{
  // Document Printing orders may attach a PDF/Word/etc. file to print.
  return $allowDocuments
    ? saveUploadedPrintFile($file, '../uploads/orders/', $err, 'order_')
    : saveUploadedImage($file, '../uploads/orders/', $err, 'order_');
}

ensurePublicCustomerSchema($conn);
if (empty($_SESSION['pending_payment_reference'])
    || !preg_match('/^BA-PAY-\d{8}-[A-F0-9]{8}$/', (string)$_SESSION['pending_payment_reference'])) {
  $_SESSION['pending_payment_reference'] = publicGenerateUniquePaymentReference($conn);
}
$autoPaymentReference = (string)$_SESSION['pending_payment_reference'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $image = null;
  $paymentProof = null;
  $paymentReference = $autoPaymentReference;
  $paymentConfirmed = (string)($_POST['payment_confirmed'] ?? '') === '1';
  $paymentMethodChoice = trim((string)($_POST['payment_method_choice'] ?? 'GCash'));
  if (!in_array($paymentMethodChoice, ['GCash', 'Maya', 'Card'], true)) {
    $err = 'Please select GCash, Maya, or Card.';
  }
  $paymentMethod = 'GCash';
  $paymentMethodOther = '';
  $paymentExpectedAmount = round((float)($_POST['payment_expected_amount'] ?? 0), 2);

  if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    $err = 'Your session expired. Please refresh the page and try again.';
  } elseif (!$usePaymongo) {
    $err = 'PayMongo checkout is temporarily unavailable. Please contact the shop before placing the order.';
  } else {
    $customer = sanitize($_POST['customer_name'] ?? '');
    $contact = sanitize($_POST['customer_contact'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    if ($signedInCustomer) {
      $customer = sanitize((string)$signedInCustomer['name']);
      $email = sanitize((string)($signedInCustomer['email'] ?? ''));
    }
    $address = sanitize($_POST['address'] ?? '');
    $source = sanitize($_POST['source'] ?? 'Other');
    $notes = sanitize($_POST['notes'] ?? '');
    try {
      $selectedPaymongoMethod = paymongoMethodFromChoice($paymentMethodChoice);
      $paymentMethod = $paymentMethodChoice;
    } catch (RuntimeException $e) {
      $selectedPaymongoMethod = null;
      $paymentMethod = 'GCash';
      $err = $e->getMessage();
    }
    $paymentMethodOther = '';
    $due = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $pickupTime = trim((string)($_POST['pickup_time'] ?? '')) ?: null;
    if ($pickupTime !== null && preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/i', $pickupTime, $timeParts)) {
      $pickupTime = sprintf('%02d:%s', (int)$timeParts[1] % 12 + (strtoupper($timeParts[3]) === 'PM' ? 12 : 0), $timeParts[2]);
    }
    if ($pickupTime !== null && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $pickupTime)) {
      $err = 'Please choose a valid pickup time.';
    }
    $cartPayload = json_decode((string)($_POST['cart_payload'] ?? '[]'), true);
    $cartItems = [];

    if (!in_array($source, $allowedSources, true)) {
      $source = 'Other';
    }

    if (is_array($cartPayload)) {
      foreach ($cartPayload as $item) {
        $productId = (int)($item['id'] ?? 0);
        $qty = max(1, min(999, (int)($item['qty'] ?? 1)));
        $selectedProduct = getProductById($conn, $productId);

        if ($selectedProduct) {
          if (($selectedProduct['order_mode'] ?? 'regular') === 'preorder') {
            $err = 'Please request a quotation for '.$selectedProduct['name'].' and wait for Admin confirmation before paying.';
            break;
          }
          $inventoryId = (int)($selectedProduct['inventory_id'] ?? 0);
          $availableQty = $selectedProduct['inventory_quantity'] === null ? null : (float)$selectedProduct['inventory_quantity'];
          if ($inventoryId > 0 && $availableQty !== null && $availableQty < $qty) {
            $err = $selectedProduct['name'] . ' is not available in the requested quantity.';
            break;
          }

          $cartItems[] = [
            'product' => $selectedProduct,
            'qty' => $qty,
          ];
        }
      }
    }

    if ($err === '') {
      if ($due === null) {
        $err = 'Please choose an available pickup date.';
      } else {
        $normalizedDue = normalizePickupDate($due);
        if ($normalizedDue === null) {
          $err = 'Please choose a valid pickup date.';
        } else {
          $due = $normalizedDue;
          try {
            validatePickupDateAvailable($conn, $due, 1);
          } catch (RuntimeException $e) {
            $err = $e->getMessage();
          }
        }
      }
    }

    if ($customer === '') {
      $err = 'Please enter your full name.';
    } elseif ($contact === '') {
      $err = 'Please enter a contact number or messaging handle.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'Please enter a valid email address.';
    } elseif (!$cartItems) {
      $err = 'Please add at least one item to your order.';
    } else {
      $hasDocumentPrinting = false;
      foreach ($cartItems as $cartItemCheck) {
        if (strtolower(trim((string)($cartItemCheck['product']['name'] ?? ''))) === 'document printing') { $hasDocumentPrinting = true; break; }
      }
      $image = publicUploadOrderImage($_FILES['reference_image'] ?? [], $err, $hasDocumentPrinting);
    }

    if ($err === '' && $usePaymongo) {
      $requestedDeposit = 0.0;
      foreach ($cartItems as $item) {
        $requestedDeposit += round($item['qty'] * (float)$item['product']['unit_price'] * 0.25, 2);
      }
      if ($requestedDeposit < 1 || $requestedDeposit > 100000) {
        $err = 'Online PayMongo checkout supports a downpayment from PHP 1 to PHP 100,000. Please contact the shop to arrange other amounts.';
      }
      $paymentReference = 'PM' . bin2hex(random_bytes(12));
      $paymentMethod = $paymentMethodChoice;
      $paymentMethodOther = '';
      $paymentProof = '';
    }
    if ($err === '' && !$usePaymongo) {
      $requestedDeposit = 0.0;
      foreach ($cartItems as $item) {
        $requestedDeposit += round($item['qty'] * (float)$item['product']['unit_price'] * 0.25, 2);
      }
      $requestedDeposit = round($requestedDeposit, 2);

      if (!$paymentConfirmed) {
        $err = 'Please confirm that you paid the exact 25% downpayment using your selected payment method.';
      } elseif ($paymentExpectedAmount <= 0 || abs($paymentExpectedAmount - $requestedDeposit) > 0.01) {
        $err = 'Your order amount changed after the payment instructions were shown. Please reopen the payment step and pay the updated downpayment amount before submitting.';
      } elseif (!preg_match('/^BA-PAY-\d{8}-[A-F0-9]{8}$/', $paymentReference)) {
        $err = 'The payment reference could not be generated. Please refresh the page and upload the receipt again.';
      } else {
        $paymentProof = saveUploadedImage($_FILES['payment_proof'] ?? [], '../uploads/payment-proofs/', $err, 'proof_');
        if (!$paymentProof && $err === '') {
          $err = 'Please upload your payment receipt.';
        }
      }
    }
    if ($err === '') {
      $actorId = publicOrderActorId($conn);
      $assignedTo = null;
      $createdBy = $actorId ?: null;
      $status = 'pending';
      $priority = 'normal';
      $createdOrders = [];
      $grandTotal = 0.0;
      $grandDeposit = 0.0;
      $grandBalance = 0.0;

      $conn->begin_transaction();

      try {
        validatePickupDateAvailable($conn, $due, count($cartItems));
        $customerNote = $signedInCustomer
          ? 'Updated from signed-in customer checkout.'
          : 'Created from public customer order form.';

        if ($signedInCustomer) {
          $customerId = (int)$signedInCustomer['id'];
          $customerStmt = $conn->prepare("
                        UPDATE customers
                        SET name = ?, contact = ?, email = ?, address = ?, source = ?, notes = ?, status = 'active', archived_at = NULL
                        WHERE id = ?
                    ");
          $customerStmt->bind_param('ssssssi', $customer, $contact, $email, $address, $source, $customerNote, $customerId);
          $customerStmt->execute();
          $customerStmt->close();
        } else {
          $findCustomer = $conn->prepare("
                      SELECT id
                      FROM customers
                      WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                        AND (contact = ? OR email = ?)
                      LIMIT 1
                  ");
          $findCustomer->bind_param('sss', $customer, $contact, $email);
          $findCustomer->execute();
          $existingCustomer = $findCustomer->get_result()->fetch_assoc();
          $findCustomer->close();

          if ($existingCustomer) {
            $customerId = (int)$existingCustomer['id'];
            $customerStmt = $conn->prepare("
                          UPDATE customers
                          SET contact = ?, email = ?, address = ?, source = ?, notes = ?, status = 'active', archived_at = NULL
                          WHERE id = ?
                      ");
            $customerStmt->bind_param('sssssi', $contact, $email, $address, $source, $customerNote, $customerId);
            $customerStmt->execute();
            $customerStmt->close();
          } else {
            $customerStmt = $conn->prepare("
                          INSERT INTO customers (name, contact, email, address, source, notes)
                          VALUES (?, ?, ?, ?, ?, ?)
                      ");
            $customerStmt->bind_param('ssssss', $customer, $contact, $email, $address, $source, $customerNote);
            $customerStmt->execute();
            $customerId = (int)$conn->insert_id;
            $customerStmt->close();
          }
        }

        foreach ($cartItems as $cartItem) {
          $selectedProduct = $cartItem['product'];
          $qty = (int)$cartItem['qty'];
          $productId = (int)$selectedProduct['id'];
          $type = (string)$selectedProduct['name'];
          $unitPrice = (float)$selectedProduct['unit_price'];
          $total = round($qty * $unitPrice, 2);
          $requiredDeposit = round($total * 0.25, 2);
          $deposit = 0.0;
          $balance = $total;
          $orderNumber = publicGenerateUniqueOrderNumber($conn);
          $descParts = array_filter([
            sanitize($_POST['description_' . $productId] ?? ''),
            $notes ? 'Checkout notes: ' . $notes : '',
          ]);
          $description = implode("\n\n", $descParts);

          $stmt = $conn->prepare("
                        INSERT INTO orders
                            (order_number, customer_name, customer_contact, customer_id, source, order_type, product_id,
                             description, quantity, unit_price, total_amount, deposit_paid, balance,
                             due_date, pickup_time, priority, status, notes, assigned_to, created_by, image)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
          $stmt->bind_param(
            'sssissisiddddsssssiis',
            $orderNumber,
            $customer,
            $contact,
            $customerId,
            $source,
            $type,
            $productId,
            $description,
            $qty,
            $unitPrice,
            $total,
            $deposit,
            $balance,
            $due,
            $pickupTime,
            $priority,
            $status,
            $notes,
            $assignedTo,
            $createdBy,
            $image
          );
          $stmt->execute();
          $orderId = (int)$conn->insert_id;
          $stmt->close();

          $sync = syncOrderProductInventory($conn, $orderId, $productId, $qty, $createdBy, $orderNumber);
          if (!$sync['ok']) {
            throw new RuntimeException($sync['error']);
          }

          $log = $conn->prepare("INSERT INTO production_logs (order_id, status_from, status_to, notes, updated_by) VALUES (?, '', 'pending', 'Customer order submitted', ?)");
          $log->bind_param('ii', $orderId, $createdBy);
          $log->execute();
          $log->close();

          $createdOrders[] = [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'product' => $type,
            'quantity' => $qty,
            'total' => $total,
            'deposit' => $requiredDeposit,
            'balance' => $balance,
          ];

          $grandTotal += $total;
          $grandDeposit += $requiredDeposit;
          $grandBalance += $balance;
        }

        $paymentStmt = $conn->prepare('INSERT INTO checkout_payments (reference_number,payment_method,payment_method_other,proof_image,amount) VALUES (?,?,?,?,?)');
        $paymentStmt->bind_param('ssssd', $paymentReference, $paymentMethod, $paymentMethodOther, $paymentProof, $grandDeposit);
        $paymentStmt->execute();
        $paymentId = (int)$conn->insert_id;
        foreach ($createdOrders as $createdOrder) {
          $link = $conn->prepare('INSERT INTO checkout_payment_orders (payment_id,order_id,amount) VALUES (?,?,?)');
          $link->bind_param('iid', $paymentId, $createdOrder['id'], $createdOrder['deposit']);
          $link->execute();
        }
        if ($usePaymongo) {
          $returnToken = bin2hex(random_bytes(32));
          $live = (int)paymongoLive();
          $gateway = $conn->prepare('INSERT INTO paymongo_checkouts (payment_id,return_token,livemode) VALUES (?,?,?)');
          $gateway->bind_param('isi', $paymentId, $returnToken, $live);
          $gateway->execute();
        }
        $conn->commit();

        foreach ($createdOrders as $createdOrder) {
          try {
            notifyNewOrder($conn, (int)$createdOrder['id'], [
              'order_number' => $createdOrder['order_number'],
              'customer_name' => $customer,
              'order_type' => $createdOrder['product'],
              'quantity' => $createdOrder['quantity'],
            ], 0);
          } catch (Throwable $notificationError) {
            error_log('Customer order notification failed: ' . $notificationError->getMessage());
          }
        }

        if (!$usePaymongo) {
          try {
            notifyGcashPaymentSubmission($conn, $paymentId, $paymentReference, $grandDeposit, $customer);
          } catch (Throwable $notificationError) {
            error_log('Payment notification failed: ' . $notificationError->getMessage());
          }
        }

        $receipt = [
          'customer' => $customer,
          'contact' => $contact,
          'orders' => $createdOrders,
          'total' => $grandTotal,
          'deposit' => $grandDeposit,
          'balance' => $grandBalance,
          'due' => $due,
          'pickup_time' => $pickupTime,
          'payment_reference' => $paymentReference,
          'payment_method' => $paymentMethod === 'Other' && $paymentMethodOther !== '' ? $paymentMethodOther : $paymentMethod,
        ];
        $msg = 'Your order request has been submitted.';
        unset($_SESSION['pending_payment_reference']);
        $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
        $csrfToken = $_SESSION['customer_order_csrf'];
        $receiptOrderIds = array_values(array_map(
          static fn(array $order): int => (int)$order['id'],
          $createdOrders
        ));
        if (empty($_SESSION['customer_chat_user_id'])) {
          $_SESSION['customer_chat_user_id'] = -random_int(100000000, 999999999);
        }
        $_SESSION['role'] = 'customer';
        $_SESSION['customer_order_ids'] = $receiptOrderIds;
        $_SESSION['csrf_token'] = $csrfToken;
        if ($usePaymongo) {
          // Orders and the return token are durable before the external API call.
          $_SESSION['paymongo_return_token'] = $returnToken;
          try {
            $checkoutUrl = paymongoDirectWalletChoice($paymentMethod)
              ? paymongoStartWallet($conn, $paymentId)
              : paymongoStart($conn, $paymentId);
          } catch (Throwable $gatewayError) {
            error_log('PayMongo checkout start failed for payment ' . $paymentId);
            $checkoutUrl = 'paymongo-return.php?token=' . $returnToken;
          }
          header('Location: ' . $checkoutUrl, true, 303);
          exit;
        }
      } catch (Throwable $e) {
        $conn->rollback();
        if ($paymentProof) {
          @unlink('../uploads/payment-proofs/' . $paymentProof);
        }
        if ($image) {
          @unlink('../uploads/orders/' . $image);
        }
        error_log('Customer checkout failed: ' . $e->getMessage());
        $err = $e instanceof mysqli_sql_exception && $e->getCode() === 1062
          ? 'A duplicate system payment reference was detected. Please refresh the page and submit the same receipt again; do not pay again.'
          : 'Your order could not be saved. Please try again or contact the shop and keep your uploaded receipt. Do not pay again.';
      }
    }
    if ($err !== '' && $image) {
      @unlink('../uploads/orders/' . $image);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Order from Brille Amour</title>
  <link rel="stylesheet" href="../assets/css/gcash-checkout.css?v=<?= filemtime(__DIR__ . '/../assets/css/gcash-checkout.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="stylesheet" href="../assets/css/messaging-page.css?v=<?= filemtime(__DIR__ . '/../assets/css/messaging-page.css') ?>">
  <link rel="stylesheet" href="../assets/css/design-system.css?v=<?= filemtime(__DIR__ . '/../assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="../assets/css/brille-ui-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-ui-polish.css') ?>">
  <link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-chatbot.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-chatbot.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-checkout-compact.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-checkout-compact.css') ?>">
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/../assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>

<body class="customer-portal-body">
  <?php require __DIR__ . '/portal_header.php'; ?>
  <section class="portal-checkout-intro">
    <div><span class="portal-eyebrow">Brille Amour Checkout</span><h1>Checkout</h1><p>Review your items, add instructions, choose pickup, and complete payment.</p></div>
    <div class="portal-checkout-actions"><a class="portal-btn secondary" href="shop.php">Back to Shop</a><?php if (!$receipt): ?><button class="cart-pill header-cart cart-icon-button" type="button" data-open-cart aria-label="Open cart, 0 items" title="View checkout cart"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3h2l2.4 12h11.2l2-8H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg><span id="cartPillCount" class="cart-count-badge" hidden>0</span></button><?php endif; ?></div>
  </section>
  <main class="customer-page">

    <?php if ($msg): ?>
      <div class="alert success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert error"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if ($receipt): ?>
      <section class="receipt order-panel">
        <span class="receipt-kicker">Order received</span>
        <h1>Thank you, <?= htmlspecialchars($receipt['customer']) ?>.</h1>
        <div class="receipt-list">
          <?php foreach ($receipt['orders'] as $order): ?>
            <article>
              <div>
                <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                <span><?= htmlspecialchars($order['product']) ?> x<?= (int)$order['quantity'] ?></span>
              </div>
              <b><?= formatMoney($order['total']) ?></b>
            </article>
          <?php endforeach; ?>
        </div>
        <dl class="cart-totals">
          <div>
            <dt>Total</dt>
            <dd><?= formatMoney($receipt['total']) ?></dd>
          </div>
          <div>
            <dt>Downpayment submitted (unverified)</dt>
            <dd><?= formatMoney($receipt['deposit']) ?></dd>
          </div>
          <div>
            <dt>Balance</dt>
            <dd><?= formatMoney($receipt['balance']) ?></dd>
          </div>
          <?php if (!empty($receipt['payment_method'])): ?><div>
              <dt>Payment method</dt>
              <dd><?= htmlspecialchars($receipt['payment_method']) ?></dd>
            </div><?php endif; ?>
          <?php if (!empty($receipt['payment_reference'])): ?><div>
              <dt>Brille Amour payment reference</dt>
              <dd><?= htmlspecialchars($receipt['payment_reference']) ?></dd>
            </div><?php endif; ?>
        </dl>
        <p class="fine-print"><strong>Payment submitted for verification.</strong> Your 25% downpayment is not credited until the admin confirms the exact amount and reference in the shop's payment account. Do not pay the same order again while verification is pending.</p>
        <div class="receipt-actions">
          <a class="btn primary" href="orders.php">View My Orders</a>
          <a class="btn secondary" href="shop.php">Continue Shopping</a>
        </div>
      </section>

    <?php else: ?>
      <form method="post" action="order.php" enctype="multipart/form-data" id="customerOrderForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="cart_payload" id="cartPayload" value="[]">
        <div class="note-field-store" id="cartNoteFields" hidden></div>

        <section class="ordering-layout">
          <aside class="category-sidebar" aria-label="Product categories">
            <div class="sidebar-frame" id="categoryList">
              <span>Categories</span>
              <button type="button" class="active" data-category="all">All Products</button>
            </div>
          </aside>

          <div class="menu-area">
            <div class="menu-heading menu-toolbar">
              <div>
                <span id="activeCategoryLabel">All Products</span>
                <strong id="visibleProductCount">Loading products</strong>
              </div>
              <button type="button" class="catalog-search-toggle" id="catalogSearchToggle" aria-label="Open product search" aria-expanded="false" aria-controls="catalogSearchPanel">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <circle cx="11" cy="11" r="7" />
                  <path d="m16 16 4 4" />
                </svg>
              </button>
            </div>
            <section class="catalog-search-popover" id="catalogSearchPanel" aria-label="Product search" hidden>
              <label class="catalog-search" for="catalogSearch">
                <span>Search products</span>
                <div class="catalog-search-box">
                  <input type="search" id="catalogSearch" placeholder="Search name, category, price, or stock" autocomplete="off">
                  <button type="button" class="catalog-search-clear" id="catalogSearchClear" aria-label="Clear product search" hidden>x</button>
                </div>
                <div class="catalog-search-suggestions" id="catalogSearchSuggestions" hidden></div>
              </label>
            </section>
            <div class="catalog-status" id="catalogStatus">Loading catalog...</div>
            <div class="menu-grid" id="productGrid"></div>
            <div class="empty-products" id="emptyProducts" hidden>No products found. Try a different search or category.</div>
          </div>
        </section>

        <div class="order-overlay" id="orderOverlay" hidden>
          <div class="overlay-backdrop" aria-hidden="true"></div>
          <section class="overlay-sheet" role="dialog" aria-modal="true" aria-labelledby="overlayTitle">
            <div class="overlay-header">
              <div>
                <span class="eyebrow">Order Summary</span>
                <h2 id="overlayTitle">Cart and checkout</h2>
              </div>
              <button type="button" class="overlay-close" data-close-overlay aria-label="Close order summary">x</button>
            </div>

            <div class="overlay-grid">
              <section class="cart-panel" id="cartPanel" aria-labelledby="cartTitle">
                <div class="cart-card">
                  <div class="checkout-title">
                    <span id="cartTitle">Review Your Cart</span>
                    <button type="button" data-clear-cart>Clear</button>
                  </div>
                  <p class="cart-hint">Adjust quantities, remove items, or update instructions before you continue.</p>
                  <div class="cart-list" id="cartList">
                    <p class="empty-cart">Add products from the menu to begin.</p>
                  </div>
                  <dl class="cart-totals">
                    <div>
                      <dt>Total</dt>
                      <dd id="cartTotal">PHP 0.00</dd>
                    </div>
                    <div>
                      <dt>Downpayment</dt>
                      <dd id="cartDeposit">PHP 0.00</dd>
                    </div>
                    <div>
                      <dt>Balance</dt>
                      <dd id="cartBalance">PHP 0.00</dd>
                    </div>
                  </dl>
                  <button type="button" class="btn primary full" data-proceed-checkout>Proceed to Checkout</button>
                </div>
              </section>

              <section class="checkout-section" id="checkoutPanel" aria-labelledby="checkoutTitle" hidden>
                <div class="checkout-card">
                  <div class="checkout-title">
                    <span id="checkoutTitle">Complete Your Order</span>
                    <button type="button" data-back-cart>Back to Cart</button>
                  </div>
                  <div class="checkout-stepper" id="checkoutStepper" aria-label="Checkout sections">
                    <span><b>1</b> Details</span>
                    <span><b>2</b> Pickup</span>
                    <span><b>3</b> Payment</span>
                  </div>

                  <div class="checkout-layout">
                    <section class="checkout-block checkout-details-block" data-checkout-stage="1" aria-labelledby="checkoutDetailsHeading">
                      <h3 class="checkout-block-title" id="checkoutDetailsHeading">Checkout Details</h3>
                      <div class="checkout-fields compact-detail-fields">
                        <div class="field-grid">
                          <label>
                            <span>Full Name</span>
                            <input type="text" name="customer_name" autocomplete="name" value="<?= htmlspecialchars((string)($signedInCustomer['name'] ?? $_POST['customer_name'] ?? '')) ?>" <?= $signedInCustomer ? 'readonly aria-readonly="true"' : '' ?> required>
                          </label>
                          <label>
                            <span>Contact Number</span>
                            <input type="text" name="customer_contact" autocomplete="tel" value="<?= htmlspecialchars((string)($_POST['customer_contact'] ?? $signedInCustomer['contact'] ?? '')) ?>" required>
                          </label>
                        </div>
                        <label>
                          <span>Email</span>
                          <input type="email" name="email" autocomplete="email" value="<?= htmlspecialchars((string)($signedInCustomer['email'] ?? $_POST['email'] ?? '')) ?>" <?= $signedInCustomer ? 'readonly aria-readonly="true"' : '' ?>>
                          <?php if ($signedInCustomer): ?><small class="verified-google-note">Verified through your Google account.</small><?php endif; ?>
                        </label>
                        <label>
                          <span>Address or Delivery Notes</span>
                          <textarea name="address" rows="2"></textarea>
                        </label>
                        <div class="field-grid checkout-detail-bottom">
                          <label>
                            <span>Reference Image / File to Print</span>
                            <input type="file" name="reference_image" accept="image/*,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt">
                            <small>Documents (PDF, Word, PowerPoint, Excel, text) are accepted for Document Printing orders only.</small>
                          </label>
                          <label>
                            <span>Additional Notes</span>
                            <textarea name="notes" rows="2" placeholder="Final reminders for the team"></textarea>
                          </label>
                        </div>
                      </div>
                      <div class="checkout-step-actions checkout-step-actions-end">
                        <button type="button" class="btn primary checkout-next" data-checkout-next="2">Next</button>
                      </div>
                    </section>

                    <section class="checkout-block pickup-block" data-checkout-stage="2" aria-labelledby="pickupScheduleHeading" hidden>
                      <h3 class="checkout-block-title" id="pickupScheduleHeading">Pickup Schedule</h3>
                      <div class="pickup-schedule-summary">
                        <p id="pickupScheduleSummary" aria-live="polite">No pickup schedule selected.</p>
                        <button type="button" class="btn" id="openPickupSchedule">Choose pickup schedule</button>
                      </div>
                      <dialog id="pickupScheduleDialog" aria-labelledby="pickupDialogTitle">
                        <div class="pickup-dialog-head">
                          <h3 id="pickupDialogTitle">Choose pickup schedule</h3>
                          <button type="button" id="closePickupSchedule" aria-label="Close pickup schedule">&times;</button>
                        </div>
                      <div class="checkout-fields pickup-fields">
                        <div class="field-grid pickup-primary-fields">
                          <label>
                            <span>Pickup Date</span>
                            <input type="date" name="due_date" id="pickupDate" value="<?= htmlspecialchars((string)($_POST['due_date'] ?? '')) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+119 days')) ?>" data-default-capacity="<?= (int)$pickupDefaultCapacity ?>" data-days="120" aria-describedby="pickupDateStatus pickupLastUpdated">
                            <small id="pickupDateStatus">Choose a pickup date. Fully booked dates cannot be submitted.</small>
                          </label>
                          <label>
                            <span>Pickup Time</span>
                            <input type="text" name="pickup_time" id="pickupTime" list="pickupTimeSuggestions" value="<?= htmlspecialchars((string)($_POST['pickup_time'] ?? '')) ?>" placeholder="e.g. 09:05 AM" autocomplete="off" aria-describedby="pickupTimeHint">
                            <datalist id="pickupTimeSuggestions">
                              <?php for ($minuteOfDay = 0; $minuteOfDay < 1440; $minuteOfDay += 1):
                                $hour24 = intdiv($minuteOfDay, 60);
                                $minute = $minuteOfDay % 60;
                                $timeValue = sprintf('%02d:%02d', $hour24, $minute);
                                $timeLabel = sprintf('%02d:%02d %s', $hour24 % 12 ?: 12, $minute, $hour24 < 12 ? 'AM' : 'PM');
                              ?>
                                <option value="<?= $timeLabel ?>"></option>
                              <?php endfor; ?>
                            </datalist>
                            <small id="pickupTimeHint">Type a time or choose a suggestion. Any minute is allowed (e.g. 09:31 AM or 14:33).</small>
                          </label>
                        </div>
                        <div class="pickup-calendar-toolbar">
                          <button type="button" id="pickupRefresh" class="pickup-refresh">Refresh availability</button>
                          <small id="pickupLastUpdated">Availability based on the current schedule.</small>
                        </div>
                        <div class="pickup-calendar" id="pickupCalendar" aria-label="Available pickup dates"></div>
                      </div>
                        <div class="pickup-dialog-actions">
                          <button type="button" class="btn primary" id="savePickupSchedule">Use this schedule</button>
                        </div>
                      </dialog>
                      <div class="checkout-step-actions">
                        <button type="button" class="btn checkout-back" data-checkout-back="1">Back</button>
                        <button type="button" class="btn primary checkout-next" data-checkout-next="3">Next</button>
                      </div>
                    </section>

                    <section class="checkout-block payment-block" data-checkout-stage="3" aria-labelledby="paymentHeading" hidden>
                      <h3 class="checkout-block-title" id="paymentHeading">Downpayment &amp; Payment</h3>
                      <dl class="cart-totals payment-totals">
                        <div>
                          <dt>Order total</dt>
                          <dd id="checkoutTotal">PHP 0.00</dd>
                        </div>
                        <div>
                          <dt>Pay now (25%)</dt>
                          <dd id="checkoutDeposit">PHP 0.00</dd>
                        </div>
                        <div>
                          <dt>Balance</dt>
                          <dd id="checkoutBalance">PHP 0.00</dd>
                        </div>
                      </dl>
                      <?php if ($usePaymongo): ?>
                        <div class="paymongo-gateway-card" role="status">
                          <div class="paymongo-gateway-icon" aria-hidden="true">P</div>
                          <div>
                            <strong>Secure checkout via PayMongo</strong>
                            <span>Choose how you want to pay the 25% downpayment. You will be redirected to PayMongo to complete the selected payment method securely.</span>
                            <?php if (!paymongoLive()): ?><small><strong>TEST MODE:</strong> no real money will be collected.</small><?php endif; ?>
                          </div>
                        </div>

                        <fieldset class="paymongo-methods" aria-labelledby="paymongoMethodLegend">
                          <legend id="paymongoMethodLegend">Choose payment method</legend>
                          <div class="paymongo-method-grid">
                            <label class="paymongo-method-card">
                              <input type="radio" name="payment_method_choice" value="GCash" checked required>
                              <span class="paymongo-method-mark">G</span>
                              <span class="paymongo-method-copy"><strong>GCash</strong><small><?= paymongoLive() ? 'Mobile: continue to GCash authorization' : 'Test mode: PayMongo GCash authorization simulator' ?></small></span>
                              <span class="paymongo-method-check" aria-hidden="true"></span>
                            </label>
                            <label class="paymongo-method-card">
                              <input type="radio" name="payment_method_choice" value="Maya" required>
                              <span class="paymongo-method-mark">M</span>
                              <span class="paymongo-method-copy"><strong>Maya</strong><small><?= paymongoLive() ? 'Continue to Maya wallet authorization' : 'Test mode: PayMongo Maya authorization simulator' ?></small></span>
                              <span class="paymongo-method-check" aria-hidden="true"></span>
                            </label>
                            <label class="paymongo-method-card">
                              <input type="radio" name="payment_method_choice" value="Card" required>
                              <span class="paymongo-method-mark card-mark" aria-hidden="true"><i></i></span>
                              <span class="paymongo-method-copy"><strong>Card</strong><small>Visa or Mastercard</small></span>
                              <span class="paymongo-method-check" aria-hidden="true"></span>
                            </label>
                          </div>
                          <p class="paymongo-method-note"><?= paymongoDirectWalletReady() ? 'GCash and Maya use the direct wallet authorization flow. Card continues through secure PayMongo checkout.' : 'Add your PayMongo public API key to enable direct GCash/Maya wallet authorization. Until then, all methods use secure PayMongo Hosted Checkout.' ?></p>
                        </fieldset>
                      <?php else: ?>
                        <div class="paymongo-gateway-card paymongo-unavailable" role="alert">
                          <div class="paymongo-gateway-icon" aria-hidden="true">!</div>
                          <div>
                            <strong>PayMongo checkout is not configured</strong>
                            <span>Online payment is temporarily unavailable. The customer checkout no longer falls back to manual QR/receipt verification.</span>
                          </div>
                        </div>
                      <?php endif; ?>

                      <div class="checkout-submit-area">
                        <p class="order-terms-note">Please review the total, fulfillment schedule, and payment method before placing your order. <a href="../terms-and-conditions.php" target="_blank" rel="noopener noreferrer">View store policies</a>.</p>
                        <div class="checkout-final-actions">
                          <button type="button" class="btn checkout-back" data-checkout-back="2">Back</button>
                          <button type="submit" class="btn primary checkout-submit-button" id="paymongoSubmitButton" <?= $usePaymongo ? '' : 'disabled aria-disabled="true"' ?>><?= $usePaymongo ? (paymongoLive() ? 'Continue with GCash' : 'Test with GCash') : 'PayMongo Unavailable' ?></button>
                        </div>
                      </div>
                    </section>
                  </div>
                </div>
              </section>
            </div>
          </section>
        </div>

        <div class="bottom-checkout-bar" id="bottomCheckoutBar" hidden>
          <div>
            <span id="bottomCartCount">0 items</span>
            <strong id="bottomCartTotal">PHP 0.00</strong>
          </div>
          <button type="button" class="btn primary" data-bottom-checkout>Review &amp; Checkout</button>
        </div>
      </form>
    <?php endif; ?>
    <?php if ($receipt): ?>
      <script>try{localStorage.removeItem('brille_customer_cart_<?= (int)$customer['id'] ?>');window.dispatchEvent(new CustomEvent('brille-commerce-change'));}catch(e){}</script>
    <?php endif; ?>

    <section class="messages-page customer-messages" id="messagesPage"
      data-api="messaging_api.php"
      data-csrf="<?= htmlspecialchars($csrfToken) ?>"
      data-role="customer">
      <button type="button" class="customer-chat-toggle" id="customerChatToggle" aria-expanded="false" aria-controls="customerChatPanel">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 5.5h16v11H8.5L4 20z" />
          <path d="M8 9.5h8" />
          <path d="M8 13h5" />
        </svg>
        <span>Messages</span>
        <em id="customerChatBadge" hidden>0</em>
      </button>
      <div class="messages-shell" id="customerChatPanel" hidden>
        <aside class="inbox-panel" id="inboxPanel">
          <div class="inbox-head">
            <div>
              <div class="messages-eyebrow">Customer inbox</div>
              <h1>Messages</h1>
              <p>Talk with Brille Amour about products or your order</p>
            </div>
            <button type="button" class="icon-btn" id="archiveViewToggle" title="Show archived conversations" aria-label="Show archived conversations" hidden>A</button>
          </div>
          <div class="inbox-search" hidden>
            <input type="search" id="messageSearch" placeholder="Search conversations..." autocomplete="off">
          </div>
          <div class="thread-list" id="pageThreadList">
            <p class="message-empty">Loading conversation...</p>
          </div>
        </aside>

        <main class="conversation-panel" id="conversationPanel">
          <header class="conversation-head" id="conversationHead">
            <button type="button" class="back-btn" id="messageBack">Back</button>
            <div class="chat-avatar">BA</div>
            <div class="conversation-title">
              <strong>Select a conversation</strong>
              <small>Send questions, artwork notes, or pickup updates.</small>
            </div>
            <div class="conversation-actions">
              <button type="button" class="icon-btn" id="markReadBtn" title="Mark as read" aria-label="Mark as read">R</button>
              <button type="button" class="icon-btn" id="archiveThreadBtn" title="Archive conversation" aria-label="Archive conversation" hidden>A</button>
              <button type="button" class="icon-btn danger" id="deleteThreadBtn" title="Delete conversation" aria-label="Delete conversation" hidden>D</button>
            </div>
          </header>

          <div class="message-log" id="pageMessageLog">
            <div class="empty-chat">
              <strong>No conversation selected</strong>
              <span>Choose a thread from the inbox.</span>
            </div>
          </div>

          <form class="message-composer" id="pageMessageComposer" autocomplete="off">
            <div class="customer-chatbot" id="customerChatbot" data-customer-bot>
              <div class="bot-head">
                <span>Brille Bot</span>
                <button type="button" class="bot-toggle" id="customerBotToggle" aria-expanded="true" aria-controls="customerBotInquiries">Hide inquiries</button>
              </div>
              <div id="customerBotInquiries">
                <div class="bot-quick-actions" aria-label="Chatbot quick questions">
                  <button type="button" data-bot-question="How do I place an order?">Order help</button>
                  <button type="button" data-bot-question="How much is the downpayment?">Payment</button>
                  <button type="button" data-bot-question="How can I track my order?">Tracking</button>
                  <button type="button" data-bot-question="Is Sept 14 2026 available for pickup?">Pickup Date</button>
                  <button type="button" data-bot-question="What products and stock are available?">Products</button>
                </div>
                <div class="bot-answer" id="customerBotAnswer" aria-live="polite">
                  Ask about products, prices, or a budget (e.g. budget PHP 500). Puwede Bisaya or Tagalog. Brille Bot gives automatic replies; staff can also respond here.
                </div>
              </div>
            </div>
            <div class="composer-tools" aria-label="Message tools" hidden></div>
            <textarea id="pageMessageInput" rows="1" placeholder="Type a message..." maxlength="2000" disabled></textarea>
            <button type="submit" id="pageMessageSend" disabled>Send</button>
          </form>
        </main>
      </div>
    </section>
  </main>
  <?php require __DIR__ . '/../includes/customer_chatbot_widget.php'; ?>


  <script>
    window.productCatalog = {};
    window.customerId = <?= (int)$customer['id'] ?>;
    window.customerCatalogApi = 'catalog_api.php';
    window.pickupCapacity = <?= json_encode($pickupCalendar, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.pickupCapacityApi = 'pickup_dates_api.php';
  </script>
  <script src="../assets/js/customer-order.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-order.js') ?>"></script>
  <script src="../assets/js/messaging-page.js?v=<?= filemtime(__DIR__ . '/../assets/js/messaging-page.js') ?>"></script>
  <script src="../assets/js/customer-chatbot.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-chatbot.js') ?>"></script>
  <script src="../assets/js/customer-floating-hub.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-floating-hub.js') ?>"></script>
  <script src="../assets/js/mobile-polish.js?v=<?= filemtime(__DIR__ . '/../assets/js/mobile-polish.js') ?>"></script>
<script>
(function () {
  var methodInputs = document.querySelectorAll('input[name="payment_method_choice"]');
  var submit = document.getElementById('paymongoSubmitButton');
  if (!methodInputs.length || !submit) return;
  var isTest = <?= $usePaymongo && !paymongoLive() ? 'true' : 'false' ?>;
  function syncPaymongoButton() {
    var selected = document.querySelector('input[name="payment_method_choice"]:checked');
    var label = selected ? selected.value : 'PayMongo';
    submit.textContent = (isTest ? 'Test with ' : 'Continue with ') + label;
  }
  methodInputs.forEach(function (input) { input.addEventListener('change', syncPaymongoButton); });
  syncPaymongoButton();
})();
</script>
<script src="../assets/js/public-ajax.js?v=<?= filemtime(__DIR__ . "/../assets/js/public-ajax.js") ?>"></script>
</body>

</html>
