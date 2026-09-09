<?php

declare(strict_types=1);

$raw = file_get_contents('php://input');
file_put_contents(getenv('CE_FIXTURE_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders()),
    'body' => $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$response = json_decode(file_get_contents(getenv('CE_FIXTURE_STATE')), true, 512, JSON_THROW_ON_ERROR);
foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
http_response_code($response['status']);
echo $response['body'];
