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

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Valid JSON body required']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);

    $name = trim((string)($input['name'] ?? ''));
    $bioInput = $input['bio'] ?? null;
    $bio = $bioInput === null ? null : trim((string)$bioInput);

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        respond(422, ['success' => false, 'message' => 'Name must be 2–80 characters']);
    }

    if ($bio !== null && mb_strlen($bio) > 250) {
        respond(422, ['success' => false, 'message' => 'Bio must be 250 characters or less']);
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET name = :name, bio = :bio WHERE id = :id'
    );
    $stmt->execute([
        'name' => $name,
        'bio' => $bio,
        'id' => (int)$user['id'],
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Profile updated',
        'profile' => [
            'id' => (int)$user['id'],
            'name' => $name,
            'bio' => $bio,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not update profile']);
}