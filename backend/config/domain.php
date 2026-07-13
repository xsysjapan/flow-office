<?php

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\Attachment\Handlers\UploadAttachmentHandler;
use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\CreateShiftPattern;
use App\Domain\Attendance\Commands\CreateWorkCalendar;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\EditEmployeeShiftAssignment;
use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\Attendance\Handlers\ApproveAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\AssignShiftPatternDayHandler;
use App\Domain\Attendance\Handlers\AssignUserWorkStyleForMonthHandler;
use App\Domain\Attendance\Handlers\ClockInHandler;
use App\Domain\Attendance\Handlers\ClockOutHandler;
use App\Domain\Attendance\Handlers\CloseAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\CorrectAttendancePunchHandler;
use App\Domain\Attendance\Handlers\CreateAttendanceDayHandler;
use App\Domain\Attendance\Handlers\CreateShiftPatternHandler;
use App\Domain\Attendance\Handlers\CreateWorkCalendarHandler;
use App\Domain\Attendance\Handlers\CreateWorkStyleHandler;
use App\Domain\Attendance\Handlers\DeleteAttendanceDayHandler;
use App\Domain\Attendance\Handlers\DeleteAttendancePunchHandler;
use App\Domain\Attendance\Handlers\DesignateLegalHolidayHandler;
use App\Domain\Attendance\Handlers\EditAttendanceDayHandler;
use App\Domain\Attendance\Handlers\EditEmployeeShiftAssignmentHandler;
use App\Domain\Attendance\Handlers\EndBreakHandler;
use App\Domain\Attendance\Handlers\GenerateEmployeeShiftAssignmentsHandler;
use App\Domain\Attendance\Handlers\PublishEmployeeShiftAssignmentsHandler;
use App\Domain\Attendance\Handlers\PublishWorkCalendarHandler;
use App\Domain\Attendance\Handlers\RecordAttendancePunchHandler;
use App\Domain\Attendance\Handlers\ReturnAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\StartBreakHandler;
use App\Domain\Attendance\Handlers\SubmitAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\UpdateShiftPatternHandler;
use App\Domain\Attendance\Handlers\UpdateWorkCalendarDaysHandler;
use App\Domain\Attendance\Handlers\WarnMonthCloseDeadlineHandler;
use App\Domain\Attendance\Handlers\WarnUnsubmittedAttendanceHandler;
use App\Domain\Attendance\Projectors\AttendanceDailyCalculationProjector;
use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\BackOffice\Handlers\AssignBackOfficeTaskHandler;
use App\Domain\BackOffice\Handlers\ChangeBackOfficeTaskStatusHandler;
use App\Domain\BackOffice\Handlers\CreateBackOfficeTaskFromApprovalHandler;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\PaidLeave\Commands\GrantScheduledPaidLeave;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Domain\PaidLeave\Handlers\ApprovePaidLeaveRequestHandler;
use App\Domain\PaidLeave\Handlers\CancelPaidLeaveRequestHandler;
use App\Domain\PaidLeave\Handlers\GrantPaidLeaveHandler;
use App\Domain\PaidLeave\Handlers\GrantScheduledPaidLeaveHandler;
use App\Domain\PaidLeave\Handlers\RequestPaidLeaveHandler;
use App\Domain\PaidLeave\Handlers\ReturnPaidLeaveRequestHandler;
use App\Domain\PaidLeave\Handlers\WarnExpiringPaidLeaveHandler;
use App\Domain\PaidLeave\Handlers\WarnFiveDayObligationHandler;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Commands\RecordSsoLogin;
use App\Domain\User\Commands\SetUserHireDate;
use App\Domain\User\Commands\SyncUsersFromMs365;
use App\Domain\User\Handlers\AssignUserRolesHandler;
use App\Domain\User\Handlers\RecordSsoLoginHandler;
use App\Domain\User\Handlers\SetUserHireDateHandler;
use App\Domain\User\Handlers\SyncUsersFromMs365Handler;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Handlers\ApproveWorkflowRequestHandler;
use App\Domain\Workflow\Handlers\CancelWorkflowRequestHandler;
use App\Domain\Workflow\Handlers\DraftWorkflowRequestHandler;
use App\Domain\Workflow\Handlers\ReturnWorkflowRequestHandler;
use App\Domain\Workflow\Handlers\SubmitWorkflowRequestHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Command Handlers
    |--------------------------------------------------------------------------
    |
    | Command::class => CommandHandler::class の対応表。CommandBusはここを見て
    | ハンドラを解決する。新しいCommandを追加したら必ずここに登録すること。
    | (.claude/skills/add-domain-event 参照)
    |
    */
    'command_handlers' => [
        UploadAttachment::class => UploadAttachmentHandler::class,

        AssignUserRoles::class => AssignUserRolesHandler::class,
        SetUserHireDate::class => SetUserHireDateHandler::class,
        SyncUsersFromMs365::class => SyncUsersFromMs365Handler::class,
        RecordSsoLogin::class => RecordSsoLoginHandler::class,

        DraftWorkflowRequest::class => DraftWorkflowRequestHandler::class,
        SubmitWorkflowRequest::class => SubmitWorkflowRequestHandler::class,
        ApproveWorkflowRequest::class => ApproveWorkflowRequestHandler::class,
        ReturnWorkflowRequest::class => ReturnWorkflowRequestHandler::class,
        CancelWorkflowRequest::class => CancelWorkflowRequestHandler::class,

        CreateBackOfficeTaskFromApproval::class => CreateBackOfficeTaskFromApprovalHandler::class,
        AssignBackOfficeTask::class => AssignBackOfficeTaskHandler::class,
        ChangeBackOfficeTaskStatus::class => ChangeBackOfficeTaskStatusHandler::class,

        ClockIn::class => ClockInHandler::class,
        StartBreak::class => StartBreakHandler::class,
        EndBreak::class => EndBreakHandler::class,
        ClockOut::class => ClockOutHandler::class,
        CreateAttendanceDay::class => CreateAttendanceDayHandler::class,
        EditAttendanceDay::class => EditAttendanceDayHandler::class,
        EditEmployeeShiftAssignment::class => EditEmployeeShiftAssignmentHandler::class,
        DeleteAttendanceDay::class => DeleteAttendanceDayHandler::class,

        CreateWorkCalendar::class => CreateWorkCalendarHandler::class,
        PublishWorkCalendar::class => PublishWorkCalendarHandler::class,
        UpdateWorkCalendarDays::class => UpdateWorkCalendarDaysHandler::class,
        CreateWorkStyle::class => CreateWorkStyleHandler::class,
        AssignUserWorkStyleForMonth::class => AssignUserWorkStyleForMonthHandler::class,
        CreateShiftPattern::class => CreateShiftPatternHandler::class,
        UpdateShiftPattern::class => UpdateShiftPatternHandler::class,
        GenerateEmployeeShiftAssignments::class => GenerateEmployeeShiftAssignmentsHandler::class,
        AssignShiftPatternDay::class => AssignShiftPatternDayHandler::class,
        PublishEmployeeShiftAssignments::class => PublishEmployeeShiftAssignmentsHandler::class,
        RecordAttendancePunch::class => RecordAttendancePunchHandler::class,
        CorrectAttendancePunch::class => CorrectAttendancePunchHandler::class,
        DeleteAttendancePunch::class => DeleteAttendancePunchHandler::class,
        DesignateLegalHoliday::class => DesignateLegalHolidayHandler::class,
        SubmitAttendanceMonth::class => SubmitAttendanceMonthHandler::class,
        ApproveAttendanceMonth::class => ApproveAttendanceMonthHandler::class,
        ReturnAttendanceMonth::class => ReturnAttendanceMonthHandler::class,
        CloseAttendanceMonth::class => CloseAttendanceMonthHandler::class,
        WarnUnsubmittedAttendance::class => WarnUnsubmittedAttendanceHandler::class,
        WarnMonthCloseDeadline::class => WarnMonthCloseDeadlineHandler::class,

        GrantPaidLeave::class => GrantPaidLeaveHandler::class,
        GrantScheduledPaidLeave::class => GrantScheduledPaidLeaveHandler::class,
        WarnExpiringPaidLeave::class => WarnExpiringPaidLeaveHandler::class,
        WarnFiveDayObligation::class => WarnFiveDayObligationHandler::class,
        RequestPaidLeave::class => RequestPaidLeaveHandler::class,
        ApprovePaidLeaveRequest::class => ApprovePaidLeaveRequestHandler::class,
        ReturnPaidLeaveRequest::class => ReturnPaidLeaveRequestHandler::class,
        CancelPaidLeaveRequest::class => CancelPaidLeaveRequestHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Projectors
    |--------------------------------------------------------------------------
    |
    | stored_events を購読してProjection Table (再生成可能な派生データ) を更新する
    | Projectorの一覧。`php artisan projections:rebuild` はこのリストを総なめして再生成する。
    | 注意: backoffice_tasks / attendance_days のような「正データ」はここに含めない。
    | 正データはCommandHandlerが直接更新するものであり、再生成対象ではない。
    | (.claude/skills/add-projection 参照)
    |
    */
    'projectors' => [
        AttendanceDailyCalculationProjector::class,
    ],

];
