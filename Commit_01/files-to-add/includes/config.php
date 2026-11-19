<?php
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (is_array($env)) {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

function config_env($key, $default = '')
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : $value;
}

define('BASE_URL', rtrim((string)config_env('APP_BASE_PATH', ''), '/'));
define('APP_ENV', (string)config_env('APP_ENV', 'production'));
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
define('SITE_NAME', 'Brille Amour');
define('VERSION', '1.0.0');
define('APP_TIMEZONE', 'Asia/Manila');
define('MAX_ADMIN_USERS', 5);
define('MAX_STAFF_USERS', 10);

// Google Cloud OAuth setup (optional): add the deployed HTTPS callback URLs
// for admin, staff, and customer to Google Cloud if Google sign-in is enabled.
define('GOOGLE_CLIENT_ID', config_env('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', config_env('GOOGLE_CLIENT_SECRET'));

// Gmail SMTP verification-code sender:
// Use a Google account with 2-Step Verification enabled, then create an App Password.
define('GOOGLE_SMTP_EMAIL', config_env('GOOGLE_SMTP_EMAIL'));
define('GOOGLE_SMTP_APP_PASSWORD', config_env('GOOGLE_SMTP_APP_PASSWORD'));
define('GOOGLE_SMTP_FROM_NAME', config_env('GOOGLE_SMTP_FROM_NAME', SITE_NAME));

date_default_timezone_set(APP_TIMEZONE);
