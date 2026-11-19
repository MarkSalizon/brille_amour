<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customer_auth.php';
require_once __DIR__ . '/includes/auth_verification.php';

startCustomerSession();

$landingAuthError = '';
$landingAuthSuccess = '';
$landingAuthMode = ((string)($_GET['auth'] ?? '') === 'signup') ? 'signup' : 'login';
$landingLoginEmail = '';
$landingSignupName = '';
$landingSignupEmail = '';
$landingSignupContact = '';
$landingSignupAddress = '';
$signupVerificationKey = 'customer_signup_verification';
$landingAuthAction = '';

try {
    ensureCustomerAuthSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_modal_action'])) {
        $action = strtolower(trim((string)$_POST['customer_modal_action']));
        $landingAuthAction = $action;

        if (!customerAuthCsrfValid($_POST['csrf_token'] ?? null)) {
            $landingAuthError = 'Your session expired. Please refresh the page and try again.';
        } elseif ($action === 'login') {
            // Starting a fresh login replaces any old/pending verification attempt.
            unset($_SESSION['customer_login_verification'], $_SESSION[$signupVerificationKey]);
            $landingAuthMode = 'login';
            $landingLoginEmail = customerNormalizeEmail((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if (!filter_var($landingLoginEmail, FILTER_VALIDATE_EMAIL)) {
                $landingAuthError = 'Please enter a valid Gmail/email address.';
            } elseif ($password === '') {
                $landingAuthError = 'Please enter your password.';
            } else {
                $customer = customerPasswordAccountByEmail($conn, $landingLoginEmail);
                if (!$customer || !password_verify($password, (string)($customer['password_hash'] ?? ''))) {
                    $landingAuthError = 'Incorrect email or password.';
                } elseif (($customer['status'] ?? 'active') !== 'active') {
                    $landingAuthError = 'This customer account is currently unavailable. Please contact Brille Amour.';
                } else {
                    // Email was already verified when this account was created.
                    // Successful password login should not request another Gmail code.
                    unset($_SESSION['customer_login_verification']);
                    $customerId = (int)$customer['id'];
                    $stmt = $conn->prepare('UPDATE customers SET last_login_at = NOW() WHERE id = ?');
                    if ($stmt) {
                        $stmt->bind_param('i', $customerId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    customerLoginSession($customer, 'password');
                    header('Location: customer/home.php');
                    exit;
                }
            }
        } elseif ($action === 'signup') {
            // Starting a fresh signup replaces any old/pending verification attempt.
            unset($_SESSION['customer_login_verification'], $_SESSION[$signupVerificationKey]);
            $landingAuthMode = 'signup';
            $landingSignupName = trim((string)($_POST['name'] ?? ''));
            $landingSignupEmail = customerNormalizeEmail((string)($_POST['email'] ?? ''));
            $landingSignupContact = trim((string)($_POST['contact'] ?? ''));
            $landingSignupAddress = trim((string)($_POST['address'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $nameLength = function_exists('mb_strlen') ? mb_strlen($landingSignupName) : strlen($landingSignupName);

            if ($nameLength < 2 || $nameLength > 150) {
                $landingAuthError = 'Please enter your full name.';
            } elseif (!filter_var($landingSignupEmail, FILTER_VALIDATE_EMAIL)) {
                $landingAuthError = 'Please enter a valid Gmail/email address.';
            } elseif (!preg_match('/^[0-9+()\\-\\s]{7,30}$/', $landingSignupContact)) {
                $landingAuthError = 'Please enter a valid contact number.';
            } elseif ((function_exists('mb_strlen') ? mb_strlen($landingSignupAddress) : strlen($landingSignupAddress)) < 5 || (function_exists('mb_strlen') ? mb_strlen($landingSignupAddress) : strlen($landingSignupAddress)) > 1000) {
                $landingAuthError = 'Please enter your complete delivery address.';
            } elseif (strlen($password) < 8 || strlen($password) > 72) {
                $landingAuthError = 'Password must be 8 to 72 characters.';
            } elseif ($password !== $confirmPassword) {
                $landingAuthError = 'Passwords do not match.';
            } elseif (($_POST['accept_terms'] ?? '') !== '1') {
                $landingAuthError = 'Please accept the Terms and Conditions and acknowledge the Privacy Policy to sign up.';
            } elseif (customerRegisteredAccountByEmail($conn, $landingSignupEmail)) {
                $landingAuthError = 'An account already exists for this email. Please log in instead.';
                $landingAuthMode = 'login';
                $landingLoginEmail = $landingSignupEmail;
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    $landingAuthError = 'Unable to prepare your account. Please try again.';
                } else {
                    $result = authVerificationStart(
                        $signupVerificationKey,
                        $landingSignupEmail,
                        $landingSignupName,
                        'customer signup',
                        [
                            'name' => $landingSignupName,
                            'email' => $landingSignupEmail,
                            'contact' => $landingSignupContact,
                            'address' => $landingSignupAddress,
                            'password_hash' => $hash,
                            'terms_version' => customerTermsVersion(),
                        ]
                    );
                    if (!empty($result['sent'])) {
                        $landingAuthSuccess = 'We sent a 6-digit verification code to your Gmail/email account.';
                    } else {
                        unset($_SESSION[$signupVerificationKey]);
                        $landingAuthError = 'Verification email could not be sent. Check GOOGLE_SMTP_EMAIL and GOOGLE_SMTP_APP_PASSWORD in your .env file.';
                    }
                }
            }
        } elseif ($action === 'verify_signup') {
            $landingAuthMode = 'signup';
            $verified = authVerificationCheck($signupVerificationKey, (string)($_POST['verification_code'] ?? ''));
            if (empty($verified['ok'])) {
                $landingAuthError = (string)($verified['error'] ?? 'Invalid verification code.');
            } else {
                $payload = $verified['payload'] ?? [];
                $name = trim((string)($payload['name'] ?? ''));
                $email = customerNormalizeEmail((string)($payload['email'] ?? ''));
                $contact = trim((string)($payload['contact'] ?? ''));
                $address = trim((string)($payload['address'] ?? ''));
                $hash = (string)($payload['password_hash'] ?? '');
                $addressLength = function_exists('mb_strlen') ? mb_strlen($address) : strlen($address);
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contact) || $addressLength < 5 || $addressLength > 1000 || $hash === '' || (string)($payload['terms_version'] ?? '') !== customerTermsVersion()) {
                    $landingAuthError = 'Verification data expired. Please sign up again.';
                } elseif (customerRegisteredAccountByEmail($conn, $email)) {
                    $landingAuthError = 'An account already exists for this email. Please log in instead.';
                    $landingAuthMode = 'login';
                    $landingLoginEmail = $email;
                } else {
                    $source = 'Website Account';
                    $notes = 'Customer account verified through Gmail code.';
                    $stmt = $conn->prepare("INSERT INTO customers (name, email, contact, address, password_hash, registered_at, last_login_at, source, notes, status, terms_version, terms_accepted_at, privacy_version, privacy_acknowledged_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, 'active', ?, NOW(), ?, NOW())");
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare customer signup: ' . $conn->error);
                    }
                    $termsVersion = customerTermsVersion();
                    $stmt->bind_param('sssssssss', $name, $email, $contact, $address, $hash, $source, $notes, $termsVersion, $termsVersion);
                    if (!$stmt->execute()) {
                        $detail = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Unable to create customer account: ' . $detail);
                    }
                    $customerId = (int)$conn->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
                    $stmt->bind_param('i', $customerId);
                    $stmt->execute();
                    $customer = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$customer) {
                        $landingAuthError = 'Account was created, but sign-in could not be completed. Please log in.';
                        $landingAuthMode = 'login';
                    } else {
                        customerRememberRecentVerification($customer, 1800);
                        customerLoginSession($customer, 'password');
                        $_SESSION['ba_customer_welcome_pending'] = 'signup';
                        header('Location: customer/home.php');
                        exit;
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Customer landing auth error: ' . $e->getMessage());
    $landingAuthError = 'Customer login/signup encountered a server error.';
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $landingAuthError .= ' ' . $e->getMessage();
    }
}

$landingCustomerLoggedIn = customerIsLoggedIn();
$landingCustomer = customerSessionProfile();
$landingAuthCsrf = customerAuthCsrfToken();
$pendingSignupVerification = authVerificationPending($signupVerificationKey);
$pendingVerification = null;
$pendingVerificationAction = '';

// Only account creation needs an email code; ordinary sign-ins do not.
if (in_array($landingAuthAction, ['signup', 'verify_signup'], true) && is_array($pendingSignupVerification)) {
    $pendingVerification = $pendingSignupVerification;
    $pendingVerificationAction = 'verify_signup';
}

$landingAuthVerify = !$landingCustomerLoggedIn
    && is_array($pendingVerification)
    && $pendingVerificationAction !== '';
$landingAuthOpen = !$landingCustomerLoggedIn && (
    $landingAuthError !== '' || $landingAuthSuccess !== '' || $landingAuthVerify ||
    (string)($_GET['auth'] ?? '') !== '' || $_SERVER['REQUEST_METHOD'] === 'POST'
);
$landingAuthSignup = !$landingCustomerLoggedIn && !$landingAuthVerify && $landingAuthMode === 'signup';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Brille Amour Prints & Crafts</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DMSans:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/design-system.css">
  <link rel="stylesheet" href="assets/css/landing.css?v=<?= filemtime(__DIR__ . '/assets/css/landing.css') ?>">
  <link rel="stylesheet" href="assets/css/brille-ui-polish.css?v=20260909">
  <link rel="stylesheet" href="assets/css/brille-mobile-polish.css?v=20260909">
  <script src="assets/js/mobile-polish.js?v=20260909" defer></script>
<link rel="stylesheet" href="assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="assets/css/password-eye.css?v=<?= filemtime(__DIR__ . '/assets/css/password-eye.css') ?>">
  <link rel="stylesheet" href="assets/css/customer-landing-featured.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-landing-featured.css') ?>">
<link rel="stylesheet" href="assets/css/customer-terms-modal.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-terms-modal.css') ?>">
  <link rel="stylesheet" href="assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="assets/css/customer-auth-mobile-fit.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-auth-mobile-fit.css') ?>">
  <link rel="stylesheet" href="assets/css/customer-terms-mobile-compact.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-terms-mobile-compact.css') ?>">
  <link rel="stylesheet" href="assets/css/landing-services-mobile.css?v=<?= filemtime(__DIR__ . '/assets/css/landing-services-mobile.css') ?>">
  <link rel="stylesheet" href="assets/css/landing-contact-mobile.css?v=<?= filemtime(__DIR__ . '/assets/css/landing-contact-mobile.css') ?>">
  <link rel="stylesheet" href="assets/css/customer-mobile-auth-v2.css?v=<?= filemtime(__DIR__ . '/assets/css/customer-mobile-auth-v2.css') ?>">
</head>

<body class="landing-public-page">
  <section class="mobile-customer-entry" aria-label="Brille Amour customer access">
    <div class="mobile-customer-entry-hero">
      <div class="mobile-customer-entry-logo-wrap" aria-hidden="true">
        <img class="mobile-customer-entry-logo" src="assets/img/image.png" alt="">
      </div>
      <p class="mobile-customer-entry-kicker">Brille Amour · Prints &amp; Crafts</p>
      <h1>Hi There!</h1>
      <p>Welcome to Brille Amour. Sign in to continue your orders, or create a customer account to get started.</p>
    </div>
    <div class="mobile-customer-entry-actions">
      <?php if ($landingCustomerLoggedIn): ?>
        <a class="mobile-customer-entry-button" href="customer/home.php">Continue to My Account</a>
      <?php else: ?>
        <a class="mobile-customer-entry-button" href="customer/login.php">I have an account</a>
        <a class="mobile-customer-entry-button secondary" href="customer/signup.php">Create an account</a>
      <?php endif; ?>
    </div>
    <p class="mobile-customer-entry-foot">Customer portal · Secure sign in and account registration</p>
  </section>
  <?php $welcomeSplashMode = (($_GET['ba_splash'] ?? '') === 'logout') ? 'logout' : 'entry'; $welcomeSplashLogo = 'assets/img/image.png'; require __DIR__ . '/includes/welcome_splash.php'; ?>
  <div class="landing-shell">
    <header class="site-nav" data-nav>
      <a class="brand" href="#home" aria-label="Brille Amour home">
        <img src="assets/img/image.png" alt="Brille Amour logo">
        <span>
          <strong>Brille Amour</strong>
          <small>Prints & Crafts</small>
        </span>
      </a>

      <nav class="nav-links" aria-label="Main navigation">
        <a href="#services" data-landing-scroll data-no-ajax>Services</a>
        <a href="#about" data-landing-scroll data-no-ajax>About</a>
        <a href="#contact" data-landing-scroll data-no-ajax>Contact</a>
      </nav>

    <div class="portal-links<?= !$landingCustomerLoggedIn ? ' auth-actions' : '' ?>" aria-label="Customer ordering">
      <?php if ($landingCustomerLoggedIn): ?>
        <a href="customer/home.php">Get Started</a>
      <?php else: ?>
        <a class="customer-login-link" href="index.php?auth=signin#customer-auth" data-auth-open="login" data-no-ajax aria-haspopup="dialog" aria-controls="customerAuthModal">Log In</a>
        <a class="customer-signup-link" href="index.php?auth=signup#customer-auth" data-auth-open="signup" data-no-ajax aria-haspopup="dialog" aria-controls="customerAuthModal">Sign Up</a>
      <?php endif; ?>
    </div>
    </header>

    <main id="home">
      <section class="hero-section" id="services" aria-labelledby="hero-title">
        <div class="hero-card reveal">
          <div class="hero-topline">
            <span class="spark" aria-hidden="true"></span>
            <img src="assets/img/image.png" alt="Brille Amour Prints & Crafts logo">
            <span class="spark" aria-hidden="true"></span>
          </div>
          <div class="hero-copy">
            <span class="eyebrow">Services offered</span>
            <h1 id="hero-title">Brille Amour Prints & Crafts</h1>
            <p class="intro">All kinds of layout, personalized crafts, printed keepsakes, and creative pieces made with love, just for you.</p>
          </div>
        </div>

        <div class="service-strip reveal" aria-label="Available services">
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M7 8V3h10v5" />
                <path d="M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                <path d="M7 14h10v7H7z" />
                <path d="M17 11h.01" />
              </svg></span><strong>Print</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M5 4h14v16H5z" />
                <path d="M8 4v16" />
                <path d="M16 4v16" />
                <path d="M5 8h14" />
                <path d="M5 16h14" />
              </svg></span><strong>Lamination</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M4 5h16v14H4z" />
                <path d="M8 10h4" />
                <path d="M8 14h8" />
                <path d="M15.5 10.5h.01" />
              </svg></span><strong>Rush ID</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M20 12v8H4v-8" />
                <path d="M2 7h20v5H2z" />
                <path d="M12 7v13" />
                <path d="M12 7H8.5A2.5 2.5 0 1 1 12 3.5V7z" />
                <path d="M12 7h3.5A2.5 2.5 0 1 0 12 3.5V7z" />
              </svg></span><strong>Souvenir</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M4 4h12l4 4v12H4z" />
                <path d="M16 4v5h5" />
                <path d="M9 13c1-2 5-2 6 0" />
                <path d="M8 16h8" />
              </svg></span><strong>Sticker</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v18H6.5A2.5 2.5 0 0 1 4 18.5z" />
                <path d="M8 7h8" />
                <path d="M8 11h8" />
                <path d="M8 15h5" />
              </svg></span><strong>Workbooks</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M3 7h18v13H3z" />
                <path d="m3 7 9 7 9-7" />
                <path d="m3 20 6-7" />
                <path d="m21 20-6-7" />
              </svg></span><strong>Invitation</strong></article>
          <article><span aria-hidden="true"><svg viewBox="0 0 24 24">
                <path d="M4 4h16v16H4z" />
                <path d="M8 4v16" />
                <path d="M4 9h16" />
                <path d="M12 13h5" />
                <path d="M12 16h4" />
              </svg></span><strong>Layout</strong></article>
        </div>

        <div class="hero-footer-note reveal">
          <span>Thank you for supporting small and local</span>
          <span>We print memories. You keep them forever.</span>
        </div>
      </section>

      <section class="landing-featured" id="featured-products" aria-labelledby="featuredProductsTitle" data-featured-products data-signed-in="<?= $landingCustomerLoggedIn ? 'true' : 'false' ?>">
        <div class="landing-featured-heading">
          <div>
            <span class="eyebrow">Shop highlights</span>
            <h2 id="featuredProductsTitle">Featured Products</h2>
            <p>A few items to explore from the Brille Amour shop.</p>
          </div>
          <?php if ($landingCustomerLoggedIn): ?>
            <a class="landing-featured-all" href="customer/shop.php">View all products <span aria-hidden="true">&rarr;</span></a>
          <?php else: ?>
            <a class="landing-featured-all" href="index.php?auth=signup#customer-auth" data-auth-open="signup" data-no-ajax>View all products <span aria-hidden="true">&rarr;</span></a>
          <?php endif; ?>
        </div>
        <p class="landing-featured-status" id="landingFeaturedStatus" role="status">Loading products...</p>
        <div class="landing-featured-grid" id="landingFeaturedGrid" aria-label="Featured shop products"></div>
      </section>

      <section class="order-section" id="about" aria-labelledby="about-title">
        <div class="section-copy reveal">
          <span class="eyebrow">About the business</span>
          <h2 id="about-title">Thoughtful prints and crafts for everyday moments.</h2>
        </div>
        <div class="order-grid">
          <article class="order-card reveal">
            <span>01</span>
            <h3>Personalized work</h3>
            <p>Each piece is prepared around the customer, occasion, layout style, and design details.</p>
          </article>
          <article class="order-card reveal">
            <span>02</span>
            <h3>Local care</h3>
            <p>Brille Amour is built for simple, heartfelt service with a sweet handmade touch.</p>
          </article>
        </div>
      </section>

      <section class="features-section" aria-labelledby="specialties-title">
        <div class="section-copy reveal">
          <span class="eyebrow">Specialties</span>
          <h2 id="specialties-title">Creative services for school, events, gifts, and memories.</h2>
        </div>
        <div class="feature-grid">
          <article class="feature-card reveal"><span>01</span>
            <h3>School needs</h3>
            <p>Workbooks, laminated materials, rush ID support, and everyday print requests.</p>
          </article>
          <article class="feature-card reveal"><span>02</span>
            <h3>Event pieces</h3>
            <p>Invitations, souvenirs, stickers, labels, and layouts for special celebrations.</p>
          </article>
          <article class="feature-card reveal"><span>03</span>
            <h3>Custom crafts</h3>
            <p>Personalized keepsakes and printed designs made for gifting and preserving moments.</p>
          </article>
        </div>
      </section>

      <section class="workflow-section" aria-labelledby="message-title">
        <div class="workflow-inner reveal">
          <h2 id="message-title">Made with love, just for you.</h2>
          <div class="workflow-list">
            <div class="workflow-row"><span>01</span><strong>Clear layouts</strong></div>
            <div class="workflow-row"><span>02</span><strong>Sweet personal details</strong></div>
            <div class="workflow-row"><span>03</span><strong>Memories worth keeping</strong></div>
          </div>
        </div>
      </section>

      <section class="mobile-order-guide" aria-labelledby="mobileOrderGuideTitle">
        <div class="mobile-order-guide-head">
          <span class="eyebrow">How to order</span>
          <h2 id="mobileOrderGuideTitle">Order in four simple steps.</h2>
        </div>
        <div class="mobile-order-steps">
          <article><span>1</span><div><strong>Choose</strong><small>Pick a product from the shop.</small></div></article>
          <article><span>2</span><div><strong>Customize</strong><small>Add your details or reference.</small></div></article>
          <article><span>3</span><div><strong>Pay</strong><small>Complete the required payment.</small></div></article>
          <article><span>4</span><div><strong>Receive</strong><small>Pickup or delivery when ready.</small></div></article>
        </div>
      </section>

      <section class="closing-section reveal" id="contact" aria-labelledby="contact-title">
        <span class="eyebrow">Contact</span>
        <h2 id="contact-title">Brille Amour Prints & Crafts</h2>
        <p class="intro">For inquiries, custom layouts, and personalized craft requests, reach Brille Amour through the contact details below.</p>
        <div class="contact-info" aria-label="Business contact information">
          <article>
            <span>Facebook</span>
            <a class="social-link" href="https://web.facebook.com/profile.php?id=61562748920721" target="_blank" rel="noopener noreferrer" aria-label="Open Brille Amour Prints and Crafts on Facebook">
              <b aria-hidden="true">f</b>
              <strong>Brille Amour Prints & Crafts</strong>
            </a>
          </article>
          <article>
            <span>Category</span>
            <strong>Arts & Crafts Store</strong>
          </article>
          <article>
            <span>Address</span>
            <strong>DAVAO, Davao City, Philippines, 8000</strong>
          </article>
          <article>
            <span>Mobile</span>
            <strong>0910 999 0449</strong>
          </article>
          <article>
            <span>Email</span>
            <strong>jeanneagbayan@sip2cd.edu.ph</strong>
          </article>
          <article>
            <span>Rating</span>
            <strong>Not yet rated (0 Reviews)</strong>
          </article>
          <article>
            <span>Hours</span>
            <strong>Always open</strong>
          </article>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <small>&copy; 2026 Brille Amour Prints & Crafts &middot; Local prints, layouts, and personalized crafts &middot; <a href="privacy-policy.php">Privacy Policy</a> &middot; <a href="terms-and-conditions.php">Terms and Conditions</a></small>
    </footer>

    <?php if ($landingCustomerLoggedIn): ?>
      <a class="floating-order-button" href="customer/home.php" aria-label="Open your customer home">Get Started</a>
    <?php else: ?>
      <a class="floating-order-button" href="index.php?auth=signup#customer-auth" data-auth-open="signup" data-get-started="signup" data-no-ajax aria-haspopup="dialog" aria-controls="customerAuthModal" aria-label="Get started — sign up">Get Started</a>
    <?php endif; ?>
  </div>

  <?php if (!$landingCustomerLoggedIn): ?>
  <div class="customer-auth-modal<?= $landingAuthOpen ? ' is-open' : '' ?>" id="customerAuthModal" data-initial-mode="<?= $landingAuthVerify ? 'verify' : ($landingAuthSignup ? 'signup' : 'login') ?>" aria-hidden="<?= $landingAuthOpen ? 'false' : 'true' ?>" role="dialog" aria-modal="true" aria-labelledby="customerAuthTitle">
    <div class="customer-auth-backdrop" data-auth-close></div>
    <section class="customer-auth-dialog" id="customer-auth" role="document">
      <button class="customer-auth-close" type="button" data-auth-close aria-label="Close sign in and sign up">&times;</button>
      <div class="customer-auth-brand">
        <img src="assets/img/image.png" alt="Brille Amour logo">
        <div><strong>Brille Amour</strong><span>Customer Account</span></div>
      </div>

      <div class="customer-auth-tabs" role="tablist" aria-label="Customer account">
        <button class="<?= (!$landingAuthVerify && !$landingAuthSignup) ? 'is-active' : '' ?>" type="button" role="tab" aria-selected="<?= (!$landingAuthVerify && !$landingAuthSignup) ? 'true' : 'false' ?>" data-auth-tab="login"<?= $landingAuthVerify ? ' disabled' : '' ?>>Login</button>
        <button class="<?= (!$landingAuthVerify && $landingAuthSignup) ? 'is-active' : '' ?>" type="button" role="tab" aria-selected="<?= (!$landingAuthVerify && $landingAuthSignup) ? 'true' : 'false' ?>" data-auth-tab="signup"<?= $landingAuthVerify ? ' disabled' : '' ?>>Sign Up</button>
      </div>

      <div class="customer-auth-status<?= ($landingAuthError !== '' || $landingAuthSuccess !== '') ? ' is-visible' : '' ?>" data-auth-status role="alert"><?= htmlspecialchars($landingAuthError !== '' ? $landingAuthError : $landingAuthSuccess) ?></div>

      <div class="customer-auth-pane<?= (!$landingAuthVerify && !$landingAuthSignup) ? ' is-active' : '' ?>" data-auth-pane="login"<?= ($landingAuthVerify || $landingAuthSignup) ? ' hidden' : '' ?>>
        <div class="customer-auth-heading">
          <span class="eyebrow">Welcome back</span>
          <h2 id="customerAuthTitle">Login to continue</h2>
          <p>Sign in first before creating a new order.</p>
        </div>
        <form class="customer-modal-auth-form" data-auth-form="login" action="index.php#customer-auth" method="post" data-no-ajax>
          <input type="hidden" name="customer_modal_action" value="login">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($landingAuthCsrf) ?>">
          <label><span>Email Address</span><input type="email" name="email" value="<?= htmlspecialchars($landingLoginEmail) ?>" autocomplete="email" placeholder="you@example.com" required></label>
          <label><span>Password</span><div class="customer-modal-password"><input id="landingLoginPassword" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required><button type="button" data-modal-password-toggle="landingLoginPassword"></button></div></label>
          <button class="customer-auth-submit" type="submit">Login &amp; Continue</button>
        </form>
        <div class="customer-auth-divider"><span>or</span></div>
        <a class="customer-google-auth" href="customer/login.php?google=1&amp;return=home.php" data-no-ajax>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.8 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.5a4.7 4.7 0 0 1-2 3.1v2.6h3.3c1.9-1.8 3-4.4 3-7.5z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.7-2.4l-3.3-2.6c-.9.6-2.1 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3v2.7A10 10 0 0 0 12 22z"/><path fill="#FBBC05" d="M6.4 13.9A6 6 0 0 1 6.1 12c0-.7.1-1.3.3-1.9V7.4H3A10 10 0 0 0 2 12c0 1.7.4 3.2 1 4.6l3.4-2.7z"/><path fill="#EA4335" d="M12 6c1.5 0 2.8.5 3.9 1.5l2.9-2.9A9.8 9.8 0 0 0 12 2a10 10 0 0 0-9 5.4l3.4 2.7C7.2 7.8 9.4 6 12 6z"/></svg>
          <span>Continue with Google</span>
        </a>
        <p class="customer-auth-switch">No account yet? <button type="button" data-auth-tab="signup">Create one here</button></p>
      </div>

      <div class="customer-auth-pane<?= (!$landingAuthVerify && $landingAuthSignup) ? ' is-active' : '' ?>" data-auth-pane="signup"<?= (!$landingAuthVerify && $landingAuthSignup) ? '' : ' hidden' ?>>
        <div class="customer-auth-heading">
          <span class="eyebrow">New customer</span>
          <h2>Create your account</h2>
          <p>Create an account first, then continue directly to your order.</p>
        </div>
        <form class="customer-modal-auth-form" data-auth-form="signup" action="index.php#customer-auth" method="post" data-no-ajax>
          <input type="hidden" name="customer_modal_action" value="signup">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($landingAuthCsrf) ?>">
          <label><span>Full Name</span><input type="text" name="name" value="<?= htmlspecialchars($landingSignupName) ?>" autocomplete="name" placeholder="Your full name" maxlength="150" required></label>
          <label><span>Email Address</span><input type="email" name="email" value="<?= htmlspecialchars($landingSignupEmail) ?>" autocomplete="email" placeholder="you@example.com" required></label>
          <label><span>Contact Number</span><input type="tel" name="contact" value="<?= htmlspecialchars($landingSignupContact) ?>" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+()\-\s]{7,30}" placeholder="09XX XXX XXXX" required></label>
          <label><span>Delivery Address</span><textarea name="address" rows="3" minlength="5" maxlength="1000" autocomplete="street-address" placeholder="House no., street, barangay, city / municipality, province" required><?= htmlspecialchars($landingSignupAddress) ?></textarea><small class="customer-auth-field-note">Saved to your profile and automatically filled during delivery checkout.</small></label>
          <label><span>Password</span><div class="customer-modal-password"><input id="landingSignupPassword" type="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" maxlength="72" required><button type="button" data-modal-password-toggle="landingSignupPassword"></button></div></label>
          <label><span>Confirm Password</span><div class="customer-modal-password"><input id="landingConfirmPassword" type="password" name="confirm_password" autocomplete="new-password" placeholder="Repeat your password" minlength="8" maxlength="72" required><button type="button" data-modal-password-toggle="landingConfirmPassword"></button></div></label>
          <label class="ba-signup-consent"><input type="checkbox" name="accept_terms" data-terms-consent value="1" required><span>I agree to the Terms &amp; Conditions and acknowledge the Privacy Policy.</span></label><button type="button" class="ba-terms-read-link" data-terms-open>Read Terms &amp; Privacy</button><small class="ba-terms-read-note" id="baTermsReviewHelp">Tap the checkbox to read the Terms & Privacy in a modal. Then tap it again to agree; it is never checked automatically.</small>
          <button class="customer-auth-submit" type="submit">Create Account &amp; Continue</button>
        </form>
        <div class="customer-auth-divider"><span>or</span></div>
        <a class="customer-google-auth" href="customer/signup.php?google=1&amp;return=home.php" data-no-ajax>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.8 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.5a4.7 4.7 0 0 1-2 3.1v2.6h3.3c1.9-1.8 3-4.4 3-7.5z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.7-2.4l-3.3-2.6c-.9.6-2.1 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3v2.7A10 10 0 0 0 12 22z"/><path fill="#FBBC05" d="M6.4 13.9A6 6 0 0 1 6.1 12c0-.7.1-1.3.3-1.9V7.4H3A10 10 0 0 0 2 12c0 1.7.4 3.2 1 4.6l3.4-2.7z"/><path fill="#EA4335" d="M12 6c1.5 0 2.8.5 3.9 1.5l2.9-2.9A9.8 9.8 0 0 0 12 2a10 10 0 0 0-9 5.4l3.4 2.7C7.2 7.8 9.4 6 12 6z"/></svg>
          <span>Continue with Google</span>
        </a>
        <p class="customer-auth-switch">Already registered? <button type="button" data-auth-tab="login">Login instead</button></p>
      </div>

      <div class="customer-auth-pane<?= $landingAuthVerify ? ' is-active' : '' ?>" data-auth-pane="verify"<?= $landingAuthVerify ? '' : ' hidden' ?>>
        <div class="customer-auth-heading">
          <span class="eyebrow">Email verification</span>
          <h2>Enter verification code</h2>
          <p>Enter the 6-digit code sent to <strong data-verification-email><?= htmlspecialchars((string)($pendingVerification['email'] ?? 'your Gmail/email account')) ?></strong>.</p>
        </div>
        <form class="customer-modal-auth-form" data-auth-form="verify" action="index.php#customer-auth" method="post" data-no-ajax>
          <input type="hidden" name="customer_modal_action" value="<?= htmlspecialchars($pendingVerificationAction) ?>" data-verification-action>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($landingAuthCsrf) ?>">
          <label><span>Verification Code</span><input type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="6-digit code" required></label>
          <button class="customer-auth-submit" type="submit">Verify &amp; Continue</button>
        </form>
        <p class="customer-auth-switch"><button type="button" data-verification-back>Back to account form</button></p>
      </div>
    </section>
  </div>
  <?php endif; ?>
<?php if (!$landingCustomerLoggedIn): $termsDocumentPrefix = ''; require __DIR__ . '/includes/customer_terms_modal.php'; endif; ?>
<!-- Load after the modal markup so both a normal visit and AJAX navigation
     initialize Get Started and the password visibility controls. -->
<script src="assets/js/landing.js?v=<?= filemtime(__DIR__ . '/assets/js/landing.js') ?>"></script>
<script src="assets/js/customer-landing-featured.js?v=<?= filemtime(__DIR__ . '/assets/js/customer-landing-featured.js') ?>"></script>
<script src="assets/js/customer-terms-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/customer-terms-modal.js') ?>"></script>
<script src="assets/js/password-eye.js?v=<?= filemtime(__DIR__ . '/assets/js/password-eye.js') ?>"></script>
<script src="assets/js/public-ajax.js?v=<?= filemtime(__DIR__ . "/assets/js/public-ajax.js") ?>"></script>
</body>

</html>
