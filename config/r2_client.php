<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Aws\S3\S3Client;

$config = require __DIR__ . '/r2.php';

return new S3Client([
    'region'      => $config['region'],
    'version'     => 'latest',
    'endpoint'    => $config['endpoint'],
    'credentials' => [
        'key'    => $config['access_key_id'],
        'secret' => $config['secret_access_key'],
    ],
]);