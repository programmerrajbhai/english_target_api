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

$type = $_GET['type'] ?? 'for_you';
$category = $_GET['category'] ?? '';

if (!in_array($type, ['for_you', 'following'], true)) {
    respond(422, [
        'success' => false,
        'message' => 'type must be for_you or following',
    ]);
}

if (!is_string($category)) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid category',
    ]);
}

$category = trim($category);

if (!in_array(
    $category,
    ['', 'speaking', 'ask_doubt', 'notes', 'vocabulary'],
    true
)) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid category',
    ]);
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);

$page = $page === null ? 1 : $page;
$limit = $limit === null ? 10 : $limit;

if ($page === false || $page < 1 || $page > 100000) {
    respond(422, [
        'success' => false,
        'message' => 'page must be between 1 and 100000',
    ]);
}

if ($limit === false || $limit < 1 || $limit > 20) {
    respond(422, [
        'success' => false,
        'message' => 'limit must be between 1 and 20',
    ]);
}

$offset = ($page - 1) * $limit;

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/optional_auth.php';

    $pdo = db();
    $viewer = optionalAuth($pdo);
    $viewerId = $viewer !== null ? (int) $viewer['id'] : 0;
    $isGuest = $viewer === null;

    if ($type === 'following' && $isGuest) {
        respond(401, [
            'success' => false,
            'message' => 'Login required to view your following feed',
            'login_required' => true,
        ]);
    }

    $where = [
        'p.status = "active"',
        'u.status NOT IN ("banned", "deleted")',
        'NOT EXISTS (
            SELECT 1 FROM user_blocks b
            WHERE (
                b.blocker_id = :viewer_block1
                AND b.blocked_id = u.id
            ) OR (
                b.blocker_id = u.id
                AND b.blocked_id = :viewer_block2
            )
        )',
    ];

    $integerParams = [
        ':viewer_block1' => $viewerId,
        ':viewer_block2' => $viewerId,
        ':liked_viewer' => $viewerId,
        ':following_viewer' => $viewerId,
        ':comment_viewer1' => $viewerId,
        ':comment_viewer2' => $viewerId,
        ':fetch_limit' => $limit + 1,
        ':offset' => $offset,
    ];

    if ($type === 'following') {
        $where[] = '(
            p.user_id = :own_user_id
            OR EXISTS (
                SELECT 1 FROM follows f
                WHERE f.follower_id = :following_filter
                  AND f.following_id = p.user_id
            )
        )';

        $integerParams[':own_user_id'] = $viewerId;
        $integerParams[':following_filter'] = $viewerId;
    }

    if ($category !== '') {
        $where[] = 'p.category = :category';
    }

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
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT :fetch_limit OFFSET :offset'
    );

    foreach ($integerParams as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    if ($category !== '') {
        $stmt->bindValue(':category', $category, PDO::PARAM_STR);
    }

    $stmt->execute();

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($posts) > $limit;

    if ($hasMore) {
        array_pop($posts);
    }

    foreach ($posts as &$post) {
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
    }
    unset($post);

    respond(200, [
        'success' => true,
        'is_guest' => $isGuest,
        'type' => $type,
        'category' => $category === '' ? null : $category,
        'posts' => $posts,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Could not load feed',
    ]);
}