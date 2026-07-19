<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class CreateMonthlyAttendanceDraftTool implements Tool
{
    public function name(): string
    {
        return 'create_monthly_attendance_draft';
    }

    public function description(): string
    {
        return '対象年月の月次勤怠下書きを新規作成する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_month' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'],
                'source_type' => ['type' => 'string'],
                'source_reference' => ['type' => 'string'],
            ],
            'required' => ['target_month'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:draft'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post('/attendance/monthly-drafts', [
            'target_month' => $arguments['target_month'],
            'source_type' => $arguments['source_type'] ?? null,
            'source_reference' => $arguments['source_reference'] ?? null,
        ]));
    }
}
