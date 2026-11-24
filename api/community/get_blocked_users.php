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
            u.id,
            u.name,
            u.avatar_key,
            u.level,
            b.created_at AS blocked_at
         FROM user_blocks b
         INNER JOIN users u ON u.id = b.blocked_id
         WHERE b.blocker_id = :user_id
         ORDER BY b.created_at DESC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
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
    }
    unset($item);

    respond(200, [
        'success' => true,
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Could not load blocked users']);
}