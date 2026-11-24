<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // শুধু localhost testing-এর জন্য
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'GET method required']);
}

$postId = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);

if (!$postId || $postId < 1) {
    respond(422, ['success' => false, 'message' => 'Valid post_id is required']);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int)$viewer['id'];

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
         LIMIT 1'
    );

    $postStmt->execute([
        'post_id' => $postId,
        'viewer1' => $viewerId,
        'viewer2' => $viewerId,
    ]);

    if (!$postStmt->fetch()) {
        respond(404, ['success' => false, 'message' => 'Post not found']);
    }

    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.post_id,
            c.parent_id,
            c.body,
            c.created_at,
            u.id AS user_id,
            u.name AS user_name,
            u.avatar_key AS user_avatar_key,
            u.level AS user_level
         FROM comments c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.post_id = :post_id
           AND c.status = "active"
           AND u.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (b.blocker_id = :viewer1 AND b.blocked_id = u.id)
                  OR (b.blocker_id = u.id AND b.blocked_id = :viewer2)
           )
         ORDER BY c.created_at ASC, c.id ASC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer1', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer2', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $comments = $stmt->fetchAll();
    $hasMore = count($comments) > $limit;

    if ($hasMore) {
        array_pop($comments);
    }

    foreach ($comments as &$comment) {
        $comment['id'] = (int)$comment['id'];
        $comment['post_id'] = (int)$comment['post_id'];
        $comment['parent_id'] = $comment['parent_id'] === null
            ? null
            : (int)$comment['parent_id'];
        $comment['user_id'] = (int)$comment['user_id'];
        $comment['user_level'] = (int)$comment['user_level'];
    }
    unset($comment);

    respond(200, [
        'success' => true,
        'comments' => $comments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load comments']);
}