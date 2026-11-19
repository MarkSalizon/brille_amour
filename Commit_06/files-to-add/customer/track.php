<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);

if (!loadSignedInCustomer($conn)) {
    header('Location: login.php?return=orders.php');
    exit;
}

header('Location: orders.php');
exit;
