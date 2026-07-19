<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyAttendanceEventsTool implements Tool
{
    public function name(): string
    {
        return 'get_my_attendance_events';
    }

    public function description(): string
    {
        return '指定した期間の自分の打刻ログ(出勤・休憩開始・休憩終了・退勤の生ログ)を取得する。';
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
        return ['attendance:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get('/attendance-punches', [
            'from' => $arguments['from'],
            'to' => $arguments['to'],
        ]));
    }
}
