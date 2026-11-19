<?php
require_once __DIR__ . '/includes/auth_redirect.php';

$portal = inferPortalFromRequest();

if ($portal === 'admin') {
    destroyPortalSession('admin');
    header('Location: admin/login.php?ba_splash=logout');
    exit;
}

if ($portal === 'staff') {
    destroyPortalSession('staff');
    header('Location: staff/login.php?ba_splash=logout');
    exit;
}

header('Location: index.php?ba_splash=logout');
exit;
