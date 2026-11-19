<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
$sql = file_get_contents(__DIR__ . '/../database/gcash_payments.sql');
$conn->multi_query($sql);
do { if ($result = $conn->store_result()) { $result->free(); } } while ($conn->more_results() && $conn->next_result());
if ($conn->errno) { throw new RuntimeException($conn->error); }
echo "GCash payment tables ready.\n";
