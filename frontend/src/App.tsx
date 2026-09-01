import { Navigate, Route, Routes } from "react-router-dom";
import { AppLayout } from "./components/AppLayout/AppLayout";
import { AdminLayout } from "./components/AdminLayout/AdminLayout";
import { RequireAuth } from "./auth/RequireAuth";
import { RequireAdminRoute } from "./auth/RequireAdminRoute";
import { AuthCallbackPage } from "./pages/auth/AuthCallbackPage";
import { LoginPage } from "./pages/auth/LoginPage";
import { OnboardingPage } from "./pages/auth/OnboardingPage";
import { HomeDashboardPage } from "./pages/home/HomeDashboardPage";
import { WeekAttendancePage } from "./pages/attendance/WeekAttendancePage";
import { AttendanceDayPage } from "./pages/attendance/AttendanceDayPage";
import { AttendanceMonthDetailPage } from "./pages/attendance/AttendanceMonthDetailPage";
import { ExpenseCategoryListPage } from "./pages/expense/ExpenseCategoryListPage";
import { ExpenseCategoryEditPage } from "./pages/expense/ExpenseCategoryEditPage";
import { ExpenseEntryPresetListPage } from "./pages/expense/ExpenseEntryPresetListPage";
import { ExpenseEntryPresetEditPage } from "./pages/expense/ExpenseEntryPresetEditPage";
import { ExpenseClaimListPage } from "./pages/expense/ExpenseClaimListPage";
import { ExpenseClaimNewPage } from "./pages/expense/ExpenseClaimNewPage";
import { ExpenseClaimDetailPage } from "./pages/expense/ExpenseClaimDetailPage";
import { WorkflowRequestListPage } from "./pages/workflow/WorkflowRequestListPage";
import { WorkflowRequestNewPage } from "./pages/workflow/WorkflowRequestNewPage";
import { WorkflowRequestDetailPage } from "./pages/workflow/WorkflowRequestDetailPage";
import { ApprovalsPage } from "./pages/approvals/ApprovalsPage";
import { AttendanceMonthsPage } from "./pages/attendance/AttendanceMonthsPage";
import { MyPaidLeavePage } from "./pages/paidLeave/MyPaidLeavePage";
import { MyPaidLeaveHistoryPage } from "./pages/paidLeave/MyPaidLeaveHistoryPage";
import { MyCompensatoryLeavePage } from "./pages/compensatoryLeave/MyCompensatoryLeavePage";
import { MySpecialLeavePage } from "./pages/specialLeave/MySpecialLeavePage";
import { MySpecialLeaveHistoryPage } from "./pages/specialLeave/MySpecialLeaveHistoryPage";
import { BackOfficeTaskListPage } from "./pages/backOffice/BackOfficeTaskListPage";
import { BackOfficeTaskDetailPage } from "./pages/backOffice/BackOfficeTaskDetailPage";
import { UserListPage } from "./pages/admin/UserListPage";
import { UserRoleEditPage } from "./pages/admin/UserRoleEditPage";
import { RequestTypeListPage } from "./pages/workflow/RequestTypeListPage";
import { RequestTypeEditPage } from "./pages/workflow/RequestTypeEditPage";
import { WorkCalendarListPage } from "./pages/workCalendar/WorkCalendarListPage";
import { WorkCalendarCreatePage } from "./pages/workCalendar/WorkCalendarCreatePage";
import { WorkCalendarDetailPage } from "./pages/workCalendar/WorkCalendarDetailPage";
import { WorkCalendarDaysPage } from "./pages/workCalendar/WorkCalendarDaysPage";
import { CalendarBulkOperationsPage } from "./pages/workCalendar/CalendarBulkOperationsPage";
import { WorkStylesPage } from "./pages/workCalendar/WorkStylesPage";
import { ShiftsPage } from "./pages/workCalendar/ShiftsPage";
import { PaidLeaveAdminPage } from "./pages/paidLeave/PaidLeaveAdminPage";
import { SpecialLeaveAdminPage } from "./pages/specialLeave/SpecialLeaveAdminPage";
import { CompensatoryLeaveAdminPage } from "./pages/compensatoryLeave/CompensatoryLeaveAdminPage";
import { AttendanceReferencePage } from "./pages/attendance/AttendanceReferencePage";
import { AuditLogPage } from "./pages/admin/AuditLogPage";
import { AttendanceExportPage } from "./pages/attendance/AttendanceExportPage";
import { SystemSettingsPage } from "./pages/admin/SystemSettingsPage";
import { AdminDashboardPage } from "./pages/admin/AdminDashboardPage";
import { DeviceListPage } from "./pages/admin/DeviceListPage";
import { ExternalIntegrationConnectionsPage } from "./pages/admin/ExternalIntegrationConnectionsPage";
import { MyIntegrationsPage } from "./pages/integrations/MyIntegrationsPage";
import { NotificationsPage } from "./pages/notifications/NotificationsPage";
import { AccountSettingsPage } from "./pages/account/AccountSettingsPage";
import { AssetListPage } from "./pages/asset/AssetListPage";
import { AssetDetailPage } from "./pages/asset/AssetDetailPage";
import { AssetQrRedirectPage } from "./pages/asset/AssetQrRedirectPage";
import { AssetRegisterPage } from "./pages/asset/AssetRegisterPage";
import { AssetEditPage } from "./pages/asset/AssetEditPage";
import { SelfBulkLoanPage } from "./pages/asset/bulk/SelfBulkLoanPage";
import { SelfBulkReturnPage } from "./pages/asset/bulk/SelfBulkReturnPage";
import { BackofficeBulkLendPage } from "./pages/asset/bulk/BackofficeBulkLendPage";
import { BackofficeBulkReturnPage } from "./pages/asset/bulk/BackofficeBulkReturnPage";
import { BulkRelocatePage } from "./pages/asset/bulk/BulkRelocatePage";
import {
  IdentitySettingsPage,
  MembershipChangesPage,
  UserManagementAccessPage,
  UserOperationsPage,
} from "./pages/admin/UserManagementAccessPage";
import { GroupTypeManagementPage } from "./pages/admin/GroupTypeManagementPage";
import { AssetNumberRuleListPage } from "./pages/admin/AssetNumberRuleListPage";
import { GroupDetailPage } from "./pages/admin/GroupDetailPage";
import { AdminCommandsPage } from "./pages/admin/AdminCommandsPage";
import { RoleDefinitionPage } from "./pages/admin/RoleDefinitionPage";

function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/onboarding" element={<OnboardingPage />} />
      <Route path="/auth/callback" element={<AuthCallbackPage />} />
      <Route
        path="/"
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        {/*
          ログイン後の着地点は案C(画面遷移再設計)でホームダッシュボードに変更した。
          今日の勤怠(旧: "/" → 一時的に"/attendance")は、独立ページとしてではなく
          `TodayAttendancePanel`としてホームダッシュボードに統合済み。旧URL"/attendance"への
          ブックマーク・直リンクが残っている可能性があるため、リンク切れを避けてホームへ
          リダイレクトする(コンポーネント自体・打刻の挙動は変更していない)。
        */}
        <Route index element={<HomeDashboardPage />} />
        <Route path="attendance" element={<Navigate to="/" replace />} />
        <Route path="attendance/week" element={<WeekAttendancePage />} />
        <Route path="attendance/days/:date" element={<AttendanceDayPage />} />
        <Route path="requests" element={<WorkflowRequestListPage />} />
        <Route path="requests/new" element={<WorkflowRequestNewPage />} />
        <Route path="requests/:id" element={<WorkflowRequestDetailPage />} />
        <Route path="approvals" element={<ApprovalsPage />} />
        <Route path="expenses" element={<ExpenseClaimListPage />} />
        <Route path="expenses/new" element={<ExpenseClaimNewPage />} />
        <Route
          path="expenses/presets"
          element={<ExpenseEntryPresetListPage />}
        />
        <Route
          path="expenses/presets/new"
          element={<ExpenseEntryPresetEditPage />}
        />
        <Route
          path="expenses/presets/:id"
          element={<ExpenseEntryPresetEditPage />}
        />
        <Route path="expenses/:id/edit" element={<ExpenseClaimNewPage />} />
        <Route path="expenses/:id" element={<ExpenseClaimDetailPage />} />
        <Route path="attendance/months" element={<AttendanceMonthsPage />} />
        <Route
          path="attendance/months/:yearMonth"
          element={<AttendanceMonthDetailPage />}
        />
        <Route path="paid-leave" element={<MyPaidLeavePage />} />
        <Route path="paid-leave/history" element={<MyPaidLeaveHistoryPage />} />
        <Route path="compensatory-leave" element={<MyCompensatoryLeavePage />} />
        <Route path="special-leave" element={<MySpecialLeavePage />} />
        <Route
          path="special-leave/history"
          element={<MySpecialLeaveHistoryPage />}
        />
        <Route path="backoffice-tasks" element={<BackOfficeTaskListPage />} />
        <Route
          path="backoffice-tasks/:id"
          element={<BackOfficeTaskDetailPage />}
        />
        <Route path="assets" element={<AssetListPage />} />
        <Route path="assets/new" element={<AssetRegisterPage />} />
        <Route path="assets/bulk/self-loan" element={<SelfBulkLoanPage />} />
        <Route path="assets/bulk/self-return" element={<SelfBulkReturnPage />} />
        <Route path="assets/bulk/lend" element={<BackofficeBulkLendPage />} />
        <Route path="assets/bulk/return" element={<BackofficeBulkReturnPage />} />
        <Route path="assets/bulk/relocate" element={<BulkRelocatePage />} />
        <Route path="assets/qr/:token" element={<AssetQrRedirectPage />} />
        <Route path="assets/:id" element={<AssetDetailPage />} />
        <Route path="assets/:id/edit" element={<AssetEditPage />} />
        <Route path="account" element={<AccountSettingsPage />} />
        <Route path="integrations" element={<MyIntegrationsPage />} />
        <Route path="notifications" element={<NotificationsPage />} />
        <Route
          path="admin"
          element={
            <RequireAdminRoute>
              <AdminLayout />
            </RequireAdminRoute>
          }
        >
          <Route index element={<AdminDashboardPage />} />
          <Route path="users" element={<UserListPage />} />
          <Route
            path="users/operations"
            element={<Navigate to="/admin/hr-import" replace />}
          />
          <Route path="users/:id" element={<UserRoleEditPage />} />
          <Route path="groups" element={<UserManagementAccessPage />} />
          <Route path="groups/:id" element={<GroupDetailPage />} />
          <Route
            path="membership-changes"
            element={<MembershipChangesPage />}
          />
          <Route path="hr-import" element={<UserOperationsPage />} />
          <Route
            path="access-control"
            element={<Navigate to="/admin/access/roles" replace />}
          />
          <Route
            path="access/assignments"
            element={<Navigate to="/admin/access/roles" replace />}
          />
          <Route path="access/roles" element={<RoleDefinitionPage />} />
          <Route path="identity-settings" element={<IdentitySettingsPage />} />
          <Route path="group-types" element={<GroupTypeManagementPage />} />
          <Route path="request-types" element={<RequestTypeListPage />} />
          <Route path="request-types/:id" element={<RequestTypeEditPage />} />
          <Route
            path="expense-categories"
            element={<ExpenseCategoryListPage />}
          />
          <Route
            path="expense-categories/:id"
            element={<ExpenseCategoryEditPage />}
          />
          <Route
            path="asset-number-rules"
            element={<AssetNumberRuleListPage />}
          />
          <Route path="work-calendars" element={<WorkCalendarListPage />} />
          <Route
            path="work-calendars/new"
            element={<WorkCalendarCreatePage />}
          />
          <Route
            path="work-calendars/:id"
            element={<WorkCalendarDetailPage />}
          />
          <Route
            path="work-calendars/:calendarId/years/:fiscalYear/days"
            element={<WorkCalendarDaysPage />}
          />
          <Route
            path="calendar-bulk-operations"
            element={<CalendarBulkOperationsPage />}
          />
          <Route path="work-styles" element={<WorkStylesPage />} />
          <Route path="shifts" element={<ShiftsPage />} />
          <Route path="paid-leave" element={<PaidLeaveAdminPage />} />
          <Route path="special-leave" element={<SpecialLeaveAdminPage />} />
          <Route
            path="compensatory-leave"
            element={<CompensatoryLeaveAdminPage />}
          />
          <Route path="attendance" element={<AttendanceReferencePage />} />
          <Route path="devices" element={<DeviceListPage />} />
          <Route path="audit-log" element={<AuditLogPage />} />
          <Route path="attendance-export" element={<AttendanceExportPage />} />
          <Route path="system-settings" element={<SystemSettingsPage />} />
          <Route path="commands" element={<AdminCommandsPage />} />
          <Route
            path="external-integration-connections"
            element={<ExternalIntegrationConnectionsPage />}
          />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
