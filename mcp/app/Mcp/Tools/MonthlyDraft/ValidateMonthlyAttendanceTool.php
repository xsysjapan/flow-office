<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;

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
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $draft = MonthlyAttendanceDraft::query()->where('user_id', $mcpUserId)->findOrFail($arguments['draft_id']);

            $unconfirmed = FieldProvenance::latestForEntity(FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT, $draft->id)
                ->filter(fn (FieldProvenance $provenance) => $provenance->isImportantAndUnconfirmed());

            $draft->status = $unconfirmed->isNotEmpty() ? MonthlyDraftStatus::NEEDS_REVIEW : MonthlyDraftStatus::READY_TO_SUBMIT;
            $draft->save();

            return [
                'draft' => $draft->toArray(),
                'unconfirmed_fields' => $unconfirmed->pluck('field_name')->values()->all(),
            ];
        });
    }
}
