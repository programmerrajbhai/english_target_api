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

$type = $_GET['type'] ?? 'followers';

if (!in_array($type, ['followers', 'following'], true)) {
    respond(422, [
        'success' => false,
        'message' => 'type must be followers or following',
    ]);
}

$query = $_GET['q'] ?? '';

if (!is_string($query) || mb_strlen($query) > 80) {
    respond(422, [
        'success' => false,
        'message' => 'q must be text up to 80 characters',
    ]);
}

$query = trim($query);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
$targetId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

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

if ($targetId !== null && ($targetId === false || $targetId < 1)) {
    respond(422, [
        'success' => false,
        'message' => 'Valid user_id is required',
    ]);
}

$offset = ($page - 1) * $limit;

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $viewer = requireAuth($pdo);
    $viewerId = (int) $viewer['id'];
    $targetId = $targetId ?? $viewerId;

    // যাঁর connections দেখা হচ্ছে, তাঁর profile visible হতে হবে।
    $targetStmt = $pdo->prepare(
        'SELECT u.id
         FROM users u
         WHERE u.id = :target_id
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
         LIMIT 1'
    );

    $targetStmt->execute([
        'target_id' => $targetId,
        'viewer1' => $viewerId,
        'viewer2' => $viewerId,
    ]);

    if (!$targetStmt->fetchColumn()) {
        respond(404, [
            'success' => false,
            'message' => 'Profile not found',
        ]);
    }

    // এই SQL অংশগুলো fixed value থেকে নেওয়া হচ্ছে।
    $relationship = $type === 'followers'
        ? 'f.following_id = :target_id'
        : 'f.follower_id = :target_id';

    $listedUserId = $type === 'followers'
        ? 'f.follower_id'
        : 'f.following_id';

    $searchCondition = $query === ''
        ? ''
        : ' AND u.name LIKE :search_query ESCAPE "!"';

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.avatar_key,
            u.level,

            EXISTS (
                SELECT 1 FROM follows vf
                WHERE vf.follower_id = :viewer_following
                  AND vf.following_id = u.id
            ) AS is_following,

            EXISTS (
                SELECT 1 FROM follows rf
                WHERE rf.follower_id = u.id
                  AND rf.following_id = :viewer_follower
            ) AS follows_you

         FROM follows f
         INNER JOIN users u ON u.id = ' . $listedUserId . '

         WHERE ' . $relationship . '
           AND u.status NOT IN ("banned", "deleted")
           AND NOT EXISTS (
               SELECT 1 FROM user_blocks b
               WHERE (
                   b.blocker_id = :viewer_block1
                   AND b.blocked_id = u.id
               ) OR (
                   b.blocker_id = u.id
                   AND b.blocked_id = :viewer_block2
               )
           )
           ' . $searchCondition . '

         ORDER BY f.created_at DESC, u.id DESC
         LIMIT :fetch_limit OFFSET :offset'
    );

    $params = [
        ':target_id' => $targetId,
        ':viewer_following' => $viewerId,
        ':viewer_follower' => $viewerId,
        ':viewer_block1' => $viewerId,
        ':viewer_block2' => $viewerId,
        ':fetch_limit' => $limit + 1,
        ':offset' => $offset,
    ];

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    if ($query !== '') {
        // Search-এর % এবং _ সাধারণ character হিসেবে গণ্য হবে।
        $escapedQuery = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $query
        );

        $stmt->bindValue(
            ':search_query',
            '%' . $escapedQuery . '%',
            PDO::PARAM_STR
        );
    }

    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($users) > $limit;

    if ($hasMore) {
        array_pop($users);
    }

    foreach ($users as &$item) {
        $item['id'] = (int) $item['id'];
        $item['level'] = (int) $item['level'];
        $item['is_following'] = (bool) $item['is_following'];
        $item['follows_you'] = (bool) $item['follows_you'];

        $item['is_mutual'] =
            $item['is_following'] && $item['follows_you'];

        $item['is_self'] = $item['id'] === $viewerId;
    }
    unset($item);

    respond(200, [
        'success' => true,
        'user_id' => $targetId,
        'type' => $type,
        'query' => $query,
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Could not load connections',
    ]);
}