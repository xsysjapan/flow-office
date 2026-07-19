<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;

class CreateMonthlyAttendanceDraftTool implements Tool
{
    public function name(): string
    {
        return 'create_monthly_attendance_draft';
    }

    public function description(): string
    {
        return '対象年月の月次勤怠下書きを新規作成する。mcp/自身のDBに保持し、backend/には書き込まない。';
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
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');

            $draft = MonthlyAttendanceDraft::query()->create([
                'user_id' => $mcpUserId,
                'target_month' => $arguments['target_month'],
                'status' => MonthlyDraftStatus::DRAFT,
                'version' => 1,
                'source_type' => $arguments['source_type'] ?? null,
                'source_reference' => $arguments['source_reference'] ?? null,
                'created_by_user_id' => $mcpUserId,
            ]);

            return $draft->toArray();
        });
    }
}
