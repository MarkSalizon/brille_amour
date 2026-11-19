<?php
/**
 * production_api.php (staff)
 * AJAX + real-time endpoint for the Production Board.
 * Boots the staff session, then hands off to the shared handler.
 */

require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('staff');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Sign in again.']);
    exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/messaging.php';

$portalIsAdmin = false;

require '../includes/production_api.php';
