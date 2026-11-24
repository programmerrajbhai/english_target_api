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
    $blockerId = (int)$user['id'];

    if ($targetId === $blockerId) {
        respond(422, ['success' => false, 'message' => 'You cannot block yourself']);
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

    $check = $pdo->prepare(
        'SELECT 1 FROM user_blocks
         WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id'
    );
    $check->execute([
        'blocker_id' => $blockerId,
        'blocked_id' => $targetId,
    ]);

    if ($check->fetchColumn()) {
        $stmt = $pdo->prepare(
            'DELETE FROM user_blocks
             WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id'
        );
        $stmt->execute([
            'blocker_id' => $blockerId,
            'blocked_id' => $targetId,
        ]);

        $isBlocked = false;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO user_blocks (blocker_id, blocked_id)
             VALUES (:blocker_id, :blocked_id)'
        );
        $stmt->execute([
            'blocker_id' => $blockerId,
            'blocked_id' => $targetId,
        ]);

        // Block করার পর দুদিকের follow সম্পর্ক সরিয়ে দাও।
        $unfollow = $pdo->prepare(
            'DELETE FROM follows
             WHERE (follower_id = :blocker1 AND following_id = :blocked1)
                OR (follower_id = :blocked2 AND following_id = :blocker2)'
        );
        $unfollow->execute([
            'blocker1' => $blockerId,
            'blocked1' => $targetId,
            'blocked2' => $targetId,
            'blocker2' => $blockerId,
        ]);

        // Block করা ব্যক্তির পুরোনো notifications নিজের তালিকা থেকে সরাও।
        $clearNotifications = $pdo->prepare(
            'DELETE FROM notifications
             WHERE user_id = :viewer AND actor_id = :blocked'
        );
        $clearNotifications->execute([
            'viewer' => $blockerId,
            'blocked' => $targetId,
        ]);

        $isBlocked = true;
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'is_blocked' => $isBlocked,
        'message' => $isBlocked ? 'User blocked' : 'User unblocked',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not update block']);
}