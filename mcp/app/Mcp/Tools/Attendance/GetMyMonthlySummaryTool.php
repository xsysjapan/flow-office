<?php

namespace App\Mcp\Tools\Attendance;

use App\Mcp\Contracts\Tool;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolResult;

class GetMyMonthlySummaryTool implements Tool
{
    public function name(): string
    {
        return 'get_my_monthly_summary';
    }

    public function description(): string
    {
        return '指定した年月の月次集計(所定内・所定外・法定外残業・深夜・休日労働・有給等)を取得する。';
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

            return $month['monthly_calculation_totals'] ?? $month;
        });
    }
}
