<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;
use App\Models\AttendanceImportSession;

class GetAttendanceImportStatusTool implements Tool
{
    public function name(): string
    {
        return 'get_attendance_import_status';
    }

    public function description(): string
    {
        return 'インポートセッションの状態・明細を取得する。';
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
