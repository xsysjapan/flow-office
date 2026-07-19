<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class SubmitMonthlyAttendanceTool implements Tool
{
    public function name(): string
    {
        return 'submit_monthly_attendance';
    }

    public function description(): string
    {
        return '月次勤怠を申請する。ユーザーの明示的な指示があった場合にのみ呼び出すこと'.
            '(docs/26「月次申請」。ユーザーの指示なしに呼ばない)。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => ['type' => 'integer'],
                'approver_user_id' => ['type' => 'integer'],
            ],
            'required' => ['draft_id', 'approver_user_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:submit'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->post("/attendance/monthly-drafts/{$arguments['draft_id']}/submit", [
            'approver_user_id' => $arguments['approver_user_id'],
        ]));
    }
}
