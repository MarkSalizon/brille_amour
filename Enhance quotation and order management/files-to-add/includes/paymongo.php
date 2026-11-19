<?php
require_once __DIR__ . '/config.php';

function paymongoColumnExists(mysqli $conn, string $column): bool {
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM paymongo_checkouts LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function paymongoIndexExists(mysqli $conn, string $index): bool {
    $index = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM paymongo_checkouts WHERE Key_name='$index'");
    return $result && $result->num_rows > 0;
}

function ensurePaymongoSchema(mysqli $conn): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $sql = "CREATE TABLE IF NOT EXISTS paymongo_checkouts (
        payment_id INT NOT NULL PRIMARY KEY,
        return_token CHAR(64) NOT NULL UNIQUE,
        session_id VARCHAR(100) NULL UNIQUE,
        checkout_url TEXT NULL,
        flow_type VARCHAR(20) NOT NULL DEFAULT 'hosted',
        payment_intent_id VARCHAR(100) NULL,
        client_key VARCHAR(255) NULL,
        payment_method_id VARCHAR(100) NULL,
        redirect_url TEXT NULL,
        state VARCHAR(30) NOT NULL DEFAULT 'new',
        livemode TINYINT(1) NOT NULL,
        paid_payment_id VARCHAR(100) NULL UNIQUE,
        last_checked_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payment_id) REFERENCES checkout_payments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        throw new RuntimeException('PayMongo checkout storage could not be initialized.');
    }

    // Existing installations are upgraded in place.
    $columns = [
        'flow_type' => "ALTER TABLE paymongo_checkouts ADD COLUMN flow_type VARCHAR(20) NOT NULL DEFAULT 'hosted' AFTER checkout_url",
        'payment_intent_id' => "ALTER TABLE paymongo_checkouts ADD COLUMN payment_intent_id VARCHAR(100) NULL AFTER flow_type",
        'client_key' => "ALTER TABLE paymongo_checkouts ADD COLUMN client_key VARCHAR(255) NULL AFTER payment_intent_id",
        'payment_method_id' => "ALTER TABLE paymongo_checkouts ADD COLUMN payment_method_id VARCHAR(100) NULL AFTER client_key",
        'redirect_url' => "ALTER TABLE paymongo_checkouts ADD COLUMN redirect_url TEXT NULL AFTER payment_method_id",
        'last_checked_at' => "ALTER TABLE paymongo_checkouts ADD COLUMN last_checked_at DATETIME NULL AFTER paid_payment_id",
    ];
    foreach ($columns as $column => $alter) {
        if (!paymongoColumnExists($conn, $column) && !$conn->query($alter)) {
            throw new RuntimeException('PayMongo checkout storage could not be upgraded.');
        }
    }
    if (!paymongoIndexExists($conn, 'idx_paymongo_payment_intent')
        && !$conn->query('ALTER TABLE paymongo_checkouts ADD INDEX idx_paymongo_payment_intent (payment_intent_id)')) {
        throw new RuntimeException('PayMongo checkout storage index could not be created.');
    }
}

function paymongoLive(): bool {
    return str_starts_with((string)config_env('PAYMONGO_SECRET_KEY'), 'sk_live_');
}

function paymongoReady(): bool {
    $base = rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/');
    $secret = (string)config_env('PAYMONGO_SECRET_KEY');
    $requireWebhook = config_env('PAYMONGO_REQUIRE_WEBHOOK', '1') === '1';
    $webhookReady = !$requireWebhook || config_env('PAYMONGO_WEBHOOK_SECRET') !== '';

    $validBase = filter_var($base, FILTER_VALIDATE_URL) !== false;
    $scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($base, PHP_URL_HOST));

    // Production/live PayMongo must always use a public HTTPS return URL.
    // For local XAMPP testing only, allow HTTP localhost when the app is in
    // development mode and PayMongo TEST keys are being used.
    $isLocalDev = APP_ENV === 'development'
        && str_starts_with($secret, 'sk_test_')
        && $scheme === 'http'
        && in_array($host, ['localhost', '127.0.0.1', '::1'], true);

    $returnUrlReady = $validBase && ($scheme === 'https' || $isLocalDev);

    return config_env('PAYMONGO_ENABLED', '0') === '1'
        && preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $secret)
        && $webhookReady
        && $returnUrlReady;
}

function paymongoDirectWalletReady(): bool {
    if (!paymongoReady()) { return false; }
    $public = (string)config_env('PAYMONGO_PUBLIC_KEY');
    $mode = paymongoLive() ? 'live' : 'test';
    return preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $public) === 1
        && str_starts_with($public, 'pk_' . $mode . '_');
}

function paymongoCheckoutUrl(string $url): bool {
    return parse_url($url, PHP_URL_SCHEME) === 'https'
        && parse_url($url, PHP_URL_HOST) === 'checkout.paymongo.com'
        && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_PASS);
}

function paymongoSafeExternalUrl(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && parse_url($url, PHP_URL_SCHEME) === 'https'
        && (string)parse_url($url, PHP_URL_HOST) !== ''
        && !parse_url($url, PHP_URL_USER)
        && !parse_url($url, PHP_URL_PASS);
}

function paymongoApiRequest(string $key, string $method, string $path, ?array $payload = null, array $headers = []): array {
    if (!preg_match('/^(sk|pk)_(test|live)_[A-Za-z0-9]+$/', $key)) {
        throw new RuntimeException('PayMongo is not configured.');
    }
    $url = 'https://api.paymongo.com/v1/' . ltrim($path, '/');
    $httpHeaders = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $key . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw === false || $status < 200 || $status >= 300) {
        // Never expose provider response bodies or credentials to the browser/logs.
        throw new RuntimeException('PayMongo request could not be completed (HTTP ' . $status . ').');
    }
    $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response['data'] ?? null)) {
        throw new RuntimeException('Invalid PayMongo response.');
    }
    return $response['data'];
}

function paymongoRequest(string $method, string $path, ?array $payload = null, array $headers = []): array {
    return paymongoApiRequest((string)config_env('PAYMONGO_SECRET_KEY'), $method, $path, $payload, $headers);
}

function paymongoPublicRequest(string $method, string $path, ?array $payload = null, array $headers = []): array {
    if (!paymongoDirectWalletReady()) {
        throw new RuntimeException('Direct wallet checkout is not configured.');
    }
    return paymongoApiRequest((string)config_env('PAYMONGO_PUBLIC_KEY'), $method, $path, $payload, $headers);
}

function paymongoFind(mysqli $conn, int $paymentId): ?array {
    ensurePaymongoSchema($conn);
    $stmt = $conn->prepare("SELECT g.*,p.reference_number,p.amount,p.payment_method,p.payment_method_other,p.status AS payment_status,(SELECT COALESCE(NULLIF(o.fulfillment_method,''),'pickup') FROM checkout_payment_orders po JOIN orders o ON o.id=po.order_id WHERE po.payment_id=p.id ORDER BY o.id LIMIT 1) AS fulfillment_method FROM paymongo_checkouts g JOIN checkout_payments p ON p.id=g.payment_id WHERE g.payment_id=?");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function paymongoFindByToken(mysqli $conn, string $token): ?array {
    ensurePaymongoSchema($conn);
    $stmt = $conn->prepare('SELECT g.payment_id FROM paymongo_checkouts g WHERE g.return_token=?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? paymongoFind($conn, (int)$row['payment_id']) : null;
}

function paymongoPaymentMethods(): array {
    return [
        'GCash' => ['type' => 'gcash', 'label' => 'GCash'],
        'Maya' => ['type' => 'paymaya', 'label' => 'Maya'],
        'QR Ph' => ['type' => 'qrph', 'label' => 'QR Ph'],
        'Card' => ['type' => 'card', 'label' => 'Visa / Mastercard'],
    ];
}

function paymongoMethodFromChoice(string $choice): array {
    $methods = paymongoPaymentMethods();
    if (!isset($methods[$choice])) {
        throw new RuntimeException('Choose a valid PayMongo payment method.');
    }
    return $methods[$choice];
}

function paymongoMethodLabelFromType(string $type): string {
    foreach (paymongoPaymentMethods() as $method) {
        if ($method['type'] === $type) { return $method['label']; }
    }
    return 'PayMongo';
}

function paymongoDirectWalletChoice(string $choice): bool {
    return in_array($choice, ['GCash', 'Maya'], true);
}

function paymongoPayload(array $checkout): array {
    $amount = (int)round((float)$checkout['amount'] * 100);
    if ($amount < 100 || $amount > 10000000) {
        throw new RuntimeException('Online checkout requires a payment between PHP 1 and PHP 100,000.');
    }
    $isDelivery = strtolower((string)($checkout['fulfillment_method'] ?? 'pickup')) === 'delivery';
    $paymentName = $isDelivery ? 'Brille Amour - Full payment' : 'Brille Amour - 25% downpayment';
    $paymentDescription = ($isDelivery ? 'Full payment for checkout ' : 'Downpayment for checkout ') . $checkout['reference_number'];
    $return = rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/') . '/customer/paymongo-return.php?token=' . $checkout['return_token'];
    $method = paymongoMethodFromChoice((string)($checkout['payment_method'] ?? 'GCash'));
    return ['data' => ['attributes' => [
        'line_items' => [[
            'name' => $paymentName,
            'amount' => $amount,
            'currency' => 'PHP',
            'quantity' => 1,
        ]],
        'payment_method_types' => [$method['type']],
        'reference_number' => $checkout['reference_number'],
        'description' => $paymentDescription,
        'success_url' => $return,
        'cancel_url' => $return . '&cancelled=1',
        'send_email_receipt' => false,
        'show_description' => true,
        'show_line_items' => true,
    ]]];
}

function paymongoWalletIntentPayload(array $checkout): array {
    $amount = (int)round((float)$checkout['amount'] * 100);
    if ($amount < 100 || $amount > 10000000) {
        throw new RuntimeException('Online checkout requires a payment between PHP 1 and PHP 100,000.');
    }
    $method = paymongoMethodFromChoice((string)($checkout['payment_method'] ?? ''));
    if (!paymongoDirectWalletChoice((string)$checkout['payment_method'])) {
        throw new RuntimeException('Direct wallet checkout is only available for GCash and Maya.');
    }
    return ['data' => ['attributes' => [
        'amount' => $amount,
        'currency' => 'PHP',
        'payment_method_allowed' => [$method['type']],
        'description' => (strtolower((string)($checkout['fulfillment_method'] ?? 'pickup')) === 'delivery' ? 'Brille Amour full payment ' : 'Brille Amour downpayment ') . $checkout['reference_number'],
        'metadata' => ['reference_number' => (string)$checkout['reference_number']],
    ]]];
}

function paymongoWalletLocalUrl(array $checkout): string {
    return rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/')
        . '/customer/paymongo-wallet-start.php?token=' . rawurlencode((string)$checkout['return_token']);
}

function paymongoStartWallet(mysqli $conn, int $paymentId): string {
    ensurePaymongoSchema($conn);
    if (!paymongoDirectWalletReady()) {
        // Safe fallback: Hosted Checkout still accepts the selected wallet.
        return paymongoStart($conn, $paymentId);
    }
    $checkout = paymongoFind($conn, $paymentId);
    if (!$checkout || (bool)$checkout['livemode'] !== paymongoLive()) {
        throw new RuntimeException('Payment configuration has changed.');
    }
    if (!paymongoDirectWalletChoice((string)$checkout['payment_method'])) {
        return paymongoStart($conn, $paymentId);
    }
    if (!empty($checkout['payment_intent_id']) && !empty($checkout['client_key'])) {
        return paymongoWalletLocalUrl($checkout);
    }

    $payload = paymongoWalletIntentPayload($checkout);
    $data = paymongoRequest('POST', 'payment_intents', $payload, [
        'Idempotency-Key: brille-wallet-intent-' . $paymentId,
    ]);
    $id = (string)($data['id'] ?? '');
    $attrs = $data['attributes'] ?? [];
    $clientKey = (string)($attrs['client_key'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $id)
        || $clientKey === ''
        || ($attrs['livemode'] ?? null) !== paymongoLive()
        || ($attrs['currency'] ?? '') !== 'PHP') {
        throw new RuntimeException('Unexpected PayMongo wallet response.');
    }
    $flow = 'wallet';
    $state = 'open';
    $stmt = $conn->prepare('UPDATE paymongo_checkouts SET flow_type=?,payment_intent_id=?,client_key=?,state=? WHERE payment_id=?');
    $stmt->bind_param('ssssi', $flow, $id, $clientKey, $state, $paymentId);
    $stmt->execute();
    $checkout['return_token'] = (string)$checkout['return_token'];
    return paymongoWalletLocalUrl($checkout);
}

function paymongoStart(mysqli $conn, int $paymentId): string {
    ensurePaymongoSchema($conn);
    if (!paymongoReady()) { throw new RuntimeException('PayMongo is not configured.'); }
    $checkout = paymongoFind($conn, $paymentId);
    if (!$checkout || (bool)$checkout['livemode'] !== paymongoLive()) {
        throw new RuntimeException('Payment configuration has changed.');
    }
    if ($checkout['checkout_url'] && paymongoCheckoutUrl($checkout['checkout_url'])) {
        return $checkout['checkout_url'];
    }
    $payload = paymongoPayload($checkout);
    $stmt = $conn->prepare("UPDATE paymongo_checkouts SET flow_type='hosted',state='creating' WHERE payment_id=? AND state='new'");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('Checkout needs reconciliation before another payment attempt.');
    }
    $data = paymongoRequest('POST', 'checkout_sessions', $payload, [
        'Idempotency-Key: brille-hosted-checkout-' . $paymentId,
    ]);
    $id = (string)($data['id'] ?? '');
    $url = (string)($data['attributes']['checkout_url'] ?? '');
    if (!preg_match('/^cs_[A-Za-z0-9]+$/', $id)
        || !paymongoCheckoutUrl($url)
        || ($data['attributes']['livemode'] ?? null) !== paymongoLive()) {
        throw new RuntimeException('Unexpected PayMongo checkout response.');
    }
    $stmt = $conn->prepare("UPDATE paymongo_checkouts SET session_id=?,checkout_url=?,state=IF(state='creating','open',state) WHERE payment_id=? AND (session_id IS NULL OR session_id=?)");
    $stmt->bind_param('ssis', $id, $url, $paymentId, $id);
    $stmt->execute();
    return $url;
}

function paymongoAuthorizeWallet(mysqli $conn, int $paymentId): string {
    ensurePaymongoSchema($conn);
    if (!paymongoDirectWalletReady()) {
        throw new RuntimeException('Direct wallet checkout is not configured.');
    }
    $checkout = paymongoFind($conn, $paymentId);
    if (!$checkout || ($checkout['flow_type'] ?? '') !== 'wallet'
        || !paymongoDirectWalletChoice((string)$checkout['payment_method'])
        || (bool)$checkout['livemode'] !== paymongoLive()) {
        throw new RuntimeException('Wallet checkout is unavailable.');
    }
    if (in_array((string)$checkout['state'], ['paid', 'test_paid', 'review'], true)) {
        return rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/')
            . '/customer/paymongo-return.php?token=' . rawurlencode((string)$checkout['return_token']);
    }
    if (!empty($checkout['redirect_url']) && paymongoSafeExternalUrl((string)$checkout['redirect_url'])) {
        return (string)$checkout['redirect_url'];
    }
    $intentId = (string)$checkout['payment_intent_id'];
    $clientKey = (string)$checkout['client_key'];
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intentId) || $clientKey === '') {
        throw new RuntimeException('Wallet payment intent is unavailable.');
    }

    $method = paymongoMethodFromChoice((string)$checkout['payment_method']);
    $pm = paymongoPublicRequest('POST', 'payment_methods', [
        'data' => ['attributes' => ['type' => $method['type']]],
    ]);
    $pmId = (string)($pm['id'] ?? '');
    if (!preg_match('/^pm_[A-Za-z0-9]+$/', $pmId)) {
        throw new RuntimeException('Wallet payment method could not be created.');
    }

    $return = rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/')
        . '/customer/paymongo-return.php?token=' . rawurlencode((string)$checkout['return_token']);
    $intent = paymongoPublicRequest('POST', 'payment_intents/' . rawurlencode($intentId) . '/attach', [
        'data' => ['attributes' => [
            'payment_method' => $pmId,
            'client_key' => $clientKey,
            'return_url' => $return,
        ]],
    ]);
    if (($intent['id'] ?? '') !== $intentId || ($intent['type'] ?? '') !== 'payment_intent') {
        throw new RuntimeException('Wallet payment intent mismatch.');
    }
    $attrs = $intent['attributes'] ?? [];
    $status = (string)($attrs['status'] ?? '');
    if ($status === 'succeeded') {
        paymongoSettleIntent($conn, $paymentId, $intent);
        return $return;
    }
    if ($status !== 'awaiting_next_action') {
        throw new RuntimeException('Wallet authorization could not be started.');
    }
    $redirect = (string)($attrs['next_action']['redirect']['url'] ?? '');
    if (!paymongoSafeExternalUrl($redirect)) {
        throw new RuntimeException('Wallet authorization URL is invalid.');
    }
    $stmt = $conn->prepare('UPDATE paymongo_checkouts SET payment_method_id=?,redirect_url=? WHERE payment_id=?');
    $stmt->bind_param('ssi', $pmId, $redirect, $paymentId);
    $stmt->execute();
    return $redirect;
}

function paymongoSignatureValid(string $body, string $header, string $secret, bool $live, ?int $now = null): bool {
    if ($secret === '') { return false; }
    $parts = [];
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) { $parts[$pair[0]] = $pair[1]; }
    }
    $timestamp = $parts['t'] ?? '';
    if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300) { return false; }
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $parts[$live ? 'li' : 'te'] ?? '');
}

function paymongoPaidPayment(array $session, array $checkout): ?string {
    $attrs = $session['attributes'] ?? [];
    if (($session['type'] ?? '') !== 'checkout_session'
        || !preg_match('/^cs_[A-Za-z0-9]+$/', (string)($session['id'] ?? ''))
        || (!empty($checkout['session_id']) && $checkout['session_id'] !== $session['id'])
        || ($attrs['reference_number'] ?? '') !== $checkout['reference_number']
        || ($attrs['livemode'] ?? null) !== (bool)$checkout['livemode']) {
        throw new RuntimeException('PayMongo checkout identity or mode mismatch.');
    }
    foreach ($attrs['payments'] ?? [] as $payment) {
        $paid = $payment['attributes'] ?? [];
        if (($paid['status'] ?? '') !== 'paid') { continue; }
        $expectedMethod = paymongoMethodFromChoice((string)($checkout['payment_method'] ?? 'GCash'));
        $actualMethod = (string)($paid['source']['type'] ?? $paid['payment_method_used'] ?? '');
        if (($paid['currency'] ?? '') !== 'PHP'
            || ($paid['amount'] ?? null) !== (int)round((float)$checkout['amount'] * 100)
            || ($paid['livemode'] ?? null) !== (bool)$checkout['livemode']
            || $actualMethod !== $expectedMethod['type']
            || !preg_match('/^pay_[A-Za-z0-9]+$/', (string)($payment['id'] ?? ''))) {
            throw new RuntimeException('PayMongo payment amount, method or currency mismatch.');
        }
        return $payment['id'];
    }
    return null;
}

function paymongoPaidPaymentFromIntent(array $intent, array $checkout): ?string {
    $attrs = $intent['attributes'] ?? [];
    $intentId = (string)($intent['id'] ?? '');
    if (($intent['type'] ?? '') !== 'payment_intent'
        || !preg_match('/^pi_[A-Za-z0-9]+$/', $intentId)
        || $intentId !== (string)$checkout['payment_intent_id']
        || ($attrs['currency'] ?? '') !== 'PHP'
        || ($attrs['amount'] ?? null) !== (int)round((float)$checkout['amount'] * 100)
        || ($attrs['livemode'] ?? null) !== (bool)$checkout['livemode']) {
        throw new RuntimeException('PayMongo payment intent identity or amount mismatch.');
    }
    if (($attrs['status'] ?? '') !== 'succeeded') { return null; }
    $expectedMethod = paymongoMethodFromChoice((string)$checkout['payment_method']);
    if (!in_array($expectedMethod['type'], (array)($attrs['payment_method_allowed'] ?? []), true)) {
        throw new RuntimeException('PayMongo payment intent method mismatch.');
    }
    foreach ($attrs['payments'] ?? [] as $payment) {
        $paid = $payment['attributes'] ?? [];
        $actualMethod = (string)($paid['source']['type'] ?? $paid['payment_method_used'] ?? '');
        if (($paid['status'] ?? '') === 'paid'
            && ($paid['currency'] ?? '') === 'PHP'
            && ($paid['amount'] ?? null) === (int)round((float)$checkout['amount'] * 100)
            && ($paid['livemode'] ?? null) === (bool)$checkout['livemode']
            && (string)($paid['payment_intent_id'] ?? $intentId) === $intentId
            && $actualMethod === $expectedMethod['type']
            && preg_match('/^pay_[A-Za-z0-9]+$/', (string)($payment['id'] ?? ''))) {
            return (string)$payment['id'];
        }
    }
    return null;
}

function paymongoValidatePaymentEvent(array $payment, array $checkout): string {
    $attrs = $payment['attributes'] ?? [];
    $paidId = (string)($payment['id'] ?? '');
    $expectedMethod = paymongoMethodFromChoice((string)$checkout['payment_method']);
    $actualMethod = (string)($attrs['source']['type'] ?? $attrs['payment_method_used'] ?? '');
    if (($payment['type'] ?? '') !== 'payment'
        || !preg_match('/^pay_[A-Za-z0-9]+$/', $paidId)
        || ($attrs['status'] ?? '') !== 'paid'
        || ($attrs['currency'] ?? '') !== 'PHP'
        || ($attrs['amount'] ?? null) !== (int)round((float)$checkout['amount'] * 100)
        || ($attrs['livemode'] ?? null) !== (bool)$checkout['livemode']
        || (string)($attrs['payment_intent_id'] ?? '') !== (string)$checkout['payment_intent_id']
        || $actualMethod !== $expectedMethod['type']) {
        throw new RuntimeException('PayMongo payment event mismatch.');
    }
    return $paidId;
}

function paymongoFinalizeConfirmedPayment(mysqli $conn, int $paymentId, array $checkout, string $paidId, ?string $sessionId = null, ?string $intentId = null): bool {
    if (in_array((string)$checkout['state'], ['paid', 'test_paid', 'review'], true)) {
        return true;
    }

    $stmt = $conn->prepare('SELECT o.*,po.amount AS allocated FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id WHERE po.payment_id=? ORDER BY o.id FOR UPDATE');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $review = !$orders || (int)round(array_sum(array_column($orders, 'allocated')) * 100) !== (int)round((float)$checkout['amount'] * 100);
    foreach ($orders as $order) {
        $review = $review || in_array($order['status'], ['cancelled', 'archived'], true)
            || (float)$order['deposit_paid'] > 0
            || (float)$order['total_amount'] < (float)$order['allocated'];
    }

    $state = !$checkout['livemode'] ? 'test_paid' : ($review ? 'review' : 'paid');
    $note = [
        'test_paid' => 'PayMongo test payment confirmed. Waiting for admin verification before production starts.',
        'review' => 'PayMongo received payment, but the linked order data needs admin reconciliation before verification.',
        'paid' => 'PayMongo ' . paymongoMethodFromChoice($checkout['payment_method'])['label'] . ' payment confirmed. Waiting for admin verification before production starts.',
    ][$state];

    foreach ($orders as $order) {
        $oldStatus = (string)$order['status'];
        $stmt = $conn->prepare('INSERT INTO production_logs (order_id,status_from,status_to,notes,updated_by) VALUES (?,?,?,?,NULL)');
        $stmt->bind_param('isss', $order['id'], $oldStatus, $oldStatus, $note);
        $stmt->execute();
    }

    $status = 'pending';
    $stmt = $conn->prepare('UPDATE checkout_payments SET status=?,review_note=?,reviewed_by=NULL,reviewed_at=NULL WHERE id=?');
    $stmt->bind_param('ssi', $status, $note, $paymentId);
    $stmt->execute();

    if ($intentId !== null) {
        $stmt = $conn->prepare('UPDATE paymongo_checkouts SET state=?,payment_intent_id=?,paid_payment_id=?,redirect_url=NULL WHERE payment_id=?');
        $stmt->bind_param('sssi', $state, $intentId, $paidId, $paymentId);
    } else {
        $sessionId = (string)$sessionId;
        $stmt = $conn->prepare('UPDATE paymongo_checkouts SET state=?,session_id=?,paid_payment_id=? WHERE payment_id=?');
        $stmt->bind_param('sssi', $state, $sessionId, $paidId, $paymentId);
    }
    $stmt->execute();
    return true;
}

// Only call with a server-retrieved session or a signature-verified checkout webhook.
function paymongoSettle(mysqli $conn, int $paymentId, array $session): bool {
    ensurePaymongoSchema($conn);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT g.*,p.amount,p.reference_number,p.payment_method,p.payment_method_other,p.status AS payment_status FROM checkout_payments p JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE p.id=? FOR UPDATE');
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $checkout = $stmt->get_result()->fetch_assoc();
        if (!$checkout) { throw new RuntimeException('Unknown PayMongo payment.'); }
        $paidId = paymongoPaidPayment($session, $checkout);
        if (!$paidId) { $conn->commit(); return false; }
        paymongoFinalizeConfirmedPayment($conn, $paymentId, $checkout, $paidId, (string)$session['id'], null);
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// Direct GCash/Maya Payment Intent settlement after server retrieval.
function paymongoSettleIntent(mysqli $conn, int $paymentId, array $intent): bool {
    ensurePaymongoSchema($conn);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT g.*,p.amount,p.reference_number,p.payment_method,p.payment_method_other,p.status AS payment_status FROM checkout_payments p JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE p.id=? FOR UPDATE');
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $checkout = $stmt->get_result()->fetch_assoc();
        if (!$checkout) { throw new RuntimeException('Unknown PayMongo payment.'); }
        $paidId = paymongoPaidPaymentFromIntent($intent, $checkout);
        if (!$paidId) { $conn->commit(); return false; }
        paymongoFinalizeConfirmedPayment($conn, $paymentId, $checkout, $paidId, null, (string)$intent['id']);
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// Direct GCash/Maya settlement from a signature-verified payment.paid webhook.
function paymongoSettlePaymentEvent(mysqli $conn, int $paymentId, array $payment): bool {
    ensurePaymongoSchema($conn);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT g.*,p.amount,p.reference_number,p.payment_method,p.payment_method_other,p.status AS payment_status FROM checkout_payments p JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE p.id=? FOR UPDATE');
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $checkout = $stmt->get_result()->fetch_assoc();
        if (!$checkout) { throw new RuntimeException('Unknown PayMongo payment.'); }
        $paidId = paymongoValidatePaymentEvent($payment, $checkout);
        paymongoFinalizeConfirmedPayment($conn, $paymentId, $checkout, $paidId, null, (string)$checkout['payment_intent_id']);
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
