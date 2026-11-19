<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/customer_auth.php';
startCustomerSession();
if (!customerIsLoggedIn()) { header('Location: ../index.php?auth=login'); exit; }
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) { header('Location: ../index.php?auth=login'); exit; }
if (customerHasAcceptedTerms($customer)) {
    header('Location: ' . (!empty($_SESSION['ba_customer_address_setup_required']) ? 'profile.php?setup=address' : 'home.php'));
    exit;
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['accept_terms'] ?? '') !== '1') {
        $error = 'Please check the agreement box to continue.';
    } else {
        try {
            if (customerRecordTermsAcceptance($conn, (int)$customer['id'])) {
                $next = !empty($_SESSION['ba_customer_address_setup_required']) ? 'profile.php?setup=address' : 'home.php';
                header('Location: ' . $next, true, 303);
                exit;
            }
            $error = 'Unable to record your agreement. Please try again.';
        } catch (Throwable $e) {
            error_log('Customer terms consent error: ' . $e->getMessage());
            $error = 'Unable to save your agreement right now. Try again later.';
        }
    }
}
$csrf = customerAuthCsrfToken();
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account Agreement · Brille Amour</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:28px 15px;background:radial-gradient(circle at top,#fff 0%,#fff1f5 48%,#f8e8ed 100%);font-family:Outfit,system-ui,-apple-system,sans-serif;color:#352531}main{width:min(100%,520px);padding:32px clamp(20px,5vw,40px);border:1px solid #f0d5df;border-radius:24px;background:#fff;box-shadow:0 24px 65px rgba(111,44,71,.1)}.brand{text-align:center;margin-bottom:20px}.brand img{width:75px;height:75px;object-fit:contain}.brand h1{font-family:Georgia,serif;font-size:2rem;color:#842b4f;margin:8px 0}p{line-height:1.7;color:#6e5763;font-size:.95rem}h2{margin:0;font-size:1.3rem}a{color:#9c315b;text-underline-offset:3px}.terms-choice{display:flex;gap:12px;align-items:flex-start;line-height:1.65;border:1px solid #edd5de;border-radius:12px;padding:16px;margin:22px 0}.terms-choice input{width:20px;height:20px;flex:none;margin:3px 0 0;accent-color:#ac416a}.terms-choice span{flex:1;font-size:.91rem}button{width:100%;border:0;border-radius:12px;background:linear-gradient(115deg,#a62c5b,#dc6891);color:#fff;font:700 1rem Outfit,system-ui,sans-serif;padding:15px;cursor:pointer}.alert{background:#fff1f3;border:1px solid #f0b7c7;color:#872c4d;padding:12px;border-radius:10px}.foot{text-align:center;font-size:.82rem;margin-top:15px}
</style><link rel="stylesheet" href="../assets/css/customer-terms-modal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-terms-modal.css') ?>">  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>"><link rel="stylesheet" href="../assets/css/customer-terms-mobile-compact.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-terms-mobile-compact.css') ?>">
</head><body><main>
<div class="brand"><img src="../assets/img/image.png" alt="Brille Amour logo"><h1>Brille Amour</h1></div>
<h2>One-time Account Agreement</h2>
<p>Before you continue shopping, please review our <a href="../terms-and-conditions.php" target="_blank" rel="noopener noreferrer">Terms and Conditions</a> and <a href="../privacy-policy.php" target="_blank" rel="noopener noreferrer">Privacy Policy</a>. This is requested once for accounts registered before this update or created through Google.</p>
<p>You can still review payment amounts, pickup or delivery dates, and order-specific conditions before confirming each order.</p>
<?php if ($error !== ''): ?><p class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
<form method="post" action="terms-accept.php">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
<label class="terms-choice"><input type="checkbox" name="accept_terms" data-terms-consent value="1" required><span>I agree to the Terms &amp; Conditions and acknowledge the Privacy Policy (version <?= htmlspecialchars(customerTermsVersion(), ENT_QUOTES) ?>).</span></label><button type="button" class="ba-terms-read-link" data-terms-open>Read Terms &amp; Privacy</button><small class="ba-terms-read-note" id="baTermsReviewHelp">Tap the checkbox to review both documents, then tap it again to agree.</small>
<button type="submit">Accept &amp; Continue</button></form>
<p class="foot">Your acceptance will be saved with your account. You won't need to check this box at each checkout.</p>
</main>
<?php $termsDocumentPrefix = '../'; require __DIR__ . '/../includes/customer_terms_modal.php'; ?>
<script src="../assets/js/customer-terms-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-terms-modal.js') ?>"></script></body></html>
