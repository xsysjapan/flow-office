<?php

use App\Application\UserManagement\Handlers\ApplyExternalHrImportHandler;
use App\Application\UserManagement\Handlers\CreateUserHandler;
use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveFeatureFromGroup;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveUserFeatureSuspension;
use App\Domain\AccessControl\Commands\SuspendUserFeature;
use App\Domain\AccessControl\Commands\UpdateRole;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\AccessControl\Handlers\AssignFeatureToGroupHandler;
use App\Domain\AccessControl\Handlers\ChangeRolePermissionsHandler;
use App\Domain\AccessControl\Handlers\CreateRoleAssignmentHandler;
use App\Domain\AccessControl\Handlers\CreateRoleHandler;
use App\Domain\AccessControl\Handlers\RemoveFeatureFromGroupHandler;
use App\Domain\AccessControl\Handlers\RemoveRoleAssignmentHandler;
use App\Domain\AccessControl\Handlers\RemoveUserFeatureSuspensionHandler;
use App\Domain\AccessControl\Handlers\SuspendUserFeatureHandler;
use App\Domain\AccessControl\Handlers\UpdateRoleAssignmentHandler;
use App\Domain\AccessControl\Handlers\UpdateRoleHandler;
use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\Attachment\Handlers\UploadAttachmentHandler;
use App\Domain\Attendance\Commands\AdjustAttendanceDailyCalculation;
use App\Domain\Attendance\Commands\ApplyCalendarBulkOperation;
use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\ArchiveCompanyCalendarYear;
use App\Domain\Attendance\Commands\AssignEmployeeRotation;
use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Commands\CancelSubmittedAttendanceMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\CreateRotationPattern;
use App\Domain\Attendance\Commands\CreateShiftPattern;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\Attendance\Commands\DisableHolidayCalendarSource;
use App\Domain\Attendance\Commands\DuplicateCompanyCalendarYear;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\EditEmployeeCalendarEntry;
use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Commands\ExcludeAttendanceSubmissionReminder;
use App\Domain\Attendance\Commands\GenerateCompanyCalendarYears;
use App\Domain\Attendance\Commands\GenerateEmployeeCalendarEntries;
use App\Domain\Attendance\Commands\GeneratePatternAttendanceDays;
use App\Domain\Attendance\Commands\GeneratePatternCalendarEntries;
use App\Domain\Attendance\Commands\GenerateRotationCalendarEntries;
use App\Domain\Attendance\Commands\PublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\PublishEmployeeCalendarEntries;
use App\Domain\Attendance\Commands\RecalculateAttendanceMonthSnapshot;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Commands\RegisterHolidayCalendarSource;
use App\Domain\Attendance\Commands\RemoveUserWorkStyleMonthlyAssignment;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\RevertCalendarBulkOperation;
use App\Domain\Attendance\Commands\RevertLastHolidayCalendarSync;
use App\Domain\Attendance\Commands\SetDefaultCompanyCalendar;
use App\Domain\Attendance\Commands\SetDefaultWorkStyle;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UnpublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\UpdateCompanyCalendar;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\Attendance\Commands\UpdateWorkStyle;
use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\Attendance\Handlers\AdjustAttendanceDailyCalculationHandler;
use App\Domain\Attendance\Handlers\ApplyCalendarBulkOperationHandler;
use App\Domain\Attendance\Handlers\ApproveAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\ArchiveCompanyCalendarYearHandler;
use App\Domain\Attendance\Handlers\AssignEmployeeRotationHandler;
use App\Domain\Attendance\Handlers\AssignShiftPatternDayHandler;
use App\Domain\Attendance\Handlers\AssignUserWorkStyleForMonthHandler;
use App\Domain\Attendance\Handlers\CancelSubmittedAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\ClockInHandler;
use App\Domain\Attendance\Handlers\ClockOutHandler;
use App\Domain\Attendance\Handlers\CloseAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\CorrectAttendancePunchHandler;
use App\Domain\Attendance\Handlers\CreateAttendanceDayHandler;
use App\Domain\Attendance\Handlers\CreateCompanyCalendarHandler;
use App\Domain\Attendance\Handlers\CreateCompanyCalendarYearHandler;
use App\Domain\Attendance\Handlers\CreateDefaultWorkStyleHandler;
use App\Domain\Attendance\Handlers\CreateRotationPatternHandler;
use App\Domain\Attendance\Handlers\CreateShiftPatternHandler;
use App\Domain\Attendance\Handlers\CreateWorkStyleHandler;
use App\Domain\Attendance\Handlers\DeleteAttendanceDayHandler;
use App\Domain\Attendance\Handlers\DeleteAttendancePunchHandler;
use App\Domain\Attendance\Handlers\DesignateLegalHolidayHandler;
use App\Domain\Attendance\Handlers\DisableHolidayCalendarSourceHandler;
use App\Domain\Attendance\Handlers\DuplicateCompanyCalendarYearHandler;
use App\Domain\Attendance\Handlers\EditAttendanceDayHandler;
use App\Domain\Attendance\Handlers\EditEmployeeCalendarEntryHandler;
use App\Domain\Attendance\Handlers\EndBreakHandler;
use App\Domain\Attendance\Handlers\ExcludeAttendanceSubmissionReminderHandler;
use App\Domain\Attendance\Handlers\GenerateCompanyCalendarYearsHandler;
use App\Domain\Attendance\Handlers\GenerateEmployeeCalendarEntriesHandler;
use App\Domain\Attendance\Handlers\GeneratePatternAttendanceDaysHandler;
use App\Domain\Attendance\Handlers\GeneratePatternCalendarEntriesHandler;
use App\Domain\Attendance\Handlers\GenerateRotationCalendarEntriesHandler;
use App\Domain\Attendance\Handlers\PublishCompanyCalendarYearHandler;
use App\Domain\Attendance\Handlers\PublishEmployeeCalendarEntriesHandler;
use App\Domain\Attendance\Handlers\RecalculateAttendanceMonthSnapshotHandler;
use App\Domain\Attendance\Handlers\RecordAttendancePunchHandler;
use App\Domain\Attendance\Handlers\RegisterHolidayCalendarSourceHandler;
use App\Domain\Attendance\Handlers\RemoveUserWorkStyleMonthlyAssignmentHandler;
use App\Domain\Attendance\Handlers\ReturnAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\RevertCalendarBulkOperationHandler;
use App\Domain\Attendance\Handlers\RevertLastHolidayCalendarSyncHandler;
use App\Domain\Attendance\Handlers\SetDefaultCompanyCalendarHandler;
use App\Domain\Attendance\Handlers\SetDefaultWorkStyleHandler;
use App\Domain\Attendance\Handlers\StartBreakHandler;
use App\Domain\Attendance\Handlers\SubmitAttendanceMonthHandler;
use App\Domain\Attendance\Handlers\SyncHolidayCalendarSourceHandler;
use App\Domain\Attendance\Handlers\UnpublishCompanyCalendarYearHandler;
use App\Domain\Attendance\Handlers\UpdateCompanyCalendarDaysHandler;
use App\Domain\Attendance\Handlers\UpdateCompanyCalendarHandler;
use App\Domain\Attendance\Handlers\UpdateShiftPatternHandler;
use App\Domain\Attendance\Handlers\UpdateWorkStyleHandler;
use App\Domain\Attendance\Handlers\WarnMonthCloseDeadlineHandler;
use App\Domain\Attendance\Handlers\WarnUnsubmittedAttendanceHandler;
use App\Domain\AuthenticationKey\Commands\DisableAuthenticationKey;
use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\AuthenticationKey\Handlers\DisableAuthenticationKeyHandler;
use App\Domain\AuthenticationKey\Handlers\IssueAuthenticationKeyHandler;
use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromAttendanceMonthApproval;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromExpenseClaimApproval;
use App\Domain\BackOffice\Handlers\AssignBackOfficeTaskHandler;
use App\Domain\BackOffice\Handlers\ChangeBackOfficeTaskStatusHandler;
use App\Domain\BackOffice\Handlers\CreateBackOfficeTaskFromApprovalHandler;
use App\Domain\BackOffice\Handlers\CreateBackOfficeTaskFromAttendanceMonthApprovalHandler;
use App\Domain\BackOffice\Handlers\CreateBackOfficeTaskFromExpenseClaimApprovalHandler;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveGrantCancellation;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveGrant;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\ConfirmCompensatoryLeaveGrantsForMonth;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeaveGrantCancellation;
use App\Domain\CompensatoryLeave\Commands\ReturnCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\SyncCompensatoryLeaveGrant;
use App\Domain\CompensatoryLeave\Handlers\ApproveCompensatoryLeaveGrantCancellationHandler;
use App\Domain\CompensatoryLeave\Handlers\ApproveCompensatoryLeaveRequestHandler;
use App\Domain\CompensatoryLeave\Handlers\CancelCompensatoryLeaveGrantHandler;
use App\Domain\CompensatoryLeave\Handlers\CancelCompensatoryLeaveRequestHandler;
use App\Domain\CompensatoryLeave\Handlers\ConfirmCompensatoryLeaveGrantsForMonthHandler;
use App\Domain\CompensatoryLeave\Handlers\RequestCompensatoryLeaveGrantCancellationHandler;
use App\Domain\CompensatoryLeave\Handlers\RequestCompensatoryLeaveHandler;
use App\Domain\CompensatoryLeave\Handlers\ReturnCompensatoryLeaveRequestHandler;
use App\Domain\CompensatoryLeave\Handlers\SyncCompensatoryLeaveGrantHandler;
use App\Domain\Device\Commands\ClaimDevicePairing;
use App\Domain\Device\Commands\DeleteDevice;
use App\Domain\Device\Commands\DisableDevice;
use App\Domain\Device\Commands\EnableDevice;
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
use App\Domain\Device\Handlers\EnableDeviceHandler;
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
use App\Domain\ExpenseClaim\Commands\AddExpenseItem;
use App\Domain\ExpenseClaim\Commands\ApproveExpenseClaim;
use App\Domain\ExpenseClaim\Commands\CancelExpenseClaim;
use App\Domain\ExpenseClaim\Commands\DeleteExpenseClaim;
use App\Domain\ExpenseClaim\Commands\DraftExpenseClaim;
use App\Domain\ExpenseClaim\Commands\RemoveExpenseItem;
use App\Domain\ExpenseClaim\Commands\ReturnExpenseClaim;
use App\Domain\ExpenseClaim\Commands\SubmitExpenseClaim;
use App\Domain\ExpenseClaim\Commands\UpdateExpenseClaimTitle;
use App\Domain\ExpenseClaim\Commands\UpdateExpenseItem;
use App\Domain\ExpenseClaim\Handlers\AddExpenseItemHandler;
use App\Domain\ExpenseClaim\Handlers\ApproveExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\CancelExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\DeleteExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\DraftExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\RemoveExpenseItemHandler;
use App\Domain\ExpenseClaim\Handlers\ReturnExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\SubmitExpenseClaimHandler;
use App\Domain\ExpenseClaim\Handlers\UpdateExpenseClaimTitleHandler;
use App\Domain\ExpenseClaim\Handlers\UpdateExpenseItemHandler;
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
use App\Domain\ShiftSwap\Commands\ApproveShiftSwapRequest;
use App\Domain\ShiftSwap\Commands\CancelShiftSwapRequest;
use App\Domain\ShiftSwap\Commands\RequestShiftSwap;
use App\Domain\ShiftSwap\Commands\ReturnShiftSwapRequest;
use App\Domain\ShiftSwap\Handlers\ApproveShiftSwapRequestHandler;
use App\Domain\ShiftSwap\Handlers\CancelShiftSwapRequestHandler;
use App\Domain\ShiftSwap\Handlers\RequestShiftSwapHandler;
use App\Domain\ShiftSwap\Handlers\ReturnShiftSwapRequestHandler;
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
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\ApplyExternalHrImport;
use App\Domain\UserManagement\Commands\ApplyMembershipChange;
use App\Domain\UserManagement\Commands\CancelMembershipChange;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use App\Domain\UserManagement\Commands\CompleteOnboardingSsoLink;
use App\Domain\UserManagement\Commands\CompleteOnboardingWithLocalPassword;
use App\Domain\UserManagement\Commands\CreateGroup;
use App\Domain\UserManagement\Commands\CreateGroupType;
use App\Domain\UserManagement\Commands\CreateMembershipChangeDraft;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Domain\UserManagement\Commands\FailMembershipChange;
use App\Domain\UserManagement\Commands\LinkExternalIdentity;
use App\Domain\UserManagement\Commands\LinkSsoAccount;
use App\Domain\UserManagement\Commands\RecordLocalLogin;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Commands\RemoveMembership;
use App\Domain\UserManagement\Commands\ScheduleExistingMembershipChange;
use App\Domain\UserManagement\Commands\ScheduleMembershipChange;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserTerminationDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Domain\UserManagement\Commands\StartOnboardingSso;
use App\Domain\UserManagement\Commands\SyncUsersFromMs365;
use App\Domain\UserManagement\Commands\UnlinkExternalIdentity;
use App\Domain\UserManagement\Commands\UpdateGroup;
use App\Domain\UserManagement\Commands\UpdateGroupType;
use App\Domain\UserManagement\Commands\UpdateMembershipChange;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Handlers\AddMembershipHandler;
use App\Domain\UserManagement\Handlers\ApplyMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\CancelMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\ChangeFieldAuthorityHandler;
use App\Domain\UserManagement\Handlers\CompleteOnboardingSsoLinkHandler;
use App\Domain\UserManagement\Handlers\CompleteOnboardingWithLocalPasswordHandler;
use App\Domain\UserManagement\Handlers\CreateGroupHandler;
use App\Domain\UserManagement\Handlers\CreateGroupTypeHandler;
use App\Domain\UserManagement\Handlers\CreateMembershipChangeDraftHandler;
use App\Domain\UserManagement\Handlers\FailMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\LinkExternalIdentityHandler;
use App\Domain\UserManagement\Handlers\LinkSsoAccountHandler;
use App\Domain\UserManagement\Handlers\RecordLocalLoginHandler;
use App\Domain\UserManagement\Handlers\RecordSsoLoginHandler;
use App\Domain\UserManagement\Handlers\RemoveMembershipHandler;
use App\Domain\UserManagement\Handlers\ScheduleExistingMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\ScheduleMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\SetUserHireDateHandler;
use App\Domain\UserManagement\Handlers\SetUserTerminationDateHandler;
use App\Domain\UserManagement\Handlers\SetUserUsageStartDateHandler;
use App\Domain\UserManagement\Handlers\StartOnboardingSsoHandler;
use App\Domain\UserManagement\Handlers\SyncUsersFromMs365Handler;
use App\Domain\UserManagement\Handlers\UnlinkExternalIdentityHandler;
use App\Domain\UserManagement\Handlers\UpdateGroupHandler;
use App\Domain\UserManagement\Handlers\UpdateGroupTypeHandler;
use App\Domain\UserManagement\Handlers\UpdateMembershipChangeHandler;
use App\Domain\UserManagement\Handlers\UpdateUserProfileHandler;
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
        CreateGroup::class => CreateGroupHandler::class,
        AddMembership::class => AddMembershipHandler::class,
        AssignFeatureToGroup::class => AssignFeatureToGroupHandler::class,
        CreateRoleAssignment::class => CreateRoleAssignmentHandler::class,
        ScheduleMembershipChange::class => ScheduleMembershipChangeHandler::class,
        ApplyMembershipChange::class => ApplyMembershipChangeHandler::class,
        CancelMembershipChange::class => CancelMembershipChangeHandler::class,
        RemoveMembership::class => RemoveMembershipHandler::class,
        RemoveFeatureFromGroup::class => RemoveFeatureFromGroupHandler::class,
        SuspendUserFeature::class => SuspendUserFeatureHandler::class,
        RemoveUserFeatureSuspension::class => RemoveUserFeatureSuspensionHandler::class,
        RemoveRoleAssignment::class => RemoveRoleAssignmentHandler::class,
        LinkExternalIdentity::class => LinkExternalIdentityHandler::class,
        UnlinkExternalIdentity::class => UnlinkExternalIdentityHandler::class,
        ChangeFieldAuthority::class => ChangeFieldAuthorityHandler::class,
        ChangeRolePermissions::class => ChangeRolePermissionsHandler::class,
        UpdateGroup::class => UpdateGroupHandler::class,
        CreateGroupType::class => CreateGroupTypeHandler::class,
        CreateRole::class => CreateRoleHandler::class,
        FailMembershipChange::class => FailMembershipChangeHandler::class,
        UpdateUserProfile::class => UpdateUserProfileHandler::class,
        CreateUser::class => CreateUserHandler::class,
        UpdateGroupType::class => UpdateGroupTypeHandler::class,
        UpdateRole::class => UpdateRoleHandler::class,
        UpdateRoleAssignment::class => UpdateRoleAssignmentHandler::class,
        CreateMembershipChangeDraft::class => CreateMembershipChangeDraftHandler::class,
        UpdateMembershipChange::class => UpdateMembershipChangeHandler::class,
        ApplyExternalHrImport::class => ApplyExternalHrImportHandler::class,
        ScheduleExistingMembershipChange::class => ScheduleExistingMembershipChangeHandler::class,
        UploadAttachment::class => UploadAttachmentHandler::class,

        RegisterDevice::class => RegisterDeviceHandler::class,
        IssueDevicePairingClaim::class => IssueDevicePairingClaimHandler::class,
        ClaimDevicePairing::class => ClaimDevicePairingHandler::class,
        DisableDevice::class => DisableDeviceHandler::class,
        EnableDevice::class => EnableDeviceHandler::class,
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

        SetUserHireDate::class => SetUserHireDateHandler::class,
        SetUserTerminationDate::class => SetUserTerminationDateHandler::class,
        SetUserUsageStartDate::class => SetUserUsageStartDateHandler::class,
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
        CreateBackOfficeTaskFromExpenseClaimApproval::class => CreateBackOfficeTaskFromExpenseClaimApprovalHandler::class,
        CreateBackOfficeTaskFromAttendanceMonthApproval::class => CreateBackOfficeTaskFromAttendanceMonthApprovalHandler::class,
        AssignBackOfficeTask::class => AssignBackOfficeTaskHandler::class,
        ChangeBackOfficeTaskStatus::class => ChangeBackOfficeTaskStatusHandler::class,

        DraftExpenseClaim::class => DraftExpenseClaimHandler::class,
        AddExpenseItem::class => AddExpenseItemHandler::class,
        UpdateExpenseItem::class => UpdateExpenseItemHandler::class,
        RemoveExpenseItem::class => RemoveExpenseItemHandler::class,
        SubmitExpenseClaim::class => SubmitExpenseClaimHandler::class,
        ApproveExpenseClaim::class => ApproveExpenseClaimHandler::class,
        ReturnExpenseClaim::class => ReturnExpenseClaimHandler::class,
        CancelExpenseClaim::class => CancelExpenseClaimHandler::class,
        DeleteExpenseClaim::class => DeleteExpenseClaimHandler::class,
        UpdateExpenseClaimTitle::class => UpdateExpenseClaimTitleHandler::class,

        ClockIn::class => ClockInHandler::class,
        StartBreak::class => StartBreakHandler::class,
        EndBreak::class => EndBreakHandler::class,
        ClockOut::class => ClockOutHandler::class,
        CreateAttendanceDay::class => CreateAttendanceDayHandler::class,
        EditAttendanceDay::class => EditAttendanceDayHandler::class,
        AdjustAttendanceDailyCalculation::class => AdjustAttendanceDailyCalculationHandler::class,
        EditEmployeeCalendarEntry::class => EditEmployeeCalendarEntryHandler::class,
        DeleteAttendanceDay::class => DeleteAttendanceDayHandler::class,

        CreateCompanyCalendar::class => CreateCompanyCalendarHandler::class,
        UpdateCompanyCalendar::class => UpdateCompanyCalendarHandler::class,
        SetDefaultCompanyCalendar::class => SetDefaultCompanyCalendarHandler::class,
        CreateCompanyCalendarYear::class => CreateCompanyCalendarYearHandler::class,
        PublishCompanyCalendarYear::class => PublishCompanyCalendarYearHandler::class,
        UnpublishCompanyCalendarYear::class => UnpublishCompanyCalendarYearHandler::class,
        ArchiveCompanyCalendarYear::class => ArchiveCompanyCalendarYearHandler::class,
        UpdateCompanyCalendarDays::class => UpdateCompanyCalendarDaysHandler::class,
        DuplicateCompanyCalendarYear::class => DuplicateCompanyCalendarYearHandler::class,
        GenerateCompanyCalendarYears::class => GenerateCompanyCalendarYearsHandler::class,
        RegisterHolidayCalendarSource::class => RegisterHolidayCalendarSourceHandler::class,
        SyncHolidayCalendarSource::class => SyncHolidayCalendarSourceHandler::class,
        DisableHolidayCalendarSource::class => DisableHolidayCalendarSourceHandler::class,
        RevertLastHolidayCalendarSync::class => RevertLastHolidayCalendarSyncHandler::class,
        ApplyCalendarBulkOperation::class => ApplyCalendarBulkOperationHandler::class,
        RevertCalendarBulkOperation::class => RevertCalendarBulkOperationHandler::class,
        CreateWorkStyle::class => CreateWorkStyleHandler::class,
        CreateDefaultWorkStyle::class => CreateDefaultWorkStyleHandler::class,
        SetDefaultWorkStyle::class => SetDefaultWorkStyleHandler::class,
        UpdateWorkStyle::class => UpdateWorkStyleHandler::class,
        AssignUserWorkStyleForMonth::class => AssignUserWorkStyleForMonthHandler::class,
        RemoveUserWorkStyleMonthlyAssignment::class => RemoveUserWorkStyleMonthlyAssignmentHandler::class,
        CreateShiftPattern::class => CreateShiftPatternHandler::class,
        UpdateShiftPattern::class => UpdateShiftPatternHandler::class,
        GenerateEmployeeCalendarEntries::class => GenerateEmployeeCalendarEntriesHandler::class,
        GeneratePatternAttendanceDays::class => GeneratePatternAttendanceDaysHandler::class,
        GeneratePatternCalendarEntries::class => GeneratePatternCalendarEntriesHandler::class,
        AssignShiftPatternDay::class => AssignShiftPatternDayHandler::class,
        PublishEmployeeCalendarEntries::class => PublishEmployeeCalendarEntriesHandler::class,
        CreateRotationPattern::class => CreateRotationPatternHandler::class,
        AssignEmployeeRotation::class => AssignEmployeeRotationHandler::class,
        GenerateRotationCalendarEntries::class => GenerateRotationCalendarEntriesHandler::class,
        RecordAttendancePunch::class => RecordAttendancePunchHandler::class,
        CorrectAttendancePunch::class => CorrectAttendancePunchHandler::class,
        DeleteAttendancePunch::class => DeleteAttendancePunchHandler::class,
        DesignateLegalHoliday::class => DesignateLegalHolidayHandler::class,
        SubmitAttendanceMonth::class => SubmitAttendanceMonthHandler::class,
        ApproveAttendanceMonth::class => ApproveAttendanceMonthHandler::class,
        ReturnAttendanceMonth::class => ReturnAttendanceMonthHandler::class,
        CancelSubmittedAttendanceMonth::class => CancelSubmittedAttendanceMonthHandler::class,
        CloseAttendanceMonth::class => CloseAttendanceMonthHandler::class,
        RecalculateAttendanceMonthSnapshot::class => RecalculateAttendanceMonthSnapshotHandler::class,
        WarnUnsubmittedAttendance::class => WarnUnsubmittedAttendanceHandler::class,
        WarnMonthCloseDeadline::class => WarnMonthCloseDeadlineHandler::class,
        ExcludeAttendanceSubmissionReminder::class => ExcludeAttendanceSubmissionReminderHandler::class,

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

        RequestShiftSwap::class => RequestShiftSwapHandler::class,
        ApproveShiftSwapRequest::class => ApproveShiftSwapRequestHandler::class,
        ReturnShiftSwapRequest::class => ReturnShiftSwapRequestHandler::class,
        CancelShiftSwapRequest::class => CancelShiftSwapRequestHandler::class,

        SyncCompensatoryLeaveGrant::class => SyncCompensatoryLeaveGrantHandler::class,
        ConfirmCompensatoryLeaveGrantsForMonth::class => ConfirmCompensatoryLeaveGrantsForMonthHandler::class,
        RequestCompensatoryLeave::class => RequestCompensatoryLeaveHandler::class,
        ApproveCompensatoryLeaveRequest::class => ApproveCompensatoryLeaveRequestHandler::class,
        ReturnCompensatoryLeaveRequest::class => ReturnCompensatoryLeaveRequestHandler::class,
        CancelCompensatoryLeaveRequest::class => CancelCompensatoryLeaveRequestHandler::class,
        CancelCompensatoryLeaveGrant::class => CancelCompensatoryLeaveGrantHandler::class,
        RequestCompensatoryLeaveGrantCancellation::class => RequestCompensatoryLeaveGrantCancellationHandler::class,
        ApproveCompensatoryLeaveGrantCancellation::class => ApproveCompensatoryLeaveGrantCancellationHandler::class,
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
    | 注意: spatie/laravel-event-sourcingに移行済みのドメイン(Attachment/Integration/
    | AuthenticationKey/Device/DeviceAdminSession/Notification/Workflow/BackOffice/
    | PaidLeave/SpecialLeave/CompensatoryLeave/User/Attendance)のProjectorはここではなく
    | Spatie\EventSourcing\EventHandlers\Projectors\Projectorのサブクラスとして実装し、
    | config/event-sourcing.phpのauto_discover_projectors_and_reactorsで自動検出させる
    | (docs/29-event-sourcing-framework-migration.md参照)。AttendanceDailyCalculationProjector
    | もAttendanceのspatie移行に伴いこちらへ移設したため、この配列は現時点で空になっている。
    |
    */
    'projectors' => [
    ],

];
