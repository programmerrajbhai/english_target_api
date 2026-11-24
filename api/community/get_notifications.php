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

$type = $_GET['type'] ?? 'all';

$filters = [
    'all' => '',
    'replies' => ' AND n.type = "comment"',
    'follows' => ' AND n.type = "follow"',
    'likes' => ' AND n.type IN ("like", "comment_like")',
    'mentions' => ' AND n.type = "mention"',
    'system' => ' AND n.type = "system"',
];

if (!is_string($type) || !array_key_exists($type, $filters)) {
    respond(422, [
        'success' => false,
        'message' => 'type must be all, replies, follows, likes, mentions, or system',
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
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $joins = '
        FROM notifications n
        LEFT JOIN users actor ON actor.id = n.actor_id
        LEFT JOIN posts p ON p.id = n.post_id
        LEFT JOIN users pa ON pa.id = p.user_id
        LEFT JOIN comments c ON c.id = n.comment_id
        LEFT JOIN users ca ON ca.id = c.user_id
    ';

    // Removed content ও unavailable/blocked users-এর notification বাদ।
    $where = '
        WHERE n.user_id = :user_id

          AND n.type IN (
              "like", "comment", "follow",
              "mention", "system", "comment_like"
          )

          AND (
              n.actor_id IS NULL
              OR (
                  actor.id IS NOT NULL
                  AND actor.status NOT IN ("banned", "deleted")
              )
          )

          AND (
              n.post_id IS NULL
              OR (
                  p.id IS NOT NULL
                  AND p.status = "active"
                  AND pa.status NOT IN ("banned", "deleted")
              )
          )

          AND (
              n.comment_id IS NULL
              OR (
                  c.id IS NOT NULL
                  AND c.status = "active"
                  AND ca.status NOT IN ("banned", "deleted")
              )
          )

          AND NOT EXISTS (
              SELECT 1
              FROM user_blocks b
              WHERE (
                  b.blocker_id = :viewer1
                  AND b.blocked_id IN (
                      n.actor_id, p.user_id, c.user_id
                  )
              ) OR (
                  b.blocked_id = :viewer2
                  AND b.blocker_id IN (
                      n.actor_id, p.user_id, c.user_id
                  )
              )
          )
    ';

    $params = [
        'user_id' => $userId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ];

    // সব visible notification-এর unread count।
    // Selected tab-এর filter এখানে প্রয়োগ হবে না।
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) '
        . $joins
        . $where
        . ' AND n.is_read = 0'
    );

    $countStmt->execute($params);
    $unreadCount = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT
            n.id,
            n.type,
            n.title,
            n.message,
            n.post_id,
            n.comment_id,
            n.is_read,
            n.created_at,

            actor.id AS actor_id,
            actor.name AS actor_name,
            actor.avatar_key AS actor_avatar_key,

            p.body AS post_body,
            c.body AS comment_body
        '
        . $joins
        . $where
        . $filters[$type]
        . '
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT :fetch_limit OFFSET :offset
        '
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }

    $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($notifications) > $limit;

    if ($hasMore) {
        array_pop($notifications);
    }

    foreach ($notifications as &$item) {
        $item['id'] = (int) $item['id'];
        $item['is_read'] = (bool) $item['is_read'];

        foreach (['post_id', 'comment_id', 'actor_id'] as $field) {
            $item[$field] = $item[$field] === null
                ? null
                : (int) $item[$field];
        }
    }
    unset($item);

    respond(200, [
        'success' => true,
        'type' => $type,
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Could not load notifications',
    ]);
}