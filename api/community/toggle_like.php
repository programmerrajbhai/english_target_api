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

$postId = filter_var(
    $input['post_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$postId || $postId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid post_id is required',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $pdo->beginTransaction();

    // Post lock এবং visibility যাচাই।
    $postStmt = $pdo->prepare(
        'SELECT p.id, p.user_id
         FROM posts p
         INNER JOIN users author ON author.id = p.user_id
         WHERE p.id = :post_id
           AND p.status = "active"
           AND author.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (
                   b.blocker_id = :viewer1
                   AND b.blocked_id = author.id
               ) OR (
                   b.blocker_id = author.id
                   AND b.blocked_id = :viewer2
               )
           )
         FOR UPDATE'
    );

    $postStmt->execute([
        'post_id' => (int) $postId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ]);

    $post = $postStmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Post not found',
        ]);
    }

    $ownerId = (int) $post['user_id'];

    $check = $pdo->prepare(
        'SELECT 1
         FROM post_likes
         WHERE post_id = :post_id
           AND user_id = :user_id'
    );

    $check->execute([
        'post_id' => (int) $postId,
        'user_id' => $userId,
    ]);

    $alreadyLiked = (bool) $check->fetchColumn();

    // এই actor ও post-এর আগের like notification পরিষ্কার।
    // এতে unlike এবং পুনরায় like—দুটিতেই duplicate হবে না।
    $clear = $pdo->prepare(
        'DELETE FROM notifications
         WHERE user_id = :owner_id
           AND actor_id = :actor_id
           AND post_id = :post_id
           AND type = "like"
           AND comment_id IS NULL'
    );

    $clear->execute([
        'owner_id' => $ownerId,
        'actor_id' => $userId,
        'post_id' => (int) $postId,
    ]);

    if ($alreadyLiked) {
        $delete = $pdo->prepare(
            'DELETE FROM post_likes
             WHERE post_id = :post_id
               AND user_id = :user_id'
        );

        $delete->execute([
            'post_id' => (int) $postId,
            'user_id' => $userId,
        ]);

        $isLiked = false;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO post_likes (post_id, user_id)
             VALUES (:post_id, :user_id)'
        );

        $insert->execute([
            'post_id' => (int) $postId,
            'user_id' => $userId,
        ]);

        if ($ownerId !== $userId) {
            $notify = $pdo->prepare(
                'INSERT INTO notifications (
                    user_id, actor_id, type, post_id
                 ) VALUES (
                    :user_id, :actor_id, "like", :post_id
                 )'
            );

            $notify->execute([
                'user_id' => $ownerId,
                'actor_id' => $userId,
                'post_id' => (int) $postId,
            ]);
        }

        $isLiked = true;
    }

    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM post_likes
         WHERE post_id = :post_id'
    );

    $count->execute([
        'post_id' => (int) $postId,
    ]);

    $likesCount = (int) $count->fetchColumn();

    $pdo->commit();

    respond(200, [
        'success' => true,
        'post_id' => (int) $postId,
        'is_liked' => $isLiked,
        'likes_count' => $likesCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not update like',
    ]);
}