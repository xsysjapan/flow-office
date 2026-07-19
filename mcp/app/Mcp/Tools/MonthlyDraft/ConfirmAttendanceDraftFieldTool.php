<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;

class ConfirmAttendanceDraftFieldTool implements Tool
{
    public function name(): string
    {
        return 'confirm_attendance_draft_field';
    }

    public function description(): string
    {
        return '月次勤怠下書きのAI推定値をユーザーが確認したことを記録する。field_provenance_idは'.
            'list_attendance_draft_fieldsで取得したidを使うこと。ユーザー自身がその値の内容を確認・'.
            '了承した場合にのみ呼び出すこと(AIが自己判断で確定させない、docs/03-architecture.md 3.7)。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => ['type' => 'integer'],
                'field_provenance_id' => ['type' => 'integer'],
            ],
            'required' => ['draft_id', 'field_provenance_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:update'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');
            $draft = MonthlyAttendanceDraft::query()->where('user_id', $mcpUserId)->findOrFail($arguments['draft_id']);
            $provenance = FieldProvenance::query()->findOrFail($arguments['field_provenance_id']);

            abort_unless(
                $provenance->entity_type === FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT
                    && $provenance->entity_id === $draft->id,
                404,
                'この下書きに属さない項目です。',
            );

            $provenance->confirmed_at = now();
            $provenance->confirmed_by_user_id = $mcpUserId;
            $provenance->save();

            return [
                'field_name' => $provenance->field_name,
                'confirmed_at' => $provenance->confirmed_at?->toIso8601String(),
            ];
        });
    }
}
