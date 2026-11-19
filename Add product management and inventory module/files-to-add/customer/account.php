<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: ../index.php?auth=login');
    exit;
}
header('Location: home.php');
exit;
