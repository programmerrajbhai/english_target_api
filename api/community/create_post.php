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

    if ($user['status'] === 'muted') {
        respond(403, ['success' => false, 'message' => 'Posting is disabled for this account']);
    }

    $category = trim((string)($input['category'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));

    $allowedCategories = ['speaking', 'ask_doubt', 'notes'];

    if (!in_array($category, $allowedCategories, true)) {
        respond(422, [
            'success' => false,
            'message' => 'Category must be speaking, ask_doubt, or notes',
        ]);
    }

    if ($body === '' || mb_strlen($body) > 2000) {
        respond(422, [
            'success' => false,
            'message' => 'Post text must be 1–2000 characters',
        ]);
    }

    // Local testing-এ spam কমাতে প্রতিদিন সর্বোচ্চ 5টি post
    $limitStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM posts
         WHERE user_id = :user_id
           AND created_at >= NOW() - INTERVAL 1 DAY'
    );
    $limitStmt->execute(['user_id' => (int)$user['id']]);

    if ((int)$limitStmt->fetchColumn() >= 5) {
        respond(429, ['success' => false, 'message' => 'Daily post limit reached']);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO posts (user_id, category, post_type, body)
         VALUES (:user_id, :category, "text", :body)'
    );
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'category' => $category,
        'body' => $body,
    ]);

    respond(201, [
        'success' => true,
        'message' => 'Post created',
        'post' => [
            'id' => (int)$pdo->lastInsertId(),
            'category' => $category,
            'post_type' => 'text',
            'body' => $body,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not create post']);
}