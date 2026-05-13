<?php

$output = [];
$returnCode = 0;

exec(
    "docker exec clim_scheduler python /app/discovery_manual.py 2>&1",
    $output,
    $returnCode
);

header('Content-Type: application/json');

echo json_encode([
    'success' => $returnCode === 0,
    'return_code' => $returnCode,
    'output' => $output
]);