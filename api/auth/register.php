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

$name = trim((string)($input['name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$phone = trim((string)($input['phone'] ?? ''));
$password = (string)($input['password'] ?? '');

if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
    respond(422, ['success' => false, 'message' => 'Name must be 2–80 characters']);
}

if ($email === '' && $phone === '') {
    respond(422, ['success' => false, 'message' => 'Email or phone is required']);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['success' => false, 'message' => 'Invalid email address']);
}

if ($phone !== '' && !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
    respond(422, ['success' => false, 'message' => 'Invalid phone number']);
}

if (strlen($password) < 8) {
    respond(422, ['success' => false, 'message' => 'Password must be at least 8 characters']);
}

require_once __DIR__ . '/../../config/db.php';

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, phone, password_hash)
         VALUES (:name, :email, :phone, :password_hash)'
    );

    $stmt->execute([
        'name' => $name,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $userId = (int)$pdo->lastInsertId();
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

    $tokenStmt = $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );

    $tokenStmt->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => 'Account created',
        'token' => $token,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
        ],
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getCode() === '23000') {
        respond(409, ['success' => false, 'message' => 'Email or phone is already registered']);
    }

    respond(500, ['success' => false, 'message' => 'Database operation failed']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Registration failed']);
}