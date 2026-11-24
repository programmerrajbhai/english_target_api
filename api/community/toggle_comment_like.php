<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Local testing
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');

    respond(405, [
        'success' => false,
        'message' => 'POST method required',
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Valid JSON body required',
    ]);
}

$commentId = filter_var(
    $input['comment_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$commentId || $commentId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid comment_id is required',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $pdo->beginTransaction();

    // Comment, parent post এবং দুই author-এর visibility যাচাই।
    // Row lock একই comment-এ simultaneous request serialize করে।
    $check = $pdo->prepare(
        'SELECT
            c.id,
            c.user_id,
            c.post_id
         FROM comments c
         INNER JOIN posts p ON p.id = c.post_id
         INNER JOIN users ca ON ca.id = c.user_id
         INNER JOIN users pa ON pa.id = p.user_id

         WHERE c.id = :comment_id
           AND c.status = "active"
           AND p.status = "active"
           AND ca.status NOT IN ("banned", "deleted")
           AND pa.status NOT IN ("banned", "deleted")

           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (
                   b.blocker_id = :comment_viewer1
                   AND b.blocked_id = ca.id
               ) OR (
                   b.blocker_id = ca.id
                   AND b.blocked_id = :comment_viewer2
               )
           )

           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (
                   b.blocker_id = :post_viewer1
                   AND b.blocked_id = pa.id
               ) OR (
                   b.blocker_id = pa.id
                   AND b.blocked_id = :post_viewer2
               )
           )

         FOR UPDATE'
    );

    $check->execute([
        'comment_id' => (int) $commentId,
        'comment_viewer1' => $userId,
        'comment_viewer2' => $userId,
        'post_viewer1' => $userId,
        'post_viewer2' => $userId,
    ]);

    $comment = $check->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Comment not found',
        ]);
    }

    $liked = $pdo->prepare(
        'SELECT 1
         FROM comment_likes
         WHERE comment_id = :comment_id
           AND user_id = :user_id'
    );

    $liked->execute([
        'comment_id' => (int) $commentId,
        'user_id' => $userId,
    ]);

    $alreadyLiked = (bool) $liked->fetchColumn();
    $commentOwnerId = (int) $comment['user_id'];

    if ($alreadyLiked) {
        $delete = $pdo->prepare(
            'DELETE FROM comment_likes
             WHERE comment_id = :comment_id
               AND user_id = :user_id'
        );

        $delete->execute([
            'comment_id' => (int) $commentId,
            'user_id' => $userId,
        ]);

        // Unlike হলে সংশ্লিষ্ট notification-ও সরাবে।
        $clear = $pdo->prepare(
            'DELETE FROM notifications
             WHERE user_id = :owner_id
               AND actor_id = :actor_id
               AND comment_id = :comment_id
               AND type = "comment_like"'
        );

        $clear->execute([
            'owner_id' => $commentOwnerId,
            'actor_id' => $userId,
            'comment_id' => (int) $commentId,
        ]);

        $isLiked = false;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO comment_likes (comment_id, user_id)
             VALUES (:comment_id, :user_id)'
        );

        $insert->execute([
            'comment_id' => (int) $commentId,
            'user_id' => $userId,
        ]);

        // নিজের comment like করলে নিজেকে notification দেবে না।
        if ($commentOwnerId !== $userId) {
            $notify = $pdo->prepare(
                'INSERT INTO notifications (
                    user_id,
                    actor_id,
                    type,
                    post_id,
                    comment_id
                 ) VALUES (
                    :user_id,
                    :actor_id,
                    "comment_like",
                    :post_id,
                    :comment_id
                 )'
            );

            $notify->execute([
                'user_id' => $commentOwnerId,
                'actor_id' => $userId,
                'post_id' => (int) $comment['post_id'],
                'comment_id' => (int) $commentId,
            ]);
        }

        $isLiked = true;
    }

    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM comment_likes
         WHERE comment_id = :comment_id'
    );

    $count->execute([
        'comment_id' => (int) $commentId,
    ]);

    $likesCount = (int) $count->fetchColumn();

    $pdo->commit();

    respond(200, [
        'success' => true,
        'comment_id' => (int) $commentId,
        'is_liked' => $isLiked,
        'likes_count' => $likesCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not update comment like',
    ]);
}