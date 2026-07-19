<?php

return [
    /*
     * backend/ のAPIベースURL。mcp/はこのAPIのクライアントとしてのみ動作し、
     * backend/のDBには一切アクセスしない(CLAUDE.md 設計原則9)。
     */
    'backend_api_base_url' => env('MCP_BACKEND_API_BASE_URL', 'http://localhost:8000/api/'),

    'oauth' => [
        'private_key_path' => env('MCP_OAUTH_PRIVATE_KEY_PATH', 'storage/oauth-private.key'),
        'public_key_path' => env('MCP_OAUTH_PUBLIC_KEY_PATH', 'storage/oauth-public.key'),
        'access_token_ttl_minutes' => (int) env('MCP_OAUTH_ACCESS_TOKEN_TTL_MINUTES', 60),
        'refresh_token_ttl_days' => (int) env('MCP_OAUTH_REFRESH_TOKEN_TTL_DAYS', 30),
        'auth_code_ttl_minutes' => (int) env('MCP_OAUTH_AUTH_CODE_TTL_MINUTES', 10),
    ],

    /*
     * mcp/ のOAuthスコープ。backend/ の integration_scopes (docs/16-database-schema.md、
     * App\Models\IntegrationScopeType)と同じ文字列をそのまま使う。ここで定義したスコープ
     * 以上をユーザーがClaude等へ許可することはできない(必ずbackendの個人連携トークンの
     * スコープでも制限される。App\Mcp\OAuth\Repositories\ScopeRepository参照)。
     */
    'scopes' => [
        'profile:self:read' => '自分のプロフィール情報の閲覧',
        'attendance:self:read' => '自分の勤怠情報の閲覧',
        'attendance:self:clock' => '自分の打刻(出勤・休憩・退勤)',
        'attendance:self:draft' => '自分の月次勤怠下書きの作成',
        'attendance:self:update' => '自分の日次勤怠の編集',
        'attendance:self:validate' => '自分の月次勤怠の検証',
        'attendance:self:submit' => '自分の月次勤怠の申請',
        'leave:self:read' => '自分の有給申請の閲覧',
        'leave:self:create' => '自分の有給申請の作成',
        'schedule:self:read' => '自分の勤務予定の閲覧',
        'report:self:import' => '作業報告書のインポート',
    ],
];
