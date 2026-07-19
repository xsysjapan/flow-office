<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Microsoft Entra ID SSO (docs/06-usecases-auth.md UC-001, Socialite azure driver)
    'azure' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),

        // ローカル開発用モックOIDCサーバー (mock-oidc/)。true のときのみ本物のEntra IDの
        // 代わりにLocalAzureProviderを使う。本番・検証環境では未設定(false)のままにする。
        'mock_enabled' => env('MICROSOFT_MOCK_ENABLED', false),
        // ブラウザからアクセスするURL (認可エンドポイントへのリダイレクト先)
        'mock_public_base_url' => env('MICROSOFT_MOCK_PUBLIC_BASE_URL', 'http://localhost:9000'),
        // backend(サーバー側)からアクセスするURL (token交換・ユーザー情報取得)
        'mock_internal_base_url' => env('MICROSOFT_MOCK_INTERNAL_BASE_URL', 'http://localhost:9000'),
    ],

    // Microsoft Graph ユーザー同期 (docs/06-usecases-auth.md UC-002)
    'microsoft_graph' => [
        'tenant_id' => env('MS_GRAPH_TENANT_ID'),
        'client_id' => env('MS_GRAPH_CLIENT_ID'),
        'client_secret' => env('MS_GRAPH_CLIENT_SECRET'),
    ],

];
