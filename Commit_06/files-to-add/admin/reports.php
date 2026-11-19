<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Reports & Analytics';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/order_feedback.php';

$periods = [
    'day' => [
        'label' => 'Day',
        'from' => date('Y-m-d'),
        'to' => date('Y-m-d'),
        'group' => 'DATE(%s)',
        'select' => 'DATE(%s)',
        'phpFormat' => 'M j',
    ],
    'week' => [
        'label' => 'Week',
        'from' => date('Y-m-d', strtotime('monday this week')),
        'to' => date('Y-m-d', strtotime('sunday this week')),
        'group' => 'YEARWEEK(%s, 3)',
        'select' => 'MIN(DATE(%s))',
        'phpFormat' => '\W\e\e\k \o\f M j',
    ],
    'month' => [
        'label' => 'Month',
        'from' => date('Y-m-01'),
        'to' => date('Y-m-t'),
        'group' => "DATE_FORMAT(%s, '%%Y-%%m')",
        'select' => "STR_TO_DATE(DATE_FORMAT(%s, '%%Y-%%m-01'), '%%Y-%%m-%%d')",
        'phpFormat' => 'M Y',
    ],
    'year' => [
        'label' => 'Year',
        'from' => date('Y-01-01'),
        'to' => date('Y-12-31'),
        'group' => 'YEAR(%s)',
        'select' => "STR_TO_DATE(CONCAT(YEAR(%s), '-01-01'), '%%Y-%%m-%%d')",
        'phpFormat' => 'Y',
    ],
];

$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'month';
$period = array_key_exists($period, $periods) ? $period : 'month';

$from = is_string($_GET['from'] ?? null) ? $_GET['from'] : $periods[$period]['from'];
$to = is_string($_GET['to'] ?? null) ? $_GET['to'] : $periods[$period]['to'];
foreach (['from', 'to'] as $field) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $$field);
    if (!$parsed || $parsed->format('Y-m-d') !== $$field) $$field = $periods[$period][$field];
}

$fromDate = DateTime::createFromFormat('Y-m-d', $from) ?: DateTime::createFromFormat('Y-m-d', $periods[$period]['from']);
$toDate = DateTime::createFromFormat('Y-m-d', $to) ?: DateTime::createFromFormat('Y-m-d', $periods[$period]['to']);
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$from = $fromDate->format('Y-m-d');
$to = $toDate->format('Y-m-d');
$rangeLabel = $fromDate->format('M j, Y') . ' to ' . $toDate->format('M j, Y');
$periodLabel = $periods[$period]['label'];
// Show daily points for short ranges and monthly points for longer ranges.
$monthly = $fromDate->diff($toDate)->days > 92;
$periods[$period]['group'] = $monthly ? "DATE_FORMAT(%s, '%%Y-%%m')" : 'DATE(%s)';
$periods[$period]['select'] = $monthly ? "STR_TO_DATE(DATE_FORMAT(%s, '%%Y-%%m-01'), '%%Y-%%m-%%d')" : 'DATE(%s)';
$periods[$period]['phpFormat'] = $monthly ? 'M Y' : 'M j';
$periodLabel = $monthly ? 'Month' : 'Day';

function reportScalar(mysqli $conn, string $sql): float
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    return (float)($result->fetch_row()[0] ?? 0);
}

$totalOrders = reportScalar($conn, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived'");
$completed = reportScalar($conn, "SELECT COUNT(*) FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$revenue = reportScalar($conn, "SELECT SUM(total_amount) FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$outstanding = reportScalar($conn, "SELECT SUM(balance) FROM orders WHERE status NOT IN ('cancelled','archived') AND DATE(created_at) BETWEEN '$from' AND '$to'");
$avgTime = reportScalar($conn, "SELECT AVG(TIMESTAMPDIFF(HOUR,created_at,updated_at)) FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$cancelled = reportScalar($conn, "SELECT COUNT(*) FROM orders WHERE status='cancelled' AND DATE(created_at) BETWEEN '$from' AND '$to'");
$active = reportScalar($conn, "SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed','cancelled','archived') AND DATE(created_at) BETWEEN '$from' AND '$to'");
$completionRate = $totalOrders > 0 ? round(($completed / $totalOrders) * 100) : 0;

$orderGroup = sprintf($periods[$period]['group'], 'created_at');
$orderSelect = sprintf($periods[$period]['select'], 'created_at');
$ordersTrend = $conn->query("
    SELECT $orderSelect AS period_start, COUNT(*) AS cnt
    FROM orders
    WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived'
    GROUP BY $orderGroup
    ORDER BY period_start
");

$trendLabels = [];
$trendOrders = [];
while ($r = $ordersTrend->fetch_assoc()) {
    $trendLabels[] = date($periods[$period]['phpFormat'], strtotime($r['period_start']));
    $trendOrders[] = (int)$r['cnt'];
}

$revenueGroup = sprintf($periods[$period]['group'], 'created_at');
$revenueSelect = sprintf($periods[$period]['select'], 'created_at');
$revenueTrend = $conn->query("
    SELECT $revenueSelect AS period_start, SUM(total_amount) AS amount
    FROM orders
    WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'
    GROUP BY $revenueGroup
    ORDER BY period_start
");

$revenueLabels = [];
$revenueCounts = [];
while ($r = $revenueTrend->fetch_assoc()) {
    $revenueLabels[] = date($periods[$period]['phpFormat'], strtotime($r['period_start']));
    $revenueCounts[] = round((float)$r['amount'], 2);
}

$statusData = $conn->query("SELECT status, COUNT(*) as cnt FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' GROUP BY status ORDER BY cnt DESC");
$statusLabels = [];
$statusCounts = [];
while ($r = $statusData->fetch_assoc()) {
    $statusLabels[] = ucwords(str_replace('_', ' ', $r['status']));
    $statusCounts[] = (int)$r['cnt'];
}

$typeData = $conn->query("SELECT order_type, COUNT(*) as cnt FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' AND order_type!='' GROUP BY order_type ORDER BY cnt DESC LIMIT 8");
$typeLabels = [];
$typeCounts = [];
while ($r = $typeData->fetch_assoc()) {
    $typeLabels[] = $r['order_type'];
    $typeCounts[] = (int)$r['cnt'];
}

$srcData = $conn->query("SELECT source, COUNT(*) as cnt FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status!='archived' GROUP BY source ORDER BY cnt DESC");
$srcLabels = [];
$srcCounts = [];
while ($r = $srcData->fetch_assoc()) {
    $srcLabels[] = $r['source'] ?: 'Unknown';
    $srcCounts[] = (int)$r['cnt'];
}

$topOrders = $conn->query("SELECT * FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status='completed' ORDER BY total_amount DESC LIMIT 10");
$sales = reportScalar($conn, "SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status NOT IN ('cancelled','archived')");
$collected = reportScalar($conn, "SELECT SUM(GREATEST(total_amount - COALESCE(balance,total_amount),0)) FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status NOT IN ('cancelled','archived')");
$bestSellers = $conn->query("SELECT COALESCE(NULLIF(order_type,''),'Other') AS product, SUM(quantity) AS units, SUM(total_amount) AS sales FROM orders WHERE DATE(created_at) BETWEEN '$from' AND '$to' AND status='completed' GROUP BY order_type ORDER BY sales DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$lowStock = $conn->query("SELECT name, quantity, unit, reorder_level FROM inventory WHERE status='active' AND quantity<=reorder_level ORDER BY quantity ASC, name LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$feedbackSummary = orderFeedbackSummary($conn, $from, $to);
$feedbackRows = orderFeedbackLatest($conn, 50, $from, $to);
$orderRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(id),0) FROM orders")->fetch_row();
$stockRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(quantity),0) FROM inventory")->fetch_row();
$feedbackRevision = $conn->query("SELECT COUNT(*), COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0), COALESCE(SUM(rating),0) FROM order_feedback")->fetch_row();
$reportsRevision = hash('sha256', implode(':', array_merge($orderRevision, $stockRevision, $feedbackRevision)));

// Include quiet days/months instead of connecting only the dates with orders.
$orderPoints = array_combine($trendLabels, $trendOrders) ?: [];
$salesPoints = array_combine($revenueLabels, $revenueCounts) ?: [];
$trendLabels = $trendOrders = $revenueLabels = $revenueCounts = [];
$cursor = clone $fromDate;
if ($monthly) $cursor->modify('first day of this month');
while ($cursor <= $toDate) {
    $label = $cursor->format($periods[$period]['phpFormat']);
    $trendLabels[] = $revenueLabels[] = $label;
    $trendOrders[] = $orderPoints[$label] ?? 0;
    $revenueCounts[] = $salesPoints[$label] ?? 0;
    $cursor->modify($monthly ? '+1 month' : '+1 day');
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analytics-' . $from . '-to-' . $to . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Orders created from', $from, 'Through', $to]);
    fputcsv($output, ['Metric', 'Value']);
    foreach (['Order value' => $sales, 'Recorded paid amount' => $collected, 'Outstanding balance' => $outstanding, 'Orders' => $totalOrders, 'Completed orders' => $completed, 'Completion rate (%)' => $completionRate] as $label => $value) fputcsv($output, [$label, $value]);
    fputcsv($output, []);
    fputcsv($output, ['Period', 'Orders', 'Completed order value']);
    foreach ($trendLabels as $i => $label) fputcsv($output, [$label, $trendOrders[$i], $revenueCounts[$i]]);
    fputcsv($output, []);
    fputcsv($output, ['Customer feedback received', $feedbackSummary['total']]);
    fputcsv($output, ['Average rating', $feedbackSummary['average_rating']]);
    fputcsv($output, ['Written comments', $feedbackSummary['written_comments']]);
    foreach ([5,4,3,2,1] as $star) fputcsv($output, [$star . '-star ratings', $feedbackSummary['distribution'][$star] ?? 0]);
    fputcsv($output, []);
    fputcsv($output, ['Order #', 'Customer', 'Product', 'Rating', 'Feedback', 'Received/updated']);
    foreach ($feedbackRows as $fb) {
        fputcsv($output, [
            $fb['order_number'] ?? '',
            $fb['customer_name'] ?? '',
            $fb['order_type'] ?? '',
            (int)$fb['rating'],
            $fb['feedback'] ?? '',
            $fb['updated_at'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-reports.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-reports.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<div id="reportsLive" data-api="reports_api.php" data-from="<?= htmlspecialchars($from) ?>" data-to="<?= htmlspecialchars($to) ?>" data-revision="<?= htmlspecialchars($reportsRevision) ?>">

  <header class="reports-toolbar">
    <div class="reports-heading">
      <h1>Reports</h1>
      <p><?= htmlspecialchars($rangeLabel) ?></p>
    </div>
    <a class="report-export" href="?<?= htmlspecialchars(http_build_query(['period' => $period, 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">Export CSV</a>
  </header>

  <div class="reports-filter-bar" aria-label="Report date filters">
    <div class="reports-filter-group">
      <nav class="period-tabs" aria-label="Report period">
        <?php foreach ($periods as $key => $meta): ?>
          <a href="reports.php?period=<?= $key ?>" class="period-tab <?= $period === $key ? 'active' : '' ?>" <?= $period === $key ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($meta['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <form method="get" class="date-range-form" aria-label="Custom report date range">
      <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
      <label class="reports-date-label">From <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" aria-label="From date"></label>
      <label class="reports-date-label">To <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" aria-label="To date"></label>
      <button type="submit" class="btn btn-primary btn-sm reports-apply">Apply dates</button>
    </form>
  </div>

  <section class="reports-section" aria-labelledby="reports-overview-title">
    <div class="reports-section-head"><h2 id="reports-overview-title">Overview</h2></div>
    <div class="r-stats">
      <article class="r-stat accent-rose"><div class="r-stat-label">Order value</div><div class="r-stat-value" data-live="sales"><?= formatMoney($sales) ?></div></article>
      <article class="r-stat"><div class="r-stat-label">Recorded paid</div><div class="r-stat-value" data-live="collected"><?= formatMoney($collected) ?></div></article>
      <article class="r-stat"><div class="r-stat-label">Outstanding balance</div><div class="r-stat-value" data-live="outstanding"><?= formatMoney($outstanding) ?></div></article>
      <article class="r-stat"><div class="r-stat-label">Total orders</div><div class="r-stat-value" data-live="total_orders"><?= (int)$totalOrders ?></div></article>
    </div>
    <div class="insight-strip" aria-label="Order status summary">
      <div><span>Active</span><strong data-live="active"><?= (int)$active ?></strong></div>
      <div><span>Completed</span><strong data-live="completion"><?= (int)$completed ?> (<?= $completionRate ?>%)</strong></div>
      <div><span>Cancelled</span><strong data-live="cancelled"><?= (int)$cancelled ?></strong></div>
    </div>
    <p class="report-basis">Based on orders created during the selected dates. Recorded paid uses order balances, not payment transaction dates; archived and cancelled orders are excluded from order value.</p>
  </section>

  <section class="reports-section" aria-labelledby="reports-trends-title">
    <div class="reports-section-head"><h2 id="reports-trends-title">Order &amp; sales trends</h2></div>
    <div class="reports-grid reports-trend-grid">
      <article class="r-card">
        <div class="r-card-header"><span>Orders by <?= htmlspecialchars(strtolower($periodLabel)) ?></span></div>
        <div class="chart-container"><canvas id="ordersTrendChart" aria-label="Orders over time" role="img"></canvas></div>
      </article>
      <article class="r-card">
        <div class="r-card-header"><span>Completed sales by <?= htmlspecialchars(strtolower($periodLabel)) ?></span></div>
        <div class="chart-container"><canvas id="revenueTrendChart" aria-label="Completed sales over time" role="img"></canvas></div>
      </article>
    </div>
  </section>

  <section class="reports-section" aria-labelledby="reports-breakdown-title">
    <div class="reports-section-head"><h2 id="reports-breakdown-title">Order breakdown</h2></div>
    <div class="reports-grid reports-breakdown-grid">
      <article class="r-card">
        <div class="r-card-header"><span>By status</span></div>
        <div class="chart-container"><canvas id="statusChart" aria-label="Order status distribution" role="img"></canvas></div>
      </article>
      <article class="r-card">
        <div class="r-card-header"><span>By source</span></div>
        <div class="chart-container"><canvas id="sourceChart" aria-label="Orders by source" role="img"></canvas></div>
      </article>
      <article class="r-card">
        <div class="r-card-header"><span>Popular products</span></div>
        <div class="chart-container"><canvas id="typeChart" aria-label="Top order types" role="img"></canvas></div>
      </article>
    </div>
  </section>


  <section class="reports-section" id="customer-feedback" aria-labelledby="reports-feedback-title">
    <div class="reports-section-head">
      <div>
        <h2 id="reports-feedback-title">Customer feedback</h2>
        <p>Ratings received or edited during the selected dates.</p>
      </div>
    </div>

    <div class="r-stats feedback-stats">
      <article class="r-stat accent-gold"><div class="r-stat-label">Average rating</div><div class="r-stat-value"><span data-live="feedback_average"><?= number_format((float)$feedbackSummary['average_rating'], 1) ?></span><span class="feedback-rating-unit"> / 5</span></div><small>Across submitted ratings</small></article>
      <article class="r-stat"><div class="r-stat-label">Feedback received</div><div class="r-stat-value" data-live="feedback_total"><?= number_format($feedbackSummary['total']) ?></div><small>One rating per completed order</small></article>
      <article class="r-stat accent-blue"><div class="r-stat-label">Written comments</div><div class="r-stat-value" data-live="feedback_comments"><?= number_format($feedbackSummary['written_comments']) ?></div><small>Ratings with customer remarks</small></article>
      <article class="r-stat accent-green"><div class="r-stat-label">5-star ratings</div><div class="r-stat-value" data-live="feedback_five_star"><?= number_format((int)($feedbackSummary['distribution'][5] ?? 0)) ?></div><small>Highest customer rating</small></article>
    </div>

    <div class="reports-grid feedback-report-grid">
      <article class="r-card feedback-distribution-card">
        <div class="r-card-header"><span>Rating distribution</span><small data-live="feedback_distribution_total"><?= number_format($feedbackSummary['total']) ?> total</small></div>
        <div class="feedback-distribution" aria-label="Customer rating distribution">
          <?php for ($star = 5; $star >= 1; $star--):
            $ratingCount = (int)($feedbackSummary['distribution'][$star] ?? 0);
            $ratingPct = $feedbackSummary['total'] > 0 ? round($ratingCount / $feedbackSummary['total'] * 100) : 0;
          ?>
            <div class="feedback-distribution-row" data-feedback-rating="<?= $star ?>">
              <span class="feedback-star-label"><?= $star ?> ★</span>
              <span class="feedback-bar"><span class="feedback-bar-fill" style="width:<?= $ratingPct ?>%"></span></span>
              <strong data-feedback-count><?= $ratingCount ?></strong>
            </div>
          <?php endfor; ?>
        </div>
      </article>

      <article class="r-card feedback-comments-card">
        <div class="r-card-header"><span>Latest customer feedback</span><small>Up to 50 in this period</small></div>
        <div class="table-wrap feedback-table-wrap">
          <table class="feedback-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Rating</th><th>Feedback</th><th>Date</th></tr></thead>
            <tbody data-live-list="customer-feedback">
              <?php if (!$feedbackRows): ?>
                <tr><td colspan="5"><div class="empty-state"><p>No customer feedback in this period.</p></div></td></tr>
              <?php else: foreach ($feedbackRows as $fb):
                $feedbackText = trim((string)($fb['feedback'] ?? ''));
              ?>
                <tr>
                  <td data-label="Order"><a href="orders.php?view=<?= (int)$fb['order_id'] ?>" class="order-num-link"><?= htmlspecialchars((string)($fb['order_number'] ?? ('#' . $fb['order_id']))) ?></a><small><?= htmlspecialchars((string)($fb['order_type'] ?? '')) ?></small></td>
                  <td data-label="Customer"><?= htmlspecialchars((string)($fb['customer_name'] ?? 'Customer')) ?></td>
                  <td data-label="Rating"><span class="feedback-stars" aria-label="<?= (int)$fb['rating'] ?> out of 5 stars"><?= htmlspecialchars(orderFeedbackStars((int)$fb['rating'])) ?></span><small><?= (int)$fb['rating'] ?>/5</small></td>
                  <td data-label="Feedback" class="feedback-comment-cell"><?= $feedbackText !== '' ? nl2br(htmlspecialchars($feedbackText)) : '<span class="feedback-no-comment">No written comment</span>' ?></td>
                  <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($fb['updated_at']))) ?><small><?= htmlspecialchars(date('g:i A', strtotime($fb['updated_at']))) ?></small></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </article>
    </div>
  </section>

  <section class="reports-section" aria-labelledby="reports-details-title">
    <div class="reports-section-head"><h2 id="reports-details-title">Products &amp; inventory</h2></div>
    <div class="reports-grid reports-details-grid">
      <section class="r-card best-sellers-card">
        <div class="r-card-header"><span>Best sellers</span></div>
        <div class="report-list" data-live-list="best-sellers">
          <?php if (!$bestSellers): ?><p class="report-empty">No completed sales in this period.</p><?php endif; ?>
          <?php foreach ($bestSellers as $i => $product): ?>
            <div class="report-list-row"><span class="report-rank"><?= $i + 1 ?></span><div><strong><?= htmlspecialchars($product['product']) ?></strong><small><?= (int)$product['units'] ?> units sold</small></div><b><?= formatMoney($product['sales']) ?></b></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="r-card stock-card">
        <div class="r-card-header"><span>Low-stock items</span></div>
        <div class="report-list" data-live-list="low-stock">
          <?php if (!$lowStock): ?><p class="report-empty">No items need restocking.</p><?php endif; ?>
          <?php foreach ($lowStock as $item): ?>
            <div class="report-list-row"><div><strong><?= htmlspecialchars($item['name']) ?></strong><small>Reorder at <?= (int)$item['reorder_level'] ?></small></div><b class="stock-warning"><?= (int)$item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></b></div>
          <?php endforeach; ?>
          <a class="report-link" href="inventory.php?low=1">View inventory &rarr;</a>
        </div>
      </section>
      <details class="r-card top-orders-card">
        <summary class="r-card-header"><span>Top completed orders</span><span class="report-disclosure-indicator" aria-hidden="true"></span></summary>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Order #</th><th>Customer</th><th>Product</th><th>Amount</th></tr></thead>
            <tbody data-live-list="top-orders">
              <?php if ($topOrders->num_rows === 0): ?>
                <tr><td colspan="4"><div class="empty-state"><p>No completed orders in this period.</p></div></td></tr>
              <?php else: while ($o = $topOrders->fetch_assoc()): ?>
                <tr>
                  <td data-label="Order"><a href="orders.php?view=<?= (int)$o['id'] ?>" class="order-num-link"><?= htmlspecialchars($o['order_number']) ?></a></td>
                  <td data-label="Customer"><?= htmlspecialchars($o['customer_name']) ?></td>
                  <td data-label="Product" class="text-sm"><?= htmlspecialchars($o['order_type'] ?? '-') ?></td>
                  <td data-label="Amount"><strong><?= formatMoney($o['total_amount']) ?></strong></td>
                </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  </section>
</div>

<script>
const chartPalette = ['#C8506A', '#D4A843', '#3B6DDB', '#2D8C56', '#0F766E', '#7C5A00', '#DC2626', '#9C98A3'];
Chart.register({
  id: 'reportsEmptyState',
  afterDraw(chart) {
    if (!chart.canvas.closest('.reports-grid')) return;
    if (chart.data.datasets.some(dataset => dataset.data.some(value => Number(value) > 0))) return;
    const { ctx, chartArea } = chart;
    if (!chartArea) return;
    ctx.save();
    ctx.fillStyle = '#8b7c85';
    ctx.font = '13px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('No activity in this period', (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
    ctx.restore();
  }
});

new Chart(document.getElementById('ordersTrendChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    datasets: [{
      label: 'Orders',
      data: <?= json_encode($trendOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      borderColor: '#C8506A',
      backgroundColor: 'rgba(200, 80, 106, .12)',
      borderWidth: 3,
      tension: .35,
      fill: true,
      pointBackgroundColor: '#C8506A',
      pointRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});

new Chart(document.getElementById('revenueTrendChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($revenueLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    datasets: [{
      label: 'Revenue',
      data: <?= json_encode($revenueCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      backgroundColor: '#2D8C56',
      borderRadius: 8,
      maxBarThickness: 42
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
  }
});

new Chart(document.getElementById('statusChart').getContext('2d'), {
  type: 'doughnut',
  data: { labels: <?= json_encode($statusLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, datasets: [{ data: <?= json_encode($statusCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, backgroundColor: chartPalette, borderColor: '#FFFFFF', borderWidth: 3 }] },
  options: { responsive: true, maintainAspectRatio: false, cutout: '64%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } } }
});

new Chart(document.getElementById('sourceChart').getContext('2d'), {
  type: 'pie',
  data: { labels: <?= json_encode($srcLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, datasets: [{ data: <?= json_encode($srcCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, backgroundColor: chartPalette, borderColor: '#FFFFFF', borderWidth: 3 }] },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } } }
});

new Chart(document.getElementById('typeChart').getContext('2d'), {
  type: 'bar',
  data: { labels: <?= json_encode($typeLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, datasets: [{ label: 'Orders', data: <?= json_encode($typeCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, backgroundColor: '#D4A843', borderRadius: 8, maxBarThickness: 38 }] },
  options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } }
});
</script>
<script src="../assets/js/realtime.js?v=<?= filemtime(__DIR__ . '/../assets/js/realtime.js') ?>"></script>
<script src="../assets/js/reports-live.js?v=<?= filemtime(__DIR__ . '/../assets/js/reports-live.js') ?>"></script>

<?php include '../includes/footer.php'; ?>
