<?php
/**
 * Shared CSRF helpers for authenticated Admin/Staff state-changing actions.
 * Call only after the appropriate portal session has been started.
 */
if (!function_exists('portalCsrfToken')) {
    function portalCsrfToken(): string
    {
        if (empty($_SESSION['portal_csrf_token']) || !is_string($_SESSION['portal_csrf_token'])) {
            $_SESSION['portal_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['portal_csrf_token'];
    }
}

if (!function_exists('portalCsrfValid')) {
    function portalCsrfValid(?string $token): bool
    {
        $stored = (string)($_SESSION['portal_csrf_token'] ?? '');
        return $stored !== '' && is_string($token) && $token !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('portalCsrfInput')) {
    function portalCsrfInput(string $name = 'portal_csrf_token'): string
    {
        $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $tokenEsc = htmlspecialchars(portalCsrfToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . $nameEsc . '" value="' . $tokenEsc . '">';
    }
}
