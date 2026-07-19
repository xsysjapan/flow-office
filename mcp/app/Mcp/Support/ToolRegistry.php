<?php

namespace App\Mcp\Support;

use App\Mcp\Contracts\Tool;
use App\Mcp\Tools\Attendance\GetMyAttendanceDayTool;
use App\Mcp\Tools\Attendance\GetMyAttendanceEventsTool;
use App\Mcp\Tools\Attendance\GetMyAttendanceMonthTool;
use App\Mcp\Tools\Attendance\GetMyCalendarTool;
use App\Mcp\Tools\Attendance\GetMyLeaveRequestsTool;
use App\Mcp\Tools\Attendance\GetMyMonthlyAttendanceStatusTool;
use App\Mcp\Tools\Attendance\GetMyMonthlySummaryTool;
use App\Mcp\Tools\Attendance\GetMyWorkScheduleTool;
use App\Mcp\Tools\Clock\ClockInTool;
use App\Mcp\Tools\Clock\ClockOutTool;
use App\Mcp\Tools\Clock\EndBreakTool;
use App\Mcp\Tools\Clock\StartBreakTool;
use App\Mcp\Tools\ImportSession\ApplyImportToMonthlyDraftTool;
use App\Mcp\Tools\ImportSession\CompareImportWithExistingAttendanceTool;
use App\Mcp\Tools\ImportSession\CreateAttendanceImportSessionTool;
use App\Mcp\Tools\ImportSession\GetAttendanceImportStatusTool;
use App\Mcp\Tools\ImportSession\PreviewAttendanceImportTool;
use App\Mcp\Tools\ImportSession\UploadAttendanceImportDataTool;
use App\Mcp\Tools\MonthlyDraft\BulkUpdateAttendanceDaysTool;
use App\Mcp\Tools\MonthlyDraft\CancelMonthlyAttendanceSubmissionTool;
use App\Mcp\Tools\MonthlyDraft\ConfirmAttendanceDraftFieldTool;
use App\Mcp\Tools\MonthlyDraft\CreateAttendanceDayDraftTool;
use App\Mcp\Tools\MonthlyDraft\CreateMonthlyAttendanceDraftTool;
use App\Mcp\Tools\MonthlyDraft\DeleteAttendanceDayDraftTool;
use App\Mcp\Tools\MonthlyDraft\GetMonthlyAttendanceDraftTool;
use App\Mcp\Tools\MonthlyDraft\ListAttendanceDraftFieldsTool;
use App\Mcp\Tools\MonthlyDraft\ListMyMonthlyAttendanceDraftsTool;
use App\Mcp\Tools\MonthlyDraft\SubmitMonthlyAttendanceTool;
use App\Mcp\Tools\MonthlyDraft\UpdateAttendanceDayDraftTool;
use App\Mcp\Tools\MonthlyDraft\ValidateMonthlyAttendanceTool;
use App\Mcp\Tools\Profile\GetMyProfileTool;

/**
 * docs/25-usecases-integrations-mcp.md「MCPツール一覧」に対応する全ツールの一覧。
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct()
    {
        foreach ([
            new GetMyProfileTool,
            new GetMyAttendanceMonthTool,
            new GetMyAttendanceDayTool,
            new GetMyAttendanceEventsTool,
            new GetMyWorkScheduleTool,
            new GetMyCalendarTool,
            new GetMyLeaveRequestsTool,
            new GetMyMonthlySummaryTool,
            new GetMyMonthlyAttendanceStatusTool,
            new ClockInTool,
            new StartBreakTool,
            new EndBreakTool,
            new ClockOutTool,
            new CreateMonthlyAttendanceDraftTool,
            new ListMyMonthlyAttendanceDraftsTool,
            new GetMonthlyAttendanceDraftTool,
            new CreateAttendanceDayDraftTool,
            new UpdateAttendanceDayDraftTool,
            new BulkUpdateAttendanceDaysTool,
            new DeleteAttendanceDayDraftTool,
            new ValidateMonthlyAttendanceTool,
            new ListAttendanceDraftFieldsTool,
            new ConfirmAttendanceDraftFieldTool,
            new SubmitMonthlyAttendanceTool,
            new CancelMonthlyAttendanceSubmissionTool,
            new CreateAttendanceImportSessionTool,
            new UploadAttendanceImportDataTool,
            new PreviewAttendanceImportTool,
            new CompareImportWithExistingAttendanceTool,
            new ApplyImportToMonthlyDraftTool,
            new GetAttendanceImportStatusTool,
        ] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return Tool[] */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
