<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function optionalAuth(PDO $pdo): ?array
{
    $authorization = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $authorization = (string) $value;
                break;
            }
        }
    }

    // Authorization header না থাকলেই guest।
    if ($authorization === null) {
        return null;
    }

    // Header থাকলে existing auth দিয়েই যাচাই।
    // ভুল/expired token-কে guest হিসেবে গ্রহণ করবে না।
    $_SERVER['HTTP_AUTHORIZATION'] = $authorization;

    return requireAuth($pdo);
}