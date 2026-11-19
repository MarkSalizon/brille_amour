<?php
/**
 * Roles & Permissions — Brille Amour Control Center
 * ─────────────────────────────────────────────────────────────
 * Owns every account-state and department change in one place, so
 * the Admin Overview can post its Approve action here rather than
 * duplicating the logic. Every mutation is CSRF-guarded and written
 * to admin_activity_log, which is what feeds the overview's
 * "recent permission changes" panel.
 */

require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Roles & Permissions';

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/staff_roles.php';
require_once '../includes/admin_settings.php';

ensureStaffRoleColumn($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf   = $_SESSION['csrf_token'];
$adminId = (int) $_SESSION['user_id'];

/* =============================================================
   HELPERS
   ============================================================= */

function acFlash(string $type, string $msg): void
{
    $_SESSION['ac_flash'] = ['type' => $type, 'msg' => $msg];
}

function acLog(mysqli $conn, int $adminId, string $action, string $targetType, ?int $targetId, string $details): void
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare("INSERT INTO admin_activity_log
        (admin_id, action, target_type, target_id, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ississ', $adminId, $action, $targetType, $targetId, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

/** Active seats currently taken by a role. */
function acActiveSeats(mysqli $conn, string $role, int $exclude = 0): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users
                            WHERE role = ? AND status = 'active' AND id <> ?");
    $stmt->bind_param('si', $role, $exclude);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $n;
}

function acFetchUser(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT id, full_name, username, role, staff_role, status FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Does this account have production or order history attached? */
function acHasHistory(mysqli $conn, int $id): bool
{
    $stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM production_logs WHERE updated_by = ?) +
        (SELECT COUNT(*) FROM orders WHERE created_by = ? OR assigned_to = ?) AS n");
    $stmt->bind_param('iii', $id, $id, $id);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n > 0;
}

/* =============================================================
   MUTATIONS
   ============================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = ($_POST['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'roles.php';

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        acFlash('error', 'Security token expired. Please try that again.');
        header('Location: ' . $back);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);
    $target = acFetchUser($conn, $userId);

    if (!$target) {
        acFlash('error', 'That account no longer exists.');
        header('Location: ' . $back);
        exit;
    }

    $name = $target['full_name'];

    switch ($action) {

        case 'approve':
            if ($target['status'] === 'active') {
                acFlash('info', htmlspecialchars($name) . ' is already active.');
                break;
            }
            $cap = effectiveRoleLimit($conn, $target['role']);
            if (acActiveSeats($conn, $target['role'], $userId) >= $cap) {
                acFlash('error', 'Cannot approve — the ' . $target['role'] . ' seat limit of ' . $cap . ' is full. Raise it in System Settings or suspend another account first.');
                break;
            }
            $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            acLog($conn, $adminId, 'approved account', 'user', $userId, $name . ' activated as ' . $target['role']);
            acFlash('success', htmlspecialchars($name) . ' can now sign in.');
            break;

        case 'suspend':
            if ($userId === $adminId) {
                acFlash('error', 'You cannot suspend your own account.');
                break;
            }
            if ($target['role'] === 'admin' && acActiveSeats($conn, 'admin', $userId) === 0) {
                acFlash('error', 'That is the last active administrator — suspending it would lock everyone out.');
                break;
            }
            $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            acLog($conn, $adminId, 'suspended account', 'user', $userId, $name . ' access revoked');
            acFlash('success', htmlspecialchars($name) . ' has been suspended.');
            break;

        case 'decline':
            if ($target['status'] !== 'inactive') {
                acFlash('error', 'Only a pending account can be declined.');
                break;
            }
            if (acHasHistory($conn, $userId)) {
                acFlash('error', htmlspecialchars($name) . ' has order or production history and cannot be removed. Leave it suspended instead.');
                break;
            }
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND status = 'inactive'");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            acLog($conn, $adminId, 'declined access request', 'user', $userId, $name . ' (@' . $target['username'] . ') removed');
            acFlash('success', 'Access request from ' . htmlspecialchars($name) . ' was declined.');
            break;

        case 'update':
            $newRole = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
            $newDept = $_POST['staff_role'] ?? '';
            if ($newDept !== '' && !isValidStaffRole($newDept)) {
                $newDept = '';
            }
            // A department only means something for staff.
            if ($newRole === 'admin') {
                $newDept = '';
            }

            if ($userId === $adminId && $newRole !== 'admin') {
                acFlash('error', 'You cannot remove your own administrator access.');
                break;
            }
            if ($target['role'] === 'admin' && $newRole !== 'admin'
                && $target['status'] === 'active' && acActiveSeats($conn, 'admin', $userId) === 0) {
                acFlash('error', 'That is the last active administrator — keep at least one.');
                break;
            }
            if ($newRole !== $target['role'] && $target['status'] === 'active') {
                $cap = effectiveRoleLimit($conn, $newRole);
                if (acActiveSeats($conn, $newRole, $userId) >= $cap) {
                    acFlash('error', 'Cannot change role — the ' . $newRole . ' seat limit of ' . $cap . ' is full.');
                    break;
                }
            }

            $deptVal = $newDept === '' ? null : $newDept;
            $stmt = $conn->prepare("UPDATE users SET role = ?, staff_role = ? WHERE id = ?");
            $stmt->bind_param('ssi', $newRole, $deptVal, $userId);
            $stmt->execute();
            $stmt->close();

            $changes = [];
            if ($newRole !== $target['role']) {
                $changes[] = 'role ' . $target['role'] . ' → ' . $newRole;
            }
            if (($target['staff_role'] ?? '') !== ($deptVal ?? '')) {
                $changes[] = 'department ' . staffRoleLabel($target['staff_role']) . ' → ' . ($deptVal ? staffRoleLabel($deptVal) : 'Unassigned');
            }
            if ($changes) {
                acLog($conn, $adminId, 'changed permissions', 'user', $userId, $name . ': ' . implode(', ', $changes));
                acFlash('success', htmlspecialchars($name) . ' updated — ' . htmlspecialchars(implode(', ', $changes)) . '.');
            } else {
                acFlash('info', 'Nothing changed for ' . htmlspecialchars($name) . '.');
            }
            break;

        default:
            acFlash('error', 'Unrecognised action.');
    }

    header('Location: ' . $back);
    exit;
}

/* =============================================================
   READS
   ============================================================= */

$flash = $_SESSION['ac_flash'] ?? null;
unset($_SESSION['ac_flash']);

$lastSeen = [];
$res = $conn->query("SELECT updated_by, MAX(created_at) AS seen
                     FROM production_logs WHERE updated_by IS NOT NULL GROUP BY updated_by");
while ($row = $res->fetch_assoc()) {
    $lastSeen[(int) $row['updated_by']] = $row['seen'];
}
$res->free();

$pending = [];
$accounts = [];
$res = $conn->query("SELECT id, full_name, username, email, role, staff_role, status, created_at
                     FROM users
                     ORDER BY FIELD(status,'inactive','active'), FIELD(role,'admin','staff'), full_name ASC");
while ($row = $res->fetch_assoc()) {
    if ($row['status'] === 'inactive') {
        $pending[] = $row;
    } else {
        $accounts[] = $row;
    }
}
$res->free();

$seats = [
    'admin' => ['used' => acActiveSeats($conn, 'admin'), 'cap' => effectiveRoleLimit($conn, 'admin')],
    'staff' => ['used' => acActiveSeats($conn, 'staff'), 'cap' => effectiveRoleLimit($conn, 'staff')],
];

$changeLog = [];
$res = $conn->query("SELECT al.action, al.details, al.created_at, a.full_name AS admin_name
                     FROM admin_activity_log al
                     LEFT JOIN users a ON a.id = al.admin_id
                     WHERE al.target_type IN ('user','role','settings')
                     ORDER BY al.created_at DESC LIMIT 12");
while ($row = $res->fetch_assoc()) {
    $changeLog[] = $row;
}
$res->free();

/* ── the capability matrix, derived from what the code actually enforces ── */
$roleCols = ['admin' => 'Administrator'];
foreach (getStaffRoles() as $key => $meta) {
    $roleCols[$key] = $meta['label'];
}

// How many *active* people currently hold each column, straight from $accounts.
$heldBy = array_fill_keys(array_keys($roleCols), 0);
foreach ($accounts as $a) {
    $slot = $a['role'] === 'admin' ? 'admin' : ($a['staff_role'] ?: null);
    if ($slot !== null && isset($heldBy[$slot])) {
        $heldBy[$slot]++;
    }
}

$capabilities = [
    ['Sign in to the portal',                 fn($r) => true],
    ['View the production board',             fn($r) => true],
    ['View inventory levels',                 fn($r) => true],
    ['Verify customer downpayments',          fn($r) => $r === 'admin'],
    ['Create and edit customer orders',       fn($r) => $r === 'admin'],
    ['Cancel or archive eligible orders',     fn($r) => $r === 'admin'],
    ['Complete orders that are Ready',        fn($r) => $r === 'admin'],
    ['Place production on hold / resume',     fn($r) => $r === 'production'],
    ['Send In Production to Quality Check',   fn($r) => $r === 'production'],
    ['Approve Quality Check as Ready',        fn($r) => $r === 'quality_check'],
    ['Return failed QC to Production',        fn($r) => $r === 'quality_check'],
    ['Adjust inventory and reserves',         fn($r) => $r === 'admin'],
    ['View reports and analytics',            fn($r) => $r === 'admin'],
    ['Manage customers',                      fn($r) => $r === 'admin'],
    ['Approve or suspend accounts',           fn($r) => $r === 'admin'],
    ['Assign roles and departments',          fn($r) => $r === 'admin'],
    ['Change system settings',                fn($r) => $r === 'admin'],
];

include '../includes/header.php';
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" style="margin-bottom:18px">
    <?= $flash['msg'] ?>
  </div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     SEATS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <div class="ac-section-head">
    <div>
      <h2>Roles &amp; permissions</h2>
      <p>Who can sign in, what department they work in, and exactly what each role is allowed to do.</p>
    </div>
    <a class="ac-link" href="users.php">Teams / Users →</a>
  </div>

  <div class="ac-grid-2">
    <?php foreach (['admin' => 'Administrator seats', 'staff' => 'Staff seats'] as $role => $lbl):
        $used = $seats[$role]['used'];
        $cap  = $seats[$role]['cap'];
        $pct  = $cap > 0 ? min(100, round($used / $cap * 100)) : 0;
        $tone = $pct >= 100 ? 'crit' : ($pct >= 80 ? 'warn' : 'good');
    ?>
      <article class="ac-kpi">
        <div class="ac-kpi-label"><?= acIcon($role === 'admin' ? 'roles' : 'teams') ?> <?= $lbl ?></div>
        <div class="ac-kpi-value"><?= $used ?><span class="ac-kpi-unit" style="margin-left:4px">/ <?= $cap ?></span></div>
        <div class="ac-meter-row">
          <span><?= max(0, $cap - $used) ?> seat<?= ($cap - $used) === 1 ? '' : 's' ?> free</span>
          <b><?= $pct ?>%</b>
        </div>
        <span class="ac-meter" data-tone="<?= $tone ?>">
          <span class="ac-meter-fill ac-meter-<?= $tone ?>" style="width:<?= max(2, $pct) ?>%"></span>
        </span>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     ACCESS REQUESTS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <article class="ac-card">
    <div class="ac-card-head">
      <div>
        <h3>Access requests</h3>
        <p>Accounts created through sign-up that cannot sign in until approved</p>
      </div>
      <?php if ($pending): ?><span class="ac-tag ac-tag-warn"><?= count($pending) ?> waiting</span><?php endif; ?>
    </div>

    <?php if (!$pending): ?>
      <div class="ac-empty">
        <svg viewBox="0 0 24 24"><path d="M20 6.5 9.5 17 4 11.5"/></svg>
        <p>No pending access requests</p>
        <small>Every account in the system has been reviewed.</small>
      </div>
    <?php else: ?>
      <?php foreach ($pending as $p): ?>
        <div class="ac-req" id="user-<?= (int) $p['id'] ?>">
          <span class="ac-avatar-sm"><?= strtoupper(substr($p['full_name'], 0, 1)) ?></span>
          <span class="ac-req-main">
            <span class="ac-row-title">
              <strong><?= htmlspecialchars($p['full_name']) ?></strong>
              <span class="ac-tag"><?= ucfirst($p['role']) ?></span>
              <?php if ($p['staff_role']): ?>
                <span class="ac-tag"><?= htmlspecialchars(staffRoleLabel($p['staff_role'])) ?></span>
              <?php endif; ?>
            </span>
            <span class="ac-row-sub">
              @<?= htmlspecialchars($p['username']) ?>
              <?= $p['email'] ? ' &middot; ' . htmlspecialchars($p['email']) : '' ?>
              &middot; requested <?= timeAgo($p['created_at']) ?>
            </span>
          </span>
          <span class="ac-req-actions">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="ac-btn ac-btn-primary">Approve</button>
            </form>
            <form method="post" onsubmit="return confirm('Decline and permanently remove the account for <?= htmlspecialchars(addslashes($p['full_name'])) ?>?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="decline">
              <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="ac-btn ac-btn-danger">Decline</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <div class="ac-card-foot">
        Declining removes the account. An account with order or production history cannot be removed — suspend it instead.
      </div>
    <?php endif; ?>
  </article>
</section>

<!-- ══════════════════════════════════════════════════════════
     ACTIVE ACCOUNTS
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <article class="ac-card">
    <div class="ac-card-head">
      <div>
        <h3>Active accounts</h3>
        <p>Set the portal role and the production department for each person</p>
      </div>
      <span class="ac-tag"><?= count($accounts) ?> active</span>
    </div>

    <?php if (!$accounts): ?>
      <div class="ac-empty">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8.2" r="3.6"/><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0"/></svg>
        <p>No active accounts</p>
      </div>
    <?php else: ?>
      <?php foreach ($accounts as $a):
          $isSelf = (int) $a['id'] === $adminId;
      ?>
        <div class="ac-req" id="user-<?= (int) $a['id'] ?>">
          <span class="ac-avatar-sm"><?= strtoupper(substr($a['full_name'], 0, 1)) ?></span>
          <span class="ac-req-main">
            <span class="ac-row-title">
              <strong><?= htmlspecialchars($a['full_name']) ?></strong>
              <?php if ($isSelf): ?><span class="ac-tag ac-tag-good">You</span><?php endif; ?>
            </span>
            <span class="ac-row-sub">
              @<?= htmlspecialchars($a['username']) ?>
              &middot; <?= isset($lastSeen[(int) $a['id']])
                    ? 'last activity ' . timeAgo($lastSeen[(int) $a['id']])
                    : 'no production activity yet' ?>
            </span>
          </span>

          <form method="post" class="ac-req-actions" style="align-items:center">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">

            <select name="role" class="ac-btn" style="padding:6px 8px" aria-label="Portal role for <?= htmlspecialchars($a['full_name']) ?>">
              <option value="staff" <?= $a['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
              <option value="admin" <?= $a['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>

            <select name="staff_role" class="ac-btn" style="padding:6px 8px" aria-label="Department for <?= htmlspecialchars($a['full_name']) ?>">
              <option value="">Unassigned</option>
              <?php foreach (getStaffRoles() as $key => $meta): ?>
                <option value="<?= $key ?>" <?= $a['staff_role'] === $key ? 'selected' : '' ?>>
                  <?= htmlspecialchars($meta['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <button type="submit" class="ac-btn ac-btn-primary">Save</button>
          </form>

          <span class="ac-req-actions">
            <?php if (!$isSelf): ?>
              <form method="post" onsubmit="return confirm('Suspend <?= htmlspecialchars(addslashes($a['full_name'])) ?>? They will not be able to sign in.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="suspend">
                <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="ac-btn ac-btn-danger">Suspend</button>
              </form>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
      <div class="ac-card-foot">
        A department only applies to staff accounts — administrators reach every stage. Changing a role writes to the audit log below.
      </div>
    <?php endif; ?>
  </article>
</section>

<!-- ══════════════════════════════════════════════════════════
     CAPABILITY MATRIX
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <article class="ac-card">
    <div class="ac-card-head">
      <div>
        <h3>Role capability matrix</h3>
        <p>What each role can do, taken from the permissions the application enforces</p>
      </div>
    </div>
    <div class="ac-table-view" style="max-height:none;border-top:0">
      <table class="ac-table">
        <thead>
          <tr>
            <th scope="col">Capability</th>
            <?php foreach ($roleCols as $key => $lbl): ?>
              <th scope="col">
                <?= htmlspecialchars($lbl) ?>
                <br><span style="font-weight:600;letter-spacing:0;text-transform:none;color:var(--ac-ink-3)">
                  <?= $heldBy[$key] ?> active
                </span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($capabilities as [$label, $test]): ?>
            <tr>
              <td><?= htmlspecialchars($label) ?></td>
              <?php foreach (array_keys($roleCols) as $key): ?>
                <?php $ok = $test($key); ?>
                <td>
                  <?php if ($ok): ?>
                    <span class="ac-tag ac-tag-good" title="Allowed">Yes</span>
                  <?php else: ?>
                    <span class="ac-tag" title="Not allowed">— No</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-card-foot">
      Stage permissions come from each department's owned stages, so a change here follows the department assignment above rather than a separate list.
    </div>
  </article>
</section>

<!-- ══════════════════════════════════════════════════════════
     AUDIT LOG
     ══════════════════════════════════════════════════════════ -->
<section class="ac-section">
  <article class="ac-card">
    <div class="ac-card-head">
      <div>
        <h3>Permission change log</h3>
        <p>Every account and role change, most recent first</p>
      </div>
    </div>
    <?php if (!$changeLog): ?>
      <div class="ac-empty">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
        <p>No changes recorded yet</p>
        <small>Approvals, suspensions and role changes are logged here as they happen.</small>
      </div>
    <?php else: ?>
      <div class="ac-log">
        <?php foreach ($changeLog as $lg): ?>
          <div class="ac-log-item">
            <span class="ac-log-rail"><span class="ac-log-dot is-rose"></span></span>
            <span class="ac-log-body">
              <strong><?= htmlspecialchars($lg['admin_name'] ?: 'System') ?></strong>
              <?= htmlspecialchars($lg['action']) ?>
              <?= $lg['details'] ? '&mdash; ' . htmlspecialchars($lg['details']) : '' ?>
              <time><?= appDateTime($lg['created_at']) ?> &middot; <?= timeAgo($lg['created_at']) ?></time>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
</section>

<?php include '../includes/footer.php'; ?>
