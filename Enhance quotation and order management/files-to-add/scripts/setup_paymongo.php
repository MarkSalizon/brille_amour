<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
$conn->query(file_get_contents(__DIR__ . '/../database/paymongo.sql'));
echo "PayMongo checkout table ready.\n";
