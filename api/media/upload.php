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
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
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

$savedPath = null;
$recordSaved = false;
$stage = 'initialization';

try {
    $stage = 'database_connection';

    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();

    $stage = 'authentication';

    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    if ($user['status'] !== 'active') {
        respond(403, [
            'success' => false,
            'message' => 'Uploading is disabled for this account',
        ]);
    }

    if ($_POST === [] && $_FILES === []) {
        respond(400, [
            'success' => false,
            'message' => 'Send form-data with purpose and file. If already selected, check PHP upload limits.',
            'php_limits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],
        ]);
    }

    $purpose = $_POST['purpose'] ?? '';

    if (!is_string($purpose)) {
        respond(422, [
            'success' => false,
            'message' => 'purpose must be text',
        ]);
    }

    $purpose = trim($purpose);

    $imageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $rules = [
        'post_image' => [
            'folder' => 'images',
            'max_bytes' => 10 * 1024 * 1024,
            'types' => $imageTypes,
        ],
        'comment_image' => [
            'folder' => 'comments',
            'max_bytes' => 5 * 1024 * 1024,
            'types' => $imageTypes,
        ],
        'avatar' => [
            'folder' => 'avatars',
            'max_bytes' => 5 * 1024 * 1024,
            'types' => $imageTypes,
        ],
        'post_video' => [
            'folder' => 'videos',
            'max_bytes' => 50 * 1024 * 1024,
            'types' => [
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
            ],
        ],
        'post_voice' => [
            'folder' => 'voices',
            'max_bytes' => 10 * 1024 * 1024,
            'types' => [
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'audio/x-m4a' => 'm4a',
                'audio/aac' => 'aac',
                'audio/ogg' => 'ogg',
                'audio/webm' => 'webm',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/vnd.wave' => 'wav',
            ],
        ],
    ];

    if (!isset($rules[$purpose])) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid purpose',
            'allowed_purposes' => array_keys($rules),
        ]);
    }

    $stage = 'file_validation';

    if (
        !isset($_FILES['file'])
        || !is_array($_FILES['file'])
        || !isset($_FILES['file']['error'])
        || !is_int($_FILES['file']['error'])
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Select one file using the file form-data field',
        ]);
    }

    $file = $_FILES['file'];
    $uploadError = $file['error'];

    if ($uploadError !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE =>
                'File exceeds PHP upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE =>
                'File exceeds form size limit',
            UPLOAD_ERR_PARTIAL =>
                'File uploaded partially. Try again',
            UPLOAD_ERR_NO_FILE =>
                'No file selected',
            UPLOAD_ERR_NO_TMP_DIR =>
                'Server temporary folder is missing',
            UPLOAD_ERR_CANT_WRITE =>
                'Server could not write the file',
            UPLOAD_ERR_EXTENSION =>
                'A PHP extension stopped the upload',
        ];

        $httpStatus = in_array($uploadError, [
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
        ], true) ? 413 : 400;

        respond($httpStatus, [
            'success' => false,
            'message' => $messages[$uploadError] ?? 'Upload failed',
        ]);
    }

    $temporaryPath = $file['tmp_name'] ?? '';

    if (
        !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid uploaded file',
        ]);
    }

    $rule = $rules[$purpose];
    $fileSize = filesize($temporaryPath);

    if ($fileSize === false || $fileSize < 1) {
        respond(422, [
            'success' => false,
            'message' => 'File is empty or unreadable',
        ]);
    }

    if ($fileSize > $rule['max_bytes']) {
        respond(413, [
            'success' => false,
            'message' => 'File is too large',
            'max_bytes' => $rule['max_bytes'],
        ]);
    }

    $stage = 'mime_detection';

    if (!class_exists('finfo')) {
        throw new RuntimeException(
            'PHP Fileinfo extension is not enabled'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedType = $finfo->file($temporaryPath);

    if (
        !is_string($detectedType)
        || !isset($rule['types'][$detectedType])
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Unsupported file type for this purpose',
            'detected_type' => $detectedType ?: null,
            'allowed_types' => array_keys($rule['types']),
        ]);
    }

    if (in_array(
        $purpose,
        ['post_image', 'comment_image', 'avatar'],
        true
    )) {
        $stage = 'image_validation';

        $imageInfo = @getimagesize($temporaryPath);

        if (
            $imageInfo === false
            || ($imageInfo['mime'] ?? '') !== $detectedType
        ) {
            respond(422, [
                'success' => false,
                'message' => 'Invalid image file',
            ]);
        }

        if (
            $imageInfo[0] > 10000
            || $imageInfo[1] > 10000
            || ($imageInfo[0] * $imageInfo[1]) > 40000000
        ) {
            respond(422, [
                'success' => false,
                'message' => 'Image dimensions are too large',
            ]);
        }
    }

    $stage = 'upload_folder_check';

    $projectRoot = dirname(__DIR__, 2);
    $uploadRoot = $projectRoot . '/Upload';

    if (!is_dir($uploadRoot)) {
        throw new RuntimeException(
            'Upload folder does not exist: ' . $uploadRoot
        );
    }

    if (!is_file($uploadRoot . '/.htaccess')) {
        throw new RuntimeException(
            'Upload/.htaccess file is missing'
        );
    }

    $relativeFolder = sprintf(
        'Upload/%s/%d/%s',
        $rule['folder'],
        $userId,
        date('Y/m')
    );

    $absoluteFolder = $projectRoot . '/' . $relativeFolder;

    $stage = 'create_subfolder';

    if (!is_dir($absoluteFolder)) {
        error_clear_last();

        if (
            !@mkdir($absoluteFolder, 0755, true)
            && !is_dir($absoluteFolder)
        ) {
            $lastError = error_get_last();

            throw new RuntimeException(
                'Could not create upload subfolder: '
                . $absoluteFolder
                . '. '
                . ($lastError['message'] ?? 'Check folder permissions')
            );
        }
    }

    if (!is_writable($absoluteFolder)) {
        throw new RuntimeException(
            'Upload subfolder is not writable: ' . $absoluteFolder
        );
    }

    $extension = $rule['types'][$detectedType];
    $filename = bin2hex(random_bytes(24)) . '.' . $extension;

    $relativePath = $relativeFolder . '/' . $filename;
    $savedPath = $projectRoot . '/' . $relativePath;

    $stage = 'save_file';

    error_clear_last();

    if (!@move_uploaded_file($temporaryPath, $savedPath)) {
        $lastError = error_get_last();

        throw new RuntimeException(
            'Could not save uploaded file. '
            . ($lastError['message'] ?? 'Check folder permissions')
        );
    }

    $stage = 'save_database_record';

    $stmt = $pdo->prepare(
        'INSERT INTO media_uploads (
            user_id,
            object_key,
            purpose,
            content_type,
            max_bytes,
            status,
            expires_at,
            completed_at
         ) VALUES (
            :user_id,
            :object_key,
            :purpose,
            :content_type,
            :max_bytes,
            "uploaded",
            DATE_ADD(NOW(), INTERVAL 7 DAY),
            NOW()
         )'
    );

    $stmt->execute([
        'user_id' => $userId,
        'object_key' => $relativePath,
        'purpose' => $purpose,
        'content_type' => $detectedType,
        'max_bytes' => $rule['max_bytes'],
    ]);

    $recordSaved = true;
    $uploadId = (int) $pdo->lastInsertId();

    $scriptName = $_SERVER['SCRIPT_NAME']
        ?? '/english_target_api/api/media/upload.php';

    $basePath = rtrim(
        str_replace('\\', '/', dirname($scriptName, 3)),
        '/.'
    );

    $urlPath = $basePath . '/' . $relativePath;

    respond(201, [
        'success' => true,
        'message' => 'File uploaded successfully',
        'upload' => [
            'id' => $uploadId,
            'purpose' => $purpose,
            'status' => 'uploaded',
            'media_key' => $relativePath,
            'media_type' => $detectedType,
            'size_bytes' => (int) $fileSize,
            'url_path' => $urlPath,
        ],
    ]);
} catch (Throwable $e) {
    if (
        !$recordSaved
        && $savedPath !== null
        && is_file($savedPath)
    ) {
        @unlink($savedPath);
    }

    $response = [
        'success' => false,
        'message' => 'Upload failed',
    ];

    // Detailed diagnostics only for localhost requests.
    if (in_array(
        $_SERVER['REMOTE_ADDR'] ?? '',
        ['127.0.0.1', '::1'],
        true
    )) {
        $response['debug_stage'] = $stage;
        $response['debug_type'] = get_class($e);
        $response['debug_code'] = (string) $e->getCode();
        $response['debug_message'] = $e->getMessage();
    }

    respond(500, $response);
}