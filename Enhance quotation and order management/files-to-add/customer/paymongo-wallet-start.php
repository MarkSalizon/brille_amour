<?php
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/db.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Payment not found.');
}

$checkout = paymongoFindByToken($conn, $token);
if (!$checkout) {
    http_response_code(404);
    exit('Payment not found.');
}

$return = 'paymongo-return.php?token=' . rawurlencode($token);

try {
    if (($checkout['flow_type'] ?? '') !== 'wallet') {
        header('Location: ' . $return, true, 303);
        exit;
    }
    $redirect = paymongoAuthorizeWallet($conn, (int)$checkout['payment_id']);
    if (!paymongoSafeExternalUrl($redirect)) {
        // A successful intent can return directly to our public HTTPS status URL.
        $base = rtrim((string)config_env('PAYMONGO_PUBLIC_URL'), '/');
        if (!str_starts_with($redirect, $base . '/')) {
            throw new RuntimeException('Wallet redirect is invalid.');
        }
    }
    header('Location: ' . $redirect, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('PayMongo direct wallet authorization failed for payment ' . (int)$checkout['payment_id']);
    header('Location: ' . $return . '&wallet_error=1', true, 303);
    exit;
}
