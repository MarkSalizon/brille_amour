<?php
/**
 * Admin Overview — Brille Amour Control Center
 * ─────────────────────────────────────────────────────────────
 * Chart colours here are validated, not eyeballed. The department
 * palette #2A78D6 / #B8902F / #1BAF7A clears the computable checks
 * (OKLCH lightness band, chroma floor, all-pairs CVD ΔE 8.8 under
 * protan/deutan at severity 1.0, normal-vision floor 16.3). Two of
 * the three sit below 3:1 against the card surface, which obligates
 * relief — hence the direct value label on every department row.
 * The project's original #3B82F6 / #8B5CF6 pair measured ΔE 1.3
 * under deuteranopia and was replaced for that reason.
 *
 * Revenue and order volume are two separate single-series charts.
 * Never a dual axis, and a single series carries no legend box.
 */

require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Admin Overview';

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/innovation.php';
ensureInnovationSchema($conn);
try { innovationSendDailyAdminSummary($conn, false); } catch (Throwable $e) { error_log('Daily summary: '.$e->getMessage()); }

require_once '../includes/functions.php';
require_once '../includes/staff_roles.php';
require_once '../includes/admin_settings.php';
require_once '../includes/attendance.php';
require_once '../includes/order_feedback.php';

ensureStaffRoleColumn($conn);
ensureStaffAttendanceTable($conn);
$settings = getSettings($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$ordersPerStaff = getSettingInt($conn, 'orders_per_staff', 4);
$dueSoonDays    = getSettingInt($conn, 'due_soon_days', 3);
$watchMult      = getSettingInt($conn, 'watch_multiplier', 2);

/* =============================================================
   1. ANALYTICS WINDOW — Day / Week / Month / Year
   ============================================================= */

$tz  = new DateTimeZone(APP_TIMEZONE);
$now = new DateTimeImmutable('now', $tz);

$range = strtolower($_GET['range'] ?? $settings['analytics_default']);
if (!in_array($range, ['day', 'week', 'month', 'year'], true)) {
    $range = 'week';
}

$midnight = $now->setTime(0, 0);

switch ($range) {
    case 'day':
        $start   = $midnight;
        $end     = $midnight->modify('+1 day');
        $step    = '+2 hours';
        $slots   = 12;
        $tickFmt = 'ga';
        $back    = '-1 day';
        $rangeLbl = 'today';
        $unit    = 'hour';
        break;
    case 'month':
        $start   = $midnight->modify('-29 days');
        $end     = $midnight->modify('+1 day');
        $step    = '+1 day';
        $slots   = 30;
        $tickFmt = 'j M';
        $back    = '-30 days';
        $rangeLbl = 'last 30 days';
        $unit    = 'day';
        break;
    case 'year':
        $monthTop = $now->modify('first day of this month')->setTime(0, 0);
        $start   = $monthTop->modify('-11 months');
        $end     = $monthTop->modify('+1 month');
        $step    = '+1 month';
        $slots   = 12;
        $tickFmt = 'M';
        $back    = '-12 months';
        $rangeLbl = 'last 12 months';
        $unit    = 'month';
        break;
    default:
        $range   = 'week';
        $start   = $midnight->modify('-6 days');
        $end     = $midnight->modify('+1 day');
        $step    = '+1 day';
        $slots   = 7;
        $tickFmt = 'D j';
        $back    = '-7 days';
        $rangeLbl = 'last 7 days';
        $unit    = 'day';
}

// Bucket edges: $slots buckets means $slots + 1 boundaries.
$edges = [];
$cursor = $start;
for ($i = 0; $i <= $slots; $i++) {
    $edges[] = $cursor;
    $cursor  = $cursor->modify($step);
}

$labels  = [];
$revenue = array_fill(0, $slots, 0.0);
$volume  = array_fill(0, $slots, 0);
for ($i = 0; $i < $slots; $i++) {
    $labels[] = $edges[$i]->format($tickFmt);
}

/** Which bucket does this timestamp fall in? -1 if outside. */
function acBucket(array $edges, string $stamp, DateTimeZone $tz): int
{
    $t = new DateTimeImmutable($stamp, $tz);
    for ($i = count($edges) - 2; $i >= 0; $i--) {
        if ($t >= $edges[$i] && $t < $edges[$i + 1]) {
            return $i;
        }
    }
    return -1;
}

$sqlStart = $start->format('Y-m-d H:i:s');
$sqlEnd   = $end->format('Y-m-d H:i:s');

$rangeRevenue  = 0.0;
$rangeOrders   = 0;
$rangeCollected = 0.0;
$rangeOutstanding = 0.0;

$stmt = $conn->prepare("SELECT created_at, total_amount, deposit_paid, balance
                        FROM orders
                        WHERE created_at >= ? AND created_at < ?
                          AND status NOT IN ('cancelled','archived')");
$stmt->bind_param('ss', $sqlStart, $sqlEnd);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $b = acBucket($edges, $row['created_at'], $tz);
    if ($b < 0) {
        continue;
    }
    $revenue[$b] += (float) $row['total_amount'];
    $volume[$b]++;
    $rangeRevenue     += (float) $row['total_amount'];
    $rangeCollected   += (float) $row['deposit_paid'];
    $rangeOutstanding += (float) $row['balance'];
    $rangeOrders++;
}
$stmt->close();

// Previous equivalent window, for the deltas.
$prevStart = $start->modify($back)->format('Y-m-d H:i:s');
$prevRevenue = 0.0;
$prevOrders  = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS cnt
                        FROM orders
                        WHERE created_at >= ? AND created_at < ?
                          AND status NOT IN ('cancelled','archived')");
$stmt->bind_param('ss', $prevStart, $sqlStart);
$stmt->execute();
if ($p = $stmt->get_result()->fetch_assoc()) {
    $prevRevenue = (float) $p['rev'];
    $prevOrders  = (int) $p['cnt'];
}
$stmt->close();

$avgOrder = $rangeOrders > 0 ? $rangeRevenue / $rangeOrders : 0.0;
$peakIdx  = $revenue ? array_keys($revenue, max($revenue))[0] : 0;

/* =============================================================
   2. PIPELINE — one grouped read serves several panels
   ============================================================= */

$byStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS v
                     FROM orders GROUP BY status");
while ($row = $res->fetch_assoc()) {
    $byStatus[$row['status']] = ['c' => (int) $row['c'], 'v' => (float) $row['v']];
}
$res->free();

$stat = fn(string $k): int => $byStatus[$k]['c'] ?? 0;

$activeOrders = $stat('pending') + $stat('in_production') + $stat('quality_check') + $stat('ready');
$inWorkshop   = $stat('pending') + $stat('in_production') + $stat('quality_check');
$activeValue  = ($byStatus['pending']['v'] ?? 0) + ($byStatus['in_production']['v'] ?? 0)
    + ($byStatus['quality_check']['v'] ?? 0) + ($byStatus['ready']['v'] ?? 0);

// Overdue / due-soon on open work.
$dueSoonDate = $midnight->modify("+{$dueSoonDays} days")->format('Y-m-d');
$today       = $midnight->format('Y-m-d');
$overdue = 0;
$dueSoon = 0;
$stmt = $conn->prepare("SELECT
        SUM(due_date IS NOT NULL AND due_date <  ?) AS overdue,
        SUM(due_date IS NOT NULL AND due_date >= ? AND due_date <= ?) AS due_soon
    FROM orders WHERE status NOT IN ('completed','cancelled','archived')");
$stmt->bind_param('sss', $today, $today, $dueSoonDate);
$stmt->execute();
if ($d = $stmt->get_result()->fetch_assoc()) {
    $overdue = (int) $d['overdue'];
    $dueSoon = (int) $d['due_soon'];
}
$stmt->close();

/* =============================================================
   3. TEAM — headcount, departments, attendance
   ============================================================= */

$staffByDept = [];
$activeStaff = 0;
$res = $conn->query("SELECT staff_role, COUNT(*) AS c FROM users
                     WHERE role = 'staff' AND status = 'active' GROUP BY staff_role");
while ($row = $res->fetch_assoc()) {
    $staffByDept[$row['staff_role'] ?? ''] = (int) $row['c'];
    $activeStaff += (int) $row['c'];
}
$res->free();

// "On shift today" = active staff with an attendance record that has not timed out yet.
$onShift = 0;
$res = $conn->query("SELECT COUNT(DISTINCT sa.user_id) AS c
                     FROM staff_attendance sa
                     JOIN users u ON u.id = sa.user_id
                     WHERE u.role = 'staff' AND u.status = 'active'
                       AND sa.attendance_date = CURDATE()
                       AND sa.time_out IS NULL");
$onShift = (int) ($res->fetch_assoc()['c'] ?? 0);
$res->free();

// Throughput per department inside the selected range.
$movesByDept = [];
$stmt = $conn->prepare("SELECT u.role AS urole, u.staff_role, COUNT(*) AS moves
                        FROM production_logs pl
                        LEFT JOIN users u ON u.id = pl.updated_by
                        WHERE pl.created_at >= ? AND pl.created_at < ?
                        GROUP BY u.role, u.staff_role");
$stmt->bind_param('ss', $sqlStart, $sqlEnd);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    // Admin moves are administration, not an unassigned artisan.
    $key = $row['urole'] === 'admin' ? '__admin' : ($row['staff_role'] ?? '');
    $movesByDept[$key] = ($movesByDept[$key] ?? 0) + (int) $row['moves'];
}
$stmt->close();

// Build the department rows: the three real roles, then the honest remainders.
$deptColors = ['production' => '#2A78D6', 'quality_check' => '#B8902F', 'packaging' => '#1BAF7A'];
$departments = [];
foreach (getStaffRoles() as $key => $meta) {
    $queue = 0;
    foreach (staffRoleStages($key) as $s) {
        $queue += $stat($s);
    }
    $departments[] = [
        'label'   => $meta['label'],
        'tagline' => $meta['tagline'],
        'color'   => $deptColors[$key] ?? '#9C98A3',
        'staff'   => $staffByDept[$key] ?? 0,
        'queue'   => $queue,
        'moves'   => $movesByDept[$key] ?? 0,
    ];
}
if (!empty($staffByDept['']) || !empty($movesByDept[''])) {
    $departments[] = [
        'label'   => 'Unassigned',
        'tagline' => 'No department set',
        'color'   => '#9C98A3',
        'staff'   => $staffByDept[''] ?? 0,
        'queue'   => 0,
        'moves'   => $movesByDept[''] ?? 0,
    ];
}
if (!empty($movesByDept['__admin'])) {
    $departments[] = [
        'label'   => 'Administration',
        'tagline' => 'Admin-logged changes',
        'color'   => '#9C98A3',
        'staff'   => 0,
        'queue'   => 0,
        'moves'   => $movesByDept['__admin'],
    ];
}
$maxMoves = max(1, max(array_column($departments, 'moves') ?: [1]));
$totalMoves = array_sum(array_column($departments, 'moves'));

/* =============================================================
   4. INVENTORY
   ============================================================= */

$invTiers = ['out' => 0, 'below' => 0, 'watch' => 0, 'total' => 0];
$stmt = $conn->prepare("SELECT
        COALESCE(SUM(quantity = 0),0)                                              AS out_of_stock,
        COALESCE(SUM(quantity > 0 AND quantity <= reorder_level),0)                AS below,
        COALESCE(SUM(quantity > reorder_level AND quantity <= reorder_level * ?),0) AS watch,
        COUNT(*)                                                                    AS total
    FROM inventory WHERE status = 'active'");
$stmt->bind_param('i', $watchMult);
$stmt->execute();
if ($t = $stmt->get_result()->fetch_assoc()) {
    $invTiers = [
        'out'   => (int) $t['out_of_stock'],
        'below' => (int) $t['below'],
        'watch' => (int) $t['watch'],
        'total' => (int) $t['total'],
    ];
}
$stmt->close();
$belowReserve = $invTiers['out'] + $invTiers['below'];

$invAlerts = [];
$stmt = $conn->prepare("SELECT id, name, sku, quantity, unit, reorder_level
                        FROM inventory
                        WHERE status = 'active' AND quantity <= reorder_level * ?
                        ORDER BY quantity / GREATEST(reorder_level, 1) ASC, quantity ASC
                        LIMIT 7");
$stmt->bind_param('i', $watchMult);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $invAlerts[] = $row;
}
$stmt->close();

/* =============================================================
   5. QUEUES — review, approvals, activity, access
   ============================================================= */

$review = [];
$stmt = $conn->prepare("SELECT o.id, o.order_number, o.customer_name, o.total_amount, o.due_date,
                               o.priority, o.status, o.created_at, u.full_name AS assignee
                        FROM orders o
                        LEFT JOIN users u ON u.id = o.assigned_to
                        WHERE o.status NOT IN ('completed','cancelled','archived')
                          AND (o.status = 'pending'
                               OR o.priority IN ('high','urgent')
                               OR (o.due_date IS NOT NULL AND o.due_date <= ?))
                        ORDER BY FIELD(o.priority,'urgent','high','normal','low'),
                                 (o.due_date IS NULL), o.due_date ASC, o.created_at ASC
                        LIMIT 6");
$stmt->bind_param('s', $dueSoonDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $review[] = $row;
}
$stmt->close();

$approvals = [];
$res = $conn->query("SELECT o.id, o.order_number, o.customer_name, o.status, o.total_amount,
                            o.updated_at, u.full_name AS assignee
                     FROM orders o
                     LEFT JOIN users u ON u.id = o.assigned_to
                     WHERE o.status IN ('quality_check','ready')
                     ORDER BY o.updated_at ASC LIMIT 6");
while ($row = $res->fetch_assoc()) {
    $approvals[] = $row;
}
$res->free();

$activity = [];
$res = $conn->query("SELECT pl.status_from, pl.status_to, pl.notes, pl.created_at,
                            u.full_name, u.staff_role, u.role AS urole, o.order_number
                     FROM production_logs pl
                     LEFT JOIN users u ON u.id = pl.updated_by
                     LEFT JOIN orders o ON o.id = pl.order_id
                     ORDER BY pl.created_at DESC LIMIT 7");
while ($row = $res->fetch_assoc()) {
    $activity[] = $row;
}
$res->free();

$feedbackSummary = orderFeedbackSummary($conn);
$latestFeedback = orderFeedbackLatest($conn, 5);

$accessRequests = [];
$res = $conn->query("SELECT id, full_name, username, email, role, staff_role, created_at
                     FROM users WHERE status = 'inactive'
                     ORDER BY created_at ASC LIMIT 5");
while ($row = $res->fetch_assoc()) {
    $accessRequests[] = $row;
}
$res->free();

/* =============================================================
   6. DERIVED FIGURES
   ============================================================= */

$capacitySlots = $activeStaff * $ordersPerStaff;
$capacityPct   = $capacitySlots > 0 ? min(100, round($inWorkshop / $capacitySlots * 100)) : 0;
$capacityTone  = $capacityPct >= 95 ? 'crit' : ($capacityPct >= 80 ? 'warn' : 'good');

$attendPct  = $activeStaff > 0 ? round($onShift / $activeStaff * 100) : 0;
$attendTone = $attendPct >= 70 ? 'good' : ($attendPct >= 40 ? 'warn' : 'crit');

$stockTone = $invTiers['out'] > 0 ? 'crit' : ($belowReserve > 0 ? 'warn' : 'good');
$stockPct  = $invTiers['total'] > 0 ? round($belowReserve / $invTiers['total'] * 100) : 0;

/** Signed delta chip. $upIsGood flips which direction gets the good colour. */
function acDelta(float $now, float $prev, bool $upIsGood = true, string $note = ''): string
{
    $up   = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>';
    $down = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>';
    $flat = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>';
    $tail = $note !== '' ? ' <span class="ac-delta-note">' . htmlspecialchars($note) . '</span>' : '';

    if ($prev <= 0) {
        $txt = $now > 0 ? 'New activity' : 'No prior data';
        return '<span class="ac-delta ac-delta-flat">' . $flat . $txt . '</span>' . $tail;
    }
    $pct = ($now - $prev) / $prev * 100;
    if (abs($pct) < 0.5) {
        return '<span class="ac-delta ac-delta-flat">' . $flat . 'Flat</span>' . $tail;
    }
    $rising = $pct > 0;
    $cls    = ($rising === $upIsGood) ? 'ac-delta-good' : 'ac-delta-bad';
    return '<span class="ac-delta ' . $cls . '">' . ($rising ? $up : $down)
        . ($rising ? '+' : '−') . number_format(abs($pct), 1) . '%</span>' . $tail;
}

/** Compact figure for display sizes: 1,284 / 12.9K / 4.2M. */
function acCompact(float $n): string
{
    if (abs($n) >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if (abs($n) >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return number_format($n);
}

/** Sparkline: de-emphasis line, current period in the accent, 2px surface ring on the dot. */
function acSpark(array $values): string
{
    $n = count($values);
    if ($n < 2) {
        return '';
    }
    $w = 100.0;
    $h = 30.0;
    $max = max($values);
    $min = min($values);
    $span = ($max - $min) > 0 ? ($max - $min) : 1;

    $pts = [];
    foreach (array_values($values) as $i => $v) {
        $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
        $y = $h - (($v - $min) / $span) * ($h - 4) - 2;
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    $lead = implode(' ', array_slice($pts, 0, $n - 1));
    $tip  = implode(' ', array_slice($pts, $n - 2, 2));
    [$lx, $ly] = explode(',', $pts[$n - 1]);

    return '<svg class="ac-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">'
        . '<polyline class="ac-spark-line" points="' . $lead . '"/>'
        . '<polyline class="ac-spark-accent" points="' . $tip . '"/>'
        . '<circle class="ac-spark-dot" cx="' . $lx . '" cy="' . $ly . '" r="2.6"/>'
        . '</svg>';
}

/** Preserve other query params when swapping the range. */
function acRangeUrl(string $r): string
{
    $q = $_GET;
    $q['range'] = $r;
    return '?' . http_build_query($q);
}

$bodyClass = 'ao-overview';
include '../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     EXECUTIVE SUMMARY
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <div class="ac-section-head">
    <div>
      <h2>Business overview</h2>
    </div>
    <a class="ac-link" href="reports.php">Full reports →</a>
  </div>

  <div class="ac-kpi-grid">

    <!-- hero: the one number the view leads with -->
    <article class="ac-kpi ac-kpi-hero">
      <div class="ao-hero-main">
        <div class="ac-kpi-label"><?= acIcon('reports') ?> Booked sales &middot; <?= $rangeLbl ?></div>
        <div class="ac-kpi-value"><span class="ac-kpi-unit">PHP</span><?= number_format($rangeRevenue, 2) ?></div>
        <div class="ac-kpi-sub"><?= number_format($rangeOrders) ?> order<?= $rangeOrders === 1 ? '' : 's' ?> &middot; <?= formatMoney($avgOrder) ?> average order</div>
        <div class="ac-kpi-foot"><?= acDelta($rangeRevenue, $prevRevenue, true, 'vs previous ' . $rangeLbl) ?></div>
      </div>
      <div class="ao-hero-side">
        <?= acSpark($revenue) ?>
        <div class="ao-revenue-breakdown">
          <div><span>Collected</span><strong><?= formatMoney($rangeCollected) ?></strong></div>
          <div><span>Outstanding</span><strong><?= formatMoney($rangeOutstanding) ?></strong></div>
        </div>
      </div>
    </article>

    <!-- active orders -->
    <article class="ac-kpi">
      <div class="ac-kpi-label"><?= acIcon('orders') ?> Active orders</div>
      <div class="ac-kpi-value"><?= number_format($activeOrders) ?></div>
      <div class="ac-kpi-sub">
        <?= $stat('in_production') ?> in production &middot;
        <?= $stat('quality_check') ?> quality check &middot;
        <?= $stat('ready') ?> ready
      </div>
      <div class="ac-kpi-foot">
        <?php if ($overdue > 0): ?>
          <span class="ac-tag ac-tag-crit"><?= $overdue ?> overdue</span>
        <?php endif; ?>
        <?php if ($dueSoon > 0): ?>
          <span class="ac-tag ac-tag-warn"><?= $dueSoon ?> due in <?= $dueSoonDays ?>d</span>
        <?php endif; ?>
      </div>
    </article>

    <!-- production capacity -->
    <article class="ac-kpi">
      <div class="ac-kpi-label"><?= acIcon('production') ?> Production capacity</div>
      <div class="ac-kpi-value"><?= $capacityPct ?><span class="ac-kpi-unit" style="margin-left:2px">%</span></div>
      <div class="ac-kpi-sub">
        <?= $inWorkshop ?> active order<?= $inWorkshop === 1 ? '' : 's' ?> &middot; <?= $capacitySlots ?> total slot<?= $capacitySlots === 1 ? '' : 's' ?>
        <?php if ($activeStaff === 0): ?>
          &mdash; no active staff
        <?php endif; ?>
      </div>
      <div class="ac-kpi-foot" style="display:block">
        <span class="ac-meter" data-tone="<?= $capacityTone ?>">
          <span class="ac-meter-fill ac-meter-<?= $capacityTone ?>" style="width:<?= $capacityPct ?>%"></span>
        </span>
      </div>
    </article>

    <!-- low-stock materials -->
    <article class="ac-kpi">
      <div class="ac-kpi-label"><?= acIcon('inventory') ?> Low-stock materials</div>
      <div class="ac-kpi-value"><?= number_format($belowReserve) ?></div>
      <div class="ac-kpi-sub">
        of <?= number_format($invTiers['total']) ?> tracked materials
        <?php if ($invTiers['watch'] > 0): ?>
          &middot; <?= $invTiers['watch'] ?> on watch
        <?php endif; ?>
      </div>
      <div class="ac-kpi-foot" style="display:block">
        <span class="ac-meter" data-tone="<?= $stockTone ?>">
          <span class="ac-meter-fill ac-meter-<?= $stockTone ?>" style="width:<?= max(2, $stockPct) ?>%"></span>
        </span>
      </div>
    </article>

    <!-- team attendance -->
    <article class="ac-kpi">
      <div class="ac-kpi-label"><?= acIcon('teams') ?> Team attendance</div>
      <div class="ac-kpi-value"><?= $onShift ?><span class="ac-kpi-unit" style="margin-left:4px">/ <?= $activeStaff ?></span></div>
      <div class="ac-kpi-sub">Staff on shift today</div>
      <div class="ac-kpi-foot" style="display:block">
        <span class="ac-meter" data-tone="<?= $attendTone ?>">
          <span class="ac-meter-fill ac-meter-<?= $attendTone ?>" style="width:<?= max(2, $attendPct) ?>%"></span>
        </span>
      </div>
    </article>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     ANALYTICS — filters in one row above both charts
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <div class="ac-section-head ao-sales-head">
    <div><h2>Sales analytics</h2></div>
    <nav class="ac-segment" aria-label="Analytics range">
      <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $lbl): ?>
        <a href="<?= htmlspecialchars(acRangeUrl($key)) ?>"
           class="<?= $range === $key ? 'is-active' : '' ?>"
           <?= $range === $key ? 'aria-current="page"' : '' ?>><?= $lbl ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="ac-grid-2">

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Revenue trend</h3>
          <p>Booked value per <?= $unit ?>, in PHP</p>
        </div>
        <button type="button" class="ac-table-toggle" data-twin="revTable" aria-expanded="false">View as table</button>
      </div>
      <div class="ac-card-body ac-chart"><canvas id="revChart"></canvas></div>
      <div class="ac-table-view" id="revTable" hidden>
        <table class="ac-table">
          <thead><tr><th scope="col"><?= ucfirst($unit) ?></th><th scope="col">Revenue</th><th scope="col">Orders</th></tr></thead>
          <tbody>
            <?php for ($i = 0; $i < $slots; $i++): ?>
              <tr>
                <td><?= htmlspecialchars($labels[$i]) ?></td>
                <td><?= number_format($revenue[$i], 2) ?></td>
                <td><?= number_format($volume[$i]) ?></td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Order volume</h3>
          <p>Orders booked per <?= $unit ?></p>
        </div>
        <button type="button" class="ac-table-toggle" data-twin="volTable" aria-expanded="false">View as table</button>
      </div>
      <div class="ac-card-body ac-chart"><canvas id="volChart"></canvas></div>
      <div class="ac-table-view" id="volTable" hidden>
        <table class="ac-table">
          <thead><tr><th scope="col"><?= ucfirst($unit) ?></th><th scope="col">Orders</th></tr></thead>
          <tbody>
            <?php for ($i = 0; $i < $slots; $i++): ?>
              <tr>
                <td><?= htmlspecialchars($labels[$i]) ?></td>
                <td><?= number_format($volume[$i]) ?></td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </article>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     DEPARTMENTS + APPROVALS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section" id="overview-production">
  <div class="ac-section-head ao-section-head">
    <div><h2>Production &amp; approvals</h2></div>
  </div>
  <div class="ac-grid-wide">

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Department activity</h3>
          <p><?= number_format($totalMoves) ?> stage change<?= $totalMoves === 1 ? '' : 's' ?> logged in the <?= $rangeLbl ?></p>
        </div>
        <a class="ac-link" href="production.php">Production board →</a>
      </div>

      <?php if ($totalMoves === 0 && array_sum(array_column($departments, 'staff')) === 0): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
          <p>No department activity yet</p>
          <small>Assign staff to departments in Roles &amp; Permissions to start tracking throughput.</small>
        </div>
      <?php else: ?>
        <div class="ac-dept">
          <?php foreach ($departments as $d): ?>
            <div class="ac-dept-row">
              <div class="ac-dept-id">
                <span class="ac-dept-dot" style="background:<?= $d['color'] ?>"></span>
                <span class="ac-dept-name">
                  <strong><?= htmlspecialchars($d['label']) ?></strong>
                  <small><?= htmlspecialchars($d['tagline']) ?></small>
                </span>
              </div>
              <!-- direct value label: the required relief for a sub-3:1 fill -->
              <div class="ac-bar-labelled">
                <div class="ac-bar-track" style="flex:1">
                  <div class="ac-bar" style="width:<?= max(2, round($d['moves'] / $maxMoves * 100)) ?>%;background:<?= $d['color'] ?>"></div>
                </div>
                <span class="ac-bar-value"><?= number_format($d['moves']) ?></span>
              </div>
              <div class="ac-dept-stats">
                <div class="ac-stat-cell"><b><?= $d['staff'] ?></b><span>Staff</span></div>
                <div class="ac-stat-cell"><b><?= $d['queue'] ?></b><span>In queue</span></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="ac-card-foot">
          Activity shows stage changes in the selected period; in queue shows current orders.
        </div>
      <?php endif; ?>
    </article>

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Pending approvals</h3>
          <p>Awaiting inspection sign-off or release</p>
        </div>
        <?php if ($approvals): ?><span class="ac-tag ac-tag-warn"><?= count($approvals) ?></span><?php endif; ?>
      </div>
      <?php if (!$approvals): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><path d="M20 6.5 9.5 17 4 11.5"/></svg>
          <p>Nothing waiting on you</p>
          <small>No orders are held at quality check or ready-for-release.</small>
        </div>
      <?php else: ?>
        <div class="ac-list">
          <?php foreach ($approvals as $a): ?>
            <a class="ac-row" href="orders.php?view=<?= (int) $a['id'] ?>">
              <span class="ac-row-flag <?= $a['status'] === 'quality_check' ? 'is-warn' : 'is-info' ?>"></span>
              <span class="ac-row-main">
                <span class="ac-row-title">
                  <strong class="ac-mono"><?= htmlspecialchars($a['order_number']) ?></strong>
                  <?= statusBadge($a['status']) ?>
                </span>
                <span class="ac-row-sub">
                  <?= htmlspecialchars($a['customer_name']) ?>
                  <?= $a['assignee'] ? ' &middot; ' . htmlspecialchars($a['assignee']) : ' &middot; unassigned' ?>
                </span>
              </span>
              <span class="ac-row-end">
                <span class="ac-row-amount"><?= number_format((float) $a['total_amount'], 2) ?></span>
                <span class="ac-row-meta">held <?= timeAgo($a['updated_at']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     REVIEW QUEUE + INVENTORY ALERTS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section" id="overview-orders">
  <div class="ac-section-head ao-section-head">
    <div><h2>Orders &amp; inventory</h2></div>
  </div>
  <div class="ac-grid-wide">

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Orders to review</h3>
          <p>Unstarted, high priority, or due within <?= $dueSoonDays ?> day<?= $dueSoonDays === 1 ? '' : 's' ?></p>
        </div>
        <a class="ac-link" href="orders.php">All orders →</a>
      </div>
      <?php if (!$review): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><path d="M20 6.5 9.5 17 4 11.5"/></svg>
          <p>Queue is clear</p>
          <small>No open order is unstarted, urgent, or due soon.</small>
        </div>
      <?php else: ?>
        <div class="ac-list">
          <?php foreach ($review as $r):
              $late = $r['due_date'] && $r['due_date'] < $today;
              $flag = $r['priority'] === 'urgent' || $late ? 'is-crit'
                    : ($r['priority'] === 'high' ? 'is-warn' : 'is-info');
          ?>
            <a class="ac-row" href="orders.php?view=<?= (int) $r['id'] ?>">
              <span class="ac-row-flag <?= $flag ?>"></span>
              <span class="ac-row-main">
                <span class="ac-row-title">
                  <strong class="ac-mono"><?= htmlspecialchars($r['order_number']) ?></strong>
                  <?= statusBadge($r['status']) ?>
                  <?= priorityBadge($r['priority']) ?>
                </span>
                <span class="ac-row-sub">
                  <?= htmlspecialchars($r['customer_name']) ?>
                  <?= $r['assignee'] ? ' &middot; ' . htmlspecialchars($r['assignee']) : ' &middot; unassigned' ?>
                </span>
              </span>
              <span class="ac-row-end">
                <span class="ac-row-amount"><?= number_format((float) $r['total_amount'], 2) ?></span>
                <span class="ac-row-meta<?= $late ? ' is-late' : '' ?>">
                  <?php if (!$r['due_date']): ?>
                    no due date
                  <?php elseif ($late): ?>
                    overdue <?= (new DateTimeImmutable($r['due_date'], $tz))->diff($midnight)->days ?>d
                  <?php else: ?>
                    due <?= (new DateTimeImmutable($r['due_date'], $tz))->format('j M') ?>
                  <?php endif; ?>
                </span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Inventory alerts</h3>
          <p><?= $invTiers['out'] ?> out &middot; <?= $invTiers['below'] ?> below reserve &middot; <?= $invTiers['watch'] ?> on watch</p>
        </div>
        <a class="ac-link" href="inventory.php">Inventory →</a>
      </div>
      <?php if (!$invAlerts): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><path d="M20 6.5 9.5 17 4 11.5"/></svg>
          <p>Every material is above reserve</p>
          <small><?= number_format($invTiers['total']) ?> active items tracked.</small>
        </div>
      <?php else: ?>
        <?php if ($belowReserve === 0): ?>
          <div class="ac-card-foot" style="border-top:0;border-bottom:1px solid var(--ac-line);border-radius:0">
            Nothing is below its reserve level. These items are within <?= $watchMult ?>× reserve and worth watching.
          </div>
        <?php endif; ?>
        <div class="ac-list">
          <?php foreach ($invAlerts as $it):
              $lvl  = max(1, (int) $it['reorder_level']);
              $qty  = (int) $it['quantity'];
              $pct  = min(100, round($qty / ($lvl * $watchMult) * 100));
              $tone = $qty === 0 ? 'crit' : ($qty <= $lvl ? 'warn' : 'good');
              $flag = $qty === 0 ? 'is-crit' : ($qty <= $lvl ? 'is-warn' : 'is-info');
          ?>
            <div class="ac-row">
              <span class="ac-row-flag <?= $flag ?>"></span>
              <span class="ac-row-main">
                <span class="ac-row-title"><strong><?= htmlspecialchars($it['name']) ?></strong></span>
                <span class="ac-row-sub">
                  <?= $it['sku'] ? htmlspecialchars($it['sku']) . ' &middot; ' : '' ?>
                  reserve <?= number_format($lvl) ?> <?= htmlspecialchars($it['unit']) ?>
                </span>
              </span>
              <span class="ac-stock">
                <span class="ac-stock-nums">
                  <span><b><?= number_format($qty) ?></b> <?= htmlspecialchars($it['unit']) ?></span>
                  <span><?= $qty === 0 ? 'out' : ($qty <= $lvl ? 'below' : 'watch') ?></span>
                </span>
                <span class="ac-meter" data-tone="<?= $tone ?>">
                  <span class="ac-meter-fill ac-meter-<?= $tone ?>" style="width:<?= max(2, $pct) ?>%"></span>
                </span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

  </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     CUSTOMER FEEDBACK
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section" id="overview-feedback">
  <div class="ac-section-head ao-section-head">
    <div><h2>Customer feedback</h2></div>
    <a class="ac-link" href="reports.php#customer-feedback">Feedback reports →</a>
  </div>
  <div class="ac-grid-wide ao-feedback-grid">

    <article class="ac-card ao-feedback-summary-card">
      <div class="ac-card-head">
        <div>
          <h3>Rating summary</h3>
          <p>Overall feedback from completed customer orders</p>
        </div>
        <?php if ($feedbackSummary['total'] > 0): ?><span class="ac-tag"><?= number_format($feedbackSummary['total']) ?> received</span><?php endif; ?>
      </div>
      <?php if ($feedbackSummary['total'] === 0): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/></svg>
          <p>No customer ratings yet</p>
          <small>Ratings submitted after completed orders will appear here.</small>
        </div>
      <?php else: ?>
        <div class="ao-feedback-summary">
          <div class="ao-feedback-score">
            <strong><?= number_format((float)$feedbackSummary['average_rating'], 1) ?></strong>
            <span class="ao-feedback-stars" aria-label="Average rating <?= number_format((float)$feedbackSummary['average_rating'], 1) ?> out of 5">★★★★★</span>
            <small><?= number_format($feedbackSummary['total']) ?> rating<?= $feedbackSummary['total'] === 1 ? '' : 's' ?> · <?= number_format($feedbackSummary['written_comments']) ?> written comment<?= $feedbackSummary['written_comments'] === 1 ? '' : 's' ?></small>
          </div>
          <div class="ao-feedback-breakdown" aria-label="Rating distribution">
            <?php for ($star = 5; $star >= 1; $star--):
              $count = (int)($feedbackSummary['distribution'][$star] ?? 0);
              $pct = $feedbackSummary['total'] > 0 ? round($count / $feedbackSummary['total'] * 100) : 0;
            ?>
              <div class="ao-rating-row">
                <span><?= $star ?>★</span>
                <span class="ao-rating-track"><span style="width:<?= $pct ?>%"></span></span>
                <b><?= $count ?></b>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
    </article>

    <article class="ac-card ao-feedback-latest-card">
      <div class="ac-card-head">
        <div>
          <h3>Latest feedback</h3>
          <p>Most recently submitted or updated ratings</p>
        </div>
        <a class="ac-link" href="reports.php#customer-feedback">View all →</a>
      </div>
      <?php if (!$latestFeedback): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
          <p>No feedback received yet</p>
          <small>New customer feedback will show here automatically after submission.</small>
        </div>
      <?php else: ?>
        <div class="ao-feedback-list">
          <?php foreach ($latestFeedback as $fb):
            $feedbackText = trim((string)($fb['feedback'] ?? ''));
            $customerLabel = trim((string)($fb['customer_name'] ?? '')) ?: 'Customer';
          ?>
            <a class="ao-feedback-item" href="orders.php?view=<?= (int)$fb['order_id'] ?>">
              <span class="ao-feedback-avatar"><?= htmlspecialchars(strtoupper(substr($customerLabel, 0, 1))) ?></span>
              <span class="ao-feedback-copy">
                <span class="ao-feedback-line">
                  <strong><?= htmlspecialchars($customerLabel) ?></strong>
                  <span class="ao-feedback-stars"><?= htmlspecialchars(orderFeedbackStars((int)$fb['rating'])) ?></span>
                </span>
                <span class="ao-feedback-comment"><?= $feedbackText !== '' ? htmlspecialchars($feedbackText) : 'Rated this order without a written comment.' ?></span>
                <span class="ao-feedback-meta"><?= htmlspecialchars((string)($fb['order_number'] ?? 'Order')) ?><?= !empty($fb['order_type']) ? ' · ' . htmlspecialchars((string)$fb['order_type']) : '' ?> · <?= htmlspecialchars(timeAgo($fb['updated_at'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     TEAM ACTIVITY + ACCESS / PERMISSIONS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section" id="overview-team">
  <div class="ac-section-head ao-section-head">
    <div><h2>Team &amp; access</h2></div>
  </div>
  <div class="ac-grid-2">

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Team activity</h3>
          <p>Recent production stage changes</p>
        </div>
        <span class="ac-tag"><?= $onShift ?> on shift</span>
      </div>
      <?php if (!$activity): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
          <p>No production activity logged</p>
          <small>Stage changes made by the team will appear here.</small>
        </div>
      <?php else: ?>
        <div class="ac-list">
          <?php foreach ($activity as $ev):
              $who  = $ev['full_name'] ?: 'System';
              $dept = $ev['urole'] === 'admin' ? 'Administration'
                    : ($ev['staff_role'] ? staffRoleLabel($ev['staff_role']) : 'Unassigned');
          ?>
            <div class="ac-row">
              <span class="ac-avatar-sm"><?= strtoupper(substr($who, 0, 1)) ?></span>
              <span class="ac-row-main">
                <span class="ac-row-title">
                  <strong><?= htmlspecialchars($who) ?></strong>
                  <?php if ($ev['status_to']): ?><?= statusBadge($ev['status_to']) ?><?php endif; ?>
                </span>
                <span class="ac-row-sub">
                  <?= $ev['order_number'] ? htmlspecialchars($ev['order_number']) : 'order removed' ?>
                  <?php if ($ev['status_from'] && $ev['status_to']): ?>
                    &middot; <?= htmlspecialchars(str_replace('_', ' ', $ev['status_from'])) ?>
                    → <?= htmlspecialchars(str_replace('_', ' ', $ev['status_to'])) ?>
                  <?php endif; ?>
                  &middot; <?= htmlspecialchars($dept) ?>
                </span>
              </span>
              <span class="ac-row-end">
                <span class="ac-row-meta"><?= timeAgo($ev['created_at']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="ac-card">
      <div class="ac-card-head">
        <div>
          <h3>Account access</h3>
          <p><?= count($accessRequests) ?> account<?= count($accessRequests) === 1 ? '' : 's' ?> awaiting approval</p>
        </div>
        <a class="ac-link" href="roles.php">Manage →</a>
      </div>

      <?php if (!$accessRequests): ?>
        <div class="ac-empty">
          <svg viewBox="0 0 24 24"><path d="M20 6.5 9.5 17 4 11.5"/></svg>
          <p>No pending access requests</p>
          <small>New sign-ups awaiting approval will appear here.</small>
        </div>
      <?php else: ?>
        <?php foreach ($accessRequests as $rq): ?>
          <div class="ac-req">
            <span class="ac-avatar-sm"><?= strtoupper(substr($rq['full_name'], 0, 1)) ?></span>
            <span class="ac-req-main">
              <span class="ac-row-title">
                <strong><?= htmlspecialchars($rq['full_name']) ?></strong>
                <span class="ac-tag"><?= ucfirst($rq['role']) ?></span>
              </span>
              <span class="ac-row-sub">
                @<?= htmlspecialchars($rq['username']) ?>
                &middot; requested <?= timeAgo($rq['created_at']) ?>
              </span>
            </span>
            <span class="ac-req-actions">
              <form method="post" action="roles.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="user_id" value="<?= (int) $rq['id'] ?>">
                <input type="hidden" name="return" value="dashboard">
                <button type="submit" class="ac-btn ac-btn-primary">Approve</button>
              </form>
              <a class="ac-btn ac-btn-quiet" href="roles.php#user-<?= (int) $rq['id'] ?>">Review</a>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </article>

  </div>
</section>

<script>
/* ── chart tokens ─────────────────────────────────────────── */
const AC = {
  rose:  '#C8506A',
  roseD: '#9B3350',
  wash:  'rgba(200, 80, 106, .10)',   /* area fill: the hue at ~10% */
  grid:  '#E8E0D8',                    /* hairline, solid, recessive */
  ink2:  '#5A5560',
  ink3:  '#7D7885',
  surf:  '#FFFFFF',
  font:  "'DM Sans', system-ui, sans-serif"
};

const AC_LABELS = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const AC_REV    = <?= json_encode(array_map(fn($v) => round($v, 2), $revenue)) ?>;
const AC_VOL    = <?= json_encode($volume) ?>;

const acCompact = (n) => {
  const a = Math.abs(n);
  if (a >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
  if (a >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
  return n.toLocaleString();
};
const acPeso = (n) => 'PHP ' + Number(n).toLocaleString(undefined, {
  minimumFractionDigits: 2, maximumFractionDigits: 2
});

const acTooltip = {
  backgroundColor: '#1C1A1E',
  titleColor: '#FFFFFF',
  bodyColor: '#F0EAE3',
  borderColor: 'rgba(255,255,255,.14)',
  borderWidth: 1,
  cornerRadius: 8,
  padding: 10,
  displayColors: false,
  titleFont: { family: AC.font, size: 11, weight: '600' },
  bodyFont:  { family: AC.font, size: 13, weight: '600' }
};

/* Crosshair: a hairline dropped at the hovered index on the line chart. */
const acCrosshair = {
  id: 'acCrosshair',
  afterDatasetsDraw(chart) {
    const act = chart.tooltip?.getActiveElements?.() || [];
    if (!act.length) return;
    const x = act[0].element.x, {top, bottom} = chart.chartArea, c = chart.ctx;
    c.save();
    c.beginPath();
    c.moveTo(x, top); c.lineTo(x, bottom);
    c.lineWidth = 1; c.strokeStyle = 'rgba(200,80,106,.34)';
    c.stroke();
    c.restore();
  }
};

const acAxes = (valueFmt) => ({
  y: {
    beginAtZero: true,
    border: { display: false },
    grid: { color: AC.grid, drawTicks: false, tickLength: 0 },
    ticks: { color: AC.ink2, font: { family: AC.font, size: 11 }, padding: 8,
             maxTicksLimit: 5, callback: valueFmt }
  },
  x: {
    grid: { display: false },
    border: { color: AC.grid },
    ticks: { color: AC.ink3, font: { family: AC.font, size: 11 },
             maxRotation: 0, autoSkipPadding: 14 }
  }
});

/* Revenue — single series, area at 10%, no legend box (the title names it). */
new Chart(document.getElementById('revChart'), {
  type: 'line',
  data: { labels: AC_LABELS, datasets: [{
    label: 'Revenue',
    data: AC_REV,
    borderColor: AC.rose,
    borderWidth: 2,                 /* 2px line */
    borderCapStyle: 'round',
    borderJoinStyle: 'round',
    tension: .32,
    fill: true,
    backgroundColor: AC.wash,
    pointRadius: 0,
    pointHoverRadius: 5,            /* >= 8px marker on hover */
    pointHoverBackgroundColor: AC.rose,
    pointHoverBorderColor: AC.surf,
    pointHoverBorderWidth: 2,       /* 2px surface ring */
    pointHitRadius: 18
  }]},
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    layout: { padding: { top: 6, right: 4 } },
    plugins: {
      legend: { display: false },
      tooltip: { ...acTooltip, callbacks: {
        label: (c) => acPeso(c.parsed.y),
        afterBody: (items) => {
          const v = AC_VOL[items[0].dataIndex] || 0;
          return v + (v === 1 ? ' order' : ' orders');
        }
      }}
    },
    scales: acAxes((v) => acCompact(v))
  },
  plugins: [acCrosshair]
});

/* Order volume — thin columns, 4px rounded cap, square at the baseline. */
new Chart(document.getElementById('volChart'), {
  type: 'bar',
  data: { labels: AC_LABELS, datasets: [{
    label: 'Orders',
    data: AC_VOL,
    backgroundColor: AC.rose,
    hoverBackgroundColor: AC.roseD,
    maxBarThickness: 24,
    borderRadius: 4,
    borderSkipped: 'bottom'
  }]},
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    layout: { padding: { top: 6, right: 4 } },
    plugins: {
      legend: { display: false },
      tooltip: { ...acTooltip, callbacks: {
        label: (c) => c.parsed.y + (c.parsed.y === 1 ? ' order' : ' orders'),
        afterBody: (items) => acPeso(AC_REV[items[0].dataIndex] || 0)
      }}
    },
    scales: acAxes((v) => Number.isInteger(v) ? v : '')
  }
});

/* Table-view twins — the non-visual channel for every chart on the page. */
document.querySelectorAll('.ac-table-toggle').forEach((btn) => {
  btn.addEventListener('click', () => {
    const twin = document.getElementById(btn.dataset.twin);
    const chart = btn.closest('.ac-card').querySelector('.ac-chart');
    const showTable = twin.hidden;
    twin.hidden = !showTable;
    if (chart) chart.hidden = showTable;
    btn.setAttribute('aria-expanded', String(showTable));
    btn.textContent = showTable ? 'View as chart' : 'View as table';
  });
});
</script>

<?php include '../includes/footer.php'; ?>
