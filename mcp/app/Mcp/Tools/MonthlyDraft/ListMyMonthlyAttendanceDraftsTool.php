<?php

namespace App\Mcp\Tools\MonthlyDraft;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\MonthlyAttendanceDraft;

class ListMyMonthlyAttendanceDraftsTool implements Tool
{
    public function name(): string
    {
        return 'list_my_monthly_attendance_drafts';
    }

    public function description(): string
    {
        return '自分の月次勤怠下書きを一覧する(新しい順)。';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');

            return MonthlyAttendanceDraft::query()
                ->where('user_id', $mcpUserId)
                ->orderByDesc('id')
                ->get()
                ->toArray();
        });
    }
}
