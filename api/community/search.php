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

$query = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
$category = trim((string)($_GET['category'] ?? ''));

if (mb_strlen($query) < 2 || mb_strlen($query) > 80) {
    respond(422, ['success' => false, 'message' => 'Search text must be 2–80 characters']);
}

if (!in_array($type, ['all', 'people', 'posts'], true)) {
    respond(422, ['success' => false, 'message' => 'type must be all, people, or posts']);
}

$allowedCategories = ['speaking', 'ask_doubt', 'notes'];
if ($category !== '' && !in_array($category, $allowedCategories, true)) {
    respond(422, ['success' => false, 'message' => 'Invalid category']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int)$viewer['id'];
    $likeQuery = '%' . $query . '%';
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    $people = [];
    $posts = [];

    if ($type === 'all' || $type === 'people') {
        $peopleStmt = $pdo->prepare(
            'SELECT
                u.id, u.name, u.avatar_key, u.level,
                EXISTS(
                    SELECT 1 FROM follows f
                    WHERE f.follower_id = :viewer_follow
                      AND f.following_id = u.id
                ) AS is_following
             FROM users u
             WHERE u.name LIKE :name_query
               AND u.id <> :viewer_id
               AND u.status NOT IN ("banned", "deleted")
               AND NOT EXISTS (
                   SELECT 1 FROM user_blocks b
                   WHERE (b.blocker_id = :viewer_block AND b.blocked_id = u.id)
                      OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
               )
             ORDER BY u.name ASC
             LIMIT :limit'
        );

        $peopleStmt->bindValue(':viewer_follow', $viewerId, PDO::PARAM_INT);
        $peopleStmt->bindValue(':name_query', $likeQuery, PDO::PARAM_STR);
        $peopleStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
        $peopleStmt->bindValue(':viewer_block', $viewerId, PDO::PARAM_INT);
        $peopleStmt->bindValue(':viewer_blocked', $viewerId, PDO::PARAM_INT);
        $peopleStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $peopleStmt->execute();

        $people = $peopleStmt->fetchAll();
        foreach ($people as &$person) {
            $person['id'] = (int)$person['id'];
            $person['level'] = (int)$person['level'];
            $person['is_following'] = (bool)$person['is_following'];
        }
        unset($person);
    }

    if ($type === 'all' || $type === 'posts') {
        $whereCategory = $category === '' ? '' : 'AND p.category = :category';

        $postsStmt = $pdo->prepare(
            'SELECT
                p.id, p.category, p.post_type, p.body,
                p.media_key, p.media_type, p.created_at,
                u.id AS author_id, u.name AS author_name,
                u.avatar_key AS author_avatar_key, u.level AS author_level,
                (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_count,
                (SELECT COUNT(*) FROM comments c
                 WHERE c.post_id = p.id AND c.status = "active") AS comments_count
             FROM posts p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.body LIKE :body_query
               AND p.status = "active"
               AND u.status NOT IN ("banned", "deleted")
               AND NOT EXISTS (
                   SELECT 1 FROM user_blocks b
                   WHERE (b.blocker_id = :viewer_block AND b.blocked_id = u.id)
                      OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
               )
               ' . $whereCategory . '
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit'
        );

        $postsStmt->bindValue(':body_query', $likeQuery, PDO::PARAM_STR);
        $postsStmt->bindValue(':viewer_block', $viewerId, PDO::PARAM_INT);
        $postsStmt->bindValue(':viewer_blocked', $viewerId, PDO::PARAM_INT);

        if ($category !== '') {
            $postsStmt->bindValue(':category', $category, PDO::PARAM_STR);
        }

        $postsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $postsStmt->execute();

        $posts = $postsStmt->fetchAll();
        foreach ($posts as &$post) {
            $post['id'] = (int)$post['id'];
            $post['author_id'] = (int)$post['author_id'];
            $post['author_level'] = (int)$post['author_level'];
            $post['likes_count'] = (int)$post['likes_count'];
            $post['comments_count'] = (int)$post['comments_count'];
        }
        unset($post);
    }

    respond(200, [
        'success' => true,
        'query' => $query,
        'people' => $people,
        'posts' => $posts,
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Search failed']);
}