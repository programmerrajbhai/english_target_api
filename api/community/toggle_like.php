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
$postId = filter_var($input['post_id'] ?? null, FILTER_VALIDATE_INT);

if (!is_array($input) || !$postId || $postId < 1) {
    respond(422, ['success' => false, 'message' => 'Valid post_id is required']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int)$user['id'];

    $pdo->beginTransaction();

    // Lock the post row so simultaneous taps cannot create duplicate likes.
    $postStmt = $pdo->prepare(
        'SELECT p.id
         FROM posts p
         INNER JOIN users author ON author.id = p.user_id
         WHERE p.id = :post_id
           AND p.status = "active"
           AND author.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (b.blocker_id = :viewer1 AND b.blocked_id = author.id)
                  OR (b.blocker_id = author.id AND b.blocked_id = :viewer2)
           )
         FOR UPDATE'
    );

    $postStmt->execute([
        'post_id' => $postId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ]);

    if (!$postStmt->fetch()) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'Post not found']);
    }

    $check = $pdo->prepare(
        'SELECT 1 FROM post_likes WHERE post_id = :post_id AND user_id = :user_id'
    );
    $check->execute([
        'post_id' => $postId,
        'user_id' => $userId,
    ]);

    if ($check->fetchColumn()) {
        $change = $pdo->prepare(
            'DELETE FROM post_likes WHERE post_id = :post_id AND user_id = :user_id'
        );
        $change->execute([
            'post_id' => $postId,
            'user_id' => $userId,
        ]);
        $isLiked = false;
    } else {
        $change = $pdo->prepare(
            'INSERT INTO post_likes (post_id, user_id) VALUES (:post_id, :user_id)'
        );
        $change->execute([
            'post_id' => $postId,
            'user_id' => $userId,
        ]);
        $isLiked = true;
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM post_likes WHERE post_id = :post_id'
    );
    $countStmt->execute(['post_id' => $postId]);
    $likesCount = (int)$countStmt->fetchColumn();

    $pdo->commit();

    respond(200, [
        'success' => true,
        'is_liked' => $isLiked,
        'likes_count' => $likesCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not update like']);
}