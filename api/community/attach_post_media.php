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

$postId = filter_var(
    $input['post_id'] ?? null,
    FILTER_VALIDATE_INT
);

$uploadId = filter_var(
    $input['upload_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$postId || $postId < 1 || !$uploadId || $uploadId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid post_id and upload_id are required',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    if ($user['status'] !== 'active') {
        respond(403, [
            'success' => false,
            'message' => 'Posting is disabled for this account',
        ]);
    }

    $pdo->beginTransaction();

    // শুধু নিজের active post-এ media যুক্ত করা যাবে।
    $postStmt = $pdo->prepare(
        'SELECT id, media_key
         FROM posts
         WHERE id = :post_id
           AND user_id = :user_id
           AND status = "active"
         FOR UPDATE'
    );

    $postStmt->execute([
        'post_id' => (int) $postId,
        'user_id' => $userId,
    ]);

    $post = $postStmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Your active post was not found',
        ]);
    }

    // অন্য user-এর upload ব্যবহার করা যাবে না।
    $mediaStmt = $pdo->prepare(
        'SELECT
            id,
            object_key,
            purpose,
            content_type,
            status,
            attached_post_id,
            attached_comment_id,
            (expires_at > NOW()) AS is_valid
         FROM media_uploads
         WHERE id = :upload_id
           AND user_id = :user_id
         FOR UPDATE'
    );

    $mediaStmt->execute([
        'upload_id' => (int) $uploadId,
        'user_id' => $userId,
    ]);

    $media = $mediaStmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Your upload was not found',
        ]);
    }

    $postTypes = [
        'post_image' => 'image',
        'post_video' => 'video',
        'post_voice' => 'voice',
    ];

    if (!isset($postTypes[$media['purpose']])) {
        $pdo->rollBack();

        respond(422, [
            'success' => false,
            'message' => 'This upload is not intended for a post',
        ]);
    }

    // একই request আবার পাঠালে duplicate attachment হবে না।
    $alreadyAttached =
        $media['status'] === 'attached'
        && (int) $media['attached_post_id'] === (int) $postId
        && $media['attached_comment_id'] === null
        && $post['media_key'] === $media['object_key'];

    if (!$alreadyAttached) {
        if (
            $media['status'] !== 'uploaded'
            || $media['attached_post_id'] !== null
            || $media['attached_comment_id'] !== null
        ) {
            $pdo->rollBack();

            respond(409, [
                'success' => false,
                'message' => 'Upload is unavailable or already attached',
            ]);
        }

        if (!(bool) $media['is_valid']) {
            $pdo->rollBack();

            respond(410, [
                'success' => false,
                'message' => 'Upload expired. Upload the file again',
            ]);
        }

        if ($post['media_key'] !== null && $post['media_key'] !== '') {
            $pdo->rollBack();

            respond(409, [
                'success' => false,
                'message' => 'This post already has media',
            ]);
        }
    }

    // শুধুমাত্র local Upload folder-এর existing file।
    $projectRoot = dirname(__DIR__, 2);
    $objectKey = (string) $media['object_key'];
    $uploadRoot = realpath($projectRoot . '/Upload');

    $filePath = str_starts_with($objectKey, 'Upload/')
        ? realpath($projectRoot . '/' . $objectKey)
        : false;

    if (
        $uploadRoot === false
        || $filePath === false
        || !str_starts_with(
            $filePath,
            $uploadRoot . DIRECTORY_SEPARATOR
        )
        || !is_file($filePath)
        || !is_readable($filePath)
    ) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Local uploaded file was not found or is unreadable',
        ]);
    }

    $postType = $postTypes[$media['purpose']];

    if (!$alreadyAttached) {
        $updatePost = $pdo->prepare(
            'UPDATE posts
             SET post_type = :post_type,
                 media_key = :media_key,
                 media_type = :media_type
             WHERE id = :post_id
               AND user_id = :user_id'
        );

        $updatePost->execute([
            'post_type' => $postType,
            'media_key' => $objectKey,
            'media_type' => $media['content_type'],
            'post_id' => (int) $postId,
            'user_id' => $userId,
        ]);

        $updateMedia = $pdo->prepare(
            'UPDATE media_uploads
             SET status = "attached",
                 attached_post_id = :post_id
             WHERE id = :upload_id
               AND user_id = :user_id'
        );

        $updateMedia->execute([
            'post_id' => (int) $postId,
            'upload_id' => (int) $uploadId,
            'user_id' => $userId,
        ]);
    }

    $pdo->commit();

    $scriptName = $_SERVER['SCRIPT_NAME']
        ?? '/english_target_api/api/community/attach_post_media.php';

    $basePath = rtrim(
        str_replace('\\', '/', dirname($scriptName, 3)),
        '/.'
    );

    respond(200, [
        'success' => true,
        'message' => $alreadyAttached
            ? 'Media already attached to this post'
            : 'Media attached successfully',
        'post' => [
            'id' => (int) $postId,
            'post_type' => $postType,
            'media_key' => $objectKey,
            'media_type' => $media['content_type'],
            'url_path' => $basePath . '/' . $objectKey,
        ],
        'upload_id' => (int) $uploadId,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not attach post media',
    ]);
}