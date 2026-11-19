<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Pickup / Delivery Schedules';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/admin_settings.php';
require_once '../includes/pickup_capacity.php';
ensurePickupCapacitySchema($conn);
ensureSettingsSchema($conn);

if (empty($_SESSION['pickup_capacity_csrf'])) {
    $_SESSION['pickup_capacity_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['pickup_capacity_csrf'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expired. Refresh and try again.');
        }
        $date = normalizePickupDate((string)($_POST['pickup_date'] ?? ''));
        if ($date === null) {
            throw new RuntimeException('Choose a valid pickup date.');
        }
        $maxOrders = max(1, min(500, (int)($_POST['max_orders'] ?? pickupDefaultCapacity($conn))));
        $isClosed = isset($_POST['is_closed']) ? 1 : 0;
        $note = substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        $stmt = $conn->prepare("INSERT INTO pickup_capacity (pickup_date,max_orders,is_closed,note)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE max_orders=VALUES(max_orders), is_closed=VALUES(is_closed), note=VALUES(note)");
        $stmt->bind_param('siis', $date, $maxOrders, $isClosed, $note);
        $stmt->execute();
        $stmt->close();
        $msg = 'Pickup availability updated for ' . $date . '.';
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
}

$today = new DateTime('today');
$todayKey = $today->format('Y-m-d');
$endDate = (clone $today)->modify('+29 days');
$endKey = $endDate->format('Y-m-d');
$defaultCapacity = (int)pickupDefaultCapacity($conn);
$selectedDate = normalizePickupDate((string)($_POST['pickup_date'] ?? '')) ?? $todayKey;
$selectedCapacity = pickupCapacityForDate($conn, $selectedDate);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

// Pickup capacity is separate from delivery: a delivery booking must not use up a pickup slot.
// Load both daily totals once, then keep the existing per-date pickup-limit logic.
$dailyCounts = [];
$dailyStmt = $conn->prepare("SELECT due_date,
    SUM(CASE WHEN fulfillment_method='delivery' THEN 1 ELSE 0 END) AS delivery_count,
    SUM(CASE WHEN fulfillment_method='delivery' THEN 0 ELSE 1 END) AS pickup_count
    FROM orders WHERE due_date BETWEEN ? AND ?
    AND status NOT IN ('cancelled','archived','completed') GROUP BY due_date");
$dailyStmt->bind_param('ss', $todayKey, $endKey);
$dailyStmt->execute();
$result = $dailyStmt->get_result();
while ($item = $result->fetch_assoc()) $dailyCounts[(string)$item['due_date']] = $item;
$dailyStmt->close();

$rows = [];
$summary = ['pickups' => 0, 'deliveries' => 0, 'open' => 0, 'unavailable' => 0];
for ($i = 0; $i < 30; $i++) {
    $date = (clone $today)->modify('+' . $i . ' days')->format('Y-m-d');
    $row = pickupCapacityForDate($conn, $date);
    $row['pickup_count'] = (int)($dailyCounts[$date]['pickup_count'] ?? 0);
    $row['delivery_count'] = (int)($dailyCounts[$date]['delivery_count'] ?? 0);
    $rows[] = $row;
    $summary['pickups'] += $row['pickup_count'];
    $summary['deliveries'] += $row['delivery_count'];
    if (!empty($row['is_closed']) || (int)$row['remaining'] <= 0) $summary['unavailable']++;
    else $summary['open']++;
}

// Ordered appointment list; include only active orders with a chosen date in the same window.
$appointments = [];
$appointmentStmt = $conn->prepare("SELECT id, order_number, customer_name, order_type,
    due_date, pickup_time, fulfillment_method, delivery_address, status
    FROM orders WHERE due_date BETWEEN ? AND ?
    AND status NOT IN ('cancelled','archived','completed')
    ORDER BY due_date ASC, (pickup_time IS NULL), pickup_time ASC, id ASC LIMIT 300");
$appointmentStmt->bind_param('ss', $todayKey, $endKey);
$appointmentStmt->execute();
$appointmentResult = $appointmentStmt->get_result();
while ($item = $appointmentResult->fetch_assoc()) $appointments[] = $item;
$appointmentStmt->close();
$appointmentCount = $summary['pickups'] + $summary['deliveries'];

require_once '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-pickup-capacity.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-pickup-capacity.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<main class="pc-page" id="pickupCapacityPage" data-pc-initial="<?= $msg !== '' || $err !== '' ? 'settings' : 'orders' ?>">
  <header class="pc-intro">
    <div><h2>Pickup / Delivery Schedules</h2><p>Manage upcoming orders and pickup availability.</p></div>
    <span class="pc-period"><?= $e($today->format('M j')) ?> – <?= $e($endDate->format('M j, Y')) ?></span>
  </header>
  <?php if ($msg): ?><div class="pc-feedback is-success" role="status"><?= $e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="pc-feedback is-error" role="alert"><?= $e($err) ?></div><?php endif; ?>

  <section class="pc-stats" aria-label="Next 30 days schedule overview">
    <article class="pc-stat"><div><span>Scheduled pickups</span><strong><?= $summary['pickups'] ?></strong><small>Next 30 days</small></div></article>
    <article class="pc-stat"><div><span>Scheduled deliveries</span><strong><?= $summary['deliveries'] ?></strong><small>Next 30 days</small></div></article>
    <article class="pc-stat"><div><span>Pickup dates open</span><strong><?= $summary['open'] ?></strong><small>Pickup dates with space</small></div></article>
    <article class="pc-stat"><div><span>Pickup dates unavailable</span><strong><?= $summary['unavailable'] ?></strong><small>Closed or fully booked</small></div></article>
  </section>

  <nav class="pc-view-switch" aria-label="Schedule sections">
    <div class="pc-view-tabs" role="tablist" aria-label="Pickup and delivery schedules">
      <button type="button" class="pc-view-tab is-active" id="pcTabOrders" role="tab" aria-controls="pcPanelOrders" aria-selected="true" tabindex="0" data-pc-tab="orders">Upcoming orders <span><?= count($appointments) ?></span></button>
      <button type="button" class="pc-view-tab" id="pcTabDaily" role="tab" aria-controls="pcPanelDaily" aria-selected="false" tabindex="-1" data-pc-tab="daily">Daily availability</button>
      <button type="button" class="pc-view-tab" id="pcTabSettings" role="tab" aria-controls="pcPanelSettings" aria-selected="false" tabindex="-1" data-pc-tab="settings">Pickup settings</button>
    </div>
    <p class="pc-view-note">Showing schedules for the next 30 days. Delivery bookings do not use pickup slots.</p>
  </nav>

  <section class="pc-panel pc-appointments is-active" id="pcPanelOrders" role="tabpanel" aria-labelledby="pcTabOrders" data-pc-panel="orders">
    <div class="pc-panel-head"><div><h3 id="appointmentsTitle">Upcoming orders</h3><p>Pickup and delivery schedules sorted by date and time.</p></div><span class="pc-list-count" id="appointmentResultCount"><?= count($appointments) ?> orders</span></div>
    <div class="pc-toolbar">
      <label class="pc-search" for="appointmentSearch"><input type="search" id="appointmentSearch" placeholder="Search order number, customer or date" autocomplete="off" aria-label="Search scheduled orders"></label>
      <label class="pc-select-wrap" for="appointmentMethod"><span>Receive method</span><select id="appointmentMethod"><option value="all">Pickup &amp; delivery</option><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select></label>
      <label class="pc-select-wrap" for="appointmentDate"><span>Scheduled date</span><input type="date" id="appointmentDate" min="<?= $e($todayKey) ?>" max="<?= $e($endKey) ?>"></label>
    </div>
    <?php if ($appointmentCount > count($appointments)): ?><p class="pc-table-foot">Showing the first 300 scheduled orders in this date range. Use Orders to view the full list.</p><?php endif; ?>
    <div class="pc-table-scroll"><table class="pc-table pc-orders-table"><thead><tr><th scope="col">Schedule</th><th scope="col">Order / Customer</th><th scope="col">Method</th><th scope="col">Product / Address</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody id="appointmentRows">
    <?php foreach ($appointments as $order):
       $method = strtolower((string)($order['fulfillment_method'] ?? 'pickup')) === 'delivery' ? 'delivery' : 'pickup';
       $time = trim((string)($order['pickup_time'] ?? ''));
       $formattedTime = $time !== '' ? date('g:i A', strtotime($time)) : 'Time not set';
       $formattedDate = date('M j, Y', strtotime((string)$order['due_date']));
       $state = strtolower((string)($order['status'] ?? 'pending'));
       $stateLabel = ucwords(str_replace('_', ' ', $state));
    ?>
      <tr data-appointment-row data-method="<?= $e($method) ?>" data-date="<?= $e($order['due_date']) ?>" data-search="<?= $e($order['order_number'] . ' ' . $order['customer_name'] . ' ' . $order['order_type'] . ' ' . $order['due_date']) ?>">
        <td data-label="Schedule"><strong class="pc-date-main"><?= $e($formattedDate) ?></strong><small><?= $e($formattedTime) ?> · PHT</small></td>
        <td data-label="Order / Customer"><strong class="pc-date-main"><?= $e($order['order_number']) ?></strong><small><?= $e($order['customer_name']) ?></small></td>
        <td data-label="Method"><span class="pc-method pc-method-<?= $method ?>"><?= ucfirst($method) ?></span></td>
        <td data-label="Product / Address"><strong class="pc-detail-product"><?= $e($order['order_type']) ?></strong><?php if ($method === 'delivery'): ?><small class="pc-address" title="<?= $e($order['delivery_address'] ?? '') ?>"><?= $e(($order['delivery_address'] ?? '') ?: 'No saved address') ?></small><?php endif; ?></td>
        <td data-label="Status"><span class="pc-order-status"><?= $e($stateLabel) ?></span></td>
        <td data-label="Action"><a class="pc-edit" href="orders.php?view=<?= (int)$order['id'] ?>">View order</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="pc-no-results" id="appointmentNoResults" hidden>No scheduled orders match these filters.</div>
    <p class="pc-table-foot">Times are in Philippine time (PHT). To reschedule an order, select View order.</p>
  </section>

  <section class="pc-panel pc-dates" id="pcPanelDaily" role="tabpanel" aria-labelledby="pcTabDaily" data-pc-panel="daily">
    <div class="pc-panel-head"><div><h3 id="scheduleDaysTitle">Daily availability</h3><p>Daily pickup limits and delivery bookings.</p></div><span class="pc-list-count" id="capacityResultCount">30 dates</span></div>
    <div class="pc-toolbar">
      <label class="pc-search" for="capacitySearch"><input type="search" id="capacitySearch" placeholder="Search date or note" autocomplete="off" aria-label="Search dates"></label>
      <label class="pc-select-wrap" for="capacityFilter"><span>Pickup availability</span><select id="capacityFilter"><option value="all">All dates</option><option value="available">Available</option><option value="almost_full">Almost full</option><option value="full">Fully booked</option><option value="closed">Closed</option></select></label>
      <button class="pc-clear" type="button" id="capacityResetFilters" hidden>Clear filters</button>
    </div>
    <div class="pc-table-scroll"><table class="pc-table pc-daily-table"><thead><tr><th scope="col">Date</th><th scope="col">Pickup bookings</th><th scope="col">Pickup slots left</th><th scope="col">Deliveries</th><th scope="col">Pickup availability</th><th scope="col">Action</th></tr></thead><tbody id="capacityRows">
    <?php foreach ($rows as $row):
      $closed = !empty($row['is_closed']);
      $status = $closed ? 'closed' : ($row['status'] === 'full' ? 'full' : $row['status']);
      $label = $closed ? 'Pickup closed' : ($status === 'full' ? 'Fully booked' : ($status === 'almost_full' ? 'Almost full' : 'Available'));
      $dateObject = DateTime::createFromFormat('!Y-m-d', (string)$row['date']);
      $current = (int)$row['pickup_count'];
      $max = (int)$row['max_orders'];
      $pct = min(100, max(0, $max > 0 ? round($current / $max * 100) : 0));
    ?>
      <tr data-capacity-row data-status="<?= $e($status) ?>" data-date="<?= $e($row['date']) ?>" data-limit="<?= $max ?>" data-closed="<?= $closed ? '1' : '0' ?>" data-note="<?= $e($row['note']) ?>">
        <td data-label="Date"><strong class="pc-date-main"><?= $e($dateObject ? $dateObject->format('M j, Y') : $row['date']) ?></strong><small><?= $e($dateObject ? $dateObject->format('l') : '') ?></small></td>
        <td data-label="Pickup bookings"><div class="pc-booking"><strong><?= $current ?> <span>/ <?= $max ?></span></strong><span class="pc-meter" role="img" aria-label="<?= $pct ?> percent of pickup capacity used"><span style="width:<?= $pct ?>%"></span></span></div></td>
        <td data-label="Pickup slots left"><strong class="pc-remaining<?= $closed || $status === 'full' ? ' is-unavailable' : '' ?>"><?= $closed ? '—' : (int)$row['remaining'] ?></strong></td>
        <td data-label="Deliveries"><strong><?= (int)$row['delivery_count'] ?></strong></td>
        <td data-label="Pickup availability"><span class="pc-status pc-<?= $e($status) ?>"><?= $e($label) ?></span><?php if ($row['note'] !== ''): ?><small class="pc-day-note"><?= $e($row['note']) ?></small><?php endif; ?></td>
        <td data-label="Action"><button type="button" class="pc-edit" data-edit-capacity>Manage date</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="pc-no-results" id="capacityNoResults" hidden>No dates match the selected filters.</div>
    <p class="pc-table-foot">Pickup limits apply to pickup orders only. Delivery has no daily capacity limit in this system.</p>
  </section>

  <section class="pc-panel pc-editor" id="pcPanelSettings" role="tabpanel" aria-labelledby="pcTabSettings" data-pc-panel="settings">
    <div class="pc-panel-head"><div><h3 id="capacityEditorTitle">Pickup settings</h3><p>Set the pickup limit or close a date for new pickup bookings.</p></div><span class="pc-default">Default: <strong><?= $defaultCapacity ?> pickups / day</strong></span></div>
    <form method="post" class="pc-form" id="capacityForm">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <label class="pc-field" for="capacityDate"><span>Pickup date</span><input type="date" id="capacityDate" name="pickup_date" min="<?= $e($todayKey) ?>" value="<?= $e($selectedDate) ?>" required></label>
      <label class="pc-field" for="capacityMax"><span>Pickup order limit</span><input type="number" id="capacityMax" name="max_orders" min="1" max="500" value="<?= (int)$selectedCapacity['max_orders'] ?>" required><small>Maximum active pickup orders on this date</small></label>
      <label class="pc-field pc-note" for="capacityNote"><span>Internal note <em>(optional)</em></span><input type="text" id="capacityNote" name="note" maxlength="255" value="<?= $e($selectedCapacity['note']) ?>" placeholder="e.g. Shortened pickup hours"></label>
      <label class="pc-close-toggle" for="capacityClosed"><input type="checkbox" id="capacityClosed" name="is_closed" value="1" <?= !empty($selectedCapacity['is_closed']) ? 'checked' : '' ?>><span class="pc-toggle-track" aria-hidden="true"></span><span><strong>Close pickup for this date</strong><small>Prevents new pickup bookings. Does not close delivery.</small></span></label>
      <div class="pc-form-actions"><span id="capacityEditHint">Changes to pickup availability also appear in the customer calendar.</span><button class="pc-save" type="submit">Save pickup settings</button></div>
    </form>
  </section>

</main>
<script src="../assets/js/admin-pickup-capacity.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-pickup-capacity.js') ?>" defer></script>
<?php require_once '../includes/footer.php'; ?>
