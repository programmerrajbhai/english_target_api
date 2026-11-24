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

$query = $_GET['q'] ?? '';
$type = $_GET['type'] ?? 'all';
$category = $_GET['category'] ?? '';

if (!is_string($query)) {
    respond(422, [
        'success' => false,
        'message' => 'q must be text',
    ]);
}

$query = trim($query);

if (mb_strlen($query) < 2 || mb_strlen($query) > 80) {
    respond(422, [
        'success' => false,
        'message' => 'Search text must be 2–80 characters',
    ]);
}

if (!in_array($type, ['all', 'people', 'posts', 'questions'], true)) {
    respond(422, [
        'success' => false,
        'message' => 'type must be all, people, posts, or questions',
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

$escapedQuery = str_replace(
    ['!', '%', '_'],
    ['!!', '!%', '!_'],
    $query
);

$searchQuery = '%' . $escapedQuery . '%';

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
        $stmt = $pdo->prepare(
            'SELECT
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
                      AND follower.status NOT IN ("banned", "deleted")
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
             WHERE u.name LIKE :search_query ESCAPE "!"
               AND u.id <> :viewer_id
               AND u.status NOT IN ("banned", "deleted")
               AND NOT EXISTS (
                   SELECT 1 FROM user_blocks b
                   WHERE (
                       b.blocker_id = :viewer1
                       AND b.blocked_id = u.id
                   ) OR (
                       b.blocker_id = u.id
                       AND b.blocked_id = :viewer2
                   )
               )
             ORDER BY u.name ASC, u.id ASC
             LIMIT :fetch_limit OFFSET :offset'
        );

        $params = [
            ':following_viewer' => $viewerId,
            ':follower_viewer' => $viewerId,
            ':viewer_id' => $viewerId,
            ':viewer1' => $viewerId,
            ':viewer2' => $viewerId,
            ':fetch_limit' => $limit + 1,
            ':offset' => $offset,
        ];

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->bindValue(':search_query', $searchQuery, PDO::PARAM_STR);
        $stmt->execute();

        $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMorePeople = count($people) > $limit;

        if ($hasMorePeople) {
            array_pop($people);
        }

        foreach ($people as &$person) {
            $person['id'] = (int) $person['id'];
            $person['level'] = (int) $person['level'];
            $person['followers_count'] = (int) $person['followers_count'];
            $person['is_following'] = (bool) $person['is_following'];
            $person['follows_you'] = (bool) $person['follows_you'];
            $person['is_mutual'] =
                $person['is_following'] && $person['follows_you'];
        }
        unset($person);
    }

    if (in_array($type, ['all', 'posts', 'questions'], true)) {
        $categoryCondition = $category === ''
            ? ''
            : ' AND p.category = :category';

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

             WHERE (
                 p.body LIKE :body_query ESCAPE "!"
                 OR p.english_sentence LIKE :english_query ESCAPE "!"
                 OR p.bangla_meaning LIKE :bangla_query ESCAPE "!"
             )
               AND p.status = "active"
               AND u.status NOT IN ("banned", "deleted")
               AND NOT EXISTS (
                   SELECT 1 FROM user_blocks b
                   WHERE (
                       b.blocker_id = :viewer1
                       AND b.blocked_id = u.id
                   ) OR (
                       b.blocker_id = u.id
                       AND b.blocked_id = :viewer2
                   )
               )
               ' . $categoryCondition . '

             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :fetch_limit OFFSET :offset'
        );

        $params = [
            ':liked_viewer' => $viewerId,
            ':following_viewer' => $viewerId,
            ':viewer1' => $viewerId,
            ':viewer2' => $viewerId,
            ':comment_viewer1' => $viewerId,
            ':comment_viewer2' => $viewerId,
            ':fetch_limit' => $limit + 1,
            ':offset' => $offset,
        ];

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        foreach ([':body_query', ':english_query', ':bangla_query'] as $key) {
            $stmt->bindValue($key, $searchQuery, PDO::PARAM_STR);
        }

        if ($category !== '') {
            $stmt->bindValue(':category', $category, PDO::PARAM_STR);
        }

        $stmt->execute();

        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    respond(500, [
        'success' => false,
        'message' => 'Search failed',
    ]);
}