<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class UpdateAttendanceDayDraftTool implements Tool
{
    public function name(): string
    {
        return 'update_attendance_day_draft';
    }

    public function description(): string
    {
        return '月次勤怠下書きの1日分の勤務候補を更新する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => ['type' => 'integer'],
                'expected_version' => ['type' => 'integer'],
                'day' => DaySchema::schema(),
            ],
            'required' => ['draft_id', 'expected_version', 'day'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:update'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->put("/attendance/monthly-drafts/{$arguments['draft_id']}/days", [
            'expected_version' => $arguments['expected_version'],
            'days' => [$arguments['day']],
        ]));
    }
}
