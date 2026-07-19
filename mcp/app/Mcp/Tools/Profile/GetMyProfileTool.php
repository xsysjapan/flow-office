<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyProfileTool implements Tool
{
    public function name(): string
    {
        return 'get_my_profile';
    }

    public function description(): string
    {
        return '自分(このトークンを発行した本人)のプロフィール情報を取得する。';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function requiredScopes(): array
    {
        return ['profile:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get('/auth/me'));
    }
}
