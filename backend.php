<?php
// Simple backend endpoint to serve the characters JSON with basic validation.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$jsonPath = __DIR__ . '/updated.json';

if (!is_readable($jsonPath)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DATA_FILE_MISSING',
        'message' => 'updated.json not found beside backend.php.',
    ]);
    exit;
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DATA_FILE_READ_ERROR',
        'message' => 'Could not read updated.json.',
    ]);
    exit;
}

$decoded = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DATA_FILE_INVALID_JSON',
        'message' => 'updated.json contains invalid JSON.',
        'detail' => json_last_error_msg(),
    ]);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
