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
    $user = requireAuth($pdo);
    $userId = (int)$user['id'];

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        'SELECT
            n.id,
            n.type,
            n.post_id,
            n.comment_id,
            n.is_read,
            n.created_at,
            actor.id AS actor_id,
            actor.name AS actor_name,
            actor.avatar_key AS actor_avatar_key,
            p.body AS post_body,
            c.body AS comment_body
         FROM notifications n
         LEFT JOIN users actor ON actor.id = n.actor_id
         LEFT JOIN posts p ON p.id = n.post_id
         LEFT JOIN comments c ON c.id = n.comment_id
         WHERE n.user_id = :user_id
           AND (
             n.actor_id IS NULL
             OR NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (b.blocker_id = :viewer_block AND b.blocked_id = n.actor_id)
                  OR (b.blocker_id = n.actor_id AND b.blocked_id = :viewer_blocked)
             )
           )
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_block', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_blocked', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = $stmt->fetchAll();
    $hasMore = count($notifications) > $limit;

    if ($hasMore) {
        array_pop($notifications);
    }

    foreach ($notifications as &$item) {
        $item['id'] = (int)$item['id'];
        $item['post_id'] = $item['post_id'] === null ? null : (int)$item['post_id'];
        $item['comment_id'] = $item['comment_id'] === null ? null : (int)$item['comment_id'];
        $item['actor_id'] = $item['actor_id'] === null ? null : (int)$item['actor_id'];
        $item['is_read'] = (bool)$item['is_read'];
    }
    unset($item);

    respond(200, [
        'success' => true,
        'notifications' => $notifications,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load notifications']);
}