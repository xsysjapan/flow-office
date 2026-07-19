<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\AttendanceImportSession;

class CompareImportWithExistingAttendanceTool implements Tool
{
    public function name(): string
    {
        return 'compare_import_with_existing_attendance';
    }

    public function description(): string
    {
        return 'インポートセッションの現在の差異検出結果(既存勤怠との比較)を取得する。'.
            'preview_attendance_importを実行済みであることが前提。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['session_id' => ['type' => 'integer']],
            'required' => ['session_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['report:self:import'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(function () use ($arguments) {
            $mcpUserId = (int) request()->attributes->get('mcp_user_id');

            return AttendanceImportSession::query()
                ->where('user_id', $mcpUserId)
                ->with('items')
                ->findOrFail($arguments['session_id'])
                ->toArray();
        });
    }
}
