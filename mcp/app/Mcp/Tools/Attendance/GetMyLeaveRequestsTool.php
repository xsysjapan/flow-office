<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyLeaveRequestsTool implements Tool
{
    public function name(): string
    {
        return 'get_my_leave_requests';
    }

    public function description(): string
    {
        return '自分の有給申請一覧を取得する。';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function requiredScopes(): array
    {
        return ['leave:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get('/paid-leave/requests/mine'));
    }
}
