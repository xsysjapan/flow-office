<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyWorkScheduleTool implements Tool
{
    public function name(): string
    {
        return 'get_my_work_schedule';
    }

    public function description(): string
    {
        return '指定した期間の自分の勤務予定(シフト)を取得する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
            ],
            'required' => ['from', 'to'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['schedule:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () use ($arguments, $client) {
            $profile = $client->get('/auth/me');

            return $client->get('/employee-shift-assignments', [
                'user_id' => $profile['id'],
                'from' => $arguments['from'],
                'to' => $arguments['to'],
            ]);
        });
    }
}
