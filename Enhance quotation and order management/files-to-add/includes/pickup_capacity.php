<?php
/**
 * Pickup date capacity helpers.
 * Uses orders.due_date as the pickup date so existing order pages stay compatible.
 */

function ensurePickupCapacitySchema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!$conn->query("CREATE TABLE IF NOT EXISTS pickup_capacity (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pickup_date DATE NOT NULL UNIQUE,
        max_orders INT NOT NULL DEFAULT 20,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pickup_capacity_date (pickup_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Pickup capacity storage could not be initialized.');
    }

    // Safe upgrades for older copies where pickup_capacity may already exist
    // but does not yet have the close-date fields used by the live calendar.
    $conn->query("ALTER TABLE pickup_capacity ADD COLUMN IF NOT EXISTS is_closed TINYINT(1) NOT NULL DEFAULT 0 AFTER max_orders");
    $conn->query("ALTER TABLE pickup_capacity ADD COLUMN IF NOT EXISTS note VARCHAR(255) NOT NULL DEFAULT '' AFTER is_closed");
    $conn->query("ALTER TABLE pickup_capacity ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $conn->query("ALTER TABLE pickup_capacity ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

function pickupDefaultCapacity(mysqli $conn): int
{
    if (function_exists('getSettingInt')) {
        return max(1, getSettingInt($conn, 'pickup_daily_capacity', 20));
    }
    return 20;
}

function normalizePickupDate(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return null;
    }
    return $date;
}

function pickupCapacityForDate(mysqli $conn, string $date, int $excludeOrderId = 0): array
{
    ensurePickupCapacitySchema($conn);
    $date = normalizePickupDate($date) ?? '';
    if ($date === '') {
        return ['date' => '', 'max_orders' => pickupDefaultCapacity($conn), 'current_orders' => 0, 'remaining' => pickupDefaultCapacity($conn), 'is_closed' => false, 'fully_booked' => false, 'status' => 'available', 'note' => ''];
    }

    $defaultCapacity = pickupDefaultCapacity($conn);
    $row = null;
    $stmt = $conn->prepare('SELECT max_orders,is_closed,note FROM pickup_capacity WHERE pickup_date=? LIMIT 1');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $maxOrders = max(1, (int)($row['max_orders'] ?? $defaultCapacity));
    $isClosed = !empty($row['is_closed']);
    $note = (string)($row['note'] ?? '');

    if ($excludeOrderId > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE due_date=? AND id<>? AND status NOT IN ('cancelled','archived','completed') AND (fulfillment_method IS NULL OR fulfillment_method <> 'delivery')");
        $stmt->bind_param('si', $date, $excludeOrderId);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE due_date=? AND status NOT IN ('cancelled','archived','completed') AND (fulfillment_method IS NULL OR fulfillment_method <> 'delivery')");
        $stmt->bind_param('s', $date);
    }
    $stmt->execute();
    $current = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $remaining = max(0, $maxOrders - $current);
    $fullyBooked = $isClosed || $remaining <= 0;
    $status = $fullyBooked ? 'full' : ($remaining <= 3 ? 'almost_full' : 'available');

    return [
        'date' => $date,
        'max_orders' => $maxOrders,
        'current_orders' => $current,
        'remaining' => $remaining,
        'is_closed' => $isClosed,
        'fully_booked' => $fullyBooked,
        'status' => $status,
        'note' => $note,
    ];
}

function pickupCalendarSnapshot(mysqli $conn, int $days = 120): array
{
    ensurePickupCapacitySchema($conn);
    $days = max(7, min(365, $days));
    $todayObj = new DateTime('today');
    $today = $todayObj->format('Y-m-d');
    $endObj = (clone $todayObj)->modify('+' . ($days - 1) . ' days');
    $end = $endObj->format('Y-m-d');

    // Start with every date so the customer calendar can visibly label each
    // day as Available, Full, or Closed instead of only returning exceptions.
    $snapshots = [];
    for ($i = 0; $i < $days; $i++) {
        $date = (clone $todayObj)->modify('+' . $i . ' days')->format('Y-m-d');
        $snapshots[$date] = [
            'date' => $date,
            'current_orders' => 0,
        ];
    }

    $stmt = $conn->prepare("SELECT due_date, COUNT(*) AS total FROM orders WHERE due_date BETWEEN ? AND ? AND status NOT IN ('cancelled','archived','completed') AND (fulfillment_method IS NULL OR fulfillment_method <> 'delivery') GROUP BY due_date");
    $stmt->bind_param('ss', $today, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $date = (string)$row['due_date'];
        if (isset($snapshots[$date])) $snapshots[$date]['current_orders'] = (int)$row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT pickup_date,max_orders,is_closed,note FROM pickup_capacity WHERE pickup_date BETWEEN ? AND ?');
    $stmt->bind_param('ss', $today, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $date = (string)$row['pickup_date'];
        if (!isset($snapshots[$date])) continue;
        $snapshots[$date]['max_orders'] = max(1, (int)$row['max_orders']);
        $snapshots[$date]['is_closed'] = (bool)$row['is_closed'];
        $snapshots[$date]['note'] = (string)$row['note'];
    }
    $stmt->close();

    $defaultCapacity = pickupDefaultCapacity($conn);
    foreach ($snapshots as $date => $row) {
        $maxOrders = max(1, (int)($row['max_orders'] ?? $defaultCapacity));
        $current = (int)($row['current_orders'] ?? 0);
        $closed = !empty($row['is_closed']);
        $remaining = max(0, $maxOrders - $current);
        $full = !$closed && $remaining <= 0;
        $snapshots[$date] = [
            'date' => $date,
            'max_orders' => $maxOrders,
            'current_orders' => $current,
            'remaining' => $remaining,
            'is_closed' => $closed,
            'fully_booked' => $closed || $full,
            'status' => $closed ? 'closed' : ($full ? 'full' : 'available'),
            'note' => (string)($row['note'] ?? ''),
        ];
    }

    ksort($snapshots);
    return array_values($snapshots);
}

function validatePickupDateAvailable(mysqli $conn, ?string $date, int $ordersToAdd = 1, int $excludeOrderId = 0): void
{
    $date = normalizePickupDate($date);
    if ($date === null) {
        return;
    }

    $today = (new DateTime('today'))->format('Y-m-d');
    if ($date < $today) {
        throw new RuntimeException('Pickup date cannot be in the past. Please choose another date.');
    }

    $capacity = pickupCapacityForDate($conn, $date, $excludeOrderId);
    if ($capacity['is_closed']) {
        throw new RuntimeException('Pickup date ' . $date . ' is closed. Please choose another available date.');
    }
    if ((int)$capacity['remaining'] < max(1, $ordersToAdd)) {
        throw new RuntimeException('Pickup date ' . $date . ' is already fully booked. Please choose another available date.');
    }
}


/** Delivery uses the configured daily date limit and shop closure dates, with its own order count. */
function deliveryCapacityForDate(mysqli $conn, string $date): array
{
    $capacity = pickupCapacityForDate($conn, $date);
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE due_date=? AND status NOT IN ('cancelled','archived','completed') AND fulfillment_method='delivery'");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $current = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $capacity['current_orders'] = $current;
    $capacity['remaining'] = max(0, (int)$capacity['max_orders'] - $current);
    $capacity['fully_booked'] = (bool)$capacity['is_closed'] || $capacity['remaining'] <= 0;
    $capacity['status'] = $capacity['is_closed'] ? 'closed' : ($capacity['fully_booked'] ? 'full' : 'available');
    return $capacity;
}

function deliveryCalendarSnapshot(mysqli $conn, array $pickupDates): array
{
    if (!$pickupDates) return [];
    $start = (string)$pickupDates[0]['date'];
    $end = (string)$pickupDates[count($pickupDates) - 1]['date'];
    $stmt = $conn->prepare("SELECT due_date, COUNT(*) AS total FROM orders WHERE due_date BETWEEN ? AND ? AND status NOT IN ('cancelled','archived','completed') AND fulfillment_method='delivery' GROUP BY due_date");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $orders = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $orders[(string)$row['due_date']] = (int)$row['total'];
    $stmt->close();
    foreach ($pickupDates as &$date) {
        $current = $orders[(string)$date['date']] ?? 0;
        $date['current_orders'] = $current;
        $date['remaining'] = max(0, (int)$date['max_orders'] - $current);
        $date['fully_booked'] = (bool)$date['is_closed'] || $date['remaining'] <= 0;
        $date['status'] = $date['is_closed'] ? 'closed' : ($date['fully_booked'] ? 'full' : 'available');
    }
    unset($date);
    return $pickupDates;
}

function validateDeliveryDateAvailable(mysqli $conn, ?string $date, int $ordersToAdd = 1): void
{
    $date = normalizePickupDate($date);
    if ($date === null || $date < (new DateTime('today'))->format('Y-m-d')) {
        throw new RuntimeException('Please choose a valid upcoming delivery date.');
    }
    $capacity = deliveryCapacityForDate($conn, $date);
    if ($capacity['is_closed']) {
        throw new RuntimeException('Delivery date ' . $date . ' is closed. Please choose another available date.');
    }
    if ((int)$capacity['remaining'] < max(1, $ordersToAdd)) {
        throw new RuntimeException('Delivery date ' . $date . ' is already fully booked. Please choose another available date.');
    }
}
