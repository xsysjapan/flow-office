<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMonthlyAttendanceDraftTool implements Tool
{
    public function name(): string
    {
        return 'get_monthly_attendance_draft';
    }

    public function description(): string
    {
        return '月次勤怠下書きを取得する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['draft_id' => ['type' => 'integer']],
            'required' => ['draft_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get("/attendance/monthly-drafts/{$arguments['draft_id']}"));
    }
}
