<?php
require_once '../includes/auth_redirect.php';

destroyPortalSession('staff');
header('Location: login.php?ba_splash=logout');
exit;
