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

$type = (string)($_GET['type'] ?? 'followers');
if (!in_array($type, ['followers', 'following'], true)) {
    respond(422, ['success' => false, 'message' => 'type must be followers or following']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int)$viewer['id'];

    $targetId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$targetId || $targetId < 1) {
        $targetId = $viewerId;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $relationship = $type === 'followers'
        ? 'f.following_id = :target_id'
        : 'f.follower_id = :target_id';

    $listedUserId = $type === 'followers'
        ? 'f.follower_id'
        : 'f.following_id';

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.avatar_key,
            u.level,
            EXISTS(
                SELECT 1 FROM follows vf
                WHERE vf.follower_id = :viewer_for_follow
                  AND vf.following_id = u.id
            ) AS is_following
         FROM follows f
         INNER JOIN users u ON u.id = ' . $listedUserId . '
         WHERE ' . $relationship . '
           AND u.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (b.blocker_id = :viewer_block AND b.blocked_id = u.id)
                  OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
           )
         ORDER BY f.created_at DESC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $stmt->bindValue(':viewer_for_follow', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':target_id', $targetId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_block', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_blocked', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll();
    $hasMore = count($users) > $limit;

    if ($hasMore) {
        array_pop($users);
    }

    foreach ($users as &$item) {
        $item['id'] = (int)$item['id'];
        $item['level'] = (int)$item['level'];
        $item['is_following'] = (bool)$item['is_following'];
    }
    unset($item);

    respond(200, [
        'success' => true,
        'type' => $type,
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load follow list']);
}