<?php

namespace App\Mcp\Tools\Clock;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class ClockOutTool implements Tool
{
    public function name(): string
    {
        return 'clock_out';
    }

    public function description(): string
    {
        return '退勤の打刻を行う(UC-A004)。';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:clock'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post('/attendance/clock-out'));
    }
}
