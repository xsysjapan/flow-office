<?php

namespace App\Mcp\Tools\ImportSession;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class PreviewAttendanceImportTool implements Tool
{
    public function name(): string
    {
        return 'preview_attendance_import';
    }

    public function description(): string
    {
        return '既存の勤怠・打刻・休暇消化・勤務予定と比較し、日別の差異を検出する(docs/26「差異検出」)。';
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
        return ToolResult::run(fn () => $client->post("/attendance/import-sessions/{$arguments['session_id']}/preview"));
    }
}
