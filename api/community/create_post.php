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

$category = $input['category'] ?? '';
$body = $input['body'] ?? '';
$englishSentence = $input['english_sentence'] ?? null;
$banglaMeaning = $input['bangla_meaning'] ?? null;

if (!is_string($category) || !is_string($body)) {
    respond(422, [
        'success' => false,
        'message' => 'category and body must be text',
    ]);
}

$category = trim($category);
$body = trim($body);

if (!in_array(
    $category,
    ['speaking', 'ask_doubt', 'notes', 'vocabulary'],
    true
)) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid category',
    ]);
}

if ($body === '' || mb_strlen($body) > 2000) {
    respond(422, [
        'success' => false,
        'message' => 'Post text must be 1–2000 characters',
    ]);
}

if (
    $englishSentence !== null
    && !is_string($englishSentence)
) {
    respond(422, [
        'success' => false,
        'message' => 'english_sentence must be text or null',
    ]);
}

if (
    $banglaMeaning !== null
    && !is_string($banglaMeaning)
) {
    respond(422, [
        'success' => false,
        'message' => 'bangla_meaning must be text or null',
    ]);
}

$englishSentence = $englishSentence === null
    ? null
    : trim($englishSentence);

$banglaMeaning = $banglaMeaning === null
    ? null
    : trim($banglaMeaning);

if ($englishSentence === '') {
    $englishSentence = null;
}

if ($banglaMeaning === '') {
    $banglaMeaning = null;
}

if (
    $englishSentence !== null
    && mb_strlen($englishSentence) > 500
) {
    respond(422, [
        'success' => false,
        'message' => 'English sentence must be 500 characters or less',
    ]);
}

if (
    $banglaMeaning !== null
    && mb_strlen($banglaMeaning) > 1000
) {
    respond(422, [
        'success' => false,
        'message' => 'Bangla meaning must be 1000 characters or less',
    ]);
}

// এই ধাপে text post। R2 integration-এর পরে media যুক্ত হবে।
if (
    ($input['post_type'] ?? 'text') !== 'text'
    || isset($input['media_key'])
    || isset($input['media_upload_id'])
) {
    respond(422, [
        'success' => false,
        'message' => 'Media posts are not enabled yet',
    ]);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = db();
    $user = requireAuth($pdo);
    $userId = (int) $user['id'];

    $pdo->beginTransaction();

    // একই user-এর simultaneous post requests serialize করে।
    $lock = $pdo->prepare(
        'SELECT status
         FROM users
         WHERE id = :user_id
         FOR UPDATE'
    );

    $lock->execute([
        'user_id' => $userId,
    ]);

    $status = $lock->fetchColumn();

    if ($status === false || $status !== 'active') {
        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'Posting is disabled for this account',
        ]);
    }

    // আগের limit: গত ২৪ ঘণ্টায় সর্বোচ্চ ৫টি post।
    $limitStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM posts
         WHERE user_id = :user_id
           AND created_at >= NOW() - INTERVAL 1 DAY'
    );

    $limitStmt->execute([
        'user_id' => $userId,
    ]);

    if ((int) $limitStmt->fetchColumn() >= 5) {
        $pdo->rollBack();

        respond(429, [
            'success' => false,
            'message' => 'Daily post limit reached',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO posts (
            user_id,
            category,
            post_type,
            body,
            english_sentence,
            bangla_meaning
         ) VALUES (
            :user_id,
            :category,
            "text",
            :body,
            :english_sentence,
            :bangla_meaning
         )'
    );

    $stmt->execute([
        'user_id' => $userId,
        'category' => $category,
        'body' => $body,
        'english_sentence' => $englishSentence,
        'bangla_meaning' => $banglaMeaning,
    ]);

    $postId = (int) $pdo->lastInsertId();

    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => 'Post created',
        'post' => [
            'id' => $postId,
            'author_id' => $userId,
            'category' => $category,
            'post_type' => 'text',
            'body' => $body,
            'english_sentence' => $englishSentence,
            'bangla_meaning' => $banglaMeaning,
            'media_key' => null,
            'media_type' => null,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Could not create post',
    ]);
}