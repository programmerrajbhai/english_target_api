<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // শুধু localhost testing-এর জন্য
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST method required']);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
    respond(401, ['success' => false, 'message' => 'Bearer token required']);
}

require_once __DIR__ . '/../../config/db.php';

try {
    $stmt = db()->prepare('DELETE FROM auth_tokens WHERE token_hash = :token_hash');
    $stmt->execute([
        'token_hash' => hash('sha256', $matches[1]),
    ]);

    respond(200, ['success' => true, 'message' => 'Logged out']);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Logout failed']);
}