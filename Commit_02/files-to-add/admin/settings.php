<?php
/**
 * System Settings — Brille Amour Control Center
 * ─────────────────────────────────────────────────────────────
 * A form over includes/admin_settings.php. Values are clamped by
 * settingBounds() on the way in, so a hand-edited field cannot put
 * the dashboard into an impossible state. Every save is written to
 * admin_activity_log.
 */

require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'System Settings';

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/staff_roles.php';
require_once '../includes/admin_settings.php';
require_once '../includes/production_capacity.php';
require_once '../includes/paymongo.php';

ensureSettingsSchema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf    = $_SESSION['csrf_token'];
$adminId = (int) $_SESSION['user_id'];

if (!function_exists('acLog')) {
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
}

/* Which keys belong to which tab, so a partial save can't blank the others. */
$groups = [
    'payments' => ['gcash_name', 'gcash_number', 'gcash_qr'],
    'identity'  => ['business_name', 'business_tagline', 'business_email', 'business_phone', 'business_address'],
    'operations' => ['production_wip_limit', 'quality_check_wip_limit', 'pickup_daily_capacity', 'due_soon_days', 'analytics_default'],
    'seats'     => ['max_admin_users', 'max_staff_users'],
    'inventory' => ['default_reorder_level', 'watch_multiplier'],
    'notify'    => ['notify_new_order', 'notify_status_change', 'notify_low_stock', 'notify_access_request'],
];
$toggleKeys = $groups['notify'];

$groupLabels = [
    'payments' => 'Payment details',
    'identity'   => 'Business identity',
    'operations' => 'Operations',
    'seats'      => 'User capacity',
    'inventory'  => 'Inventory thresholds',
    'notify'     => 'Notifications',
];

/* =============================================================
   SAVE
   ============================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $_SESSION['ac_flash'] = ['type' => 'error', 'msg' => 'Security token expired. Please try that again.'];
        header('Location: settings.php');
        exit;
    }

    $group = $_POST['group'] ?? '';

    if ($group === 'reset' && isset($groups[$_POST['reset_group'] ?? ''])) {
        $rg       = $_POST['reset_group'];
        $defaults = settingDefaults();
        $pairs    = [];
        foreach ($groups[$rg] as $key) {
            $pairs[$key] = $defaults[$key];
        }
        $changed = saveSettings($conn, $pairs, $adminId);
        if ($changed) {
            acLog($conn, $adminId, 'reset settings', 'settings', null,
                $groupLabels[$rg] . ' restored to defaults (' . $changed . ' value' . ($changed === 1 ? '' : 's') . ')');
        }
        $_SESSION['ac_flash'] = [
            'type' => $changed ? 'success' : 'info',
            'msg'  => $changed
                ? htmlspecialchars($groupLabels[$rg]) . ' restored to defaults.'
                : htmlspecialchars($groupLabels[$rg]) . ' was already at its defaults.',
        ];
        header('Location: settings.php#g-' . $rg);
        exit;
    }

    if (!isset($groups[$group])) {
        $_SESSION['ac_flash'] = ['type' => 'error', 'msg' => 'Unknown settings group.'];
        header('Location: settings.php');
        exit;
    }

    $pairs  = [];
    $errors = [];

    foreach ($groups[$group] as $key) {
        if (in_array($key, $toggleKeys, true)) {
            // An unchecked box sends nothing, which is the "off" signal.
            $pairs[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }
        if (!isset($_POST[$key])) {
            continue;
        }
        $pairs[$key] = trim((string) $_POST[$key]);
    }

    // Production and Quality Check intentionally share one active-order limit.
    // The admin edits one field; the legacy QC key is mirrored for consistency.
    if ($group === 'operations' && isset($pairs['production_wip_limit'])) {
        $pairs['quality_check_wip_limit'] = $pairs['production_wip_limit'];
    }

    if ($group === 'payments') {
        unset($pairs['gcash_qr']);
        if (empty($pairs['gcash_name']) || (($pairs['gcash_number'] ?? '') !== '' && strlen($pairs['gcash_number']) > 40)) {
            $_SESSION['ac_flash'] = ['type' => 'error', 'msg' => 'Enter the account name shown on the QR. The mobile/account number is optional and must be 40 characters or fewer.'];
            header('Location: settings.php#g-payments'); exit;
        }
        if (($_FILES['gcash_qr_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadError = '';
            $qr = saveUploadedImage($_FILES['gcash_qr_upload'], '../uploads/gcash/', $uploadError, 'qr_');
            if (!$qr) {
                $_SESSION['ac_flash'] = ['type' => 'error', 'msg' => htmlspecialchars($uploadError ?: 'QR upload failed.')];
                header('Location: settings.php#g-payments'); exit;
            }
            $pairs['gcash_qr'] = $qr;
        }
    }
    // Field-level validation beyond the numeric clamp.
    if (isset($pairs['business_name']) && $pairs['business_name'] === '') {
        $errors[] = 'Business name cannot be empty.';
        unset($pairs['business_name']);
    }
    if (!empty($pairs['business_email']) && !filter_var($pairs['business_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That business email address is not valid.';
        unset($pairs['business_email']);
    }
    if (isset($pairs['analytics_default'])
        && !in_array($pairs['analytics_default'], ['day', 'week', 'month', 'year'], true)) {
        unset($pairs['analytics_default']);
    }

    // Never let a seat cap drop below the seats already occupied.
    foreach (['max_admin_users' => 'admin', 'max_staff_users' => 'staff'] as $key => $role) {
        if (!isset($pairs[$key])) {
            continue;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE role = ? AND status = 'active'");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $used = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        if ((int) $pairs[$key] < $used) {
            $errors[] = 'The ' . $role . ' limit cannot go below the ' . $used . ' account'
                . ($used === 1 ? '' : 's') . ' already active.';
            unset($pairs[$key]);
        }
    }

    $changed = saveSettings($conn, $pairs, $adminId);

    if ($changed && $group === 'operations' && array_key_exists('production_wip_limit', $pairs)) {
        settingsCacheBust();
        $moves = reconcileProductionQueues($conn, $adminId);
        if ($moves) {
            require_once '../includes/messaging.php';
            foreach ($moves as $move) {
                notifyOrderStageChange($conn, (int)$move['id'], (string)$move['order_number'], (string)$move['from'], (string)$move['to'], $adminId);
            }
        }
    }

    if ($changed) {
        acLog($conn, $adminId, 'updated settings', 'settings', null,
            $groupLabels[$group] . ': ' . $changed . ' value' . ($changed === 1 ? '' : 's') . ' changed');
    }

    if ($errors) {
        $_SESSION['ac_flash'] = [
            'type' => 'warning',
            'msg'  => ($changed ? 'Saved ' . $changed . ' change' . ($changed === 1 ? '' : 's') . ', but: ' : '')
                . htmlspecialchars(implode(' ', $errors)),
        ];
    } else {
        $_SESSION['ac_flash'] = [
            'type' => $changed ? 'success' : 'info',
            'msg'  => $changed
                ? htmlspecialchars($groupLabels[$group]) . ' saved — ' . $changed . ' value' . ($changed === 1 ? '' : 's') . ' updated.'
                : 'Nothing to save — no values changed.',
        ];
    }

    header('Location: settings.php#g-' . $group);
    exit;
}

/* =============================================================
   READS
   ============================================================= */

$flash = $_SESSION['ac_flash'] ?? null;
unset($_SESSION['ac_flash']);

settingsCacheBust();
$s        = getSettings($conn);
$defaults = settingDefaults();
$bounds   = settingBounds();

/** Print min/max/step attributes and the "default N" hint for a bounded int. */
function acBoundAttrs(string $key): string
{
    $b = settingBounds();
    if (!isset($b[$key])) {
        return '';
    }
    return ' min="' . $b[$key][0] . '" max="' . $b[$key][1] . '" step="1"';
}

function acHint(string $key): string
{
    $d = settingDefaults()[$key] ?? '';
    $b = settingBounds();
    $out = 'Default ' . htmlspecialchars((string) $d);
    if (isset($b[$key])) {
        $out .= ' · allowed ' . $b[$key][0] . '–' . $b[$key][1];
    }
    return $out;
}

// Live context so the numbers on this page aren't abstract.
$ctx = $conn->query("SELECT
    (SELECT COUNT(*) FROM users     WHERE role='admin' AND status='active')  AS admins,
    (SELECT COUNT(*) FROM users     WHERE role='staff' AND status='active')  AS staff,
    (SELECT COUNT(*) FROM orders    WHERE status IN ('pending','in_production','quality_check','ready')) AS active_orders,
    (SELECT COUNT(*) FROM inventory WHERE status='active')                   AS items,
    (SELECT COUNT(*) FROM inventory WHERE status='active' AND quantity <= reorder_level) AS below
")->fetch_assoc();
$ctx = array_map('intval', $ctx);


$lastSaved = null;
$res = $conn->query("SELECT s.updated_at, u.full_name
                     FROM settings s LEFT JOIN users u ON u.id = s.updated_by
                     ORDER BY s.updated_at DESC LIMIT 1");
if ($res && $res->num_rows) {
    $lastSaved = $res->fetch_assoc();
}
if ($res) {
    $res->free();
}


include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-settings-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-settings-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-settings-v2.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-settings-v2.css') ?>">
<main class="settings-page" id="systemSettings">

<?php if ($flash): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" style="margin-bottom:18px">
    <?= $flash['msg'] ?>
  </div>
<?php endif; ?>

<section class="ac-section">
  <header class="settings-page-head">
    <div>
      <h1>System settings</h1>
      <p>Payment details, business information and day-to-day controls.</p>
    </div>
    <?php if ($lastSaved): ?>
      <p class="settings-last-saved">Updated <?= htmlspecialchars(timeAgo($lastSaved['updated_at']), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </header>
  <nav class="settings-menu" id="settingsMenu" role="tablist" aria-label="System settings sections">
    <button type="button" class="settings-menu-tab is-active" role="tab" id="settings-tab-payments" aria-controls="g-payments" aria-selected="true" tabindex="0" data-settings-target="g-payments">Payments</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-identity" aria-controls="g-identity" aria-selected="false" tabindex="-1" data-settings-target="g-identity">Business details</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-operations" aria-controls="g-operations" aria-selected="false" tabindex="-1" data-settings-target="g-operations">Operations</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-seats" aria-controls="g-seats" aria-selected="false" tabindex="-1" data-settings-target="g-seats">User capacity</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-inventory" aria-controls="g-inventory" aria-selected="false" tabindex="-1" data-settings-target="g-inventory">Inventory</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-notify" aria-controls="g-notify" aria-selected="false" tabindex="-1" data-settings-target="g-notify">Notifications</button>
    <button type="button" class="settings-menu-tab" role="tab" id="settings-tab-technical" aria-controls="settingsEnvironment" aria-selected="false" tabindex="-1" data-settings-target="settingsEnvironment">Technical</button>
  </nav>
  <div class="settings-tabs-content" id="settingsTabsContent">
    <article class="ac-card settings-payment-card" id="g-payments" data-settings-slide="Payment details" role="tabpanel" aria-labelledby="settings-tab-payments" tabindex="0">
      <div class="ac-card-head"><div><h3>Payment details</h3><p>Account and QR shown to customers at checkout.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="ac-form settings-payment-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group" value="payments">
        <div class="settings-payment-layout">
          <div class="settings-payment-fields">
            <label class="ac-field"><span>Receiving account name</span><input type="text" name="gcash_name" maxlength="150" required value="<?= htmlspecialchars($s['gcash_name']) ?>"></label>
            <label class="ac-field"><span>Mobile / account number <em>Optional</em></span><input type="text" name="gcash_number" maxlength="40" value="<?= htmlspecialchars($s['gcash_number']) ?>"></label>
          </div>
          <div class="settings-qr-panel">
            <label class="ac-field"><span>Payment QR image</span><input type="file" name="gcash_qr_upload" accept="image/png,image/jpeg,image/webp,image/gif" <?= $s['gcash_qr'] === '' ? 'required' : '' ?>><small>PNG, JPG or WebP · up to 5 MB</small></label>
            <?php if ($s['gcash_qr'] !== ''): ?><img class="settings-qr-preview" src="../uploads/gcash/<?= htmlspecialchars(basename($s['gcash_qr'])) ?>" alt="Current payment QR"><?php endif; ?>
          </div>
        </div>
        <p class="settings-payment-note">Make sure the account name matches the recipient encoded in the uploaded QR.</p>
        <div class="ac-form-foot settings-payment-actions"><button class="ac-btn ac-btn-primary" type="submit">Save payment details</button><a class="ac-link" href="payments.php">View payments</a></div>
      </form>
      <details class="settings-gateway">
        <summary><span><strong>Online payment gateway</strong><small>PayMongo · <?= paymongoReady() ? (paymongoLive() ? 'Live configuration' : 'Test configuration') : 'Not configured' ?></small></span><span class="settings-gateway-chevron" aria-hidden="true">⌄</span></summary>
        <div class="settings-gateway-content">
          <p>Configure <code>PAYMONGO_ENABLED</code>, <code>PAYMONGO_SECRET_KEY</code>, <code>PAYMONGO_WEBHOOK_SECRET</code>, and <code>PAYMONGO_PUBLIC_URL</code> in the server <code>.env</code> file. Use a public HTTPS URL and activate GCash in your PayMongo account.</p>
          <p>Webhook: <code>customer/paymongo-webhook.php</code> · Event: <code>checkout_session.payment.paid</code></p>
        </div>
      </details>
    </article>
    <!-- ══════════════ BUSINESS IDENTITY ══════════════ -->
    <article class="ac-card" id="g-identity" data-settings-slide="Business details" role="tabpanel" aria-labelledby="settings-tab-identity" tabindex="0">
      <div class="ac-card-head">
        <div>
          <h3>Business details</h3>
        </div>
      </div>
      <form method="post" class="ac-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group" value="identity">

        <label class="ac-field">
          <span class="ac-field-label">Business name</span>
          <input type="text" name="business_name" maxlength="80" required
                 value="<?= htmlspecialchars($s['business_name']) ?>">
        </label>

        <label class="ac-field">
          <span class="ac-field-label">Tagline</span>
          <input type="text" name="business_tagline" maxlength="120"
                 value="<?= htmlspecialchars($s['business_tagline']) ?>">
        </label>

        <div class="ac-field-pair">
          <label class="ac-field">
            <span class="ac-field-label">Contact email</span>
            <input type="email" name="business_email" maxlength="120"
                   placeholder="orders@example.com"
                   value="<?= htmlspecialchars($s['business_email']) ?>">
          </label>
          <label class="ac-field">
            <span class="ac-field-label">Contact number</span>
            <input type="text" name="business_phone" maxlength="40"
                   placeholder="+63 …"
                   value="<?= htmlspecialchars($s['business_phone']) ?>">
          </label>
        </div>

        <label class="ac-field">
          <span class="ac-field-label">Atelier address</span>
          <textarea name="business_address" rows="2" maxlength="240"><?= htmlspecialchars($s['business_address']) ?></textarea>
        </label>

        <div class="ac-form-foot">
          <button type="submit" class="ac-btn ac-btn-primary">Save identity</button>
        </div>
      </form>
    </article>

    <!-- ══════════════ OPERATIONS ══════════════ -->
    <article class="ac-card" id="g-operations" data-settings-slide="Operations" role="tabpanel" aria-labelledby="settings-tab-operations" tabindex="0">
      <div class="ac-card-head">
        <div>
          <h3>Operations</h3>
        </div>
      </div>
      <form method="post" class="ac-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group" value="operations">

        <label class="ac-field">
          <span class="ac-field-label">Production &amp; Quality Check active accepted-order limit</span>
          <input type="number" name="production_wip_limit"<?= acBoundAttrs('production_wip_limit') ?>
                 value="<?= (int) $s['production_wip_limit'] ?>">
<small>Waiting has no limit. Only orders accepted by staff consume an active slot.</small>
        </label>

        <label class="ac-field">
          <span class="ac-field-label">Default pickup limit per day</span>
          <input type="number" name="pickup_daily_capacity"<?= acBoundAttrs('pickup_daily_capacity') ?>
                 value="<?= (int) $s['pickup_daily_capacity'] ?>">
          <small>Fully booked dates become unavailable at checkout.</small>
        </label>

        <label class="ac-field">
          <span class="ac-field-label">“Due soon” window (days)</span>
          <input type="number" name="due_soon_days"<?= acBoundAttrs('due_soon_days') ?>
                 value="<?= (int) $s['due_soon_days'] ?>">
          <small>Orders due within this window are flagged for review.</small>
        </label>

        <label class="ac-field">
          <span class="ac-field-label">Default analytics range</span>
          <select name="analytics_default">
            <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $s['analytics_default'] === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
          
        </label>

        <div class="ac-form-foot">
          <button type="submit" class="ac-btn ac-btn-primary">Save operations</button>
        </div>
      </form>
      <details class="settings-reset">
        <summary>Advanced options</summary>
        <form method="post" class="ac-reset-foot" onsubmit="return confirm('Restore the default settings for this section?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="group" value="reset">
          <input type="hidden" name="reset_group" value="operations">
          <button type="submit" class="ac-btn ac-btn-quiet">Restore defaults</button>
        </form>
      </details>
    </article>

    <!-- ══════════════ USER CAPACITY ══════════════ -->
    <article class="ac-card" id="g-seats" data-settings-slide="User capacity" role="tabpanel" aria-labelledby="settings-tab-seats" tabindex="0">
      <div class="ac-card-head">
        <div>
          <h3>User capacity</h3>
        </div>
        <a class="ac-link" href="users.php">Manage accounts →</a>
      </div>
      <form method="post" class="ac-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group" value="seats">

        <div class="ac-field-pair">
          <label class="ac-field">
            <span class="ac-field-label">Administrator limit</span>
            <input type="number" name="max_admin_users"<?= acBoundAttrs('max_admin_users') ?>
                   value="<?= (int) $s['max_admin_users'] ?>">
            <small><?= $ctx['admins'] ?> active now · <?= acHint('max_admin_users') ?></small>
          </label>
          <label class="ac-field">
            <span class="ac-field-label">Staff limit</span>
            <input type="number" name="max_staff_users"<?= acBoundAttrs('max_staff_users') ?>
                   value="<?= (int) $s['max_staff_users'] ?>">
            <small><?= $ctx['staff'] ?> active now · <?= acHint('max_staff_users') ?></small>
          </label>
        </div>


        <div class="ac-form-foot">
          <button type="submit" class="ac-btn ac-btn-primary">Save capacity</button>
        </div>
      </form>
    </article>

    <!-- ══════════════ INVENTORY ══════════════ -->
    <article class="ac-card" id="g-inventory" data-settings-slide="Inventory thresholds" role="tabpanel" aria-labelledby="settings-tab-inventory" tabindex="0">
      <div class="ac-card-head">
        <div>
          <h3>Inventory thresholds</h3>
        </div>
        <a class="ac-link" href="inventory.php">Open inventory →</a>
      </div>
      <form method="post" class="ac-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group" value="inventory">

        <label class="ac-field">
          <span class="ac-field-label">Default reserve level for new items</span>
          <input type="number" name="default_reorder_level"<?= acBoundAttrs('default_reorder_level') ?>
                 value="<?= (int) $s['default_reorder_level'] ?>">
          <small>Used when a new item has no custom reserve.</small>
        </label>

        <label class="ac-field">
          <span class="ac-field-label">Watch tier multiplier</span>
          <input type="number" name="watch_multiplier"<?= acBoundAttrs('watch_multiplier') ?>
                 value="<?= (int) $s['watch_multiplier'] ?>">
<small>Flags items approaching their reserve level.</small>
        </label>

        <div class="ac-form-foot">
          <button type="submit" class="ac-btn ac-btn-primary">Save thresholds</button>
        </div>
      </form>
      <details class="settings-reset">
        <summary>Advanced options</summary>
        <form method="post" class="ac-reset-foot" onsubmit="return confirm('Restore the default settings for this section?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="group" value="reset">
          <input type="hidden" name="reset_group" value="inventory">
          <button type="submit" class="ac-btn ac-btn-quiet">Restore defaults</button>
        </form>
      </details>
    </article>
<!-- ══════════════ NOTIFICATIONS ══════════════ -->
  <article class="ac-card" id="g-notify" data-settings-slide="Notifications" role="tabpanel" aria-labelledby="settings-tab-notify" tabindex="0">
    <div class="ac-card-head">
      <div>
        <h3>Notifications</h3>
        
      </div>
      <a class="ac-link" href="messaging.php">Open messages →</a>
    </div>
    <form method="post" class="ac-form settings-notification-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="group" value="notify">

      <?php
      $notifCopy = [
          'notify_new_order'      => ['A new order is placed', 'Alerts every administrator as soon as an order is created.'],
          'notify_status_change'  => ['An order changes stage', 'Follows the order through production, quality check, and dispatch.'],
          'notify_low_stock'      => ['Material drops to its reserve level', 'Raised once per item until stock is replenished.'],
          'notify_access_request' => ['Someone requests portal access', 'Alerts administrators when someone requests portal access.'],
      ];
      foreach ($notifCopy as $key => [$title, $sub]): ?>
        <label class="ac-toggle-row">
          <input type="checkbox" name="<?= $key ?>" value="1" <?= $s[$key] === '1' ? 'checked' : '' ?>>
          <span class="ac-toggle-copy">
            <strong><?= $title ?></strong>
            <small><?= $sub ?></small>
          </span>
        </label>
      <?php endforeach; ?>

      <div class="ac-form-foot">
        <button type="submit" class="ac-btn ac-btn-primary">Save notifications</button>
      </div>
    </form>
      <details class="settings-reset">
        <summary>Advanced options</summary>
        <form method="post" class="ac-reset-foot" onsubmit="return confirm('Restore the default settings for this section?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="group" value="reset">
          <input type="hidden" name="reset_group" value="notify">
          <button type="submit" class="ac-btn ac-btn-quiet">Restore defaults</button>
        </form>
      </details>
  </article>
<!-- ══════════════ ENVIRONMENT ══════════════ -->
  <details class="settings-environment-disclosure" id="settingsEnvironment" data-settings-slide="Technical information" role="tabpanel" aria-labelledby="settings-tab-technical" tabindex="0">
    <summary>Technical information <span>Server versions and configuration</span></summary>
    <article class="ac-card settings-environment">
      <div class="ac-card-head"><div><h3>Environment</h3></div></div>
    <div class="ac-table-view" style="max-height:none;border-top:0">
      <table class="ac-table">
        <tbody>
          <tr><td>Application version</td><td class="ac-mono"><?= htmlspecialchars(VERSION) ?></td></tr>
          <tr><td>Timezone</td><td class="ac-mono"><?= htmlspecialchars(APP_TIMEZONE) ?> · <?= date('P') ?></td></tr>
          <tr><td>Server time</td><td class="ac-mono"><?= date('D, j M Y g:i A') ?></td></tr>
          <tr><td>PHP</td><td class="ac-mono"><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
          <tr><td>MySQL</td><td class="ac-mono"><?= htmlspecialchars($conn->server_info) ?></td></tr>
          <tr><td>Seat floor from config.php</td><td class="ac-mono"><?= (int) MAX_ADMIN_USERS ?> admin · <?= (int) MAX_STAFF_USERS ?> staff</td></tr>
        </tbody>
      </table>
    </div>
    <div class="ac-card-foot">
      Server settings are managed in <span class="ac-mono">includes/config.php</span>.
    </div>
    </article>
  </details>
  </div><!-- /.settings-tabs-content -->
</section>
</main>
<script src="../assets/js/admin-settings-menu.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-settings-menu.js') ?>" defer></script>
<?php include '../includes/footer.php'; ?>
