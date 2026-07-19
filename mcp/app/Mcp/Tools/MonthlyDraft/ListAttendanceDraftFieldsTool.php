<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;

class ListAttendanceDraftFieldsTool implements Tool
{
    public function name(): string
    {
        return 'list_attendance_draft_fields';
    }

    public function description(): string
    {
        return '月次勤怠下書きに紐づく各項目(日付・項目名・値の出所・確認状況)を一覧する。'.
            'validate_monthly_attendanceは未確認項目の名前(例: "2026-07-01:start_time")しか返さないため、'.
            'confirm_attendance_draft_fieldに渡すfield_provenance_idはこのツールで取得すること。';
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
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $draft = MonthlyAttendanceDraft::query()->where('user_id', $mcpUserId)->findOrFail($arguments['draft_id']);

            return FieldProvenance::latestForEntity(FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT, $draft->id)
                ->sortBy('field_name')
                ->values()
                ->toArray();
        });
    }
}
