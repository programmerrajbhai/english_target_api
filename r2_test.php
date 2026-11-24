<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/config/r2.php';
    $s3 = require __DIR__ . '/config/r2_client.php';

    $s3->listObjectsV2([
        'Bucket'  => $config['bucket'],
        'MaxKeys' => 1,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'R2 connection successful',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'R2 connection failed. Check local R2 config and bucket token permissions.',
    ]);
}