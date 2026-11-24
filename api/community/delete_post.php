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
$postId = is_array($input)
    ? filter_var($input['post_id'] ?? null, FILTER_VALIDATE_INT)
    : false;

if (!$postId || $postId < 1) {
    respond(422, ['success' => false, 'message' => 'Valid post_id is required']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);

    $stmt = $pdo->prepare(
        'UPDATE posts
         SET status = "removed"
         WHERE id = :post_id
           AND user_id = :user_id
           AND status IN ("active", "hidden")'
    );

    $stmt->execute([
        'post_id' => $postId,
        'user_id' => (int)$user['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Post not found or you cannot delete it',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Post deleted',
        'post_id' => $postId,
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not delete post']);
}