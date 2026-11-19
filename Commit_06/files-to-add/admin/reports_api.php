<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Sign in again.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/order_feedback.php';

function validReportDate($value, string $fallback): string
{
    if (!is_string($value)) return $fallback;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function scalarValue(mysqli $conn, string $sql): float
{
    $result = $conn->query($sql);
    return $result ? (float)($result->fetch_row()[0] ?? 0) : 0;
}

$today = date('Y-m-d');
$from = validReportDate($_GET['from'] ?? null, date('Y-m-01'));
$to = validReportDate($_GET['to'] ?? null, $today);
if ($from > $to) [$from, $to] = [$to, $from];

// Includes inventory because Stock Watch is part of this analytics screen.
$orderRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(id),0) FROM orders")->fetch_row();
$stockRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(quantity),0) FROM inventory")->fetch_row();
ensureOrderFeedbackSchema($conn);
$feedbackRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(rating),0) FROM order_feedback")->fetch_row();
$revision = hash('sha256', implode(':', array_merge($orderRevision, $stockRevision, $feedbackRevision)));
$seen = is_string($_GET['revision'] ?? null) ? $_GET['revision'] : '';

if ($seen !== '' && hash_equals($revision, $seen)) {
    echo json_encode(['ok' => true, 'changed' => false, 'revision' => $revision]);
    exit;
}

$monthly = (new DateTime($from))->diff(new DateTime($to))->days > 92;
$group = $monthly ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
$select = $monthly ? "STR_TO_DATE(DATE_FORMAT(created_at, '%Y-%m-01'), '%Y-%m-%d')" : 'DATE(created_at)';
$format = $monthly ? 'M Y' : 'M j';

$totalOrders = scalarValue($conn, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived'");
$completed = scalarValue($conn, "SELECT COUNT(*) FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$revenue = scalarValue($conn, "SELECT SUM(total_amount) FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$outstanding = scalarValue($conn, "SELECT SUM(balance) FROM orders WHERE status NOT IN ('cancelled','archived') AND DATE(created_at) BETWEEN '$from' AND '$to'");
$cancelled = scalarValue($conn, "SELECT COUNT(*) FROM orders WHERE status='cancelled' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$active = scalarValue($conn, "SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed','cancelled','archived') AND DATE(created_at) BETWEEN '$from' AND '$to'");
$sales = scalarValue($conn, "SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status NOT IN ('cancelled','archived')");
$collected = scalarValue($conn, "SELECT SUM(GREATEST(total_amount - COALESCE(balance,total_amount),0)) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status NOT IN ('cancelled','archived')");

function groupedData(mysqli $conn, string $sql, string $label, string $value): array
{
    $labels = $values = [];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $labels[] = (string)$row[$label];
        $values[] = (float)$row[$value];
    }
    return [$labels, $values];
}

[$rawOrderLabels, $rawOrders] = groupedData($conn, "SELECT $select AS point, COUNT(*) AS value FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' GROUP BY $group ORDER BY point", 'point', 'value');
[$rawSalesLabels, $rawSales] = groupedData($conn, "SELECT $select AS point, SUM(total_amount) AS value FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY $group ORDER BY point", 'point', 'value');
$orderPoints = $salesPoints = [];
foreach ($rawOrderLabels as $i => $date) $orderPoints[date($format, strtotime($date))] = (int)$rawOrders[$i];
foreach ($rawSalesLabels as $i => $date) $salesPoints[date($format, strtotime($date))] = round($rawSales[$i], 2);

$trendLabels = $trendOrders = $revenueCounts = [];
$cursor = new DateTime($from);
$last = new DateTime($to);
if ($monthly) $cursor->modify('first day of this month');
while ($cursor <= $last) {
    $label = $cursor->format($format);
    $trendLabels[] = $label;
    $trendOrders[] = $orderPoints[$label] ?? 0;
    $revenueCounts[] = $salesPoints[$label] ?? 0;
    $cursor->modify($monthly ? '+1 month' : '+1 day');
}

[$statusLabels, $statusCounts] = groupedData($conn, "SELECT status AS label, COUNT(*) AS value FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' GROUP BY status ORDER BY value DESC", 'label', 'value');
$statusLabels = array_map(static fn($label) => ucwords(str_replace('_', ' ', $label)), $statusLabels);
[$sourceLabels, $sourceCounts] = groupedData($conn, "SELECT COALESCE(NULLIF(source,''),'Unknown') AS label, COUNT(*) AS value FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' GROUP BY source ORDER BY value DESC", 'label', 'value');
[$typeLabels, $typeCounts] = groupedData($conn, "SELECT order_type AS label, COUNT(*) AS value FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' AND order_type!='' GROUP BY order_type ORDER BY value DESC LIMIT 8", 'label', 'value');

$bestSellers = $conn->query("SELECT COALESCE(NULLIF(order_type,''),'Other') AS product, SUM(quantity) AS units, SUM(total_amount) AS sales FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status='completed' GROUP BY order_type ORDER BY sales DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$lowStock = $conn->query("SELECT name, quantity, unit, reorder_level FROM inventory WHERE status='active' AND quantity<=reorder_level ORDER BY quantity ASC, name LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$topOrders = $conn->query("SELECT id, order_number, customer_name, order_type, total_amount FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status='completed' ORDER BY total_amount DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$feedbackSummary = orderFeedbackSummary($conn, $from, $to);
$feedbackRows = orderFeedbackLatest($conn, 50, $from, $to);

echo json_encode([
    'ok' => true, 'changed' => true, 'revision' => $revision,
    'metrics' => [
        'sales' => $sales, 'collected' => $collected, 'outstanding' => $outstanding,
        'total_orders' => (int)$totalOrders, 'completed' => (int)$completed,
        'completion_rate' => $totalOrders > 0 ? (int)round($completed / $totalOrders * 100) : 0,
        'active' => (int)$active, 'cancelled' => (int)$cancelled, 'revenue' => $revenue,
    ],
    'charts' => [
        'orders' => ['labels' => $trendLabels, 'values' => $trendOrders],
        'revenue' => ['labels' => $trendLabels, 'values' => $revenueCounts],
        'status' => ['labels' => $statusLabels, 'values' => array_map('intval', $statusCounts)],
        'source' => ['labels' => $sourceLabels, 'values' => array_map('intval', $sourceCounts)],
        'type' => ['labels' => $typeLabels, 'values' => array_map('intval', $typeCounts)],
    ],
    'best_sellers' => $bestSellers, 'low_stock' => $lowStock, 'top_orders' => $topOrders,
    'feedback' => [
        'summary' => $feedbackSummary,
        'rows' => $feedbackRows,
    ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
