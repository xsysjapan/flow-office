<?php

$apiPrefix = trim((string) env('APP_API_PREFIX', 'api'), '/');

return [
    'paths' => [$apiPrefix === '' ? '*' : $apiPrefix.'/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // フロントエンドがダウンロード時にAPI指定の日本語ファイル名を取得するために必要。
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => false,
];
