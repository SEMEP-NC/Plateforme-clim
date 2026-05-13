<?php

$url = "http://clim_scheduler:5001/run-discovery";

$options = [
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json",
        "timeout" => 60
    ]
];

$context = stream_context_create($options);

$response = file_get_contents($url, false, $context);

header("Content-Type: application/json");

echo $response;