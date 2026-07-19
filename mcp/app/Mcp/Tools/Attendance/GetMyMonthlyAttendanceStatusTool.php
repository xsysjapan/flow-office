<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyMonthlyAttendanceStatusTool implements Tool
{
    public function name(): string
    {
        return 'get_my_monthly_attendance_status';
    }

    public function description(): string
    {
        return '指定した年月の月次勤怠の状態(未提出/提出済み/差戻し/承認済み/締め済み)を取得する。';
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
        return ToolResult::run(function () use ($arguments, $client) {
            $month = $client->get("/attendance/months/{$arguments['year_month']}");

            return ['year_month' => $arguments['year_month'], 'status' => $month['status'] ?? 'not_submitted'];
        });
    }
}
