<?php

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\Attachment\Handlers\UploadAttachmentHandler;
use App\Domain\Attendance\Commands\AdjustAttendanceDailyCalculation;
use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\AssignEmployeeRotation;
use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\CreateRotationPattern;
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
use App\Domain\Attendance\Commands\GenerateRotationShiftAssignments;
use App\Domain\Attendance\Commands\PublishEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Commands\RemoveUserWorkStyleMonthlyAssignment;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\SetDefaultWorkStyle;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\Attendance\Handlers\AdjustAttendanceDailyCalculationHandler;
use App\Domain\Attendance\Handlers\ApproveAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\AssignEmployeeRotationHandler;
use App\Domain\Attendance\Handlers\AssignShiftPatternDayHandler;
use App\Domain\Attendance\Handlers\AssignUserWorkStyleForMonthHandler;
use App\Domain\Attendance\Handlers\ClockInHandler;
use App\Domain\Attendance\Handlers\ClockOutHandler;
use App\Domain\Attendance\Handlers\CloseAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\CorrectAttendancePunchHandler;
use App\Domain\Attendance\Handlers\CreateAttendanceDayHandler;
use App\Domain\Attendance\Handlers\CreateDefaultWorkStyleHandler;
use App\Domain\Attendance\Handlers\CreateRotationPatternHandler;
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
use App\Domain\Attendance\Handlers\GenerateRotationShiftAssignmentsHandler;
use App\Domain\Attendance\Handlers\PublishEmployeeShiftAssignmentsHandler;
use App\Domain\Attendance\Handlers\PublishWorkCalendarHandler;
use App\Domain\Attendance\Handlers\RecordAttendancePunchHandler;
use App\Domain\Attendance\Handlers\RemoveUserWorkStyleMonthlyAssignmentHandler;
use App\Domain\Attendance\Handlers\ReturnAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\SetDefaultWorkStyleHandler;
use App\Domain\Attendance\Handlers\StartBreakHandler;
use App\Domain\Attendance\Handlers\SubmitAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\UpdateShiftPatternHandler;
use App\Domain\Attendance\Handlers\UpdateWorkCalendarDaysHandler;
use App\Domain\Attendance\Handlers\WarnMonthCloseDeadlineHandler;
use App\Domain\Attendance\Handlers\WarnUnsubmittedAttendanceHandler;
use App\Domain\Attendance\Projectors\AttendanceDailyCalculationProjector;
use App\Domain\AuthenticationKey\Commands\DisableAuthenticationKey;
use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\AuthenticationKey\Handlers\DisableAuthenticationKeyHandler;
use App\Domain\AuthenticationKey\Handlers\IssueAuthenticationKeyHandler;
use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\BackOffice\Handlers\AssignBackOfficeTaskHandler;
use App\Domain\BackOffice\Handlers\ChangeBackOfficeTaskStatusHandler;
use App\Domain\BackOffice\Handlers\CreateBackOfficeTaskFromApprovalHandler;
use App\Domain\BackOffice\Projectors\BackOfficeTaskProjector;
use App\Domain\Device\Commands\ClaimDevicePairing;
use App\Domain\Device\Commands\DeleteDevice;
use App\Domain\Device\Commands\DisableDevice;
use App\Domain\Device\Commands\GrantDeviceScope;
use App\Domain\Device\Commands\IssueDevicePairingClaim;
use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\Device\Commands\RevokeDevice;
use App\Domain\Device\Commands\UpdateDeviceRoles;
use App\Domain\Device\Commands\UpdateDeviceSettings;
use App\Domain\Device\Commands\WarnStaleDevices;
use App\Domain\Device\Handlers\ClaimDevicePairingHandler;
use App\Domain\Device\Handlers\DeleteDeviceHandler;
use App\Domain\Device\Handlers\DisableDeviceHandler;
use App\Domain\Device\Handlers\GrantDeviceScopeHandler;
use App\Domain\Device\Handlers\IssueDevicePairingClaimHandler;
use App\Domain\Device\Handlers\RegisterDeviceHandler;
use App\Domain\Device\Handlers\RevokeDeviceHandler;
use App\Domain\Device\Handlers\UpdateDeviceRolesHandler;
use App\Domain\Device\Handlers\UpdateDeviceSettingsHandler;
use App\Domain\Device\Handlers\WarnStaleDevicesHandler;
use App\Domain\DeviceAdminSession\Commands\EndDeviceAdminSession;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSession;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSessionBootstrap;
use App\Domain\DeviceAdminSession\Handlers\EndDeviceAdminSessionHandler;
use App\Domain\DeviceAdminSession\Handlers\StartDeviceAdminSessionBootstrapHandler;
use App\Domain\DeviceAdminSession\Handlers\StartDeviceAdminSessionHandler;
use App\Domain\Integration\Commands\RegisterIntegration;
use App\Domain\Integration\Commands\ReissueIntegrationToken;
use App\Domain\Integration\Commands\RevokeIntegration;
use App\Domain\Integration\Handlers\RegisterIntegrationHandler;
use App\Domain\Integration\Handlers\ReissueIntegrationTokenHandler;
use App\Domain\Integration\Handlers\RevokeIntegrationHandler;
use App\Domain\Notification\Commands\ConfirmNotification;
use App\Domain\Notification\Handlers\ConfirmNotificationHandler;
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
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\GrantScheduledSpecialLeave;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\SpecialLeave\Commands\ReturnSpecialLeaveRequest;
use App\Domain\SpecialLeave\Handlers\ApproveSpecialLeaveRequestHandler;
use App\Domain\SpecialLeave\Handlers\CancelSpecialLeaveRequestHandler;
use App\Domain\SpecialLeave\Handlers\GrantScheduledSpecialLeaveHandler;
use App\Domain\SpecialLeave\Handlers\GrantSpecialLeaveHandler;
use App\Domain\SpecialLeave\Handlers\RequestSpecialLeaveHandler;
use App\Domain\SpecialLeave\Handlers\ReturnSpecialLeaveRequestHandler;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Commands\CompleteOnboardingSsoLink;
use App\Domain\User\Commands\CompleteOnboardingWithLocalPassword;
use App\Domain\User\Commands\LinkSsoAccount;
use App\Domain\User\Commands\RecordLocalLogin;
use App\Domain\User\Commands\RecordSsoLogin;
use App\Domain\User\Commands\SetUserHireDate;
use App\Domain\User\Commands\SetUserTerminationDate;
use App\Domain\User\Commands\StartOnboardingSso;
use App\Domain\User\Commands\SyncUsersFromMs365;
use App\Domain\User\Handlers\AssignUserRolesHandler;
use App\Domain\User\Handlers\CompleteOnboardingSsoLinkHandler;
use App\Domain\User\Handlers\CompleteOnboardingWithLocalPasswordHandler;
use App\Domain\User\Handlers\LinkSsoAccountHandler;
use App\Domain\User\Handlers\RecordLocalLoginHandler;
use App\Domain\User\Handlers\RecordSsoLoginHandler;
use App\Domain\User\Handlers\SetUserHireDateHandler;
use App\Domain\User\Handlers\SetUserTerminationDateHandler;
use App\Domain\User\Handlers\StartOnboardingSsoHandler;
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
use App\Domain\Workflow\Projectors\WorkflowRequestProjector;

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

        RegisterDevice::class => RegisterDeviceHandler::class,
        IssueDevicePairingClaim::class => IssueDevicePairingClaimHandler::class,
        ClaimDevicePairing::class => ClaimDevicePairingHandler::class,
        DisableDevice::class => DisableDeviceHandler::class,
        RevokeDevice::class => RevokeDeviceHandler::class,
        DeleteDevice::class => DeleteDeviceHandler::class,
        GrantDeviceScope::class => GrantDeviceScopeHandler::class,
        UpdateDeviceSettings::class => UpdateDeviceSettingsHandler::class,
        UpdateDeviceRoles::class => UpdateDeviceRolesHandler::class,
        WarnStaleDevices::class => WarnStaleDevicesHandler::class,

        StartDeviceAdminSession::class => StartDeviceAdminSessionHandler::class,
        StartDeviceAdminSessionBootstrap::class => StartDeviceAdminSessionBootstrapHandler::class,
        EndDeviceAdminSession::class => EndDeviceAdminSessionHandler::class,

        IssueAuthenticationKey::class => IssueAuthenticationKeyHandler::class,
        DisableAuthenticationKey::class => DisableAuthenticationKeyHandler::class,

        RegisterIntegration::class => RegisterIntegrationHandler::class,
        RevokeIntegration::class => RevokeIntegrationHandler::class,
        ReissueIntegrationToken::class => ReissueIntegrationTokenHandler::class,

        ConfirmNotification::class => ConfirmNotificationHandler::class,

        AssignUserRoles::class => AssignUserRolesHandler::class,
        SetUserHireDate::class => SetUserHireDateHandler::class,
        SetUserTerminationDate::class => SetUserTerminationDateHandler::class,
        SyncUsersFromMs365::class => SyncUsersFromMs365Handler::class,
        RecordSsoLogin::class => RecordSsoLoginHandler::class,
        RecordLocalLogin::class => RecordLocalLoginHandler::class,
        StartOnboardingSso::class => StartOnboardingSsoHandler::class,
        CompleteOnboardingSsoLink::class => CompleteOnboardingSsoLinkHandler::class,
        CompleteOnboardingWithLocalPassword::class => CompleteOnboardingWithLocalPasswordHandler::class,
        LinkSsoAccount::class => LinkSsoAccountHandler::class,

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
        AdjustAttendanceDailyCalculation::class => AdjustAttendanceDailyCalculationHandler::class,
        EditEmployeeShiftAssignment::class => EditEmployeeShiftAssignmentHandler::class,
        DeleteAttendanceDay::class => DeleteAttendanceDayHandler::class,

        CreateWorkCalendar::class => CreateWorkCalendarHandler::class,
        PublishWorkCalendar::class => PublishWorkCalendarHandler::class,
        UpdateWorkCalendarDays::class => UpdateWorkCalendarDaysHandler::class,
        CreateWorkStyle::class => CreateWorkStyleHandler::class,
        CreateDefaultWorkStyle::class => CreateDefaultWorkStyleHandler::class,
        SetDefaultWorkStyle::class => SetDefaultWorkStyleHandler::class,
        AssignUserWorkStyleForMonth::class => AssignUserWorkStyleForMonthHandler::class,
        RemoveUserWorkStyleMonthlyAssignment::class => RemoveUserWorkStyleMonthlyAssignmentHandler::class,
        CreateShiftPattern::class => CreateShiftPatternHandler::class,
        UpdateShiftPattern::class => UpdateShiftPatternHandler::class,
        GenerateEmployeeShiftAssignments::class => GenerateEmployeeShiftAssignmentsHandler::class,
        AssignShiftPatternDay::class => AssignShiftPatternDayHandler::class,
        PublishEmployeeShiftAssignments::class => PublishEmployeeShiftAssignmentsHandler::class,
        CreateRotationPattern::class => CreateRotationPatternHandler::class,
        AssignEmployeeRotation::class => AssignEmployeeRotationHandler::class,
        GenerateRotationShiftAssignments::class => GenerateRotationShiftAssignmentsHandler::class,
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

        GrantSpecialLeave::class => GrantSpecialLeaveHandler::class,
        GrantScheduledSpecialLeave::class => GrantScheduledSpecialLeaveHandler::class,
        RequestSpecialLeave::class => RequestSpecialLeaveHandler::class,
        ApproveSpecialLeaveRequest::class => ApproveSpecialLeaveRequestHandler::class,
        ReturnSpecialLeaveRequest::class => ReturnSpecialLeaveRequestHandler::class,
        CancelSpecialLeaveRequest::class => CancelSpecialLeaveRequestHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Projectors
    |--------------------------------------------------------------------------
    |
    | stored_events を購読してProjection Table (再生成可能な派生データ) を更新する
    | Projectorの一覧。`php artisan projections:rebuild` はこのリストを総なめして再生成する。
    | 注意: attendance_days / paid_leave_requests / special_leave_requests のような
    | 「正データ」はここに含めない。これらはCommandHandlerが直接更新するものであり、
    | 再生成対象ではない(承認1件が複数集約にまたがる副作用を持つなど、単純な
    | イベント→行の対応関係に収まらないため)。
    |
    | workflow_requests / backoffice_tasks は主キーをコマンド側生成のUUIDにしたことで、
    | 行の新規作成を含めて完全にProjector化している(DB採番PKだと集約IDが確定する前に
    | イベントを書けず、作成イベントだけはProjector化できないため)。
    | (.claude/skills/add-projection 参照)
    |
    | 注意: spatie/laravel-event-sourcingに移行済みのドメイン(Attachment/Integration/
    | AuthenticationKey/Device/DeviceAdminSession/Notification)のProjectorはここに登録しない。
    | それらはSpatie\EventSourcing\EventHandlers\Projectors\Projectorのサブクラスであり、
    | config/event-sourcing.phpのauto_discover_projectors_and_reactorsで自動検出される
    | (docs/29-event-sourcing-framework-migration.md参照)。
    |
    */
    'projectors' => [
        AttendanceDailyCalculationProjector::class,
        WorkflowRequestProjector::class,
        BackOfficeTaskProjector::class,
    ],

];
