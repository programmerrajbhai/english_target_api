<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // শুধু localhost testing-এর জন্য
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST method required']);
}

$input = json_decode(file_get_contents('php://input'), true);
$targetId = is_array($input)
    ? filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT)
    : false;

if (!$targetId || $targetId < 1) {
    respond(422, ['success' => false, 'message' => 'Valid user_id is required']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);
    $followerId = (int)$user['id'];

    if ($targetId === $followerId) {
        respond(422, ['success' => false, 'message' => 'You cannot follow yourself']);
    }

    $pdo->beginTransaction();

    $targetStmt = $pdo->prepare(
        'SELECT id FROM users
         WHERE id = :user_id
           AND status NOT IN ("banned", "deleted")
         FOR UPDATE'
    );
    $targetStmt->execute(['user_id' => $targetId]);

    if (!$targetStmt->fetch()) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'User not found']);
    }

    $blockStmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks
         WHERE (blocker_id = :viewer AND blocked_id = :target)
            OR (blocker_id = :target2 AND blocked_id = :viewer2)
         LIMIT 1'
    );
    $blockStmt->execute([
        'viewer' => $followerId,
        'target' => $targetId,
        'target2' => $targetId,
        'viewer2' => $followerId,
    ]);

    if ($blockStmt->fetchColumn()) {
        $pdo->rollBack();
        respond(403, ['success' => false, 'message' => 'Follow is unavailable']);
    }

    $check = $pdo->prepare(
        'SELECT 1 FROM follows
         WHERE follower_id = :follower_id AND following_id = :following_id'
    );
    $check->execute([
        'follower_id' => $followerId,
        'following_id' => $targetId,
    ]);

    if ($check->fetchColumn()) {
        $change = $pdo->prepare(
            'DELETE FROM follows
             WHERE follower_id = :follower_id AND following_id = :following_id'
        );
        $change->execute([
            'follower_id' => $followerId,
            'following_id' => $targetId,
        ]);
        $isFollowing = false;
    } else {
        $change = $pdo->prepare(
            'INSERT INTO follows (follower_id, following_id)
             VALUES (:follower_id, :following_id)'
        );
        $change->execute([
            'follower_id' => $followerId,
            'following_id' => $targetId,
        ]);
        $isFollowing = true;

        $notify = $pdo->prepare(
            'INSERT INTO notifications (user_id, actor_id, type)
             VALUES (:user_id, :actor_id, "follow")'
        );
        $notify->execute([
            'user_id' => $targetId,
            'actor_id' => $followerId,
        ]);
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM follows WHERE following_id = :user_id'
    );
    $countStmt->execute(['user_id' => $targetId]);
    $followersCount = (int)$countStmt->fetchColumn();

    $pdo->commit();

    respond(200, [
        'success' => true,
        'is_following' => $isFollowing,
        'followers_count' => $followersCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not update follow']);
}