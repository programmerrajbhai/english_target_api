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

$uploadId = filter_var(
    $input['upload_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$uploadId || $uploadId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'Valid upload_id is required',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $pdo->beginTransaction();

    $userStmt = $pdo->prepare(
        'SELECT avatar_key, status
         FROM users
         WHERE id = :user_id
         FOR UPDATE'
    );

    $userStmt->execute([
        'user_id' => $userId,
    ]);

    $profile = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile || $profile['status'] !== 'active') {
        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'Profile changes are disabled for this account',
        ]);
    }

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

    if (
        $media['purpose'] !== 'avatar'
        || !in_array(
            $media['content_type'],
            ['image/jpeg', 'image/png', 'image/webp'],
            true
        )
    ) {
        $pdo->rollBack();

        respond(422, [
            'success' => false,
            'message' => 'Upload an image with purpose avatar first',
        ]);
    }

    if (
        $media['attached_post_id'] !== null
        || $media['attached_comment_id'] !== null
    ) {
        $pdo->rollBack();

        respond(409, [
            'success' => false,
            'message' => 'This upload is attached to other content',
        ]);
    }

    $alreadyAttached =
        $media['status'] === 'attached'
        && $profile['avatar_key'] === $media['object_key'];

    if (!$alreadyAttached) {
        if ($media['status'] !== 'uploaded') {
            $pdo->rollBack();

            respond(409, [
                'success' => false,
                'message' => 'Upload is unavailable or already used',
            ]);
        }

        if (!(bool) $media['is_valid']) {
            $pdo->rollBack();

            respond(410, [
                'success' => false,
                'message' => 'Upload expired. Upload the image again',
            ]);
        }
    }

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
            'message' => 'Uploaded avatar file was not found or is unreadable',
        ]);
    }

    if (!$alreadyAttached) {
        $updateUser = $pdo->prepare(
            'UPDATE users
             SET avatar_key = :avatar_key
             WHERE id = :user_id'
        );

        $updateUser->execute([
            'avatar_key' => $objectKey,
            'user_id' => $userId,
        ]);

        $updateMedia = $pdo->prepare(
            'UPDATE media_uploads
             SET status = "attached"
             WHERE id = :upload_id
               AND user_id = :user_id'
        );

        $updateMedia->execute([
            'upload_id' => (int) $uploadId,
            'user_id' => $userId,
        ]);
    }

    $pdo->commit();

    $scriptName = $_SERVER['SCRIPT_NAME']
        ?? '/english_target_api/api/users/set_avatar.php';

    $basePath = rtrim(
        str_replace('\\', '/', dirname($scriptName, 3)),
        '/.'
    );

    respond(200, [
        'success' => true,
        'message' => $alreadyAttached
            ? 'This avatar is already set'
            : 'Profile photo updated',
        'profile' => [
            'id' => $userId,
            'avatar_key' => $objectKey,
            'avatar_url_path' => $basePath . '/' . $objectKey,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not update profile photo',
    ]);
}