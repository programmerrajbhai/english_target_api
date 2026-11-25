<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

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

$postId = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);

if (!$postId || $postId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid post_id is required',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/optional_auth.php';

    $pdo = db();
    $viewer = optionalAuth($pdo);
    $viewerId = $viewer !== null ? (int) $viewer['id'] : 0;
    $isGuest = $viewer === null;

    $stmt = $pdo->prepare(
        'SELECT
            p.id,
            p.category,
            p.post_type,
            p.body,
            p.english_sentence,
            p.bangla_meaning,
            p.media_key,
            p.media_type,
            p.created_at,
            p.updated_at,

            u.id AS author_id,
            u.name AS author_name,
            u.avatar_key AS author_avatar_key,
            u.level AS author_level,

            (
                SELECT COUNT(*)
                FROM post_likes l
                WHERE l.post_id = p.id
            ) AS likes_count,

            (
                SELECT COUNT(*)
                FROM comments c
                INNER JOIN users cu ON cu.id = c.user_id
                WHERE c.post_id = p.id
                  AND c.status = "active"
                  AND cu.status NOT IN ("banned", "deleted")
                  AND NOT EXISTS (
                      SELECT 1 FROM user_blocks cb
                      WHERE (
                          cb.blocker_id = :comment_viewer1
                          AND cb.blocked_id = cu.id
                      ) OR (
                          cb.blocker_id = cu.id
                          AND cb.blocked_id = :comment_viewer2
                      )
                  )
            ) AS comments_count,

            EXISTS (
                SELECT 1 FROM post_likes l
                WHERE l.post_id = p.id
                  AND l.user_id = :liked_viewer
            ) AS is_liked,

            EXISTS (
                SELECT 1 FROM follows f
                WHERE f.follower_id = :following_viewer
                  AND f.following_id = u.id
            ) AS is_following

         FROM posts p
         INNER JOIN users u ON u.id = p.user_id

         WHERE p.id = :post_id
           AND p.status = "active"
           AND u.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (
                   b.blocker_id = :blocked_viewer1
                   AND b.blocked_id = u.id
               ) OR (
                   b.blocker_id = u.id
                   AND b.blocked_id = :blocked_viewer2
               )
           )
         LIMIT 1'
    );

    $params = [
        ':post_id' => (int) $postId,
        ':liked_viewer' => $viewerId,
        ':following_viewer' => $viewerId,
        ':blocked_viewer1' => $viewerId,
        ':blocked_viewer2' => $viewerId,
        ':comment_viewer1' => $viewerId,
        ':comment_viewer2' => $viewerId,
    ];

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        respond(404, [
            'success' => false,
            'message' => 'Post not found',
        ]);
    }

    foreach ([
        'id',
        'author_id',
        'author_level',
        'likes_count',
        'comments_count',
    ] as $field) {
        $post[$field] = (int) $post[$field];
    }

    $post['is_liked'] = !$isGuest && (bool) $post['is_liked'];
    $post['is_following'] =
        !$isGuest && (bool) $post['is_following'];
    $post['is_owner'] =
        !$isGuest && $post['author_id'] === $viewerId;

    respond(200, [
        'success' => true,
        'is_guest' => $isGuest,
        'post' => $post,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Could not load post',
    ]);
}