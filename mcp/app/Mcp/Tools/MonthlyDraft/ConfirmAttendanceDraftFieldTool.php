<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

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
        return ToolResult::run(fn () => $client->post(
            "/attendance/monthly-drafts/{$arguments['draft_id']}/fields/{$arguments['field_provenance_id']}/confirm"
        ));
    }
}
