<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // শুধু localhost testing-এর জন্য
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Valid JSON body required']);
}

$identifier = trim((string)($input['email_or_phone'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    respond(422, ['success' => false, 'message' => 'Email/phone and password are required']);
}

require_once __DIR__ . '/../../config/db.php';

try {
    $pdo = db();

    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, password_hash, status
         FROM users
         WHERE ' . ($isEmail ? 'email = :identifier' : 'phone = :identifier') . '
         LIMIT 1'
    );

    $stmt->execute([
        'identifier' => $isEmail ? strtolower($identifier) : $identifier,
    ]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['success' => false, 'message' => 'Invalid email/phone or password']);
    }

    if ($user['status'] === 'banned' || $user['status'] === 'deleted') {
        respond(403, ['success' => false, 'message' => 'This account is unavailable']);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

    $tokenStmt = $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );

    $tokenStmt->execute([
        'user_id' => (int)$user['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Login failed']);
}