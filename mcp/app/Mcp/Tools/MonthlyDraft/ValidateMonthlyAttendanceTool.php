<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class ValidateMonthlyAttendanceTool implements Tool
{
    public function name(): string
    {
        return 'validate_monthly_attendance';
    }

    public function description(): string
    {
        return '月次勤怠下書きを検証する。未確認のAI推定値が残っている場合はその一覧を返す。';
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
        return ['attendance:self:validate'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post("/attendance/monthly-drafts/{$arguments['draft_id']}/validate"));
    }
}
