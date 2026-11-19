<?php
require_once __DIR__ . '/config.php';

if (!defined('DB_HOST')) define('DB_HOST', (string)config_env('DB_HOST', 'localhost'));
if (!defined('DB_USER')) define('DB_USER', (string)config_env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', (string)config_env('DB_PASS', ''));
if (!defined('DB_NAME')) define('DB_NAME', (string)config_env('DB_NAME', 'brille_amour'));

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_errno) {
    error_log('Brille Amour database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection is not configured yet. Please check the server .env database settings.');
}

$conn->set_charset('utf8mb4');
// The application displays and stores operational timestamps in Philippine time.
@$conn->query("SET time_zone = '+08:00'");
