<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Local testing
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');

    respond(405, [
        'success' => false,
        'message' => 'GET method required',
    ]);
}

$postId = filter_input(
    INPUT_GET,
    'post_id',
    FILTER_VALIDATE_INT
);

if (!$postId || $postId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid post_id is required',
    ]);
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);

$page = $page === null ? 1 : $page;
$limit = $limit === null ? 20 : $limit;

if ($page === false || $page < 1 || $page > 100000) {
    respond(422, [
        'success' => false,
        'message' => 'page must be between 1 and 100000',
    ]);
}

if ($limit === false || $limit < 1 || $limit > 50) {
    respond(422, [
        'success' => false,
        'message' => 'limit must be between 1 and 50',
    ]);
}

$offset = ($page - 1) * $limit;

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int) $viewer['id'];

    // Post active ও viewer-এর জন্য visible হতে হবে।
    $postStmt = $pdo->prepare(
        'SELECT p.id
         FROM posts p
         INNER JOIN users author ON author.id = p.user_id
         WHERE p.id = :post_id
           AND p.status = "active"
           AND author.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1
               FROM user_blocks b
               WHERE (
                   b.blocker_id = :viewer1
                   AND b.blocked_id = author.id
               ) OR (
                   b.blocker_id = author.id
                   AND b.blocked_id = :viewer2
               )
           )
         LIMIT 1'
    );

    $postStmt->execute([
        'post_id' => (int) $postId,
        'viewer1' => $viewerId,
        'viewer2' => $viewerId,
    ]);

    if (!$postStmt->fetchColumn()) {
        respond(404, [
            'success' => false,
            'message' => 'Post not found',
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.post_id,
            c.parent_id,
            c.body,
            c.english_sentence,
            c.bangla_meaning,
            c.media_key,
            c.media_type,
            c.created_at,

            u.id AS user_id,
            u.name AS user_name,
            u.avatar_key AS user_avatar_key,
            u.level AS user_level,

            (
                SELECT COUNT(*)
                FROM comment_likes cl
                WHERE cl.comment_id = c.id
            ) AS likes_count,

            EXISTS (
                SELECT 1
                FROM comment_likes cl
                WHERE cl.comment_id = c.id
                  AND cl.user_id = :liked_viewer
            ) AS is_liked

         FROM comments c
         INNER JOIN users u ON u.id = c.user_id

         WHERE c.post_id = :post_id
           AND c.status = "active"
           AND u.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1
               FROM user_blocks b
               WHERE (
                   b.blocker_id = :viewer1
                   AND b.blocked_id = u.id
               ) OR (
                   b.blocker_id = u.id
                   AND b.blocked_id = :viewer2
               )
           )

         ORDER BY c.created_at ASC, c.id ASC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $params = [
        ':post_id' => (int) $postId,
        ':liked_viewer' => $viewerId,
        ':viewer1' => $viewerId,
        ':viewer2' => $viewerId,
        ':fetch_limit' => $limit + 1,
        ':offset' => $offset,
    ];

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();

    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($comments) > $limit;

    if ($hasMore) {
        array_pop($comments);
    }

    foreach ($comments as &$comment) {
        foreach ([
            'id',
            'post_id',
            'user_id',
            'user_level',
            'likes_count',
        ] as $field) {
            $comment[$field] = (int) $comment[$field];
        }

        $comment['parent_id'] = $comment['parent_id'] === null
            ? null
            : (int) $comment['parent_id'];

        $comment['is_liked'] = (bool) $comment['is_liked'];
        $comment['is_owner'] = $comment['user_id'] === $viewerId;
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
    respond(500, [
        'success' => false,
        'message' => 'Could not load comments',
    ]);
}