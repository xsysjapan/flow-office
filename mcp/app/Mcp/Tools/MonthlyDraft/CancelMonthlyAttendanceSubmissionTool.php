<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

/**
 * backendに月次申請の取り消しAPIが未実装のため、常にエラーを返すスタブ
 * (mcp-server/src/tools/monthlyDraft.tsの既存の制約をそのまま踏襲する)。
 */
class CancelMonthlyAttendanceSubmissionTool implements Tool
{
    public function name(): string
    {
        return 'cancel_monthly_attendance_submission';
    }

    public function description(): string
    {
        return '月次勤怠の申請を取り消す。backendは現時点でこの取り消しAPIを実装していないため、'.
            '常にエラーを返す(将来のPhase6以降で対応予定)。';
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
        return ['attendance:self:submit'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::error(
            '月次申請の取り消しはbackendに未実装です。既存の差戻し(UC-A010、承認者操作)を'.
            '利用するか、管理者に問い合わせてください。'
        );
    }
}
