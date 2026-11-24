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

    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.user_id AS comment_author_id,
            c.post_id,
            p.user_id AS post_owner_id
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
                   b.blocker_id = :viewer1
                   AND b.blocked_id IN (ca.id, pa.id)
               ) OR (
                   b.blocker_id IN (ca.id, pa.id)
                   AND b.blocked_id = :viewer2
               )
           )
         FOR UPDATE'
    );

    $stmt->execute([
        'comment_id' => (int) $commentId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ]);

    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Comment not found',
        ]);
    }

    if ((int) $comment['post_owner_id'] !== $userId) {
        $pdo->rollBack();
        respond(403, [
            'success' => false,
            'message' => 'Only the post owner can mark answers helpful',
        ]);
    }

    $authorId = (int) $comment['comment_author_id'];

    if ($authorId === $userId) {
        $pdo->rollBack();
        respond(422, [
            'success' => false,
            'message' => 'You cannot mark your own comment helpful',
        ]);
    }

    $check = $pdo->prepare(
        'SELECT marked_by
         FROM comment_helpful
         WHERE comment_id = :comment_id'
    );

    $check->execute([
        'comment_id' => (int) $commentId,
    ]);

    $existingMarker = $check->fetchColumn();

    if ($existingMarker !== false) {
        $remove = $pdo->prepare(
            'DELETE FROM comment_helpful
             WHERE comment_id = :comment_id'
        );
        $remove->execute([
            'comment_id' => (int) $commentId,
        ]);

        $clear = $pdo->prepare(
            'DELETE FROM notifications
             WHERE type = "helpful"
               AND comment_id = :comment_id
               AND user_id = :author_id'
        );
        $clear->execute([
            'comment_id' => (int) $commentId,
            'author_id' => $authorId,
        ]);

        $isHelpful = false;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO comment_helpful (comment_id, marked_by)
             VALUES (:comment_id, :marked_by)'
        );
        $insert->execute([
            'comment_id' => (int) $commentId,
            'marked_by' => $userId,
        ]);

        $notify = $pdo->prepare(
            'INSERT INTO notifications (
                user_id, actor_id, type, post_id, comment_id
             ) VALUES (
                :user_id, :actor_id, "helpful", :post_id, :comment_id
             )'
        );
        $notify->execute([
            'user_id' => $authorId,
            'actor_id' => $userId,
            'post_id' => (int) $comment['post_id'],
            'comment_id' => (int) $commentId,
        ]);

        $isHelpful = true;
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => $isHelpful
            ? 'Answer marked helpful'
            : 'Helpful mark removed',
        'comment_id' => (int) $commentId,
        'is_helpful' => $isHelpful,
        'marked_by' => $isHelpful ? $userId : null,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not update helpful mark',
    ]);
}