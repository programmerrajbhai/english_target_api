<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // localhost testing only
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

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int)$viewer['id'];

    $type = (string)($_GET['type'] ?? 'for_you');
    $category = trim((string)($_GET['category'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    if (!in_array($type, ['for_you', 'following'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'type must be for_you or following',
        ]);
    }

    $allowedCategories = ['speaking', 'ask_doubt', 'notes'];

    if ($category !== '' && !in_array($category, $allowedCategories, true)) {
        respond(422, ['success' => false, 'message' => 'Invalid category']);
    }

    $where = [
        'p.status = "active"',
        'u.status NOT IN ("banned", "deleted")',
        'NOT EXISTS (
            SELECT 1 FROM user_blocks b
            WHERE (b.blocker_id = :block_viewer AND b.blocked_id = u.id)
               OR (b.blocker_id = u.id AND b.blocked_id = :block_author)
        )',
    ];

    $params = [
        'block_viewer' => $viewerId,
        'block_author' => $viewerId,
        'liked_viewer' => $viewerId,
        'following_viewer' => $viewerId,
    ];

    if ($type === 'following') {
        $where[] = '(p.user_id = :own_id OR EXISTS (
            SELECT 1 FROM follows f
            WHERE f.follower_id = :following_filter
              AND f.following_id = p.user_id
        ))';
        $params['own_id'] = $viewerId;
        $params['following_filter'] = $viewerId;
    }

    if ($category !== '') {
        $where[] = 'p.category = :category';
        $params['category'] = $category;
    }

    $sql = '
        SELECT
            p.id,
            p.category,
            p.post_type,
            p.body,
            p.media_key,
            p.media_type,
            p.created_at,
            u.id AS author_id,
            u.name AS author_name,
            u.avatar_key AS author_avatar_key,
            u.level AS author_level,
            (SELECT COUNT(*) FROM post_likes l
             WHERE l.post_id = p.id) AS likes_count,
            (SELECT COUNT(*) FROM comments c
             WHERE c.post_id = p.id AND c.status = "active") AS comments_count,
            EXISTS(
                SELECT 1 FROM post_likes l
                WHERE l.post_id = p.id AND l.user_id = :liked_viewer
            ) AS is_liked,
            EXISTS(
                SELECT 1 FROM follows f
                WHERE f.follower_id = :following_viewer
                  AND f.following_id = u.id
            ) AS is_following
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT :fetch_limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }

    $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $posts = $stmt->fetchAll();
    $hasMore = count($posts) > $limit;

    if ($hasMore) {
        array_pop($posts);
    }

    foreach ($posts as &$post) {
        $post['id'] = (int)$post['id'];
        $post['author_id'] = (int)$post['author_id'];
        $post['author_level'] = (int)$post['author_level'];
        $post['likes_count'] = (int)$post['likes_count'];
        $post['comments_count'] = (int)$post['comments_count'];
        $post['is_liked'] = (bool)$post['is_liked'];
        $post['is_following'] = (bool)$post['is_following'];
    }
    unset($post);

    respond(200, [
        'success' => true,
        'posts' => $posts,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load feed']);
}