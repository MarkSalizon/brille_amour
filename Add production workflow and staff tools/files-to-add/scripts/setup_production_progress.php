<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/production_progress.php';
ensureProductionProgressSchema($conn);
echo "Production progress schema ready.\n";
