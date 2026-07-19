<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyAttendanceMonthTool implements Tool
{
    public function name(): string
    {
        return 'get_my_attendance_month';
    }

    public function description(): string
    {
        return '指定した年月(YYYY-MM)の自分の月次勤怠(日別明細・月次集計)を取得する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year_month' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'],
            ],
            'required' => ['year_month'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScopes(): array
    {
        return ['attendance:self:read'];
    }

    public function handle(array $arguments, BackendApiClient $client): array
    {
        return ToolResult::run(fn () => $client->get("/attendance/months/{$arguments['year_month']}"));
    }
}
