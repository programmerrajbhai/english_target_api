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

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $viewer = requireAuth($pdo);

    $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        $userId = (int)$viewer['id'];
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    // একে অপরকে block করলে profile দেখানো হবে না
    $blocked = $pdo->prepare(
        'SELECT 1 FROM user_blocks
         WHERE (blocker_id = :viewer AND blocked_id = :target)
            OR (blocker_id = :target2 AND blocked_id = :viewer2)
         LIMIT 1'
    );
    $blocked->execute([
        'viewer' => (int)$viewer['id'],
        'target' => $userId,
        'target2' => $userId,
        'viewer2' => (int)$viewer['id'],
    ]);

    if ($blocked->fetchColumn()) {
        respond(404, ['success' => false, 'message' => 'Profile not found']);
    }

    $profileStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.bio,
            u.avatar_key,
            u.xp,
            u.level,
            u.created_at,
            (SELECT COUNT(*) FROM posts p
             WHERE p.user_id = u.id AND p.status = "active") AS posts_count,
            (SELECT COUNT(*) FROM follows f
             WHERE f.following_id = u.id) AS followers_count,
            (SELECT COUNT(*) FROM follows f
             WHERE f.follower_id = u.id) AS following_count,
            EXISTS(
              SELECT 1 FROM follows f
              WHERE f.follower_id = :viewer
                AND f.following_id = u.id
            ) AS is_following
         FROM users u
         WHERE u.id = :user_id
           AND u.status NOT IN ("banned", "deleted")
         LIMIT 1'
    );

    $profileStmt->execute([
        'viewer' => (int)$viewer['id'],
        'user_id' => $userId,
    ]);

    $profile = $profileStmt->fetch();

    if (!$profile) {
        respond(404, ['success' => false, 'message' => 'Profile not found']);
    }

    $postsStmt = $pdo->prepare(
        'SELECT
            p.id,
            p.category,
            p.post_type,
            p.body,
            p.media_key,
            p.media_type,
            p.created_at,
            (SELECT COUNT(*) FROM post_likes l
             WHERE l.post_id = p.id) AS likes_count,
            (SELECT COUNT(*) FROM comments c
             WHERE c.post_id = p.id AND c.status = "active") AS comments_count,
            EXISTS(
              SELECT 1 FROM post_likes l
              WHERE l.post_id = p.id AND l.user_id = :viewer
            ) AS is_liked
         FROM posts p
         WHERE p.user_id = :user_id
           AND p.status = "active"
         ORDER BY p.created_at DESC
         LIMIT :limit OFFSET :offset'
    );

    $postsStmt->bindValue(':viewer', (int)$viewer['id'], PDO::PARAM_INT);
    $postsStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $postsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $postsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $postsStmt->execute();

    $profile['id'] = (int)$profile['id'];
    $profile['xp'] = (int)$profile['xp'];
    $profile['level'] = (int)$profile['level'];
    $profile['posts_count'] = (int)$profile['posts_count'];
    $profile['followers_count'] = (int)$profile['followers_count'];
    $profile['following_count'] = (int)$profile['following_count'];
    $profile['is_following'] = (bool)$profile['is_following'];

    respond(200, [
        'success' => true,
        'profile' => $profile,
        'posts' => $postsStmt->fetchAll(),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load profile']);
}