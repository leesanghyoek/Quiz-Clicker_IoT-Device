<?php

$host = "localhost";
$user = "iot_app";
$password = "YOUR_DB_PASSWORD";
$database = "iot";

try {
    $conn = new mysqli($host, $user, $password, $database);
} catch (Throwable $exception) {
    error_log(
        'Database connection failed with error code '
        . (int) $exception->getCode()
        . '.'
    );

    http_response_code(500);
    $request_path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    if (str_contains($request_path, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'status' => 'ERROR',
            'reason' => 'server_error'
        ]);
        exit;
    }

    exit('The service is temporarily unavailable. Please try again later.');
}
