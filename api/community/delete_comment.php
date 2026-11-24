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
$commentId = is_array($input)
    ? filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT)
    : false;

if (!$commentId || $commentId < 1) {
    respond(422, ['success' => false, 'message' => 'Valid comment_id is required']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT id FROM comments
         WHERE id = :comment_id
           AND user_id = :user_id
           AND status = "active"
         FOR UPDATE'
    );
    $check->execute([
        'comment_id' => $commentId,
        'user_id' => (int)$user['id'],
    ]);

    if (!$check->fetch()) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Comment not found or you cannot delete it',
        ]);
    }

    $replies = $pdo->prepare(
        'UPDATE comments
         SET status = "removed"
         WHERE parent_id = :parent_id
           AND status = "active"'
    );
    $replies->execute(['parent_id' => $commentId]);

    $delete = $pdo->prepare(
        'UPDATE comments SET status = "removed" WHERE id = :comment_id'
    );
    $delete->execute(['comment_id' => $commentId]);

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'Comment deleted',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not delete comment']);
}