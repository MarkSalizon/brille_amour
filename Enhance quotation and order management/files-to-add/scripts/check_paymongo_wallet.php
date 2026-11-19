<?php
require_once __DIR__ . '/../includes/paymongo.php';

$public = (string)config_env('PAYMONGO_PUBLIC_KEY');
$secret = (string)config_env('PAYMONGO_SECRET_KEY');
$expectedPublicPrefix = str_starts_with($secret, 'sk_live_') ? 'pk_live_' : 'pk_test_';

echo 'PayMongo ready: ' . (paymongoReady() ? 'YES' : 'NO') . PHP_EOL;
echo 'Direct wallet ready: ' . (paymongoDirectWalletReady() ? 'YES' : 'NO') . PHP_EOL;
echo 'Public key present: ' . ($public !== '' ? 'YES' : 'NO') . PHP_EOL;
echo 'Public key mode matches secret: ' . (str_starts_with($public, $expectedPublicPrefix) ? 'YES' : 'NO') . PHP_EOL;
echo 'Public URL: ' . (string)config_env('PAYMONGO_PUBLIC_URL') . PHP_EOL;
