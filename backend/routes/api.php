<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AdminCommandController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceImportPreviewController;
use App\Http\Controllers\Api\AttendancePunchController;
use App\Http\Controllers\Api\AttendanceSubmissionReminderExclusionController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthenticationKeyController;
use App\Http\Controllers\Api\BackOfficeTaskController;
use App\Http\Controllers\Api\CalendarBulkOperationController;
use App\Http\Controllers\Api\CompanyCalendarController;
use App\Http\Controllers\Api\CompensatoryLeaveController;
use App\Http\Controllers\Api\DevApplyMembershipChangesController;
use App\Http\Controllers\Api\DevDatabaseResetController;
use App\Http\Controllers\Api\DeviceAdminController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceIdentityController;
use App\Http\Controllers\Api\DevicePunchController;
use App\Http\Controllers\Api\EffectiveAccessController;
use App\Http\Controllers\Api\EmployeeCalendarEntryController;
use App\Http\Controllers\Api\EmployeeRotationAssignmentController;
use App\Http\Controllers\Api\EmploymentCategoryController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseClaimController;
use App\Http\Controllers\Api\ExpenseEntryPresetController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HolidayCalendarSourceController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LegalHolidayDesignationController;
use App\Http\Controllers\Api\MockOidcUserController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PaidLeaveController;
use App\Http\Controllers\Api\PublicSystemSettingController;
use App\Http\Controllers\Api\RequestTypeController;
use App\Http\Controllers\Api\RotationPatternController;
use App\Http\Controllers\Api\ShiftPatternController;
use App\Http\Controllers\Api\ShiftSwapRequestController;
use App\Http\Controllers\Api\SpecialLeaveController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\UserWorkStyleMonthlyAssignmentController;
use App\Http\Controllers\Api\WorkflowRequestController;
use App\Http\Controllers\Api\WorkStyleController;
use Illuminate\Support\Facades\Route;

// --- 初回オンボーディング (docs/06-usecases-auth.md UC-000) ---
// Entra ID SSO自体がまだ設定されていない状態でも呼べる必要があるため認証不要。
// 完了済み(system_settings.onboarding_completed_at設定済み)なら以後は422を返す
// (OnboardingController・StartOnboardingSsoHandler/CompleteOnboardingWithLocalPasswordHandler参照)。
Route::get('/onboarding/status', [OnboardingController::class, 'status']);
Route::post('/onboarding/sso', [OnboardingController::class, 'storeSso']);
Route::post('/onboarding/local', [OnboardingController::class, 'storeLocal']);

// --- 認証 (docs/06-usecases-auth.md) ---
Route::prefix('auth')->group(function () {
    Route::get('/microsoft/redirect', [AuthController::class, 'redirect']);
    Route::get('/microsoft/callback', [AuthController::class, 'callback']);
    Route::post('/token', [AuthController::class, 'token']);
    // SSOを設定しなかった場合のローカルパスワードログイン。ブルートフォース対策として
    // 1分あたり5回に制限する(このアプリで初めてパスワード認証を扱うため)。
    Route::post('/local-login', [AuthController::class, 'localLogin'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->middleware('ability:profile:self:read');
        Route::post('/logout', [AuthController::class, 'logout']);
        // UC-004: ローカルパスワードでログイン中のユーザーが、任意のタイミングで自分の
        // アカウントにMicrosoft 365アカウントを紐づける。
        Route::get('/microsoft/link-redirect', [AuthController::class, 'linkRedirect']);
    });
});

// mock-oidc(ローカル開発用OIDCモックサーバー)がログイン画面のユーザー一覧を取得するための
// 開発専用エンドポイント。認証不要(ログイン前に呼ばれるため)。MICROSOFT_MOCK_ENABLED=false
// では404を返す(MockOidcUserController参照)。
Route::get('/dev/mock-users', [MockOidcUserController::class, 'index']);

// Playwright E2Eテスト(frontend/e2e/)の実行開始時にDBをリセットするための開発専用
// エンドポイント。認証不要(テスト実行の最初期、ログイン前に呼ばれるため)。
// MICROSOFT_MOCK_ENABLED=falseでは404を返す(DevDatabaseResetController参照)。
Route::post('/dev/reset-database', DevDatabaseResetController::class);
Route::post('/dev/apply-membership-changes', DevApplyMembershipChangesController::class);

Route::middleware(['auth:sanctum', 'account.active', 'feature.route'])->group(function () {
    Route::get('/access/me', EffectiveAccessController::class);
    Route::middleware(['feature:administration.users'])->prefix('admin/user-management')->group(function () {
        Route::get('/group-types', [UserManagementController::class, 'groupTypes'])->middleware('permission:group_type.view,any');
        Route::get('/groups', [UserManagementController::class, 'groups'])->middleware('permission:group.view,any');
        Route::get('/membership-change-sets', [UserManagementController::class, 'changeSets'])->middleware('permission:group.change.schedule,any');
        Route::get('/external-identities', [UserManagementController::class, 'externalIdentities'])->middleware('permission:external_identity.view,any');
        Route::get('/field-authorities', [UserManagementController::class, 'fieldAuthorities'])->middleware('permission:field_authority.view,any');
        Route::post('/external-hr/import-preview', [UserManagementController::class, 'externalHrImportPreview'])->middleware('permission:external_hr.import,any');
        Route::post('/external-hr/import', [UserManagementController::class, 'applyExternalHrImport'])->middleware('permission:external_hr.import,any');
        Route::post('/groups', [UserManagementController::class, 'storeGroup'])->middleware('permission:group.create,any');
        Route::post('/group-types', [UserManagementController::class, 'storeGroupType'])->middleware('permission:group_type.create,any');
        Route::patch('/group-types/{groupType}', [UserManagementController::class, 'updateGroupType'])->middleware('permission:group_type.update,any');
        Route::patch('/groups/{group}', [UserManagementController::class, 'updateGroup'])->middleware('permission:group.update');
        Route::post('/memberships', [UserManagementController::class, 'storeMembership'])->middleware('permission:group.membership.update,any');
        Route::delete('/users/{user}/groups/{group}', [UserManagementController::class, 'destroyMembership'])->middleware('permission:group.membership.update');
        Route::post('/users/{user}/external-identities', [UserManagementController::class, 'linkExternalIdentity'])->middleware('permission:external_identity.manage');
        Route::delete('/external-identities/{identity}', [UserManagementController::class, 'unlinkExternalIdentity'])->middleware('permission:external_identity.manage,any');
        Route::put('/field-authorities/{fieldKey}', [UserManagementController::class, 'updateFieldAuthority'])->middleware('permission:field_authority.update,any');
        Route::post('/membership-change-sets', [UserManagementController::class, 'scheduleChange'])->middleware('permission:group.change.schedule,any');
        Route::post('/membership-change-sets/drafts', [UserManagementController::class, 'draftChange'])->middleware('permission:group.change.schedule,any');
        Route::patch('/membership-change-sets/{changeSet}', [UserManagementController::class, 'updateChange'])->middleware('permission:group.change.schedule,any');
        Route::post('/membership-change-sets/{changeSet}/apply', [UserManagementController::class, 'applyChange'])->middleware('permission:group.change.schedule,any');
        Route::post('/membership-change-sets/{changeSet}/schedule', [UserManagementController::class, 'scheduleExistingChange'])->middleware('permission:group.change.schedule,any');
        Route::post('/membership-change-sets/{changeSet}/cancel', [UserManagementController::class, 'cancelChange'])->middleware('permission:group.change.schedule,any');
    });
    Route::middleware(['feature:administration.users'])->prefix('admin/access-control')->group(function () {
        Route::get('/features', [AccessControlController::class, 'features'])->middleware('permission:feature.view,any');
        Route::get('/permissions', [AccessControlController::class, 'permissions'])->middleware('permission:role.view,any');
        Route::get('/roles', [AccessControlController::class, 'roles'])->middleware('permission:role.view,any');
        Route::get('/role-assignments', [AccessControlController::class, 'roleAssignments'])->middleware('permission:role.view,any');
        Route::get('/feature-suspensions', [AccessControlController::class, 'suspensions'])->middleware('permission:feature.view,any');
        Route::post('/roles', [AccessControlController::class, 'storeRole'])->middleware('permission:role.create,any');
        Route::post('/roles/{role}/clone', [AccessControlController::class, 'cloneRole'])->middleware('permission:role.create,any');
        Route::patch('/roles/{role}', [AccessControlController::class, 'updateRole'])->middleware('permission:role.update,any');
        Route::post('/groups/{group}/features', [AccessControlController::class, 'assignFeature'])->middleware('permission:feature.assign');
        Route::delete('/groups/{group}/features/{feature}', [AccessControlController::class, 'removeFeature'])->middleware('permission:feature.assign');
        Route::post('/feature-suspensions', [AccessControlController::class, 'suspendFeature'])->middleware('permission:feature.assign,any');
        Route::delete('/feature-suspensions/{suspension}', [AccessControlController::class, 'removeSuspension'])->middleware('permission:feature.assign,any');
        Route::post('/role-assignments', [AccessControlController::class, 'storeRoleAssignment'])->middleware('permission:role.assign,any');
        Route::delete('/role-assignments/{assignment}', [AccessControlController::class, 'destroyRoleAssignment'])->middleware('permission:role.assign,any');
        Route::patch('/role-assignments/{assignment}', [AccessControlController::class, 'updateRoleAssignment'])->middleware('permission:role.assign,any');
        Route::put('/roles/{role}/permissions', [AccessControlController::class, 'updateRolePermissions'])->middleware('permission:role.update,any');
    });
    // --- ユーザー・権限管理 (docs/15-usecases-admin.md UC-M001) ---
    // 入社日・退社日・雇用区分を含む一覧・詳細も実効Permissionで制御する。
    // 承認者選択(UserPicker)等、一般社員も使う軽量な検索は下のUserController::search
    // (/users/search)を使う。
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view,any');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create,any');
    Route::get('/users/search', [UserController::class, 'search']);
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware(['feature:administration.users', 'permission:user.update']);
    Route::put('/users/{user}/hire-date', [UserController::class, 'updateHireDate'])->middleware('permission:user.update');
    Route::put('/users/{user}/termination-date', [UserController::class, 'updateTerminationDate'])->middleware('permission:user.update');
    Route::put('/users/{user}/usage-start-date', [UserController::class, 'updateUsageStartDate'])->middleware('permission:user.update');
    // --- 申請種別マスタ (docs/10-usecases-workflow.md UC-W001, docs/15 UC-M002) ---
    Route::get('/request-types', [RequestTypeController::class, 'index']);

    // --- 汎用申請 (docs/10-usecases-workflow.md UC-W002〜UC-W005) ---
    Route::get('/workflow-requests/mine', [WorkflowRequestController::class, 'indexMine']);
    Route::get('/workflow-requests/to-approve', [WorkflowRequestController::class, 'indexToApprove'])->middleware('permission:approval.execute');
    Route::get('/workflow-requests/{workflowRequest}', [WorkflowRequestController::class, 'show']);
    Route::post('/workflow-requests', [WorkflowRequestController::class, 'store']);
    Route::post('/workflow-requests/{workflowRequest}/submit', [WorkflowRequestController::class, 'submit']);
    Route::post('/workflow-requests/{workflowRequest}/approve', [WorkflowRequestController::class, 'approve'])->middleware('permission:approval.execute');
    Route::post('/workflow-requests/{workflowRequest}/return', [WorkflowRequestController::class, 'return'])->middleware('permission:approval.execute');
    Route::post('/workflow-requests/{workflowRequest}/cancel', [WorkflowRequestController::class, 'cancel']);
    Route::get('/workflow-requests/{workflowRequest}/history', [WorkflowRequestController::class, 'history']);

    // --- 通知 (docs/13-usecases-notification.md UC-N001) ---
    Route::get('/notifications/mine', [NotificationController::class, 'indexMine']);
    Route::post('/notifications/{notification}/confirm', [NotificationController::class, 'confirm']);

    // --- 添付ファイル (docs/12-usecases-attachment.md) ---
    Route::get('/attachments', [AttachmentController::class, 'index']);
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);

    // --- 経費精算 (docs/30-usecases-expense.md UC-X001〜UC-X012) ---
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);

    Route::get('/expense-entry-presets', [ExpenseEntryPresetController::class, 'index']);
    Route::post('/expense-entry-presets', [ExpenseEntryPresetController::class, 'store']);
    Route::get('/expense-entry-presets/{expenseEntryPreset}', [ExpenseEntryPresetController::class, 'show']);
    Route::put('/expense-entry-presets/{expenseEntryPreset}', [ExpenseEntryPresetController::class, 'update']);
    Route::delete('/expense-entry-presets/{expenseEntryPreset}', [ExpenseEntryPresetController::class, 'destroy']);
    Route::post('/expense-entry-presets/{expenseEntryPreset}/apply', [ExpenseEntryPresetController::class, 'apply']);

    Route::get('/expense-claims/mine', [ExpenseClaimController::class, 'indexMine']);
    Route::get('/expense-claims/to-approve', [ExpenseClaimController::class, 'indexToApprove'])->middleware('permission:approval.execute');
    Route::get('/expense-claims/{expenseClaim}', [ExpenseClaimController::class, 'show']);
    Route::post('/expense-claims', [ExpenseClaimController::class, 'store']);
    Route::patch('/expense-claims/{expenseClaim}/title', [ExpenseClaimController::class, 'updateTitle']);
    Route::post('/expense-claims/{expenseClaim}/items', [ExpenseClaimController::class, 'addItem']);
    Route::post('/expense-claims/{expenseClaim}/items/bulk', [ExpenseClaimController::class, 'bulkAddItems']);
    Route::put('/expense-claims/{expenseClaim}/items/{item}', [ExpenseClaimController::class, 'updateItem']);
    Route::delete('/expense-claims/{expenseClaim}/items/{item}', [ExpenseClaimController::class, 'removeItem']);
    Route::post('/expense-claims/{expenseClaim}/submit', [ExpenseClaimController::class, 'submit']);
    Route::post('/expense-claims/{expenseClaim}/approve', [ExpenseClaimController::class, 'approve'])->middleware('permission:approval.execute');
    Route::post('/expense-claims/{expenseClaim}/return', [ExpenseClaimController::class, 'return'])->middleware('permission:approval.execute');
    Route::post('/expense-claims/{expenseClaim}/cancel', [ExpenseClaimController::class, 'cancel']);
    Route::delete('/expense-claims/{expenseClaim}', [ExpenseClaimController::class, 'destroy']);
    Route::get('/expense-claims/{expenseClaim}/history', [ExpenseClaimController::class, 'history']);

    // --- バックオフィス処理 (docs/11-usecases-backoffice.md UC-B002〜UC-B003) ---
    Route::middleware(['feature:backoffice.tasks', 'permission:backoffice_task.execute,any'])->group(function () {
        Route::get('/backoffice-tasks/unassigned', [BackOfficeTaskController::class, 'indexUnassigned']);
        Route::get('/backoffice-tasks/mine', [BackOfficeTaskController::class, 'indexMine']);
        Route::post('/backoffice-tasks/bulk-complete', [BackOfficeTaskController::class, 'bulkComplete']);
        Route::get('/backoffice-tasks/{backOfficeTask}', [BackOfficeTaskController::class, 'show']);
        Route::post('/backoffice-tasks/{backOfficeTask}/assign', [BackOfficeTaskController::class, 'assign']);
        Route::post('/backoffice-tasks/{backOfficeTask}/status', [BackOfficeTaskController::class, 'changeStatus']);
    });

    // --- CSV/Excel出力 (docs/14-usecases-export.md) ---
    Route::get('/exports/attendance', [ExportController::class, 'attendance'])
        ->middleware('permission:attendance.export,any');
    Route::get('/exports/attendance.xlsx', [ExportController::class, 'attendanceExcel'])
        ->middleware('permission:attendance.export,any');
    Route::post('/exports/attendance/external-publish', [ExportController::class, 'attendanceExternalPublish'])
        ->middleware('permission:attendance.export,any');
    Route::get('/exports/expenses', [ExportController::class, 'expenses'])
        ->middleware(['feature:backoffice.expenses', 'permission:expense.export,any']);
    Route::get('/exports/expenses.xlsx', [ExportController::class, 'expensesExcel'])
        ->middleware(['feature:backoffice.expenses', 'permission:expense.export,any']);
    Route::post('/exports/expenses/external-publish', [ExportController::class, 'expensesExternalPublish'])
        ->middleware(['feature:backoffice.expenses', 'permission:expense.export,any']);

    // --- カレンダー・勤務形態 (docs/08-usecases-calendar-shift.md UC-C009〜UC-C013) ---
    Route::get('/company-calendars', [CompanyCalendarController::class, 'index']);
    Route::get('/company-calendars/{companyCalendar}/years', [CompanyCalendarController::class, 'years']);
    Route::get('/company-calendar-years/{companyCalendarYear}/days', [CompanyCalendarController::class, 'days']);
    Route::get('/holiday-calendar-sources', [HolidayCalendarSourceController::class, 'index']);
    Route::get('/employment-categories', [EmploymentCategoryController::class, 'index']);
    Route::get('/work-styles', [WorkStyleController::class, 'index']);
    Route::get('/employee-calendar-entries', [EmployeeCalendarEntryController::class, 'index'])->middleware('ability:schedule:self:read');
    Route::get('/shift-patterns', [ShiftPatternController::class, 'index']);
    Route::get('/rotation-patterns', [RotationPatternController::class, 'index']);
    Route::get('/employee-rotation-assignments', [EmployeeRotationAssignmentController::class, 'show']);
    Route::get('/user-work-style-monthly-assignments', [UserWorkStyleMonthlyAssignmentController::class, 'index']);
    Route::middleware('permission:attendance.manage,any')->group(function () {
        Route::post('/onboarding/calendar/generate-now', [OnboardingController::class, 'generateCalendarNow']);
        Route::post('/company-calendars', [CompanyCalendarController::class, 'store']);
        Route::put('/company-calendars/{companyCalendar}', [CompanyCalendarController::class, 'update']);
        Route::delete('/company-calendars/{companyCalendar}', [CompanyCalendarController::class, 'destroy']);
        Route::post('/company-calendars/{companyCalendar}/set-default', [CompanyCalendarController::class, 'setDefault']);
        Route::post('/company-calendars/{companyCalendar}/years', [CompanyCalendarController::class, 'storeYear']);
        Route::post('/company-calendar-years/{companyCalendarYear}/publish', [CompanyCalendarController::class, 'publish']);
        Route::post('/company-calendar-years/{companyCalendarYear}/unpublish', [CompanyCalendarController::class, 'unpublish']);
        Route::post('/company-calendar-years/{companyCalendarYear}/archive', [CompanyCalendarController::class, 'archive']);
        Route::delete('/company-calendar-years/{companyCalendarYear}', [CompanyCalendarController::class, 'destroyYear']);
        Route::put('/company-calendar-years/{companyCalendarYear}/days', [CompanyCalendarController::class, 'putDays']);
        Route::post('/company-calendar-years/{companyCalendarYear}/duplicate', [CompanyCalendarController::class, 'duplicate']);
        Route::post('/company-calendar-years/{companyCalendarYear}/regenerate', [CompanyCalendarController::class, 'regenerateYear']);
        Route::post('/company-calendar-years/{companyCalendarYear}/sync-holiday-calendar', [CompanyCalendarController::class, 'syncHolidayCalendar']);
        Route::post('/holiday-calendar-sources', [HolidayCalendarSourceController::class, 'store']);
        Route::post('/holiday-calendar-sources/{holidayCalendarSource}', [HolidayCalendarSourceController::class, 'update']);
        Route::post('/holiday-calendar-sources/{holidayCalendarSource}/sync', [HolidayCalendarSourceController::class, 'sync']);
        Route::post('/holiday-calendar-sources/{holidayCalendarSource}/disable', [HolidayCalendarSourceController::class, 'disable']);
        Route::post('/holiday-calendar-sources/{holidayCalendarSource}/revert-last-sync', [HolidayCalendarSourceController::class, 'revertLastSync']);
        Route::delete('/holiday-calendar-sources/{holidayCalendarSource}', [HolidayCalendarSourceController::class, 'destroy']);
        Route::post('/calendar-bulk-operations/preview', [CalendarBulkOperationController::class, 'preview']);
        Route::post('/calendar-bulk-operations', [CalendarBulkOperationController::class, 'store']);
        Route::get('/calendar-bulk-operations', [CalendarBulkOperationController::class, 'index']);
        Route::get('/calendar-bulk-operations/{calendarBulkOperation}', [CalendarBulkOperationController::class, 'show']);
        Route::post('/calendar-bulk-operations/{calendarBulkOperation}/revert', [CalendarBulkOperationController::class, 'revert']);
        Route::post('/employment-categories', [EmploymentCategoryController::class, 'store']);
        Route::post('/work-styles', [WorkStyleController::class, 'store']);
        Route::post('/work-styles/default', [WorkStyleController::class, 'storeDefault']);
        Route::put('/work-styles/{workStyle}', [WorkStyleController::class, 'update']);
        Route::post('/work-styles/{workStyle}/set-default', [WorkStyleController::class, 'setDefault']);
        Route::post('/employee-calendar-entries/generate', [EmployeeCalendarEntryController::class, 'generate']);
        Route::post('/employee-calendar-entries/preview-pattern', [EmployeeCalendarEntryController::class, 'previewPattern']);
        Route::post('/employee-calendar-entries/generate-pattern', [EmployeeCalendarEntryController::class, 'generatePattern']);
        Route::put('/employee-calendar-entries/{employeeCalendarEntry}', [EmployeeCalendarEntryController::class, 'update']);
        Route::post('/user-work-style-monthly-assignments', [UserWorkStyleMonthlyAssignmentController::class, 'store']);
        Route::delete('/user-work-style-monthly-assignments/{userWorkStyleMonthlyAssignment}', [UserWorkStyleMonthlyAssignmentController::class, 'destroy']);

        // --- 3交代制シフト表 (docs/08-usecases-calendar-shift.md UC-C004) ---
        Route::post('/shift-patterns', [ShiftPatternController::class, 'store']);
        Route::put('/shift-patterns/{shiftPattern}', [ShiftPatternController::class, 'update']);
        Route::post('/employee-calendar-entries/assign-pattern', [EmployeeCalendarEntryController::class, 'assignPattern']);
        Route::get('/employee-calendar-entries/review', [EmployeeCalendarEntryController::class, 'review']);
        Route::post('/employee-calendar-entries/publish', [EmployeeCalendarEntryController::class, 'publish']);

        // --- 交代制ローテーション (指示書 8章) ---
        Route::post('/rotation-patterns', [RotationPatternController::class, 'store']);
        Route::post('/rotation-patterns/{rotationPattern}/preview', [RotationPatternController::class, 'preview']);
        Route::post('/employee-rotation-assignments', [EmployeeRotationAssignmentController::class, 'store']);
        Route::post('/employee-rotation-assignments/generate', [EmployeeRotationAssignmentController::class, 'generate']);
    });

    // --- 勤怠 (docs/07-usecases-attendance.md UC-A001〜UC-A011, UC-A015) ---
    // ability:のタグは個人API/MCP連携(docs/25-usecases-integrations-mcp.md)のスコープ限定
    // トークンからも呼べるようにするためのオプトイン。ability`*`を持つ通常の人間向けトークンは
    // 影響を受けない(Sanctumのability`*`は全ability判定を満たすため)。
    Route::prefix('attendance')->group(function () {
        Route::get('/today', [AttendanceController::class, 'today'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->middleware(['ability:attendance:self:clock', 'permission:attendance.update,self']);
        Route::post('/break/start', [AttendanceController::class, 'startBreak'])->middleware(['ability:attendance:self:clock', 'permission:attendance.update,self']);
        Route::post('/break/end', [AttendanceController::class, 'endBreak'])->middleware(['ability:attendance:self:clock', 'permission:attendance.update,self']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->middleware(['ability:attendance:self:clock', 'permission:attendance.update,self']);
        Route::get('/week', [AttendanceController::class, 'week'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::get('/week/overtime', [AttendanceController::class, 'weekOvertime'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::put('/weeks/{weekStartDate}/overtime-allocations', [AttendanceController::class, 'allocateWeekOvertime'])->middleware(['ability:attendance:self:update', 'permission:attendance.update,self']);
        Route::get('/day-defaults', [AttendanceController::class, 'dayDefaults'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::post('/days', [AttendanceController::class, 'storeDay'])->middleware(['ability:attendance:self:update', 'permission:attendance.update,self']);
        Route::post('/days/preview-pattern', [AttendanceController::class, 'previewAttendancePattern'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::post('/days/generate-pattern', [AttendanceController::class, 'generateAttendancePattern'])->middleware(['ability:attendance:self:update', 'permission:attendance.update,self']);
        Route::get('/days/{attendanceDay}', [AttendanceController::class, 'showDay'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::put('/days/{attendanceDay}', [AttendanceController::class, 'updateDay'])->middleware(['ability:attendance:self:update', 'permission:attendance.update,self']);
        Route::put('/days/{attendanceDay}/calculation', [AttendanceController::class, 'adjustCalculation'])->middleware('permission:attendance.update,any');
        Route::delete('/days/{attendanceDay}', [AttendanceController::class, 'destroyDay'])->middleware('permission:attendance.update,any');
        Route::post('/legal-holiday-designations', [LegalHolidayDesignationController::class, 'store'])->middleware('permission:attendance.update,self');
        Route::get('/months/mine', [AttendanceController::class, 'myMonths'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::get('/months/to-approve', [AttendanceController::class, 'monthsToApprove'])->middleware('permission:approval.execute');
        Route::get('/months/user/{userId}', [AttendanceController::class, 'monthsForUser'])
            ->middleware('permission:attendance.read,global');
        Route::get('/months/{yearMonth}', [AttendanceController::class, 'month'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::post('/months/{yearMonth}/submit', [AttendanceController::class, 'submitMonth'])->middleware(['ability:attendance:self:submit', 'permission:attendance.update,self']);
    });

    // --- 作業報告書インポートの差異検出 (docs/26-usecases-monthly-import.md UC-R001) ---
    // ステートレス(何も保存しない)。下書き・インポートセッション自体の保持はmcp/自身のDBで
    // 行う(CLAUDE.mdの設計原則9)。
    Route::post('/attendance/import-preview', [AttendanceImportPreviewController::class, 'check'])
        ->middleware(['ability:report:self:import', 'permission:attendance.read,self']);

    // --- 打刻ログ (docs/07-usecases-attendance.md UC-A012〜UC-A014) ---
    Route::prefix('attendance-punches')->group(function () {
        Route::get('/', [AttendancePunchController::class, 'index'])->middleware(['ability:attendance:self:read', 'permission:attendance.read,self']);
        Route::post('/', [AttendancePunchController::class, 'store'])->middleware(['ability:attendance:self:clock', 'permission:attendance.update,self']);
        Route::put('/{attendancePunch}', [AttendancePunchController::class, 'update'])->middleware('permission:attendance.update,any');
        Route::delete('/{attendancePunch}', [AttendancePunchController::class, 'destroy'])->middleware('permission:attendance.update,any');
    });

    Route::prefix('attendance-months')->group(function () {
        Route::get('/{attendanceMonth}', [AttendanceController::class, 'showMonth'])->middleware('permission:attendance.read,any');
        Route::post('/{attendanceMonth}/approve', [AttendanceController::class, 'approveMonth'])->middleware('permission:approval.execute');
        Route::post('/{attendanceMonth}/return', [AttendanceController::class, 'returnMonth'])->middleware('permission:approval.execute');
        Route::post('/{attendanceMonth}/close', [AttendanceController::class, 'closeMonth'])
            ->middleware('permission:attendance.update,any');
        // 救済コマンド(管理者専用): 締め済みの月次勤怠の締めを取り消す。
        Route::post('/{attendanceMonth}/reopen', [AttendanceController::class, 'reopenMonth'])
            ->middleware('permission:attendance.month_reopen,any');
        // 救済コマンド(バックオフィス担当者専用): 「勤怠確定取消依頼」の承認後、承認済みの
        // 月次勤怠の確定を取り消す。
        Route::post('/{attendanceMonth}/revert-confirmation', [AttendanceController::class, 'revertMonthConfirmation'])
            ->middleware('permission:attendance.confirmation_revert,any');
    });

    // フロントエンド起動時のブートストラップ設定(デフォルトタイムゾーン・デフォルト働き方・
    // 勤怠提出/締め期限日・有給/特別休暇の承認要否)をまとめて返す軽量エンドポイント。
    // SystemSettingController(/admin/system-settings、system_settings Permission限定、M365設定・通知メール設定等の
    // 機微な項目を含む)とは別に、認証済みなら誰でも参照できるエンドポイントとしてここ
    // (/system-settings、PublicSystemSettingController)に分離する。
    Route::get('/system-settings', [PublicSystemSettingController::class, 'show']);

    // --- グループ対象指定ピッカー用の軽量グループ参照(docs/25-usecases-integrations-mcp.md等の
    //     承認者・通知対象範囲の選択で使う)。admin/user-management/groups(フル情報付き)とは
    //     別に、id・nameのみを返す読み取り専用エンドポイント。認可は既存の管理者向け一覧
    //     エンドポイント(paid-leave/grants/user/{userId}等)と同じくPermissionで行う。 ---
    Route::middleware('permission:group.view,any')->group(function () {
        Route::get('/groups', [UserManagementController::class, 'groupsLite']);
        Route::get('/groups/{group}/members', [UserManagementController::class, 'groupMembers']);
    });

    // --- 有給残数管理・申請・承認 (docs/09-usecases-paid-leave.md UC-P001〜UC-P004, UC-P007) ---
    Route::get('/paid-leave/grants/mine', [PaidLeaveController::class, 'myGrants'])->middleware('ability:leave:self:read');
    Route::get('/paid-leave/grant-rules', [PaidLeaveController::class, 'indexRules']);
    Route::get('/paid-leave/requests/mine', [PaidLeaveController::class, 'myRequests'])->middleware('ability:leave:self:read');
    Route::get('/paid-leave/requests/to-approve', [PaidLeaveController::class, 'requestsToApprove'])->middleware('permission:approval.execute');
    Route::get('/paid-leave/history/mine', [PaidLeaveController::class, 'myHistory'])->middleware('ability:leave:self:read');
    Route::post('/paid-leave/requests', [PaidLeaveController::class, 'storeRequest'])->middleware('ability:leave:self:create');
    Route::post('/paid-leave/requests/{paidLeaveRequest}/approve', [PaidLeaveController::class, 'approveRequest'])->middleware('permission:approval.execute');
    Route::post('/paid-leave/requests/{paidLeaveRequest}/return', [PaidLeaveController::class, 'returnRequest'])->middleware('permission:approval.execute');
    Route::post('/paid-leave/requests/{paidLeaveRequest}/cancel', [PaidLeaveController::class, 'cancelRequest']);
    Route::middleware('permission:leave.manage,any')->group(function () {
        Route::post('/paid-leave/grant-rules', [PaidLeaveController::class, 'storeRule']);
        Route::get('/paid-leave/grants/user/{userId}', [PaidLeaveController::class, 'grantsForUser']);
        Route::get('/paid-leave/history/user/{userId}', [PaidLeaveController::class, 'historyForUser']);
        Route::get('/paid-leave/usages/user/{userId}', [PaidLeaveController::class, 'usagesForUser']);
        Route::post('/paid-leave/grants', [PaidLeaveController::class, 'grant']);
        Route::post('/paid-leave/grants/{grant}/revoke', [PaidLeaveController::class, 'revoke']);
        Route::post('/paid-leave/requests/{paidLeaveRequest}/admin-cancel', [PaidLeaveController::class, 'adminCancelRequest']);
    });

    // --- 特別休暇の種別マスタ・残数管理・申請・承認(有給と同じUXだが、ビジネスロジックは
    //     App\Domain\SpecialLeaveとして完全に独立させる) ---
    Route::get('/special-leave/types', [SpecialLeaveController::class, 'indexTypes']);
    Route::get('/special-leave/grant-rules', [SpecialLeaveController::class, 'indexRules']);
    Route::get('/special-leave/grants/mine', [SpecialLeaveController::class, 'myGrants']);
    Route::get('/special-leave/requests/mine', [SpecialLeaveController::class, 'myRequests']);
    Route::get('/special-leave/requests/to-approve', [SpecialLeaveController::class, 'requestsToApprove'])->middleware('permission:approval.execute');
    Route::get('/special-leave/history/mine', [SpecialLeaveController::class, 'myHistory']);
    Route::post('/special-leave/requests', [SpecialLeaveController::class, 'storeRequest']);
    Route::post('/special-leave/requests/{specialLeaveRequest}/approve', [SpecialLeaveController::class, 'approveRequest'])->middleware('permission:approval.execute');
    Route::post('/special-leave/requests/{specialLeaveRequest}/return', [SpecialLeaveController::class, 'returnRequest'])->middleware('permission:approval.execute');
    Route::post('/special-leave/requests/{specialLeaveRequest}/cancel', [SpecialLeaveController::class, 'cancelRequest']);
    Route::middleware('permission:leave.manage,any')->group(function () {
        Route::post('/special-leave/types', [SpecialLeaveController::class, 'storeType']);
        Route::put('/special-leave/types/{specialLeaveType}', [SpecialLeaveController::class, 'updateType']);
        Route::post('/special-leave/grant-rules', [SpecialLeaveController::class, 'storeRule']);
        Route::get('/special-leave/grants/user/{userId}', [SpecialLeaveController::class, 'grantsForUser']);
        Route::get('/special-leave/history/user/{userId}', [SpecialLeaveController::class, 'historyForUser']);
        Route::get('/special-leave/usages/user/{userId}', [SpecialLeaveController::class, 'usagesForUser']);
        Route::post('/special-leave/grants', [SpecialLeaveController::class, 'grant']);
        Route::post('/special-leave/grants/{grant}/revoke', [SpecialLeaveController::class, 'revoke']);
        Route::post('/special-leave/requests/{specialLeaveRequest}/admin-cancel', [SpecialLeaveController::class, 'adminCancelRequest']);
    });

    // --- 代休の残数管理・消化申請・承認(付与は休日出勤の勤怠実績から自動導出される。
    //     ビジネスロジックはApp\Domain\CompensatoryLeaveとして完全に独立させる) ---
    Route::get('/compensatory-leave/grants/mine', [CompensatoryLeaveController::class, 'myGrants']);
    Route::get('/compensatory-leave/history/mine', [CompensatoryLeaveController::class, 'myHistory']);
    Route::get('/compensatory-leave/requests/mine', [CompensatoryLeaveController::class, 'myRequests']);
    Route::get('/compensatory-leave/requests/to-approve', [CompensatoryLeaveController::class, 'requestsToApprove'])->middleware('permission:approval.execute');
    Route::post('/compensatory-leave/requests', [CompensatoryLeaveController::class, 'storeRequest']);
    Route::post('/compensatory-leave/requests/{compensatoryLeaveRequest}/approve', [CompensatoryLeaveController::class, 'approveRequest'])->middleware('permission:approval.execute');
    Route::post('/compensatory-leave/requests/{compensatoryLeaveRequest}/return', [CompensatoryLeaveController::class, 'returnRequest'])->middleware('permission:approval.execute');
    Route::post('/compensatory-leave/requests/{compensatoryLeaveRequest}/cancel', [CompensatoryLeaveController::class, 'cancelRequest']);
    Route::post('/compensatory-leave/grants/{grant}/request-cancellation', [CompensatoryLeaveController::class, 'requestGrantCancellation']);
    Route::post('/compensatory-leave/grant-cancellations/{cancellationId}/approve', [CompensatoryLeaveController::class, 'approveGrantCancellation'])->middleware('permission:approval.execute');
    Route::middleware('permission:leave.manage,any')->group(function () {
        Route::get('/compensatory-leave/grants/user/{userId}', [CompensatoryLeaveController::class, 'grantsForUser']);
        Route::get('/compensatory-leave/history/user/{userId}', [CompensatoryLeaveController::class, 'historyForUser']);
        Route::get('/compensatory-leave/usages/user/{userId}', [CompensatoryLeaveController::class, 'usagesForUser']);
        Route::post('/compensatory-leave/grants', [CompensatoryLeaveController::class, 'grant']);
        Route::post('/compensatory-leave/grants/{grant}/revoke', [CompensatoryLeaveController::class, 'revoke']);
        Route::post('/compensatory-leave/requests/{compensatoryLeaveRequest}/admin-cancel', [CompensatoryLeaveController::class, 'adminCancelRequest']);
    });

    // --- 振替休日申請(固定勤務の休日を別日の労働日と入れ替える。ビジネスロジックは
    //     App\Domain\ShiftSwapとして独立させる) ---
    Route::get('/shift-swap/requests/mine', [ShiftSwapRequestController::class, 'myRequests']);
    Route::get('/shift-swap/requests/to-approve', [ShiftSwapRequestController::class, 'requestsToApprove'])->middleware('permission:approval.execute');
    Route::post('/shift-swap/requests', [ShiftSwapRequestController::class, 'storeRequest']);
    Route::get('/shift-swap/requests/{shiftSwapRequest}', [ShiftSwapRequestController::class, 'show']);
    Route::post('/shift-swap/requests/{shiftSwapRequest}/approve', [ShiftSwapRequestController::class, 'approveRequest'])->middleware('permission:approval.execute');
    Route::post('/shift-swap/requests/{shiftSwapRequest}/return', [ShiftSwapRequestController::class, 'returnRequest'])->middleware('permission:approval.execute');
    Route::post('/shift-swap/requests/{shiftSwapRequest}/cancel', [ShiftSwapRequestController::class, 'cancelRequest']);

    // --- 端末管理 (docs/23-usecases-devices.md UC-D001〜UC-D005) ---
    Route::get('/users/me/devices', [DeviceController::class, 'indexMine']);
    Route::post('/users/me/devices', [DeviceController::class, 'storePersonal']);
    Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat'])
        ->middleware('ability:recorder:punch,punch:self,device:heartbeat');
    // 端末アプリが一時ペアリングトークン(claim token、device:claim-pairingのみのability)を
    // 業務用の本トークンに交換する。呼び出し元はこの時点でその一時トークンの持ち主自身
    // (Device)であることが認証済みのため、ユーザーPermissionではなくabilityで絞る。
    Route::post('/devices/pairing/claim', [DeviceController::class, 'claimPairing'])
        ->middleware('ability:device:claim-pairing')
        ->name('devices.pairing.claim');
    // 停止・有効化・失効は「本人(個人端末)または管理者」を許可するためController側で判定する
    // (abortUnlessDeviceOwnerOrAdmin)。ユーザー向けPermissionミドルウェアでは絞り込まない。
    Route::post('/devices/{device}/disable', [DeviceController::class, 'disable']);
    Route::post('/devices/{device}/enable', [DeviceController::class, 'enable']);
    Route::post('/devices/{device}/revoke', [DeviceController::class, 'revoke']);

    // --- 認証キー管理 (docs/24-usecases-authentication-keys.md UC-K001〜UC-K003) ---
    Route::get('/users/me/authentication-keys', [AuthenticationKeyController::class, 'indexMine']);
    Route::post('/users/me/authentication-keys', [AuthenticationKeyController::class, 'store']);
    Route::get('/users/{userId}/authentication-keys', [AuthenticationKeyController::class, 'indexForUser']);
    Route::post('/authentication-keys/{authenticationKey}/disable', [AuthenticationKeyController::class, 'disable']);

    // --- 個人API・MCP連携 (docs/25-usecases-integrations-mcp.md UC-I001〜UC-I003) ---
    // 連携の登録・再発行・停止自体は連携トークンではなく本人の通常ログインセッションで行う
    // (ability指定なし。scoped自身のトークンではこのAPIを呼べない=連携管理は連携自身に
    // 権限を持たせない)。
    Route::get('/users/me/integrations', [IntegrationController::class, 'indexMine']);
    Route::post('/users/me/integrations', [IntegrationController::class, 'store']);
    Route::post('/users/me/integrations/{integration}/reissue', [IntegrationController::class, 'reissue']);
    Route::post('/users/me/integrations/{integration}/revoke', [IntegrationController::class, 'revoke']);

    // Feature / Permission による動的な付与を許可するシステム管理機能。
    // 実効Feature・Permissionだけを認可境界にする。
    Route::prefix('admin')->middleware('feature:administration.settings')->group(function () {
        Route::get('/commands', [AdminCommandController::class, 'index'])->middleware('permission:admin_command.view,any');
        Route::get('/command-runs', [AdminCommandController::class, 'runs'])->middleware('permission:admin_command.view,any');
        Route::post('/commands/{command}/runs', [AdminCommandController::class, 'store'])->where('command', '.*')->middleware('permission:admin_command.execute,any');
        Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit_log.view,any');
        Route::get('/audit-log/export', [AuditLogController::class, 'exportCsv'])->middleware('permission:audit_log.export,any');
        Route::get('/system-settings', [SystemSettingController::class, 'show'])->middleware('permission:system_settings.read');
        Route::put('/system-settings', [SystemSettingController::class, 'update'])->middleware('permission:system_settings.update');
    });

    // --- システム管理エンドポイント。旧ユーザーロールではなく管理Featureと実効Permissionで制御する。 ---
    Route::prefix('admin')->middleware('feature:administration')->group(function () {
        // 申請種別マスタ (docs/10-usecases-workflow.md UC-W001, docs/15 UC-M002)
        Route::post('/request-types', [RequestTypeController::class, 'store'])->middleware('permission:request_type.manage,any');
        Route::put('/request-types/{requestType}', [RequestTypeController::class, 'update'])->middleware('permission:request_type.manage,any');

        // 経費精算 カテゴリマスタ (docs/30-usecases-expense.md)
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('permission:expense_category.manage,any');
        Route::put('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->middleware('permission:expense_category.manage,any');
        Route::delete('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->middleware('permission:expense_category.manage,any');

        // 勤怠未提出督促の個別除外
        Route::get('/attendance-submission-reminder-exclusions', [AttendanceSubmissionReminderExclusionController::class, 'index'])->middleware('permission:attendance_reminder_exclusion.manage,any');
        Route::post('/attendance-submission-reminder-exclusions', [AttendanceSubmissionReminderExclusionController::class, 'store'])->middleware('permission:attendance_reminder_exclusion.manage,any');

        // 端末管理 (docs/23-usecases-devices.md UC-D001〜UC-D005)
        Route::get('/devices', [DeviceController::class, 'index'])->middleware('permission:device.manage,any');
        Route::post('/devices', [DeviceController::class, 'store'])->middleware('permission:device.manage,any');
        Route::get('/devices/{device}', [DeviceController::class, 'show'])->middleware('permission:device.manage,any');
        Route::patch('/devices/{device}', [DeviceController::class, 'update'])->middleware('permission:device.manage,any');
        Route::patch('/devices/{device}/roles', [DeviceController::class, 'updateRoles'])->middleware('permission:device.manage,any');
        Route::post('/devices/{device}/pairing', [DeviceController::class, 'issuePairingClaim'])->middleware('permission:device.manage,any');
        Route::post('/devices/{device}/scopes', [DeviceController::class, 'grantScope'])->middleware('permission:device.manage,any');
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:device.manage,any');
    });
});

// --- 端末打刻 (docs/07-usecases-attendance.md UC-A020、docs/23-usecases-devices.md UC-D002) ---
// 端末トークン(ability: recorder:punch=共有端末、punch:self=個人端末)で認証する、
// 人間のSanctumセッションを前提とするattendance-punchesとは別の入口。
Route::middleware(['auth:sanctum', 'ability:recorder:punch,punch:self,attendance:clock'])->group(function () {
    Route::post('/device-punches', [DevicePunchController::class, 'store']);
});
Route::middleware(['auth:sanctum', 'ability:identity:resolve,recorder:punch'])->group(function () {
    Route::post('/devices/identity/resolve', [DeviceIdentityController::class, 'resolve']);
});

// --- 端末管理者モード (docs/23-usecases-devices.md UC-D006) ---
// Android端末が管理者ICカードをかざして管理者モードに入り、社員証NFCを現地登録するための入口。
Route::middleware(['auth:sanctum', 'ability:admin:mode'])->group(function () {
    Route::get('/devices/me/admin-bootstrap', [DeviceAdminController::class, 'bootstrapEligibility']);
    Route::post('/devices/me/admin-bootstrap/authentication-keys', [DeviceAdminController::class, 'bootstrapRegisterKey']);
    Route::post('/devices/me/admin-sessions', [DeviceAdminController::class, 'startSession']);
    Route::post('/devices/me/admin-sessions/current/end', [DeviceAdminController::class, 'endSession']);
    Route::get('/devices/me/admin/users', [DeviceAdminController::class, 'users']);
    Route::get('/devices/me/admin/users/{user}/authentication-keys', [DeviceAdminController::class, 'userAuthenticationKeys']);
    Route::post('/devices/me/admin/users/{user}/authentication-keys', [DeviceAdminController::class, 'registerUserAuthenticationKey']);
});
