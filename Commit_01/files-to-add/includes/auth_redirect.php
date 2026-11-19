<?php
function authPortalConfig(): array
{
    return [
        'admin' => [
            'session' => 'admin_session',
            'dashboard' => '../admin/dashboard.php',
        ],
        'staff' => [
            'session' => 'staff_session',
            'dashboard' => '../staff/dashboard.php',
        ],
    ];
}

function portalSessionCookieParams(): array
{
    $params = session_get_cookie_params();

    return [
        'lifetime' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function startPortalSession(string $role): void
{
    $portals = authPortalConfig();
    if (!isset($portals[$role])) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($portals[$role]['session']);
    session_set_cookie_params(portalSessionCookieParams());

    $sessionPath = dirname(__DIR__) . '/.tmp/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    session_start();
}

function portalIsAuthenticated(string $role): bool
{
    startPortalSession($role);
    $authenticated = isset($_SESSION['user_id'], $_SESSION['role']) && $_SESSION['role'] === $role;
    session_write_close();

    return $authenticated;
}

function inferPortalFromRequest(): string
{
    $portal = $_POST['portal'] ?? $_GET['portal'] ?? '';
    $portals = authPortalConfig();

    if (isset($portals[$portal])) {
        return $portal;
    }

    $refererPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '';
    foreach ($portals as $role => $config) {
        if (strpos($refererPath, '/' . $role . '/') !== false) {
            return $role;
        }
    }

    foreach ($portals as $role => $config) {
        if (!empty($_COOKIE[$config['session']])) {
            return $role;
        }
    }

    return '';
}

function startAuthPageSession(string $pageRole): void
{
    $portals = authPortalConfig();
    if (!isset($portals[$pageRole])) {
        return;
    }

    startPortalSession($pageRole);

    if (isset($_SESSION['user_id'], $_SESSION['role']) && $_SESSION['role'] === $pageRole) {
        header('Location: ' . $portals[$pageRole]['dashboard']);
        exit;
    }
}

function destroyPortalSession(string $role): void
{
    $portals = authPortalConfig();
    if (!isset($portals[$role])) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($portals[$role]['session']);
    session_set_cookie_params(portalSessionCookieParams());
    session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    session_write_close();
}
