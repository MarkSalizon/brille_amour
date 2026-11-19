<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_settings.php';
require_once __DIR__ . '/../includes/pickup_capacity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    ensurePickupCapacitySchema($conn);
    $days = max(14, min(365, (int)($_GET['days'] ?? 120)));
    $pickupDates = pickupCalendarSnapshot($conn, $days);
    echo json_encode([
        'ok' => true,
        'generated_at' => date('c'),
        'default_capacity' => pickupDefaultCapacity($conn),
        'dates' => $pickupDates,
        'delivery_dates' => deliveryCalendarSnapshot($conn, $pickupDates),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Date availability could not be loaded. Please try again.',
    ]);
}
