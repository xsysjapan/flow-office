<?php

namespace App\Mcp\Tools\Clock;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class StartBreakTool implements Tool
{
    public function name(): string
    {
        return 'start_break';
    }

    public function description(): string
    {
        return '休憩開始の打刻を行う(UC-A002)。';
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
        return ToolResult::run(fn () => $client->post('/attendance/break/start'));
    }
}
