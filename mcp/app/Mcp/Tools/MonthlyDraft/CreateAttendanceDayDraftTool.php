<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\AttendanceDraftDayApplier;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class CreateAttendanceDayDraftTool implements Tool
{
    public function name(): string
    {
        return 'create_attendance_day_draft';
    }

    public function description(): string
    {
        return '月次勤怠下書きに1日分の勤務候補を追加する(内部的にはbulk_update_attendance_daysの1日版)。';
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
        return ToolResult::run(function () use ($arguments, $client) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');

            $applier = new AttendanceDraftDayApplier;
            $result = $applier->apply(
                $client,
                $arguments['draft_id'],
                $mcpUserId,
                $arguments['expected_version'],
                [$arguments['day']],
                null,
                $mcpUserId,
            );

            return ['draft' => $result['draft']->toArray(), 'results' => $result['results']];
        });
    }
}
