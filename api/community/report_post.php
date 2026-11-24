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

if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Valid JSON body required']);
}

$postId = filter_var($input['post_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string)($input['reason'] ?? ''));
$details = trim((string)($input['details'] ?? ''));

$allowedReasons = ['spam', 'inappropriate', 'harassment', 'other'];

if (!$postId || $postId < 1 || !in_array($reason, $allowedReasons, true)) {
    respond(422, ['success' => false, 'message' => 'Valid post_id and reason are required']);
}

if (mb_strlen($details) > 500) {
    respond(422, ['success' => false, 'message' => 'Details must be 500 characters or less']);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int)$user['id'];

    $pdo->beginTransaction();

    $postStmt = $pdo->prepare(
        'SELECT user_id FROM posts
         WHERE id = :post_id AND status = "active"
         FOR UPDATE'
    );
    $postStmt->execute(['post_id' => $postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'Post not found']);
    }

    if ((int)$post['user_id'] === $userId) {
        $pdo->rollBack();
        respond(422, ['success' => false, 'message' => 'You cannot report your own post']);
    }

    $insert = $pdo->prepare(
        'INSERT INTO post_reports (post_id, reporter_id, reason, details)
         VALUES (:post_id, :reporter_id, :reason, :details)'
    );
    $insert->execute([
        'post_id' => $postId,
        'reporter_id' => $userId,
        'reason' => $reason,
        'details' => $details !== '' ? $details : null,
    ]);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM post_reports WHERE post_id = :post_id'
    );
    $countStmt->execute(['post_id' => $postId]);
    $reportCount = (int)$countStmt->fetchColumn();

    $hidden = false;

    if ($reportCount >= 3) {
        $hideStmt = $pdo->prepare(
            'UPDATE posts SET status = "hidden"
             WHERE id = :post_id AND status = "active"'
        );
        $hideStmt->execute(['post_id' => $postId]);
        $hidden = $hideStmt->rowCount() > 0;
    }

    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => $hidden ? 'Report received; post hidden for review' : 'Report received',
        'report_count' => $reportCount,
        'post_hidden' => $hidden,
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getCode() === '23000') {
        respond(409, ['success' => false, 'message' => 'You already reported this post']);
    }

    respond(500, ['success' => false, 'message' => 'Could not submit report']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['success' => false, 'message' => 'Could not submit report']);
}