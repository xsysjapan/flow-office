<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceImportPreviewController;
use App\Http\Controllers\Api\AttendancePunchController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthenticationKeyController;
use App\Http\Controllers\Api\BackOfficeTaskController;
use App\Http\Controllers\Api\DevDatabaseResetController;
use App\Http\Controllers\Api\DeviceAdminController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceIdentityController;
use App\Http\Controllers\Api\DevicePunchController;
use App\Http\Controllers\Api\EmployeeRotationAssignmentController;
use App\Http\Controllers\Api\EmployeeShiftAssignmentController;
use App\Http\Controllers\Api\EmploymentCategoryController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LegalHolidayDesignationController;
use App\Http\Controllers\Api\MockOidcUserController;
use App\Http\Controllers\Api\PaidLeaveController;
use App\Http\Controllers\Api\RequestTypeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RotationPatternController;
use App\Http\Controllers\Api\ShiftPatternController;
use App\Http\Controllers\Api\SpecialLeaveController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserWorkStyleMonthlyAssignmentController;
use App\Http\Controllers\Api\WorkCalendarController;
use App\Http\Controllers\Api\WorkflowRequestController;
use App\Http\Controllers\Api\WorkStyleController;
use Illuminate\Support\Facades\Route;

// --- 認証 (docs/06-usecases-auth.md) ---
Route::prefix('auth')->group(function () {
    Route::get('/microsoft/redirect', [AuthController::class, 'redirect']);
    Route::get('/microsoft/callback', [AuthController::class, 'callback']);
    Route::post('/token', [AuthController::class, 'token']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->middleware('ability:profile:self:read');
        Route::post('/logout', [AuthController::class, 'logout']);
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

Route::middleware('auth:sanctum')->group(function () {
    // --- ユーザー・権限管理 (docs/15-usecases-admin.md UC-M001) ---
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('role:admin,hr_staff');
    Route::put('/users/{user}/hire-date', [UserController::class, 'updateHireDate'])->middleware('role:admin,hr_staff');
    Route::put('/users/{user}/termination-date', [UserController::class, 'updateTerminationDate'])->middleware('role:admin,hr_staff');
    Route::get('/roles', [RoleController::class, 'index']);

    // --- 申請種別マスタ (docs/10-usecases-workflow.md UC-W001, docs/15 UC-M002) ---
    Route::get('/request-types', [RequestTypeController::class, 'index']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/request-types', [RequestTypeController::class, 'store']);
        Route::put('/request-types/{requestType}', [RequestTypeController::class, 'update']);
    });

    // --- 汎用申請 (docs/10-usecases-workflow.md UC-W002〜UC-W005) ---
    Route::get('/workflow-requests/mine', [WorkflowRequestController::class, 'indexMine']);
    Route::get('/workflow-requests/to-approve', [WorkflowRequestController::class, 'indexToApprove']);
    Route::get('/workflow-requests/{workflowRequest}', [WorkflowRequestController::class, 'show']);
    Route::post('/workflow-requests', [WorkflowRequestController::class, 'store']);
    Route::post('/workflow-requests/{workflowRequest}/submit', [WorkflowRequestController::class, 'submit']);
    Route::post('/workflow-requests/{workflowRequest}/approve', [WorkflowRequestController::class, 'approve']);
    Route::post('/workflow-requests/{workflowRequest}/return', [WorkflowRequestController::class, 'return']);
    Route::post('/workflow-requests/{workflowRequest}/cancel', [WorkflowRequestController::class, 'cancel']);
    Route::get('/workflow-requests/{workflowRequest}/history', [WorkflowRequestController::class, 'history']);

    // --- 添付ファイル (docs/12-usecases-attachment.md) ---
    Route::get('/attachments', [AttachmentController::class, 'index']);
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);

    // --- バックオフィス処理 (docs/11-usecases-backoffice.md UC-B002〜UC-B003) ---
    Route::middleware('role:backoffice_staff,accounting_staff,general_affairs_staff,admin')->group(function () {
        Route::get('/backoffice-tasks/unassigned', [BackOfficeTaskController::class, 'indexUnassigned']);
        Route::get('/backoffice-tasks/mine', [BackOfficeTaskController::class, 'indexMine']);
        Route::get('/backoffice-tasks/{backOfficeTask}', [BackOfficeTaskController::class, 'show']);
        Route::post('/backoffice-tasks/{backOfficeTask}/assign', [BackOfficeTaskController::class, 'assign']);
        Route::post('/backoffice-tasks/{backOfficeTask}/status', [BackOfficeTaskController::class, 'changeStatus']);
    });

    // --- CSV出力 (docs/14-usecases-export.md) ---
    Route::get('/exports/attendance', [ExportController::class, 'attendance'])
        ->middleware('role:admin,hr_staff');
    Route::get('/exports/expenses', [ExportController::class, 'expenses'])
        ->middleware('role:accounting_staff,admin');

    // --- カレンダー・勤務形態 (docs/08-usecases-calendar-shift.md UC-C001〜UC-C003) ---
    Route::get('/work-calendars', [WorkCalendarController::class, 'index']);
    Route::get('/employment-categories', [EmploymentCategoryController::class, 'index']);
    Route::get('/work-styles', [WorkStyleController::class, 'index']);
    Route::get('/employee-shift-assignments', [EmployeeShiftAssignmentController::class, 'index'])->middleware('ability:schedule:self:read');
    Route::get('/shift-patterns', [ShiftPatternController::class, 'index']);
    Route::get('/rotation-patterns', [RotationPatternController::class, 'index']);
    Route::get('/employee-rotation-assignments', [EmployeeRotationAssignmentController::class, 'show']);
    Route::get('/user-work-style-monthly-assignments', [UserWorkStyleMonthlyAssignmentController::class, 'index']);
    Route::middleware('role:admin,hr_staff')->group(function () {
        Route::post('/work-calendars', [WorkCalendarController::class, 'store']);
        Route::post('/work-calendars/{workCalendar}/publish', [WorkCalendarController::class, 'publish']);
        Route::put('/work-calendars/{workCalendar}/days', [WorkCalendarController::class, 'putDays']);
        Route::post('/employment-categories', [EmploymentCategoryController::class, 'store']);
        Route::post('/work-styles', [WorkStyleController::class, 'store']);
        Route::post('/work-styles/default', [WorkStyleController::class, 'storeDefault']);
        Route::post('/work-styles/{workStyle}/set-default', [WorkStyleController::class, 'setDefault']);
        Route::post('/employee-shift-assignments/generate', [EmployeeShiftAssignmentController::class, 'generate']);
        Route::put('/employee-shift-assignments/{employeeShiftAssignment}', [EmployeeShiftAssignmentController::class, 'update']);
        Route::post('/user-work-style-monthly-assignments', [UserWorkStyleMonthlyAssignmentController::class, 'store']);
        Route::delete('/user-work-style-monthly-assignments/{userWorkStyleMonthlyAssignment}', [UserWorkStyleMonthlyAssignmentController::class, 'destroy']);

        // --- 3交代制シフト表 (docs/08-usecases-calendar-shift.md UC-C004) ---
        Route::post('/shift-patterns', [ShiftPatternController::class, 'store']);
        Route::put('/shift-patterns/{shiftPattern}', [ShiftPatternController::class, 'update']);
        Route::post('/employee-shift-assignments/assign-pattern', [EmployeeShiftAssignmentController::class, 'assignPattern']);
        Route::get('/employee-shift-assignments/review', [EmployeeShiftAssignmentController::class, 'review']);
        Route::post('/employee-shift-assignments/publish', [EmployeeShiftAssignmentController::class, 'publish']);

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
        Route::get('/today', [AttendanceController::class, 'today'])->middleware('ability:attendance:self:read');
        Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->middleware('ability:attendance:self:clock');
        Route::post('/break/start', [AttendanceController::class, 'startBreak'])->middleware('ability:attendance:self:clock');
        Route::post('/break/end', [AttendanceController::class, 'endBreak'])->middleware('ability:attendance:self:clock');
        Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->middleware('ability:attendance:self:clock');
        Route::get('/week', [AttendanceController::class, 'week'])->middleware('ability:attendance:self:read');
        Route::get('/day-defaults', [AttendanceController::class, 'dayDefaults'])->middleware('ability:attendance:self:read');
        Route::post('/days', [AttendanceController::class, 'storeDay'])->middleware('ability:attendance:self:update');
        Route::get('/days/{attendanceDay}', [AttendanceController::class, 'showDay'])->middleware('ability:attendance:self:read');
        Route::put('/days/{attendanceDay}', [AttendanceController::class, 'updateDay'])->middleware('ability:attendance:self:update');
        Route::put('/days/{attendanceDay}/calculation', [AttendanceController::class, 'adjustCalculation']);
        Route::delete('/days/{attendanceDay}', [AttendanceController::class, 'destroyDay']);
        Route::post('/legal-holiday-designations', [LegalHolidayDesignationController::class, 'store']);
        Route::get('/months/mine', [AttendanceController::class, 'myMonths'])->middleware('ability:attendance:self:read');
        Route::get('/months/to-approve', [AttendanceController::class, 'monthsToApprove']);
        Route::get('/months/user/{userId}', [AttendanceController::class, 'monthsForUser'])
            ->middleware('role:admin');
        Route::get('/months/{yearMonth}', [AttendanceController::class, 'month'])->middleware('ability:attendance:self:read');
        Route::post('/months/{yearMonth}/submit', [AttendanceController::class, 'submitMonth'])->middleware('ability:attendance:self:submit');
    });

    // --- 作業報告書インポートの差異検出 (docs/26-usecases-monthly-import.md UC-R001) ---
    // ステートレス(何も保存しない)。下書き・インポートセッション自体の保持はmcp/自身のDBで
    // 行う(CLAUDE.mdの設計原則9)。
    Route::post('/attendance/import-preview', [AttendanceImportPreviewController::class, 'check'])
        ->middleware('ability:report:self:import');

    // --- 打刻ログ (docs/07-usecases-attendance.md UC-A012〜UC-A014) ---
    Route::prefix('attendance-punches')->group(function () {
        Route::get('/', [AttendancePunchController::class, 'index'])->middleware('ability:attendance:self:read');
        Route::post('/', [AttendancePunchController::class, 'store'])->middleware('ability:attendance:self:clock');
        Route::put('/{attendancePunch}', [AttendancePunchController::class, 'update']);
        Route::delete('/{attendancePunch}', [AttendancePunchController::class, 'destroy']);
    });

    Route::prefix('attendance-months')->group(function () {
        Route::post('/{attendanceMonth}/approve', [AttendanceController::class, 'approveMonth']);
        Route::post('/{attendanceMonth}/return', [AttendanceController::class, 'returnMonth']);
        Route::post('/{attendanceMonth}/close', [AttendanceController::class, 'closeMonth'])
            ->middleware('role:admin,hr_staff');
    });

    // --- 有給残数管理・申請・承認 (docs/09-usecases-paid-leave.md UC-P001〜UC-P004, UC-P007) ---
    Route::get('/paid-leave/grants/mine', [PaidLeaveController::class, 'myGrants'])->middleware('ability:leave:self:read');
    Route::get('/paid-leave/grant-rules', [PaidLeaveController::class, 'indexRules']);
    Route::get('/paid-leave/requests/mine', [PaidLeaveController::class, 'myRequests'])->middleware('ability:leave:self:read');
    Route::get('/paid-leave/requests/to-approve', [PaidLeaveController::class, 'requestsToApprove']);
    Route::get('/paid-leave/history/mine', [PaidLeaveController::class, 'myHistory'])->middleware('ability:leave:self:read');
    Route::post('/paid-leave/requests', [PaidLeaveController::class, 'storeRequest'])->middleware('ability:leave:self:create');
    Route::post('/paid-leave/requests/{paidLeaveRequest}/approve', [PaidLeaveController::class, 'approveRequest']);
    Route::post('/paid-leave/requests/{paidLeaveRequest}/return', [PaidLeaveController::class, 'returnRequest']);
    Route::post('/paid-leave/requests/{paidLeaveRequest}/cancel', [PaidLeaveController::class, 'cancelRequest']);
    Route::middleware('role:admin,hr_staff')->group(function () {
        Route::post('/paid-leave/grant-rules', [PaidLeaveController::class, 'storeRule']);
        Route::get('/paid-leave/grants/user/{userId}', [PaidLeaveController::class, 'grantsForUser']);
        Route::get('/paid-leave/history/user/{userId}', [PaidLeaveController::class, 'historyForUser']);
        Route::post('/paid-leave/grants', [PaidLeaveController::class, 'grant']);
    });

    // --- 特別休暇の種別マスタ・残数管理・申請・承認(有給と同じUXだが、ビジネスロジックは
    //     App\Domain\SpecialLeaveとして完全に独立させる) ---
    Route::get('/special-leave/types', [SpecialLeaveController::class, 'indexTypes']);
    Route::get('/special-leave/grant-rules', [SpecialLeaveController::class, 'indexRules']);
    Route::get('/special-leave/grants/mine', [SpecialLeaveController::class, 'myGrants']);
    Route::get('/special-leave/requests/mine', [SpecialLeaveController::class, 'myRequests']);
    Route::get('/special-leave/requests/to-approve', [SpecialLeaveController::class, 'requestsToApprove']);
    Route::get('/special-leave/history/mine', [SpecialLeaveController::class, 'myHistory']);
    Route::post('/special-leave/requests', [SpecialLeaveController::class, 'storeRequest']);
    Route::post('/special-leave/requests/{specialLeaveRequest}/approve', [SpecialLeaveController::class, 'approveRequest']);
    Route::post('/special-leave/requests/{specialLeaveRequest}/return', [SpecialLeaveController::class, 'returnRequest']);
    Route::post('/special-leave/requests/{specialLeaveRequest}/cancel', [SpecialLeaveController::class, 'cancelRequest']);
    Route::middleware('role:admin,hr_staff')->group(function () {
        Route::post('/special-leave/types', [SpecialLeaveController::class, 'storeType']);
        Route::put('/special-leave/types/{specialLeaveType}', [SpecialLeaveController::class, 'updateType']);
        Route::post('/special-leave/grant-rules', [SpecialLeaveController::class, 'storeRule']);
        Route::get('/special-leave/grants/user/{userId}', [SpecialLeaveController::class, 'grantsForUser']);
        Route::get('/special-leave/history/user/{userId}', [SpecialLeaveController::class, 'historyForUser']);
        Route::post('/special-leave/grants', [SpecialLeaveController::class, 'grant']);
    });

    // --- 監査ログ (docs/15-usecases-admin.md UC-M003) ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/audit-log', [AuditLogController::class, 'index']);
        Route::get('/audit-log/export', [AuditLogController::class, 'exportCsv']);
    });

    // --- システム設定 (docs/06-usecases-auth.md UC-003) ---
    Route::get('/system-settings', [SystemSettingController::class, 'show'])->middleware('role:admin');
    Route::put('/system-settings', [SystemSettingController::class, 'update'])->middleware('role:admin');

    // --- 端末管理 (docs/23-usecases-devices.md UC-D001〜UC-D005) ---
    Route::get('/users/me/devices', [DeviceController::class, 'indexMine']);
    Route::post('/users/me/devices', [DeviceController::class, 'storePersonal']);
    Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat'])
        ->middleware('ability:recorder:punch,punch:self,device:heartbeat');
    // 端末アプリが一時ペアリングトークン(claim token、device:claim-pairingのみのability)を
    // 業務用の本トークンに交換する。呼び出し元はこの時点でその一時トークンの持ち主自身
    // (Device)であることが認証済みのため、role:adminではなくabilityで絞る。
    Route::post('/devices/pairing/claim', [DeviceController::class, 'claimPairing'])
        ->middleware('ability:device:claim-pairing');
    Route::middleware('role:admin')->group(function () {
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::post('/devices', [DeviceController::class, 'store']);
        Route::get('/devices/{device}', [DeviceController::class, 'show']);
        Route::patch('/devices/{device}', [DeviceController::class, 'update']);
        Route::post('/devices/{device}/pairing', [DeviceController::class, 'issuePairingClaim']);
        Route::post('/devices/{device}/scopes', [DeviceController::class, 'grantScope']);
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);
    });
    // 停止・失効は「本人(個人端末)または管理者」を許可するためController側で判定する
    // (abortUnlessDeviceOwnerOrAdmin)。role:adminミドルウェアでは絞り込まない。
    Route::post('/devices/{device}/disable', [DeviceController::class, 'disable']);
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
