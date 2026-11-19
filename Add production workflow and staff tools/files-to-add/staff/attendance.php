<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Attendance';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/attendance.php';

$uid = (int)$_SESSION['user_id'];
$msg = '';
$err = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        $err = 'Invalid request token. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'time_in') {
            $result = staffTimeIn($conn, $uid, 'manual');
            $msg = !empty($result['created']) ? 'Time in recorded.' : 'You are already timed in today.';
        } elseif ($action === 'time_out') {
            $result = staffTimeOut($conn, $uid);
            if (!empty($result['ok'])) {
                $msg = !empty($result['changed']) ? 'Time out recorded.' : 'You already timed out today.';
            } else {
                $err = $result['error'] ?? 'Unable to record time out.';
            }
        }
    }
}

$today = staffAttendanceToday($conn, $uid);
$history = staffAttendanceHistory($conn, $uid, 14);
$timedIn = $today && empty($today['time_out']);
$hasTimedOut = $today && !empty($today['time_out']);

include '../includes/staff_header.php';
?>
<link rel="stylesheet" href="../assets/css/staff-attendance.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-attendance.css') ?>">
<link rel="stylesheet" href="../assets/css/staff-portal-professional.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-portal-professional.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<section class="attendance-page">
  <div class="attendance-hero">
    <div>
      <h1>Attendance</h1>
      <p><?= date('l, F j, Y') ?></p>
    </div>
    <div class="attendance-status <?= $timedIn ? 'active' : ($hasTimedOut ? 'done' : '') ?>">
      <?= $timedIn ? 'On shift' : ($hasTimedOut ? 'Shift completed' : 'Not timed in') ?>
    </div>
  </div>

  <div class="attendance-grid">
    <article class="attendance-card primary">
      <span class="card-label">Time In</span>
      <strong><?= $today ? date('h:i A', strtotime($today['time_in'])) : '--:--' ?></strong>
      <small><?= $today ? 'Recorded automatically after login' : 'No time in yet' ?></small>
    </article>

    <article class="attendance-card">
      <span class="card-label">Time Out</span>
      <strong><?= $hasTimedOut ? date('h:i A', strtotime($today['time_out'])) : '--:--' ?></strong>
      <small><?= $hasTimedOut ? 'Shift closed for today' : 'Use Time Out when your shift ends' ?></small>
    </article>

    <article class="attendance-card">
      <span class="card-label">Duration</span>
      <strong><?= $today ? htmlspecialchars(staffAttendanceDuration($today['time_in'], $today['time_out'] ?? null)) : '-' ?></strong>
      <small><?= $timedIn ? 'Still counting until time out' : 'Total shift time' ?></small>
    </article>
  </div>

  <div class="attendance-actions">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="time_in">
      <button class="btn btn-primary" type="submit" <?= $today ? 'disabled' : '' ?>>Time In</button>
    </form>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="time_out">
      <button class="btn btn-secondary" type="submit" <?= (!$today || $hasTimedOut) ? 'disabled' : '' ?>>Time Out</button>
    </form>
  </div>

  <div class="attendance-history">
    <div class="history-head">
      <strong>Attendance history</strong>
      <span><?= count($history) ?> record<?= count($history) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Duration</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$history): ?>
            <tr><td colspan="5"><div class="empty-state">No attendance records yet.</div></td></tr>
          <?php else: foreach ($history as $row): ?>
            <tr>
              <td data-label="Date"><strong><?= date('M j, Y', strtotime($row['attendance_date'])) ?></strong></td>
              <td data-label="Time in"><?= date('h:i A', strtotime($row['time_in'])) ?></td>
              <td data-label="Time out"><?= !empty($row['time_out']) ? date('h:i A', strtotime($row['time_out'])) : '-' ?></td>
              <td data-label="Duration"><?= htmlspecialchars(staffAttendanceDuration($row['time_in'], $row['time_out'] ?? null)) ?></td>
              <td data-label="Status">
                <span class="attendance-chip <?= empty($row['time_out']) ? 'active' : 'done' ?>">
                  <?= empty($row['time_out']) ? 'On shift' : 'Completed' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php include '../includes/staff_footer.php'; ?>
