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

$postId = filter_var($input['post_id'] ?? null, FILTER_VALIDATE_INT);
$parentId = isset($input['parent_id'])
    ? filter_var($input['parent_id'], FILTER_VALIDATE_INT)
    : null;
$body = trim((string)($input['body'] ?? ''));

if (!$postId || $postId < 1 || ($parentId !== null && (!$parentId || $parentId < 1))) {
    respond(422, ['success' => false, 'message' => 'Valid post_id and parent_id are required']);
}

if ($body === '' || mb_strlen($body) > 1000) {
    respond(422, ['success' => false, 'message' => 'Comment must be 1–1000 characters']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int)$user['id'];

    if ($user['status'] === 'muted') {
        respond(403, ['success' => false, 'message' => 'Commenting is disabled for this account']);
    }

    $postStmt = $pdo->prepare(
        'SELECT p.user_id
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
         LIMIT 1'
    );

    $postStmt->execute([
        'post_id' => $postId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ]);

    $post = $postStmt->fetch();

    if (!$post) {
        respond(404, ['success' => false, 'message' => 'Post not found']);
    }

    $parentUserId = null;

    if ($parentId !== null) {
        $parentStmt = $pdo->prepare(
            'SELECT c.user_id
             FROM comments c
             INNER JOIN users comment_author ON comment_author.id = c.user_id
             WHERE c.id = :parent_id
               AND c.post_id = :post_id
               AND c.status = "active"
               AND comment_author.status NOT IN ("banned", "deleted")
             LIMIT 1'
        );
        $parentStmt->execute([
            'parent_id' => $parentId,
            'post_id' => $postId,
        ]);
        $parent = $parentStmt->fetch();

        if (!$parent) {
            respond(404, ['success' => false, 'message' => 'Parent comment not found']);
        }

        $parentUserId = (int)$parent['user_id'];
    }

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT INTO comments (post_id, user_id, parent_id, body)
         VALUES (:post_id, :user_id, :parent_id, :body)'
    );
    $insert->execute([
        'post_id' => $postId,
        'user_id' => $userId,
        'parent_id' => $parentId,
        'body' => $body,
    ]);

    $commentId = (int)$pdo->lastInsertId();
    $notifyUserIds = [];

    $postOwnerId = (int)$post['user_id'];
    if ($postOwnerId !== $userId) {
        $notifyUserIds[] = $postOwnerId;
    }

    if ($parentUserId !== null && $parentUserId !== $userId) {
        $notifyUserIds[] = $parentUserId;
    }

    $notifyUserIds = array_unique($notifyUserIds);

    if ($notifyUserIds !== []) {
        $notify = $pdo->prepare(
            'INSERT INTO notifications (user_id, actor_id, type, post_id, comment_id)
             VALUES (:user_id, :actor_id, "comment", :post_id, :comment_id)'
        );

        foreach ($notifyUserIds as $notifyUserId) {
            $notify->execute([
                'user_id' => $notifyUserId,
                'actor_id' => $userId,
                'post_id' => $postId,
                'comment_id' => $commentId,
            ]);
        }
    }

    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => 'Comment added',
        'comment' => [
            'id' => $commentId,
            'post_id' => $postId,
            'parent_id' => $parentId,
            'body' => $body,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not add comment']);
}