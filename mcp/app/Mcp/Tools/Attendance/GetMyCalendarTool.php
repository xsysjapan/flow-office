<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyCalendarTool implements Tool
{
    public function name(): string
    {
        return 'get_my_calendar';
    }

    public function description(): string
    {
        return '対象年の会社カレンダー(所定休日・法定休日等)一覧を取得する。';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function requiredScopes(): array
    {
        return ['schedule:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get('/work-calendars'));
    }
}
