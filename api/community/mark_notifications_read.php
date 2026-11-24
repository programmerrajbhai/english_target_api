<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Local testing
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');

    respond(405, [
        'success' => false,
        'message' => 'POST method required',
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Valid JSON body required',
    ]);
}

$markAll = $input['all'] ?? false;

if (!is_bool($markAll)) {
    respond(422, [
        'success' => false,
        'message' => 'all must be true or false',
    ]);
}

$notificationId = null;

if ($markAll) {
    if (array_key_exists('notification_id', $input)) {
        respond(422, [
            'success' => false,
            'message' => 'Send either all:true or notification_id',
        ]);
    }
} else {
    $notificationId = filter_var(
        $input['notification_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$notificationId || $notificationId < 1) {
        respond(422, [
            'success' => false,
            'message' => 'Valid notification_id is required',
        ]);
    }
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $pdo->beginTransaction();

    if ($markAll) {
        // শুধু নিজের notifications read হবে।
        $update = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id
               AND is_read = 0'
        );

        $update->execute([
            'user_id' => $userId,
        ]);
    } else {
        // Notification নিজের কি না যাচাই।
        $check = $pdo->prepare(
            'SELECT id
             FROM notifications
             WHERE id = :notification_id
               AND user_id = :user_id
             FOR UPDATE'
        );

        $check->execute([
            'notification_id' => (int) $notificationId,
            'user_id' => $userId,
        ]);

        if (!$check->fetchColumn()) {
            $pdo->rollBack();

            respond(404, [
                'success' => false,
                'message' => 'Notification not found',
            ]);
        }

        $update = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE id = :notification_id
               AND user_id = :user_id
               AND is_read = 0'
        );

        $update->execute([
            'notification_id' => (int) $notificationId,
            'user_id' => $userId,
        ]);
    }

    $updatedCount = $update->rowCount();

    // Existing notification list-এর মতো blocked actor বাদ।
    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications n
         WHERE n.user_id = :user_id
           AND n.is_read = 0
           AND (
               n.actor_id IS NULL
               OR NOT EXISTS (
                   SELECT 1
                   FROM user_blocks b
                   WHERE (
                       b.blocker_id = :viewer1
                       AND b.blocked_id = n.actor_id
                   ) OR (
                       b.blocker_id = n.actor_id
                       AND b.blocked_id = :viewer2
                   )
               )
           )'
    );

    $count->execute([
        'user_id' => $userId,
        'viewer1' => $userId,
        'viewer2' => $userId,
    ]);

    $unreadCount = (int) $count->fetchColumn();

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => $markAll
            ? 'All notifications marked as read'
            : 'Notification marked as read',
        'updated_count' => $updatedCount,
        'unread_count' => $unreadCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not update notifications',
    ]);
}