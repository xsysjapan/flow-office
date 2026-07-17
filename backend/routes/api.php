<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendancePunchController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackOfficeTaskController;
use App\Http\Controllers\Api\EmployeeRotationAssignmentController;
use App\Http\Controllers\Api\EmployeeShiftAssignmentController;
use App\Http\Controllers\Api\EmploymentCategoryController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\LegalHolidayDesignationController;
use App\Http\Controllers\Api\MockOidcUserController;
use App\Http\Controllers\Api\PaidLeaveController;
use App\Http\Controllers\Api\RequestTypeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RotationPatternController;
use App\Http\Controllers\Api\ShiftPatternController;
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
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// mock-oidc(ローカル開発用OIDCモックサーバー)がログイン画面のユーザー一覧を取得するための
// 開発専用エンドポイント。認証不要(ログイン前に呼ばれるため)。MICROSOFT_MOCK_ENABLED=false
// では404を返す(MockOidcUserController参照)。
Route::get('/dev/mock-users', [MockOidcUserController::class, 'index']);

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
    Route::get('/employee-shift-assignments', [EmployeeShiftAssignmentController::class, 'index']);
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
    Route::prefix('attendance')->group(function () {
        Route::get('/today', [AttendanceController::class, 'today']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/break/start', [AttendanceController::class, 'startBreak']);
        Route::post('/break/end', [AttendanceController::class, 'endBreak']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('/week', [AttendanceController::class, 'week']);
        Route::get('/day-defaults', [AttendanceController::class, 'dayDefaults']);
        Route::post('/days', [AttendanceController::class, 'storeDay']);
        Route::get('/days/{attendanceDay}', [AttendanceController::class, 'showDay']);
        Route::put('/days/{attendanceDay}', [AttendanceController::class, 'updateDay']);
        Route::put('/days/{attendanceDay}/calculation', [AttendanceController::class, 'adjustCalculation']);
        Route::delete('/days/{attendanceDay}', [AttendanceController::class, 'destroyDay']);
        Route::post('/legal-holiday-designations', [LegalHolidayDesignationController::class, 'store']);
        Route::get('/months/mine', [AttendanceController::class, 'myMonths']);
        Route::get('/months/to-approve', [AttendanceController::class, 'monthsToApprove']);
        Route::get('/months/user/{userId}', [AttendanceController::class, 'monthsForUser'])
            ->middleware('role:admin');
        Route::get('/months/{yearMonth}', [AttendanceController::class, 'month']);
        Route::post('/months/{yearMonth}/submit', [AttendanceController::class, 'submitMonth']);
    });

    // --- 打刻ログ (docs/07-usecases-attendance.md UC-A012〜UC-A014) ---
    Route::prefix('attendance-punches')->group(function () {
        Route::get('/', [AttendancePunchController::class, 'index']);
        Route::post('/', [AttendancePunchController::class, 'store']);
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
    Route::get('/paid-leave/grants/mine', [PaidLeaveController::class, 'myGrants']);
    Route::get('/paid-leave/grant-rules', [PaidLeaveController::class, 'indexRules']);
    Route::get('/paid-leave/requests/mine', [PaidLeaveController::class, 'myRequests']);
    Route::get('/paid-leave/requests/to-approve', [PaidLeaveController::class, 'requestsToApprove']);
    Route::get('/paid-leave/history/mine', [PaidLeaveController::class, 'myHistory']);
    Route::post('/paid-leave/requests', [PaidLeaveController::class, 'storeRequest']);
    Route::post('/paid-leave/requests/{paidLeaveRequest}/approve', [PaidLeaveController::class, 'approveRequest']);
    Route::post('/paid-leave/requests/{paidLeaveRequest}/return', [PaidLeaveController::class, 'returnRequest']);
    Route::post('/paid-leave/requests/{paidLeaveRequest}/cancel', [PaidLeaveController::class, 'cancelRequest']);
    Route::middleware('role:admin,hr_staff')->group(function () {
        Route::post('/paid-leave/grant-rules', [PaidLeaveController::class, 'storeRule']);
        Route::get('/paid-leave/grants/user/{userId}', [PaidLeaveController::class, 'grantsForUser']);
        Route::get('/paid-leave/history/user/{userId}', [PaidLeaveController::class, 'historyForUser']);
        Route::post('/paid-leave/grants', [PaidLeaveController::class, 'grant']);
    });

    // --- 監査ログ (docs/15-usecases-admin.md UC-M003) ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/audit-log', [AuditLogController::class, 'index']);
        Route::get('/audit-log/export', [AuditLogController::class, 'exportCsv']);
    });

    // --- システム設定 (docs/06-usecases-auth.md UC-003) ---
    Route::get('/system-settings', [SystemSettingController::class, 'show'])->middleware('role:admin');
    Route::put('/system-settings', [SystemSettingController::class, 'update'])->middleware('role:admin');
});
