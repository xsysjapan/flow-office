<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class DeleteAttendanceDayDraftTool implements Tool
{
    public function name(): string
    {
        return 'delete_attendance_day_draft';
    }

    public function description(): string
    {
        return '確定済みの日次勤怠(attendance_days)を削除する(UC-A015)。月次下書き固有の削除APIは'.
            'backendに未実装のため、既存の日次勤怠削除エンドポイントを呼び出す。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attendance_day_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['attendance_day_id', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:update'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->delete("/attendance/days/{$arguments['attendance_day_id']}", [
            'reason' => $arguments['reason'],
        ]));
    }
}
