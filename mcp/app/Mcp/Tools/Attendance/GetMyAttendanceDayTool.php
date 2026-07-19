<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyAttendanceDayTool implements Tool
{
    public function name(): string
    {
        return 'get_my_attendance_day';
    }

    public function description(): string
    {
        return '指定した日付(YYYY-MM-DD)の自分の日次勤怠を取得する。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
            ],
            'required' => ['date'],
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
            $date = $arguments['date'];
            $yearMonth = substr($date, 0, 7);
            $month = $client->get("/attendance/months/{$yearMonth}");
            $days = $month['days'] ?? [];
            foreach ($days as $day) {
                if (($day['work_date'] ?? null) === $date) {
                    return $day;
                }
            }

            return ['message' => "{$date}の日次勤怠は登録されていません。", 'work_date' => $date];
        });
    }
}
