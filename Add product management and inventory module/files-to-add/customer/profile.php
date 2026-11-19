<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=profile.php');
    exit;
}

$error = '';
$success = '';
$authMethod = customerAuthMethod();
$addressSetupRequired = !empty($_SESSION['ba_customer_address_setup_required']) || ((string)($_GET['setup'] ?? '') === 'address');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $email = customerNormalizeEmail((string)($_POST['email'] ?? $customer['email'] ?? ''));

    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        $error = 'Please enter a valid full name.';
    } elseif ($contact !== '' && mb_strlen($contact) > 100) {
        $error = 'Contact number is too long.';
    } elseif ($addressSetupRequired && mb_strlen($address) < 5) {
        $error = 'Please enter your complete delivery address to finish account setup.';
    } elseif (mb_strlen($address) > 1000) {
        $error = 'Address is too long.';
    } elseif ($authMethod !== 'google' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        if ($authMethod === 'google') {
            $email = (string)$customer['email'];
        } else {
            $stmt = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(email)) = ? AND id <> ? AND (password_hash IS NOT NULL OR google_sub IS NOT NULL) LIMIT 1");
            $customerId = (int)$customer['id'];
            $stmt->bind_param('si', $email, $customerId);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($duplicate) {
                $error = 'That email address is already used by another customer account.';
            }
        }

        if ($error === '') {
            $customerId = (int)$customer['id'];
            $oldName = trim((string)($customer['name'] ?? ''));
            $oldContact = trim((string)($customer['contact'] ?? ''));

            // Preserve older guest orders that matched the profile before details change.
            if ($oldName !== '' && $oldContact !== '') {
                $linkStmt = $conn->prepare("UPDATE orders SET customer_id = ? WHERE customer_id IS NULL AND LOWER(TRIM(customer_name)) = LOWER(TRIM(?)) AND customer_contact = ?");
                $linkStmt->bind_param('iss', $customerId, $oldName, $oldContact);
                $linkStmt->execute();
                $linkStmt->close();
            }

            $stmt = $conn->prepare("UPDATE customers SET name = ?, email = ?, contact = ?, address = ? WHERE id = ?");
            $stmt->bind_param('ssssi', $name, $email, $contact, $address, $customerId);
            $stmt->execute();
            $stmt->close();
            $customer = loadSignedInCustomer($conn);
            if ($addressSetupRequired) {
                $next = customerSafeReturn((string)($_SESSION['ba_customer_address_setup_return'] ?? 'home.php'));
                unset($_SESSION['ba_customer_address_setup_required'], $_SESSION['ba_customer_address_setup_return']);
                header('Location: ' . $next);
                exit;
            }
            $success = 'Your profile has been updated.';
        }
    }
}

$profileTotalOrders = 0;
$profileActiveOrders = 0;
$profileCompletedOrders = 0;
$profileCustomerId = (int)$customer['id'];
$profileName = trim((string)$customer['name']);
$profileContact = trim((string)($customer['contact'] ?? ''));
if ($profileContact !== '') {
    $summary = $conn->prepare("SELECT status, COUNT(*) AS amount FROM orders WHERE status <> 'archived' AND (customer_id = ? OR (customer_id IS NULL AND LOWER(TRIM(customer_name)) = LOWER(TRIM(?)) AND customer_contact = ?)) GROUP BY status");
    $summary->bind_param('iss', $profileCustomerId, $profileName, $profileContact);
} else {
    $summary = $conn->prepare("SELECT status, COUNT(*) AS amount FROM orders WHERE status <> 'archived' AND customer_id = ? GROUP BY status");
    $summary->bind_param('i', $profileCustomerId);
}
$summary->execute();
foreach ($summary->get_result() as $summaryRow) {
    $count = (int)$summaryRow['amount'];
    $status = strtolower((string)$summaryRow['status']);
    $profileTotalOrders += $count;
    if ($status === 'completed') $profileCompletedOrders += $count;
    if (!in_array($status, ['completed', 'cancelled'], true)) $profileActiveOrders += $count;
}
$summary->close();
$csrfToken = customerAuthCsrfToken();
$activePage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>My Profile - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/customer-profile.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-profile.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
  <style>
    /* Compact profile dashboard: keeps the important account controls visible without wasting vertical space. */
    .customer-profile-body .profile-page-main {
      width: min(1180px, calc(100% - 32px));
      padding-top: 14px !important;
      padding-bottom: 16px !important;
    }
    .profile-compact-dashboard {
      display: grid;
      grid-template-columns: minmax(190px, .72fr) minmax(0, 2.28fr);
      align-items: stretch;
      gap: 12px;
      margin: 0 0 12px;
    }
    .profile-compact-title {
      display: flex;
      flex-direction: column;
      justify-content: center;
      min-width: 0;
      padding: 11px 14px;
      border: 1px solid #f0d8df;
      border-radius: 15px;
      background: linear-gradient(135deg, #fff 0%, #fff8fa 100%);
    }
    .profile-compact-title .profile-eyebrow {
      margin-bottom: 2px;
      font-size: .64rem;
      letter-spacing: .1em;
    }
    .profile-compact-title h1 {
      margin: 0;
      color: #302129;
      font-size: 1.28rem;
      line-height: 1.15;
      letter-spacing: -.02em;
    }
    .profile-compact-title p {
      margin: 3px 0 0;
      color: #8a7380;
      font-size: .7rem;
      line-height: 1.35;
    }
    .profile-compact-dashboard .portal-stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      min-width: 0;
    }
    .profile-compact-dashboard .portal-stat-card {
      min-width: 0;
      min-height: 78px;
      padding: 10px 14px;
      border: 1px solid #f0d8df;
      border-radius: 15px;
      background: #fff;
      display: grid;
      align-content: center;
      gap: 2px;
    }
    .profile-compact-dashboard .portal-stat-card > span {
      color: #775864;
      font-size: .68rem;
    }
    .profile-compact-dashboard .portal-stat-card strong {
      color: #24171d;
      font-size: 1.4rem;
      line-height: 1.05;
    }
    .profile-compact-dashboard .portal-stat-card small {
      color: #9a7f8a;
      font-size: .62rem;
      line-height: 1.25;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .profile-compact-dashboard .portal-stat-card strong.is-live-updated {
      animation: profile-stat-live-pulse .42s ease;
    }
    @keyframes profile-stat-live-pulse {
      0% { transform: scale(1); }
      45% { transform: scale(1.1); }
      100% { transform: scale(1); }
    }

    /* Tighten the existing profile cards so the desktop view fits comfortably in one screen. */
    .customer-profile-body .profile-layout {
      grid-template-columns: minmax(230px, 270px) minmax(0, 1fr);
      gap: 12px;
    }
    .customer-profile-body .profile-sidebar { gap: 10px; }
    .customer-profile-body .profile-identity-card,
    .customer-profile-body .profile-shortcuts,
    .customer-profile-body .profile-edit-card { border-radius: 16px; }
    .customer-profile-body .profile-identity-cover { height: 50px; }
    .customer-profile-body .profile-identity-content { padding: 0 13px 13px; }
    .customer-profile-body .profile-identity-avatar {
      width: 58px;
      height: 58px;
      margin: -30px 0 7px;
      border-width: 3px;
      border-radius: 18px;
    }
    .customer-profile-body .profile-identity-avatar img,
    .customer-profile-body .profile-identity-avatar .profile-avatar-fallback { border-radius: 15px; }
    .customer-profile-body .profile-identity-content h2 { margin-bottom: 2px; font-size: .95rem; }
    .customer-profile-body .profile-identity-email { margin-bottom: 8px; font-size: .68rem; }
    .customer-profile-body .profile-account-chip { min-height: 27px; padding: 5px 9px; font-size: .62rem; }

    .customer-profile-body .profile-shortcuts { padding: 8px 7px 5px; }
    .customer-profile-body .profile-shortcuts-label { margin: 0 7px 5px; font-size: .6rem; }
    .customer-profile-body .profile-shortcuts a {
      min-height: 46px;
      margin-bottom: 2px;
      padding: 6px 7px;
      gap: 8px;
      border-radius: 10px;
    }
    .customer-profile-body .profile-shortcut-icon { width: 29px; height: 29px; flex-basis: 29px; }
    .customer-profile-body .profile-shortcut-copy strong { font-size: .72rem; }
    .customer-profile-body .profile-shortcut-copy small { font-size: .61rem; }

    .customer-profile-body .profile-card-heading { padding: 14px 18px 11px; }
    .customer-profile-body .profile-card-heading h2 { margin-bottom: 2px; font-size: 1.08rem; }
    .customer-profile-body .profile-card-heading p { font-size: .69rem; line-height: 1.3; }
    .customer-profile-body .profile-card-eyebrow { font-size: .61rem; }
    .customer-profile-body .profile-edit-mark { width: 36px; height: 36px; }
    .customer-profile-body .profile-form-grid {
      gap: 9px 11px;
      padding: 13px 18px;
    }
    .customer-profile-body .profile-form-grid label { gap: 5px; font-size: .7rem; }
    .customer-profile-body .profile-form-grid input,
    .customer-profile-body .profile-form-grid textarea {
      min-height: 39px;
      padding: 8px 11px;
      border-radius: 10px;
      font-size: .77rem;
    }
    .customer-profile-body .profile-form-grid textarea { min-height: 58px; line-height: 1.35; }
    .customer-profile-body .profile-field-hint { font-size: .59rem; line-height: 1.25; }
    .customer-profile-body .profile-google-hint { margin: 0; padding: 0 18px 10px; font-size: .64rem; }
    .customer-profile-body .profile-form-footer { padding: 9px 18px; }
    .customer-profile-body .profile-form-footer > span { font-size: .62rem; }
    .customer-profile-body .profile-form-footer .account-save-button {
      min-height: 38px;
      padding: 0 15px;
      font-size: .72rem;
    }
    .customer-profile-body .profile-feedback { margin: 0 0 10px; padding: 9px 12px; font-size: .72rem; }

    @media (max-width: 880px) {
      .profile-compact-dashboard { grid-template-columns: 1fr; }
      .profile-compact-title { padding: 9px 12px; }
      .profile-compact-title p { display: none; }
      .profile-compact-dashboard .portal-stat-card { min-height: 70px; }
      .customer-profile-body .profile-layout { grid-template-columns: 1fr; }
      .customer-profile-body .profile-sidebar {
        grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr);
        gap: 10px;
      }
    }
    @media (max-width: 620px) {
      .customer-profile-body .profile-page-main {
        width: calc(100% - 20px);
        padding-top: 10px !important;
        padding-bottom: 24px !important;
      }
      .profile-compact-dashboard { gap: 8px; margin-bottom: 8px; }
      .profile-compact-title {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
      }
      .profile-compact-title .profile-eyebrow { margin: 0; }
      .profile-compact-title h1 { font-size: 1.05rem; }
      .profile-compact-dashboard .portal-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
      }
      .profile-compact-dashboard .portal-stat-card {
        min-height: 61px;
        padding: 8px 10px;
        border-radius: 12px;
      }
      .profile-compact-dashboard .portal-stat-card strong { font-size: 1.22rem; }
      .profile-compact-dashboard .portal-stat-card small { font-size: .58rem; }
      .customer-profile-body .profile-sidebar { grid-template-columns: 1fr; }
      .customer-profile-body .profile-shortcuts {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
        padding: 7px;
      }
      .customer-profile-body .profile-shortcuts-label { grid-column: 1 / -1; margin: 0 3px 1px; }
      .customer-profile-body .profile-shortcuts a {
        display: grid;
        justify-items: center;
        gap: 4px;
        min-height: 64px;
        margin: 0;
        padding: 7px 4px;
        text-align: center;
      }
      .customer-profile-body .profile-shortcut-copy small,
      .customer-profile-body .profile-shortcut-arrow { display: none; }
      .customer-profile-body .profile-form-grid { grid-template-columns: 1fr; padding: 12px 14px; }
      .customer-profile-body .profile-card-heading { padding: 13px 14px 10px; }
      .customer-profile-body .profile-form-footer { padding: 9px 14px; }
    }
  </style>
</head>
<body class="customer-account-body customer-portal-body customer-profile-body">
  <?php $portalActive = 'profile'; require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-account-content profile-page-main">
    <header class="profile-compact-dashboard" aria-label="Profile and account summary">
      <div class="profile-compact-title">
        <span class="profile-eyebrow">My Account</span>
        <h1>My Profile</h1>
        <p>Account details and order activity in one place.</p>
      </div>
      <div class="portal-stats">
        <article class="portal-stat-card"><span>Total Orders</span><strong data-profile-stat="total" aria-live="polite"><?= $profileTotalOrders ?></strong><small>All linked orders</small></article>
        <article class="portal-stat-card"><span>Active</span><strong data-profile-stat="active" aria-live="polite"><?= $profileActiveOrders ?></strong><small>Currently in progress</small></article>
        <article class="portal-stat-card"><span>Completed</span><strong data-profile-stat="completed" aria-live="polite"><?= $profileCompletedOrders ?></strong><small>Finished orders</small></article>
        <article class="portal-stat-card"><span>Cart Items</span><strong data-profile-stat="cart" data-home-cart-count aria-live="polite">0</strong><small>Ready for checkout</small></article>
      </div>
    </header>

    <?php if ($addressSetupRequired && !$error): ?><div class="auth-alert profile-feedback" role="status">Add your delivery address once to finish setting up your account. It will be filled automatically during checkout.</div><?php endif; ?>
    <?php if ($error): ?><div class="auth-alert profile-feedback" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-alert auth-success profile-feedback" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="profile-layout">
      <aside class="profile-sidebar" aria-label="Your account overview">
        <section class="profile-identity-card">
          <div class="profile-identity-cover" aria-hidden="true"></div>
          <div class="profile-identity-content">
            <div class="profile-identity-avatar">
              <?php if (!empty($customer['google_avatar'])): ?>
                <img src="<?= htmlspecialchars((string)$customer['google_avatar']) ?>" alt="Profile photo" referrerpolicy="no-referrer">
              <?php else: ?>
                <span class="profile-avatar-fallback" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr((string)$customer['name'], 0, 1))) ?></span>
              <?php endif; ?>
            </div>
            <h2><?= htmlspecialchars((string)$customer['name']) ?></h2>
            <p class="profile-identity-email"><?= htmlspecialchars((string)$customer['email']) ?></p>
            <span class="profile-account-chip"><span class="profile-chip-dot" aria-hidden="true"></span><?= $authMethod === 'google' ? 'Google-connected account' : 'Customer account' ?></span>
          </div>
        </section>

        <nav class="profile-shortcuts" aria-label="Account shortcuts">
          <span class="profile-shortcuts-label">Quick access</span>
          <a href="orders.php"><span class="profile-shortcut-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10l2 3v15l-7-3-7 3V6l2-3Z"/><path d="M9 9h6M9 13h4"/></svg></span><span class="profile-shortcut-copy"><strong>My orders</strong><small>Track your purchases</small></span><span class="profile-shortcut-arrow" aria-hidden="true">›</span></a>
          <a href="messaging.php"><span class="profile-shortcut-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l-2 2V9a7 7 0 0 1 7-7h2.5a7.5 7.5 0 0 1 7.5 7.5Z"/><path d="M8 10h8M8 14h5"/></svg></span><span class="profile-shortcut-copy"><strong>Messages</strong><small>Contact customer support</small></span><span class="profile-shortcut-arrow" aria-hidden="true">›</span></a>
          <a href="settings.php"><span class="profile-shortcut-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 15v2"/></svg></span><span class="profile-shortcut-copy"><strong>Account security</strong><small>Manage your password</small></span><span class="profile-shortcut-arrow" aria-hidden="true">›</span></a>
        </nav>
      </aside>

      <section class="profile-edit-card" aria-labelledby="profileEditHeading">
        <div class="profile-card-heading">
          <div><span class="profile-card-eyebrow">Personal details</span><h2 id="profileEditHeading">Edit profile</h2><p>These details are used to help complete your orders.</p></div>
          <button type="button" class="profile-edit-mark" id="profileEditToggle" aria-label="Edit personal details" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L9 17l-4 1 1-4 10.5-10.5Z"/></svg></button>
        </div>

        <form class="account-form profile-edit-form is-readonly" id="profileEditForm" method="post" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="account-form-grid profile-form-grid">
            <label><span>Full name</span><input type="text" name="name" value="<?= htmlspecialchars((string)$customer['name']) ?>" maxlength="150" autocomplete="name" required readonly data-profile-editable></label>
            <label><span>Email address</span><input type="email" name="email" value="<?= htmlspecialchars((string)$customer['email']) ?>" autocomplete="email" readonly <?= $authMethod === 'google' ? 'data-profile-google-email' : 'required data-profile-editable' ?>></label>
            <label class="full-width"><span>Contact number <small>(optional)</small></span><input type="tel" name="contact" value="<?= htmlspecialchars((string)($customer['contact'] ?? '')) ?>" maxlength="100" autocomplete="tel" placeholder="09XX XXX XXXX" readonly data-profile-editable></label>
            <label class="full-width"><span>Delivery address<?= $addressSetupRequired ? '' : ' <small>(optional)</small>' ?></span><textarea name="address" rows="3" maxlength="1000" minlength="5" autocomplete="street-address" placeholder="House no., street, barangay, city / municipality"<?= $addressSetupRequired ? ' required' : '' ?> readonly data-profile-editable><?= htmlspecialchars((string)($customer['address'] ?? '')) ?></textarea><small class="profile-field-hint">Your address can be used to fill in delivery details during checkout.</small></label>
          </div>
          <?php if ($authMethod === 'google'): ?><p class="form-help profile-google-hint">Your email is connected to Google and cannot be changed here.</p><?php endif; ?>
          <div class="profile-form-footer"><span id="profileEditHint">Click the edit button above to update your details.</span><button class="account-save-button" id="profileSaveButton" type="submit" disabled hidden>Save changes <span aria-hidden="true">→</span></button></div>
        </form>
      </section>
    </div>
  </main>
  <script>
  (() => {
    const form = document.getElementById('profileEditForm');
    const toggle = document.getElementById('profileEditToggle');
    const save = document.getElementById('profileSaveButton');
    const hint = document.getElementById('profileEditHint');
    if (!form || !toggle || !save) return;

    const fields = Array.from(form.querySelectorAll('[data-profile-editable]'));
    let editing = <?= $addressSetupRequired ? 'true' : 'false' ?>;

    const applyState = () => {
      form.classList.toggle('is-readonly', !editing);
      form.classList.toggle('is-editing', editing);
      fields.forEach((field) => {
        field.readOnly = !editing;
        field.setAttribute('aria-readonly', editing ? 'false' : 'true');
      });
      toggle.classList.toggle('is-active', editing);
      toggle.setAttribute('aria-pressed', editing ? 'true' : 'false');
      toggle.setAttribute('aria-label', editing ? 'Cancel editing personal details' : 'Edit personal details');
      save.hidden = !editing;
      save.disabled = !editing;
      if (hint) hint.textContent = editing ? 'Review your changes, then save your profile.' : 'Click the edit button above to update your details.';
    };

    toggle.addEventListener('click', () => {
      editing = !editing;
      if (!editing) form.reset();
      applyState();
      if (editing) {
        const addressField = form.querySelector('textarea[name="address"]');
        (<?= $addressSetupRequired ? 'true' : 'false' ?> && addressField ? addressField : fields[0])?.focus();
      }
    });

    applyState();
    if (<?= $addressSetupRequired ? 'true' : 'false' ?>) {
      const addressField = form.querySelector('textarea[name="address"]');
      if (addressField) addressField.focus();
    }
  })();
  </script>
  <script>
  (() => {
    const header = document.getElementById('customerPortalHeader');
    const customerId = String(header?.dataset.customerId || '');
    if (!customerId) return;

    const nodes = {
      total: document.querySelector('[data-profile-stat="total"]'),
      active: document.querySelector('[data-profile-stat="active"]'),
      completed: document.querySelector('[data-profile-stat="completed"]'),
      cart: document.querySelector('[data-profile-stat="cart"]')
    };
    const cartKey = `brille_customer_cart_${customerId}`;
    let orderSyncBusy = false;
    let orderTimer = null;
    let lastOrderSync = 0;

    const setStat = (key, value) => {
      const node = nodes[key];
      if (!node) return;
      const next = String(Math.max(0, Number(value) || 0));
      if (node.textContent.trim() === next) return;
      node.textContent = next;
      node.classList.remove('is-live-updated');
      void node.offsetWidth;
      node.classList.add('is-live-updated');
      window.setTimeout(() => node.classList.remove('is-live-updated'), 450);
    };

    const syncCart = () => {
      try {
        const cart = JSON.parse(localStorage.getItem(cartKey) || '{}');
        const count = Object.values(cart && typeof cart === 'object' ? cart : {}).reduce((sum, qty) => {
          return sum + Math.max(0, Number(qty) || 0);
        }, 0);
        setStat('cart', count);
      } catch (_) {
        setStat('cart', 0);
      }
    };

    const syncOrders = async (force = false) => {
      const now = Date.now();
      if (orderSyncBusy || (!force && now - lastOrderSync < 2500) || document.hidden) return;
      orderSyncBusy = true;
      try {
        const response = await fetch(`profile_summary_api.php?_=${now}`, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) return;
        const data = await response.json();
        if (!data || !data.ok) return;
        setStat('total', data.total_orders);
        setStat('active', data.active_orders);
        setStat('completed', data.completed_orders);
        lastOrderSync = Date.now();
      } catch (_) {
        // Keep the last known values and retry on the next live sync.
      } finally {
        orderSyncBusy = false;
      }
    };

    const syncAll = (force = false) => {
      syncCart();
      syncOrders(force);
    };

    syncAll(true);
    orderTimer = window.setInterval(() => syncAll(false), 5000);

    window.addEventListener('brille-commerce-change', syncCart);
    window.addEventListener('storage', (event) => {
      if (!event.key || event.key === cartKey) syncCart();
    });
    window.addEventListener('brille:order-progress', () => syncOrders(false));
    window.addEventListener('focus', () => syncAll(true));
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) syncAll(true);
    });
    window.addEventListener('beforeunload', () => {
      if (orderTimer) window.clearInterval(orderTimer);
    }, { once: true });
  })();
  </script>
</body>
</html>
