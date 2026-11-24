<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/db.php';

try {
    db();

    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}