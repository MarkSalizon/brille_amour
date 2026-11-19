<?php
/**
 * Public Brille Bot API.
 * Read-only assistant for product questions, payment guide, pickup availability,
 * and verified order status checks using order number or customer name + contact.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/pickup_capacity.php';
require_once __DIR__ . '/../includes/chatbot_conversations.php';
require_once __DIR__ . '/../includes/messaging.php';

function brilleBotJson(array $payload, int $status = 200): void {
    $payload['generated_at'] = $payload['generated_at'] ?? date('c');
    $payload['realtime'] = $payload['realtime'] ?? true;
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function brilleBotLoggedJson(mysqli $conn, ?int $conversationId, string $customerMessage, string $mode, string $reply, array $extra = [], int $status = 200, bool $silent = false): void {
    if (!$silent && $conversationId) {
        $intent = $mode !== '' ? $mode : ($extra['intent'] ?? 'general');
        chatbotRecordMessage($conn, $conversationId, 'customer', $customerMessage !== '' ? $customerMessage : 'Customer opened Brille Bot', (string)$intent);
        chatbotRecordMessage($conn, $conversationId, 'bot', $reply, (string)$intent);
    }
    brilleBotJson(array_merge(['ok' => true, 'reply' => $reply], $extra), $status);
}

function brilleBotNormalizeContact(string $value): string {
    return preg_replace('/[^0-9a-z]/i', '', mb_strtolower(trim($value)));
}

function brilleBotStatusLabel(string $status): string {
    $status = strtolower(trim($status));
    $labels = [
        'pending' => 'Pending Payment',
        'confirmed' => 'Payment Verified',
        'approved' => 'Payment Verified',
        'payment_verified' => 'Payment Verified',
        'in_production' => 'In Production',
        'processing' => 'In Production',
        'ready' => 'Ready for Pickup',
        'ready_for_pickup' => 'Ready for Pickup',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'archived' => 'Archived',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function brilleBotOrderNumberFromMessage(string $message): string {
    if (preg_match('/\bBA-\d{3,6}\b/i', $message, $m)) {
        return strtoupper($m[0]);
    }
    if (preg_match('/\bBA-\d{8}-\d{3,6}\b/i', $message, $m)) {
        return strtoupper($m[0]);
    }
    return '';
}

function brilleBotDateFromMessage(string $message): string {
    if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $message, $m)) {
        return $m[1];
    }

    // Accept common messages like "Sept 14 2026", "September 14", "14 Sep 2026".
    if (preg_match('/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{1,2}(?:,?\s+20\d{2})?|\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*(?:,?\s+20\d{2})?)\b/i', $message, $m)) {
        $raw = $m[1];
        if (!preg_match('/20\d{2}/', $raw)) {
            $raw .= ' ' . date('Y');
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return '';
}

function brilleBotPaymentReply(): string {
    $methods = paymentMethodOptions();
    $methodsText = implode(', ', $methods);
    return "We accept {$methodsText}. Pickup orders require a 25% downpayment, while Delivery orders require full payment at checkout. After payment, wait for admin verification before production starts.";
}

function brilleBotProductsReply(mysqli $conn, string $message): string {
    ensureCatalogSchema($conn);
    $products = getActiveProductsList($conn);
    if (!$products) {
        return 'No active products are listed right now. Please message staff for the latest menu.';
    }

    $budget = null;
    if (preg_match('/(?:budget|under|below|ubos sa|hanggang|php|₱)\s*[:=]?\s*(?:php|₱)?\s*([0-9]+(?:,[0-9]{3})*(?:\.\d{1,2})?)/i', $message, $m)) {
        $budget = (float)str_replace(',', '', $m[1]);
    }

    if ($budget !== null) {
        $products = array_values(array_filter($products, static function (array $product) use ($budget): bool {
            return (float)$product['unit_price'] <= $budget;
        }));
    }

    usort($products, static fn(array $a, array $b): int => (float)$a['unit_price'] <=> (float)$b['unit_price']);
    $shown = array_slice($products, 0, 6);
    if (!$shown) {
        return 'No product matched that budget. Try a higher budget or ask staff for a custom quote.';
    }

    $lines = ['Here are our made-to-order products and prices:'];
    if ($budget !== null) {
        $lines[] = 'Budget per item: ' . formatMoney($budget);
    }
    foreach ($shown as $product) {
        $lines[] = $product['name'] . ' — ' . formatMoney((float)$product['unit_price']) . '/' . ($product['unit'] ?: 'piece') . ' — Made to order';
    }
    $lines[] = 'Open the Shop and tap Order or the cart icon to check out.';
    return implode("\n", $lines);
}

function brilleBotPickupReply(mysqli $conn, string $date): string {
    if ($date === '') {
        return 'Please choose or type a pickup date first, for example: Sept 14 2026 or 2026-09-14.';
    }

    $date = normalizePickupDate($date) ?? '';
    if ($date === '') {
        return 'That pickup date format is not valid. Please use YYYY-MM-DD, for example 2026-09-14.';
    }

    ensurePickupCapacitySchema($conn);
    $capacity = pickupCapacityForDate($conn, $date);
    $pretty = appDateTime($date, 'M j, Y');
    if (!empty($capacity['is_closed'])) {
        $note = trim((string)($capacity['note'] ?? ''));
        return $pretty . ' is closed for pickup' . ($note !== '' ? ' — ' . $note : '') . '. Please choose another date.';
    }
    if (!empty($capacity['fully_booked'])) {
        return $pretty . ' is already fully booked. Please choose another pickup date.';
    }
    $remaining = (int)$capacity['remaining'];
    $maxOrders = (int)$capacity['max_orders'];
    if ($remaining <= 3) {
        return $pretty . " is still available, but only {$remaining} slot" . ($remaining === 1 ? '' : 's') . " left out of {$maxOrders}.";
    }
    return $pretty . " is available for pickup. {$remaining} slots left out of {$maxOrders}.";
}

function brilleBotOrderHistory(mysqli $conn, string $customerName, string $contact, int $limit = 8): array {
    $customerName = trim($customerName);
    $contact = trim($contact);
    $limit = max(1, min(20, $limit));

    if ($customerName === '' || $contact === '') {
        return [];
    }

    $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_contact, order_type, quantity, status, due_date, pickup_time, total_amount, deposit_paid, balance, updated_at, created_at FROM orders WHERE LOWER(TRIM(COALESCE(customer_name, ''))) = LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(customer_contact, ''))) = LOWER(TRIM(?)) AND status <> 'archived' ORDER BY created_at DESC, id DESC LIMIT ?");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ssi', $customerName, $contact, $limit);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return $orders;
}

function brilleBotPickupLabelForOrder(array $order): string {
    if (empty($order['due_date']) || $order['due_date'] === '0000-00-00') {
        return 'pickup to be confirmed';
    }

    $pickup = appDateTime((string)$order['due_date'], 'M j, Y');
    if (!empty($order['pickup_time'])) {
        $pickup .= ' ' . date('g:i A', strtotime((string)$order['pickup_time']));
    }

    return $pickup;
}

function brilleBotOrderReply(mysqli $conn, string $orderNumber, string $contact, string $trackBy = 'order', string $customerName = ''): string {
    $trackBy = strtolower(trim($trackBy));
    $orderNumber = strtoupper(trim($orderNumber));
    $customerName = trim($customerName);
    $contact = trim($contact);

    if ($contact === '') {
        return 'For privacy, please enter the same contact number or handle used at checkout before I show the order status.';
    }

    if ($trackBy === 'name') {
        if ($customerName === '') {
            return 'Please enter the full name used at checkout.';
        }

        $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_contact, status, due_date, pickup_time, total_amount, deposit_paid, balance, updated_at, created_at FROM orders WHERE LOWER(TRIM(COALESCE(customer_name, ''))) = LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(customer_contact, ''))) = LOWER(TRIM(?)) AND status <> 'archived' ORDER BY created_at DESC, id DESC LIMIT 1");
        if (!$stmt) {
            return 'Order tracking is not available right now. Please try again later.';
        }
        $stmt->bind_param('ss', $customerName, $contact);
    } else {
        if ($orderNumber === '') {
            return 'Please enter your order number first, for example BA-001. You can also track by customer name.';
        }

        $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_contact, status, due_date, pickup_time, total_amount, deposit_paid, balance, updated_at, created_at FROM orders WHERE order_number = ? AND status <> 'archived' LIMIT 1");
        if (!$stmt) {
            return 'Order tracking is not available right now. Please try again later.';
        }
        $stmt->bind_param('s', $orderNumber);
    }

    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return $trackBy === 'name'
            ? 'No active order was found for that customer name. Please check the spelling or use your order number like BA-001.'
            : 'Order number not found. Please check if it looks like BA-001, or try tracking by customer name.';
    }

    $savedContact = brilleBotNormalizeContact((string)$order['customer_contact']);
    $givenContact = brilleBotNormalizeContact($contact);
    if ($savedContact === '' || $givenContact === '' || (!str_contains($savedContact, $givenContact) && !str_contains($givenContact, $savedContact))) {
        return 'The contact does not match this order. Please use the exact contact number or handle used at checkout.';
    }

    $history = brilleBotOrderHistory($conn, (string)$order['customer_name'], (string)$order['customer_contact']);
    $previous = array_values(array_filter($history, static function (array $historyOrder) use ($order): bool {
        return (int)($historyOrder['id'] ?? 0) !== (int)($order['id'] ?? 0);
    }));

    $lines = [];
    $prefix = $trackBy === 'name' ? 'Latest matching order ' : 'Order ';
    $lines[] = $prefix . $order['order_number'] . ' is currently: ' . brilleBotStatusLabel((string)$order['status']);
    $lines[] = 'Customer: ' . $order['customer_name'];
    $lines[] = 'Pickup: ' . brilleBotPickupLabelForOrder($order);
    $lines[] = 'Paid downpayment: ' . formatMoney((float)($order['deposit_paid'] ?? 0));
    $lines[] = 'Remaining balance: ' . formatMoney((float)($order['balance'] ?? 0));
    if (!empty($order['updated_at'])) {
        $lines[] = 'Last updated: ' . appDateTime($order['updated_at']);
    }

    if ($previous) {
        $lines[] = '';
        $lines[] = 'Previous orders found for this customer:';
        foreach (array_slice($previous, 0, 6) as $historyOrder) {
            $lines[] = '- ' . $historyOrder['order_number'] . ' — ' . brilleBotStatusLabel((string)$historyOrder['status']) . ' — ' . brilleBotPickupLabelForOrder($historyOrder) . ' — Balance: ' . formatMoney((float)($historyOrder['balance'] ?? 0));
        }
        if (count($previous) > 6) {
            $lines[] = 'Plus ' . (count($previous) - 6) . ' more previous order(s). Open My Orders in your account to view the full history.';
        }
    } else {
        $lines[] = '';
        $lines[] = 'No previous orders found yet for this customer.';
    }

    return implode("\n", $lines);
}

try {
    ensureChatbotConversationSchema($conn);

    $message = trim((string)($_POST['message'] ?? $_GET['message'] ?? ''));
    $mode = trim((string)($_POST['mode'] ?? $_GET['mode'] ?? ''));
    $trackBy = strtolower(trim((string)($_POST['track_by'] ?? $_GET['track_by'] ?? 'order')));
    $orderNumber = trim((string)($_POST['order_number'] ?? $_GET['order_number'] ?? ''));
    $customerName = trim((string)($_POST['customer_name'] ?? $_GET['customer_name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? $_GET['contact'] ?? ''));
    if ($trackBy !== 'name' && $trackBy !== 'order') {
        $trackBy = $customerName !== '' && $orderNumber === '' ? 'name' : 'order';
    }
    $date = trim((string)($_POST['date'] ?? $_GET['date'] ?? ''));
    $silent = (string)($_POST['silent'] ?? $_GET['silent'] ?? '') === '1';

    if ($orderNumber === '') {
        $orderNumber = brilleBotOrderNumberFromMessage($message);
    }
    if ($date === '') {
        $date = brilleBotDateFromMessage($message);
    }

    $conversationId = $silent ? null : chatbotGetOrCreateConversation($conn);
    if (!$silent && $conversationId) {
        chatbotUpdateConversationMeta($conn, $conversationId, [
            'contact' => $contact,
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'mode' => $mode,
        ]);
    }

    $text = mb_strtolower($message . ' ' . $mode);

    if ($mode === 'order_status' || $orderNumber !== '') {
        $reply = brilleBotOrderReply($conn, $orderNumber, $contact, $trackBy, $customerName);
        $logMessage = $trackBy === 'name'
            ? 'Track order by name: ' . ($customerName !== '' ? $customerName : '(missing customer name)')
            : 'Track order by number: ' . ($orderNumber !== '' ? $orderNumber : '(missing order number)');
        brilleBotLoggedJson($conn, $conversationId, $logMessage, 'order_status', $reply, [], 200, $silent);
    }

    if ($mode === 'pickup' || preg_match('/\b(pickup|available|availability|fully booked|closed|date|schedule|sept|sep|jan|feb|mar|apr|may|jun|jul|aug|oct|nov|dec)\b/i', $text)) {
        $reply = brilleBotPickupReply($conn, $date);
        $logMessage = 'Check pickup availability' . ($date !== '' ? ': ' . $date : '');
        brilleBotLoggedJson($conn, $conversationId, $logMessage, 'pickup', $reply, [], 200, $silent);
    }

    if ($mode === 'payment' || preg_match('/\b(payment|pay|gcash|maya|maribank|gotyme|seabank|bpi|bdo|unionbank|downpayment|receipt|bayad)\b/i', $text)) {
        $reply = brilleBotPaymentReply();
        brilleBotLoggedJson($conn, $conversationId, $message !== '' ? $message : 'Asked about payment methods', 'payment', $reply, [], 200, $silent);
    }

    if ($mode === 'products' || preg_match('/\b(product|products|menu|price|prices|stock|available|budget|recommend|suggest|pila|tagpila|magkano)\b/i', $text)) {
        $reply = brilleBotProductsReply($conn, $message);
        brilleBotLoggedJson($conn, $conversationId, $message !== '' ? $message : 'Asked about products and prices', 'products', $reply, [], 200, $silent);
    }

    if (preg_match('/\b(contact|staff|admin|human|agent|help)\b/i', $text)) {
        $reply = 'You can leave a message in the Messages panel after ordering or tracking your order. For urgent changes, include your order number, contact, and request details.';
        brilleBotLoggedJson($conn, $conversationId, $message, 'contact', $reply, [], 200, $silent);
    }

    if (preg_match('/\b(hi|hello|hey|kumusta|maayong)\b/i', $text)) {
        $reply = 'Hello! I can help with order status, pickup availability, payment methods, products, and basic ordering questions.';
        brilleBotLoggedJson($conn, $conversationId, $message, 'greeting', $reply, [], 200, $silent);
    }

    $reply = 'Ask me about order status, pickup availability, payment methods, products, or how to place an order.';
    brilleBotLoggedJson($conn, $conversationId, $message, $mode ?: 'general', $reply, [], 200, $silent);
} catch (Throwable $e) {
    brilleBotJson(['ok' => false, 'reply' => 'Brille Bot could not load that information right now. Please try again or message staff.', 'error' => 'bot_error'], 500);
}
