<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Local testing
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

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

$purpose = $input['purpose'] ?? '';
$contentType = $input['content_type'] ?? '';

if (!is_string($purpose) || !is_string($contentType)) {
    respond(422, [
        'success' => false,
        'message' => 'purpose and content_type must be text',
    ]);
}

$purpose = trim($purpose);
$contentType = strtolower(trim($contentType));

$imageTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

// প্রাথমিক upload limits।
$rules = [
    'post_image' => [
        'max_bytes' => 10 * 1024 * 1024,
        'types' => $imageTypes,
    ],
    'comment_image' => [
        'max_bytes' => 5 * 1024 * 1024,
        'types' => $imageTypes,
    ],
    'avatar' => [
        'max_bytes' => 5 * 1024 * 1024,
        'types' => $imageTypes,
    ],
    'post_video' => [
        'max_bytes' => 50 * 1024 * 1024,
        'types' => [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
    ],
    'post_voice' => [
        'max_bytes' => 10 * 1024 * 1024,
        'types' => [
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'audio/wav' => 'wav',
        ],
    ],
];

if (!isset($rules[$purpose])) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid upload purpose',
    ]);
}

$rule = $rules[$purpose];

if (!isset($rule['types'][$contentType])) {
    respond(422, [
        'success' => false,
        'message' => 'Unsupported content type for this purpose',
        'allowed_types' => array_keys($rule['types']),
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $config = require __DIR__ . '/../../config/r2.php';
    $s3 = require __DIR__ . '/../../config/r2_client.php';

    if (empty($config['bucket'])) {
        throw new RuntimeException('R2 bucket is not configured');
    }

    $extension = $rule['types'][$contentType];
    $maxBytes = $rule['max_bytes'];

    // Pending upload আলাদা prefix-এ থাকবে।
    $objectKey = sprintf(
        'pending/%d/%s/%s.%s',
        $userId,
        $purpose,
        bin2hex(random_bytes(24)),
        $extension
    );

    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        'SELECT status
         FROM users
         WHERE id = :user_id
         FOR UPDATE'
    );

    $lock->execute([
        'user_id' => $userId,
    ]);

    if ($lock->fetchColumn() !== 'active') {
        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'Uploading is disabled for this account',
        ]);
    }

    // একসঙ্গে সর্বোচ্চ ১০টি unexpired pending upload।
    $pending = $pdo->prepare(
        'SELECT COUNT(*)
         FROM media_uploads
         WHERE user_id = :user_id
           AND status = "pending"
           AND expires_at > NOW()'
    );

    $pending->execute([
        'user_id' => $userId,
    ]);

    if ((int) $pending->fetchColumn() >= 10) {
        $pdo->rollBack();

        respond(429, [
            'success' => false,
            'message' => 'Too many pending uploads. Complete an upload or try later.',
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO media_uploads (
            user_id,
            object_key,
            purpose,
            content_type,
            max_bytes,
            status,
            expires_at
         ) VALUES (
            :user_id,
            :object_key,
            :purpose,
            :content_type,
            :max_bytes,
            "pending",
            DATE_ADD(NOW(), INTERVAL 10 MINUTE)
         )'
    );

    $insert->execute([
        'user_id' => $userId,
        'object_key' => $objectKey,
        'purpose' => $purpose,
        'content_type' => $contentType,
        'max_bytes' => $maxBytes,
    ]);

    $uploadId = (int) $pdo->lastInsertId();

    $command = $s3->getCommand('PutObject', [
        'Bucket' => $config['bucket'],
        'Key' => $objectKey,
        'ContentType' => $contentType,
    ]);

    $request = $s3->createPresignedRequest(
        $command,
        '+10 minutes'
    );

    // SDK-generated required headers, Host ছাড়া।
    $uploadHeaders = [];

    foreach ($request->getHeaders() as $name => $values) {
        if (strtolower($name) !== 'host') {
            $uploadHeaders[$name] = implode(', ', $values);
        }
    }

    $uploadHeaders['Content-Type'] = $contentType;

    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => 'Upload URL created',
        'upload' => [
            'id' => $uploadId,
            'purpose' => $purpose,
            'status' => 'pending',
            'method' => 'PUT',
            'upload_url' => (string) $request->getUri(),
            'headers' => $uploadHeaders,
            'max_bytes' => $maxBytes,
            'expires_in_seconds' => 600,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not create upload URL. Check server database and R2 configuration.',
    ]);
}