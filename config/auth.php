<?php
declare(strict_types=1);

function requireAuth(PDO $pdo): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization']
            ?? $headers['authorization']
            ?? '';
    }

    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Bearer token required',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.status
         FROM auth_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :token_hash
           AND t.expires_at > NOW()
         LIMIT 1'
    );

    $stmt->execute([
        'token_hash' => hash('sha256', $matches[1]),
    ]);

    $user = $stmt->fetch();

    if (!$user || in_array($user['status'], ['banned', 'deleted'], true)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired token',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $user;
}