<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
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

/**
 * প্রতিটি search word অন্তত একটি নির্ধারিত field-এ মিলতে হবে।
 * Column expressions শুধু নিচের fixed code থেকে আসে।
 */
function searchCondition(
    array $columns,
    array $words,
    string $prefix,
    array &$params
): string {
    $groups = [];

    foreach ($words as $wordIndex => $word) {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $word
        );

        $matches = [];

        foreach ($columns as $columnIndex => $column) {
            $key = ':' . $prefix . '_' . $wordIndex . '_' . $columnIndex;

            $matches[] = "
                CONVERT(COALESCE($column, '') USING utf8mb4)
                COLLATE utf8mb4_unicode_ci
                LIKE $key ESCAPE '!'
            ";

            $params[$key] = '%' . $escaped . '%';
        }

        $groups[] = '(' . implode(' OR ', $matches) . ')';
    }

    return '(' . implode(' AND ', $groups) . ')';
}

/**
 * Banned/deleted এবং দুই দিকের blocked users বাদ।
 */
function visibleUser(
    string $alias,
    string $prefix,
    int $viewerId,
    array &$params
): string {
    $viewer1 = ':' . $prefix . '_viewer1';
    $viewer2 = ':' . $prefix . '_viewer2';

    $params[$viewer1] = $viewerId;
    $params[$viewer2] = $viewerId;

    return "
        $alias.status NOT IN ('banned', 'deleted')
        AND NOT EXISTS (
            SELECT 1
            FROM user_blocks b
            WHERE (
                b.blocker_id = $viewer1
                AND b.blocked_id = $alias.id
            ) OR (
                b.blocker_id = $alias.id
                AND b.blocked_id = $viewer2
            )
        )
    ";
}

function fetchRows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue(
            $key,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

$query = $_GET['q'] ?? '';
$type = $_GET['type'] ?? 'all';
$category = $_GET['category'] ?? '';

if (
    !is_string($query) ||
    !is_string($type) ||
    !is_string($category)
) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid search parameters',
    ]);
}

$normalized = preg_replace('/\s+/u', ' ', $query);

if ($normalized === null) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid search text encoding',
    ]);
}

$query = trim($normalized);
$category = trim($category);

if (mb_strlen($query, 'UTF-8') < 2 ||
    mb_strlen($query, 'UTF-8') > 80) {
    respond(422, [
        'success' => false,
        'message' => 'Search text must be 2–80 characters',
    ]);
}

if (!in_array($type, ['all', 'people', 'posts', 'questions'], true)) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid search type',
    ]);
}

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

if ($type === 'questions') {
    $category = 'ask_doubt';
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
$words = array_values(array_unique(explode(' ', $query)));

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int) $viewer['id'];

    $people = [];
    $posts = [];
    $hasMorePeople = false;
    $hasMorePosts = false;

    if ($type === 'all' || $type === 'people') {
        $params = [
            ':following_viewer' => $viewerId,
            ':follower_viewer' => $viewerId,
            ':fetch_limit' => $limit + 1,
            ':offset' => $offset,
        ];

        $match = searchCondition(
            ['u.name', 'u.bio'],
            $words,
            'people_search',
            $params
        );

        $visible = visibleUser(
            'u',
            'people_visibility',
            $viewerId,
            $params
        );

        $people = fetchRows(
            $pdo,
            "
            SELECT
                u.id,
                u.name,
                u.bio,
                u.avatar_key,
                u.level,

                (
                    SELECT COUNT(*)
                    FROM follows f
                    INNER JOIN users follower
                        ON follower.id = f.follower_id
                    WHERE f.following_id = u.id
                      AND follower.status NOT IN ('banned', 'deleted')
                ) AS followers_count,

                EXISTS (
                    SELECT 1 FROM follows f
                    WHERE f.follower_id = :following_viewer
                      AND f.following_id = u.id
                ) AS is_following,

                EXISTS (
                    SELECT 1 FROM follows f
                    WHERE f.follower_id = u.id
                      AND f.following_id = :follower_viewer
                ) AS follows_you

            FROM users u

            WHERE $match
              AND $visible

            ORDER BY u.name ASC, u.id ASC
            LIMIT :fetch_limit OFFSET :offset
            ",
            $params
        );

        $hasMorePeople = count($people) > $limit;

        if ($hasMorePeople) {
            array_pop($people);
        }

        foreach ($people as &$person) {
            foreach (['id', 'level', 'followers_count'] as $field) {
                $person[$field] = (int) $person[$field];
            }

            $person['is_self'] = $person['id'] === $viewerId;

            $person['is_following'] =
                !$person['is_self'] && (bool) $person['is_following'];

            $person['follows_you'] =
                !$person['is_self'] && (bool) $person['follows_you'];

            $person['is_mutual'] =
                $person['is_following'] && $person['follows_you'];
        }
        unset($person);
    }

    if (in_array($type, ['all', 'posts', 'questions'], true)) {
        $params = [
            ':liked_viewer' => $viewerId,
            ':following_viewer' => $viewerId,
            ':fetch_limit' => $limit + 1,
            ':offset' => $offset,
        ];

        $match = searchCondition(
            [
                'p.body',
                'p.english_sentence',
                'p.bangla_meaning',
                'u.name',
                "REPLACE(p.category, '_', ' ')",
            ],
            $words,
            'post_search',
            $params
        );

        $visible = visibleUser(
            'u',
            'post_visibility',
            $viewerId,
            $params
        );

        $commentVisible = visibleUser(
            'cu',
            'comment_visibility',
            $viewerId,
            $params
        );

        $categoryCondition = '';

        if ($category !== '') {
            $categoryCondition = ' AND p.category = :category';
            $params[':category'] = $category;
        }

        $posts = fetchRows(
            $pdo,
            "
            SELECT
                p.id,
                p.category,
                p.post_type,
                p.body,
                p.english_sentence,
                p.bangla_meaning,
                p.media_key,
                p.media_type,
                p.created_at,

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
                      AND c.status = 'active'
                      AND $commentVisible
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

            WHERE p.status = 'active'
              AND $visible
              AND $match
              $categoryCondition

            ORDER BY p.created_at DESC, p.id DESC
            LIMIT :fetch_limit OFFSET :offset
            ",
            $params
        );

        $hasMorePosts = count($posts) > $limit;

        if ($hasMorePosts) {
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

            $post['is_liked'] = (bool) $post['is_liked'];
            $post['is_following'] = (bool) $post['is_following'];
            $post['is_owner'] = $post['author_id'] === $viewerId;
        }
        unset($post);
    }

    respond(200, [
        'success' => true,
        'query' => $query,
        'type' => $type,
        'category' => $category === '' ? null : $category,
        'people' => $people,
        'posts' => $posts,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more_people' => $hasMorePeople,
            'has_more_posts' => $hasMorePosts,
        ],
    ]);
} catch (Throwable $e) {
    // Details শুধু server log-এ থাকবে।
    error_log('Community search failed: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Search failed. Check server error log.',
    ]);
}