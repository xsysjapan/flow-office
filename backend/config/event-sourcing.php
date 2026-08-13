<?php

use App\Domain\AccessControl\Events\FeatureAssignedToGroup;
use App\Domain\AccessControl\Events\FeatureRemovedFromGroup;
use App\Domain\AccessControl\Events\RoleAssignmentCreated;
use App\Domain\AccessControl\Events\RoleAssignmentRemoved;
use App\Domain\AccessControl\Events\RoleAssignmentUpdated;
use App\Domain\AccessControl\Events\RoleCreated;
use App\Domain\AccessControl\Events\RolePermissionsChanged;
use App\Domain\AccessControl\Events\RoleUpdated;
use App\Domain\AccessControl\Events\UserFeatureSuspended;
use App\Domain\AccessControl\Events\UserFeatureSuspensionRemoved;
use App\Domain\Attachment\Events\AttachmentDownloaded;
use App\Domain\Attachment\Events\AttachmentUploaded;
use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthLocked;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthShared;
use App\Domain\Attendance\Events\AttendanceMonthSnapshotRecalculated;
use App\Domain\Attendance\Events\AttendanceMonthSubmissionCancelled;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\Attendance\Events\AttendanceMonthUnlocked;
use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Events\AttendancePunchDeleted;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\Attendance\Events\AttendanceSubmissionReminderExcluded;
use App\Domain\Attendance\Events\CalendarBulkOperationApplied;
use App\Domain\Attendance\Events\CalendarBulkOperationReverted;
use App\Domain\Attendance\Events\CompanyCalendarCreated;
use App\Domain\Attendance\Events\CompanyCalendarDaysUpdated;
use App\Domain\Attendance\Events\CompanyCalendarDefaultChanged;
use App\Domain\Attendance\Events\CompanyCalendarDeleted;
use App\Domain\Attendance\Events\CompanyCalendarUpdated;
use App\Domain\Attendance\Events\CompanyCalendarYearArchived;
use App\Domain\Attendance\Events\CompanyCalendarYearBatchGenerated;
use App\Domain\Attendance\Events\CompanyCalendarYearCreated;
use App\Domain\Attendance\Events\CompanyCalendarYearDeleted;
use App\Domain\Attendance\Events\CompanyCalendarYearPublished;
use App\Domain\Attendance\Events\CompanyCalendarYearUnpublished;
use App\Domain\Attendance\Events\EmployeeCalendarEntryAssigned;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPlanChanged;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPublished;
use App\Domain\Attendance\Events\EmployeeRotationAssigned;
use App\Domain\Attendance\Events\HolidayCalendarSourceDeleted;
use App\Domain\Attendance\Events\HolidayCalendarSourceDisabled;
use App\Domain\Attendance\Events\HolidayCalendarSourceRegistered;
use App\Domain\Attendance\Events\HolidayCalendarSourceSynced;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncFailed;
use App\Domain\Attendance\Events\HolidayCalendarSourceSyncReverted;
use App\Domain\Attendance\Events\HolidayCalendarSourceUpdated;
use App\Domain\Attendance\Events\LegalHolidayDesignated;
use App\Domain\Attendance\Events\RotationPatternCreated;
use App\Domain\Attendance\Events\ShiftPatternCreated;
use App\Domain\Attendance\Events\ShiftPatternUpdated;
use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\Attendance\Events\UserWorkStyleMonthlyAssignmentRemoved;
use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\Attendance\Events\WorkStyleDefaultChanged;
use App\Domain\Attendance\Events\WorkStyleUpdated;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use App\Domain\BackOffice\Events\BackOfficeTaskAssigned;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantConfirmed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantRemoved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantSynced;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageDesignated;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageReversed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsed;
use App\Domain\Device\Events\DeviceDeleted;
use App\Domain\Device\Events\DeviceDisabled;
use App\Domain\Device\Events\DeviceEnabled;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\Device\Events\DevicePairingClaimIssued;
use App\Domain\Device\Events\DeviceRegistered;
use App\Domain\Device\Events\DeviceRevoked;
use App\Domain\Device\Events\DeviceRoleAssigned;
use App\Domain\Device\Events\DeviceScopeGranted;
use App\Domain\Device\Events\DeviceSettingsUpdated;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimLocked;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimShared;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimTitleUpdated;
use App\Domain\ExpenseClaim\Events\ExpenseClaimUnlocked;
use App\Domain\ExpenseClaim\Events\ExpenseItemAdded;
use App\Domain\ExpenseClaim\Events\ExpenseItemRemoved;
use App\Domain\ExpenseClaim\Events\ExpenseItemUpdated;
use App\Domain\Export\Events\ExportCreated;
use App\Domain\Integration\Events\ApplicationIntegrationRegistered;
use App\Domain\Integration\Events\ApplicationIntegrationRevoked;
use App\Domain\Integration\Events\ApplicationIntegrationTokenReissued;
use App\Domain\Notification\Events\NotificationConfirmed;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
use App\Domain\PaidLeave\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeave\Events\PaidLeaveUsageReversed;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestApproved;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestCancelled;
use App\Domain\ShiftSwap\Events\ShiftSwapRequested;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestReturned;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageDesignated;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageReversed;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Domain\SystemSettings\Events\SystemSettingsUpdated;
use App\Domain\UserManagement\Events\ExternalHrImportApplied;
use App\Domain\UserManagement\Events\ExternalIdentityLinked;
use App\Domain\UserManagement\Events\ExternalIdentityUnlinked;
use App\Domain\UserManagement\Events\GroupCreated;
use App\Domain\UserManagement\Events\GroupTypeCreated;
use App\Domain\UserManagement\Events\GroupTypeUpdated;
use App\Domain\UserManagement\Events\GroupUpdated;
use App\Domain\UserManagement\Events\MembershipAdded;
use App\Domain\UserManagement\Events\MembershipChangeSetApplied;
use App\Domain\UserManagement\Events\MembershipChangeSetCancelled;
use App\Domain\UserManagement\Events\MembershipChangeSetCreated;
use App\Domain\UserManagement\Events\MembershipChangeSetFailed;
use App\Domain\UserManagement\Events\MembershipChangeSetScheduled;
use App\Domain\UserManagement\Events\MembershipChangeSetUpdated;
use App\Domain\UserManagement\Events\MembershipPrimaryChanged;
use App\Domain\UserManagement\Events\MembershipRemoved;
use App\Domain\UserManagement\Events\UserCreatedFromSsoLogin;
use App\Domain\UserManagement\Events\UserCreatedManually;
use App\Domain\UserManagement\Events\UserFieldAuthorityChanged;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserLoggedIn;
use App\Domain\UserManagement\Events\UserMigratedFromLegacy;
use App\Domain\UserManagement\Events\UserOnboardedAsAdmin;
use App\Domain\UserManagement\Events\UserProfileUpdated;
use App\Domain\UserManagement\Events\UserRolesChanged;
use App\Domain\UserManagement\Events\UserRolesMigratedFromLegacy;
use App\Domain\UserManagement\Events\UserSsoAccountLinked;
use App\Domain\UserManagement\Events\UserSyncedFromMs365;
use App\Domain\UserManagement\Events\UserTerminationDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use Spatie\EventSourcing\EventSerializers\JsonEventSerializer;
use Spatie\EventSourcing\Snapshots\EloquentSnapshot;
use Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\Repositories\EloquentStoredEventRepository;
use Spatie\EventSourcing\Support\CarbonNormalizer;
use Spatie\EventSourcing\Support\ModelIdentifierNormalizer;
use Spatie\EventSourcing\Support\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

return [

    /*
     * These directories will be scanned for projectors and reactors. They
     * will be registered to Projectionist automatically.
     */
    'auto_discover_projectors_and_reactors' => [
        app()->path(),
    ],

    /*
     * This directory will be used as the base path when scanning
     * for projectors and reactors.
     */
    'auto_discover_base_path' => base_path(),

    /*
     * Projectors are classes that build up projections. You can create them by performing
     * `php artisan event-sourcing:create-projector`. When not using auto-discovery,
     * Projectors can be registered in this array or a service provider.
     */
    'projectors' => [
        // App\Projectors\YourProjector::class
    ],

    /*
     * Reactors are classes that handle side-effects. You can create them by performing
     * `php artisan event-sourcing:create-reactor`. When not using auto-discovery
     * Reactors can be registered in this array or a service provider.
     */
    'reactors' => [
        // App\Reactors\YourReactor::class
    ],

    /*
     * A queue is used to guarantee that all events get passed to the projectors in
     * the right order. Here you can set of the name of the queue.
     */
    'queue' => env('EVENT_PROJECTOR_QUEUE_NAME', null),

    /*
     * When a Projector or Reactor throws an exception the event Projectionist can catch it
     * so all other projectors and reactors can still do their work. The exception will
     * be passed to the `handleException` method on that Projector or Reactor.
     */
    'catch_exceptions' => env('EVENT_PROJECTOR_CATCH_EXCEPTIONS', false),

    /*
     * This class is responsible for storing events in the EloquentStoredEventRepository.
     * To add extra behaviour you can change this to a class of your own. It should
     * extend the \Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent model.
     */
    'stored_event_model' => EloquentStoredEvent::class,

    /*
     * This class is responsible for storing events. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\StoredEvents\Repositories\EloquentStoredEventRepository.
     */
    'stored_event_repository' => EloquentStoredEventRepository::class,

    /*
     * This class is responsible for storing snapshots. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository.
     */
    'snapshot_repository' => EloquentSnapshotRepository::class,

    /*
     * This class is responsible for storing events in the EloquentSnapshotRepository.
     * To add extra behaviour you can change this to a class of your own. It should
     * extend the \Spatie\EventSourcing\Snapshots\EloquentSnapshot model.
     */
    'snapshot_model' => EloquentSnapshot::class,

    /*
     * This class is responsible for handling stored events. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\StoredEvents\HandleDomainEventJob.
     */
    'stored_event_job' => HandleStoredEventJob::class,

    /*
     * backend/CLAUDE.md の原則により、stored_events.event_class にはPHPクラス名ではなく
     * 短い文字列(例: 'attachment.uploaded')を保存する。イベントクラスの名前空間・クラス名を
     * 後から変更しても既存イベントの再生(event-sourcing:replay)に影響しないようにするため、
     * event_class_map への登録を必須にする。
     */
    'enforce_event_class_map' => true,

    /*
     * Similar to Relation::morphMap() you can define which alias responds to which
     * event class. This allows you to change the namespace or class names
     * of your events but still handle older events correctly.
     *
     * ドメインイベントを追加・移行したら必ずここに <aggregate>.<past_tense_verb> 形式の
     * 短い文字列を登録すること(docs/17-events.md の命名規則。.claude/skills/add-domain-event 参照)。
     */
    'event_class_map' => [
        'group.created' => GroupCreated::class,
        'membership.added' => MembershipAdded::class,
        'feature.assigned_to_group' => FeatureAssignedToGroup::class,
        'role_assignment.created' => RoleAssignmentCreated::class,
        'membership_change_set.scheduled' => MembershipChangeSetScheduled::class,
        'membership_change_set.applied' => MembershipChangeSetApplied::class,
        'membership_change_set.cancelled' => MembershipChangeSetCancelled::class,
        'membership.removed' => MembershipRemoved::class,
        'membership.primary_changed' => MembershipPrimaryChanged::class,
        'user.profile_updated' => UserProfileUpdated::class,
        'user.created_manually' => UserCreatedManually::class,
        'group_type.updated' => GroupTypeUpdated::class,
        'role.updated' => RoleUpdated::class,
        'role_assignment.updated' => RoleAssignmentUpdated::class,
        'membership_change_set.created' => MembershipChangeSetCreated::class,
        'membership_change_set.updated' => MembershipChangeSetUpdated::class,
        'external_hr.import_applied' => ExternalHrImportApplied::class,
        'feature.removed_from_group' => FeatureRemovedFromGroup::class,
        'user.feature_suspended' => UserFeatureSuspended::class,
        'user.feature_suspension_removed' => UserFeatureSuspensionRemoved::class,
        'role_assignment.removed' => RoleAssignmentRemoved::class,
        'external_identity.linked' => ExternalIdentityLinked::class,
        'external_identity.unlinked' => ExternalIdentityUnlinked::class,
        'user.field_authority_changed' => UserFieldAuthorityChanged::class,
        'role.permissions_changed' => RolePermissionsChanged::class,
        'system_settings.updated' => SystemSettingsUpdated::class,
        'group.updated' => GroupUpdated::class,
        'group_type.created' => GroupTypeCreated::class,
        'role.created' => RoleCreated::class,
        'membership_change_set.failed' => MembershipChangeSetFailed::class,
        'attachment.uploaded' => AttachmentUploaded::class,
        'attachment.downloaded' => AttachmentDownloaded::class,
        'export.created' => ExportCreated::class,

        'attendance_day.created' => AttendanceDayCreated::class,
        'attendance_day.edited' => AttendanceDayEdited::class,
        'attendance_day.calculated' => AttendanceDayCalculated::class,
        'attendance_day.daily_calculation_adjusted' => AttendanceDailyCalculationAdjusted::class,
        'attendance_day.deleted' => AttendanceDayDeleted::class,
        'attendance_day.live_status_synced' => AttendanceDayLiveStatusSynced::class,
        'attendance_day.synced_from_punches' => AttendanceDaySyncedFromPunches::class,
        'attendance_day.break_auto_inserted' => AttendanceBreakAutoInserted::class,

        'attendance_punch.recorded' => AttendancePunchRecorded::class,
        'attendance_punch.corrected' => AttendancePunchCorrected::class,
        'attendance_punch.deleted' => AttendancePunchDeleted::class,

        'attendance_month.submitted' => AttendanceMonthSubmitted::class,
        'attendance_month.approved' => AttendanceMonthApproved::class,
        'attendance_month.returned' => AttendanceMonthReturned::class,
        'attendance_month.submission_cancelled' => AttendanceMonthSubmissionCancelled::class,
        'attendance_month.closed' => AttendanceMonthClosed::class,
        'attendance_month.locked' => AttendanceMonthLocked::class,
        'attendance_month.shared' => AttendanceMonthShared::class,
        'attendance_month.unlocked' => AttendanceMonthUnlocked::class,
        'attendance_month.snapshot_recalculated' => AttendanceMonthSnapshotRecalculated::class,

        'attendance.submission_reminder_excluded' => AttendanceSubmissionReminderExcluded::class,

        'company_calendar.created' => CompanyCalendarCreated::class,
        'company_calendar.updated' => CompanyCalendarUpdated::class,
        'company_calendar.default_changed' => CompanyCalendarDefaultChanged::class,
        'company_calendar.deleted' => CompanyCalendarDeleted::class,
        'company_calendar_year.created' => CompanyCalendarYearCreated::class,
        'company_calendar_year.days_updated' => CompanyCalendarDaysUpdated::class,
        'company_calendar_year.published' => CompanyCalendarYearPublished::class,
        'company_calendar_year.unpublished' => CompanyCalendarYearUnpublished::class,
        'company_calendar_year.archived' => CompanyCalendarYearArchived::class,
        'company_calendar_year.deleted' => CompanyCalendarYearDeleted::class,
        'company_calendar_year.batch_generated' => CompanyCalendarYearBatchGenerated::class,

        'holiday_calendar_source.registered' => HolidayCalendarSourceRegistered::class,
        'holiday_calendar_source.updated' => HolidayCalendarSourceUpdated::class,
        'holiday_calendar_source.synced' => HolidayCalendarSourceSynced::class,
        'holiday_calendar_source.sync_failed' => HolidayCalendarSourceSyncFailed::class,
        'holiday_calendar_source.disabled' => HolidayCalendarSourceDisabled::class,
        'holiday_calendar_source.deleted' => HolidayCalendarSourceDeleted::class,
        'holiday_calendar_source.sync_reverted' => HolidayCalendarSourceSyncReverted::class,

        'calendar_bulk_operation.applied' => CalendarBulkOperationApplied::class,
        'calendar_bulk_operation.reverted' => CalendarBulkOperationReverted::class,

        'work_style.created' => WorkStyleCreated::class,
        'work_style.default_changed' => WorkStyleDefaultChanged::class,
        'work_style.updated' => WorkStyleUpdated::class,

        'shift_pattern.created' => ShiftPatternCreated::class,
        'shift_pattern.updated' => ShiftPatternUpdated::class,

        'employee_calendar_entry.assigned' => EmployeeCalendarEntryAssigned::class,
        'employee_calendar_entry.plan_changed' => EmployeeCalendarEntryPlanChanged::class,
        'employee_calendar_entry.published' => EmployeeCalendarEntryPublished::class,

        'rotation_pattern.created' => RotationPatternCreated::class,

        'employee_rotation.assigned' => EmployeeRotationAssigned::class,

        'user_work_style_monthly_assignment.assigned' => UserWorkStyleAssignedForMonth::class,
        'user_work_style_monthly_assignment.removed' => UserWorkStyleMonthlyAssignmentRemoved::class,

        'attendance.legal_holiday_designated' => LegalHolidayDesignated::class,

        'application_integration.registered' => ApplicationIntegrationRegistered::class,
        'application_integration.token_reissued' => ApplicationIntegrationTokenReissued::class,
        'application_integration.revoked' => ApplicationIntegrationRevoked::class,

        'authentication_key.issued' => AuthenticationKeyIssued::class,
        'authentication_key.disabled' => AuthenticationKeyDisabled::class,

        'device.registered' => DeviceRegistered::class,
        'device.paired' => DevicePaired::class,
        'device.pairing_claim_issued' => DevicePairingClaimIssued::class,
        'device.disabled' => DeviceDisabled::class,
        'device.enabled' => DeviceEnabled::class,
        'device.revoked' => DeviceRevoked::class,
        'device.deleted' => DeviceDeleted::class,
        'device.role_assigned' => DeviceRoleAssigned::class,
        'device.scope_granted' => DeviceScopeGranted::class,
        'device.settings_updated' => DeviceSettingsUpdated::class,

        'device_admin_session.started' => DeviceAdminSessionStarted::class,
        'device_admin_session.ended' => DeviceAdminSessionEnded::class,

        'notification.queued' => NotificationQueued::class,
        'notification.sent' => NotificationSent::class,
        'notification.confirmed' => NotificationConfirmed::class,

        'workflow_request.drafted' => WorkflowRequestDrafted::class,
        'workflow_request.submitted' => WorkflowRequestSubmitted::class,
        'workflow_request.approved' => WorkflowRequestApproved::class,
        'workflow_request.returned' => WorkflowRequestReturned::class,
        'workflow_request.cancelled' => WorkflowRequestCancelled::class,

        'paid_leave.granted' => PaidLeaveGranted::class,
        'paid_leave.requested' => PaidLeaveRequested::class,
        'paid_leave.request_approved' => PaidLeaveRequestApproved::class,
        'paid_leave.request_returned' => PaidLeaveRequestReturned::class,
        'paid_leave.request_cancelled' => PaidLeaveRequestCancelled::class,
        'paid_leave.request_shared' => PaidLeaveRequestShared::class,
        'paid_leave.usage_designated' => PaidLeaveUsageDesignated::class,
        'paid_leave.used' => PaidLeaveUsed::class,
        'paid_leave.usage_reversed' => PaidLeaveUsageReversed::class,
        'paid_leave.warning_raised' => PaidLeaveWarningRaised::class,

        'special_leave.granted' => SpecialLeaveGranted::class,
        'special_leave.requested' => SpecialLeaveRequested::class,
        'special_leave.request_approved' => SpecialLeaveRequestApproved::class,
        'special_leave.request_returned' => SpecialLeaveRequestReturned::class,
        'special_leave.request_cancelled' => SpecialLeaveRequestCancelled::class,
        'special_leave.request_shared' => SpecialLeaveRequestShared::class,
        'special_leave.usage_designated' => SpecialLeaveUsageDesignated::class,
        'special_leave.used' => SpecialLeaveUsed::class,
        'special_leave.usage_reversed' => SpecialLeaveUsageReversed::class,

        'compensatory_leave.grant_synced' => CompensatoryLeaveGrantSynced::class,
        'compensatory_leave.grant_removed' => CompensatoryLeaveGrantRemoved::class,
        'compensatory_leave.grant_confirmed' => CompensatoryLeaveGrantConfirmed::class,
        'compensatory_leave.grant_cancelled' => CompensatoryLeaveGrantCancelled::class,
        'compensatory_leave.usage_designated' => CompensatoryLeaveUsageDesignated::class,
        'compensatory_leave.used' => CompensatoryLeaveUsed::class,
        'compensatory_leave.usage_reversed' => CompensatoryLeaveUsageReversed::class,
        'compensatory_leave.requested' => CompensatoryLeaveRequested::class,
        'compensatory_leave.request_shared' => CompensatoryLeaveRequestShared::class,
        'compensatory_leave.request_approved' => CompensatoryLeaveRequestApproved::class,
        'compensatory_leave.request_returned' => CompensatoryLeaveRequestReturned::class,
        'compensatory_leave.request_cancelled' => CompensatoryLeaveRequestCancelled::class,

        'shift_swap.requested' => ShiftSwapRequested::class,
        'shift_swap.request_approved' => ShiftSwapRequestApproved::class,
        'shift_swap.request_returned' => ShiftSwapRequestReturned::class,
        'shift_swap.request_cancelled' => ShiftSwapRequestCancelled::class,
        'shift_swap.request_shared' => ShiftSwapRequestShared::class,

        'user.onboarded_as_admin' => UserOnboardedAsAdmin::class,
        'user.created_from_sso_login' => UserCreatedFromSsoLogin::class,
        'user.synced_from_ms365' => UserSyncedFromMs365::class,
        'user.sso_account_linked' => UserSsoAccountLinked::class,
        'user.logged_in' => UserLoggedIn::class,
        'user.roles_changed' => UserRolesChanged::class,
        'user.roles_migrated_from_legacy' => UserRolesMigratedFromLegacy::class,
        'user.hire_date_set' => UserHireDateSet::class,
        'user.termination_date_set' => UserTerminationDateSet::class,
        'user.usage_start_date_set' => UserUsageStartDateSet::class,
        'user.migrated_from_legacy' => UserMigratedFromLegacy::class,

        'backoffice_task.created' => BackOfficeTaskCreated::class,
        'backoffice_task.assigned' => BackOfficeTaskAssigned::class,
        'backoffice_task.completed' => BackOfficeTaskCompleted::class,
        'backoffice_task.status_changed' => BackOfficeTaskStatusChanged::class,

        'expense_claim.drafted' => ExpenseClaimDrafted::class,
        'expense_claim.item_added' => ExpenseItemAdded::class,
        'expense_claim.item_updated' => ExpenseItemUpdated::class,
        'expense_claim.item_removed' => ExpenseItemRemoved::class,
        'expense_claim.submitted' => ExpenseClaimSubmitted::class,
        'expense_claim.approved' => ExpenseClaimApproved::class,
        'expense_claim.returned' => ExpenseClaimReturned::class,
        'expense_claim.cancelled' => ExpenseClaimCancelled::class,
        'expense_claim.deleted' => ExpenseClaimDeleted::class,
        'expense_claim.title_updated' => ExpenseClaimTitleUpdated::class,
        'expense_claim.locked' => ExpenseClaimLocked::class,
        'expense_claim.unlocked' => ExpenseClaimUnlocked::class,
        'expense_claim.shared' => ExpenseClaimShared::class,
    ],

    /*
     * This class is responsible for serializing events. By default an event will be serialized
     * and stored as json. You can customize the class name. A valid serializer
     * should implement Spatie\EventSourcing\EventSerializers\EventSerializer.
     */
    'event_serializer' => JsonEventSerializer::class,

    /*
     * These classes normalize and restore your events when they're serialized. They allow
     * you to efficiently store PHP objects like Carbon instances, Eloquent models, and
     * Collections. If you need to store other complex data, you can add your own normalizers
     * to the chain. See https://symfony.com/doc/current/components/serializer.html#normalizers
     */
    'event_normalizers' => [
        CarbonNormalizer::class,
        ModelIdentifierNormalizer::class,
        DateTimeNormalizer::class,
        ArrayDenormalizer::class,
        ObjectNormalizer::class,
    ],

    /*
     * In production, you likely don't want the package to auto-discover the event handlers
     * on every request. The package can cache all registered event handlers.
     * More info:
     * https://spatie.be/docs/laravel-event-sourcing/v7/advanced-usage/discovering-projectors-and-reactors#content-caching-discovered-projectors-and-reactors
     *
     * Here you can specify where the cache should be stored.
     */
    'cache_path' => base_path('bootstrap/cache'),

    /*
     * When storable events are fired from aggregates roots, the package can fire off these
     * events as regular events as well.
     */

    'dispatch_events_from_aggregate_roots' => false,

    /*
     * This setting determines which column is used to order events when retrieving
     * events for a specific aggregate.
     *
     * Options:
     * - 'id' (default): Orders by the auto-incrementing ID column. This is the traditional
     *   behavior but can cause MySQL "Out of sort memory" errors with large event payloads
     *   because it requires a filesort operation.
     *
     * - 'aggregate_version': Orders by the aggregate_version column. This is semantically
     *   correct for event sourcing and uses the existing (aggregate_uuid, aggregate_version)
     *   index, avoiding filesort operations and preventing memory issues with large payloads.
     *
     * Note: This only affects queries filtered by aggregate_uuid. Global event queries
     * (without uuid filter) always use 'id' for proper cross-aggregate ordering.
     */
    'aggregate_event_order_column' => 'aggregate_version',
];
