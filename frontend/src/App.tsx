import { Navigate, Route, Routes } from "react-router-dom";
import { AppLayout } from "./components/AppLayout/AppLayout";
import { AdminLayout } from "./components/AdminLayout/AdminLayout";
import { RequireAuth } from "./auth/RequireAuth";
import { RequireAdminRoute } from "./auth/RequireAdminRoute";
import { AuthCallbackPage } from "./pages/auth/AuthCallbackPage";
import { LoginPage } from "./pages/auth/LoginPage";
import { OnboardingPage } from "./pages/auth/OnboardingPage";
import { TodayAttendancePage } from "./pages/attendance/TodayAttendancePage";
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
import { WorkCalendarYearsPage } from "./pages/workCalendar/WorkCalendarYearsPage";
import { WorkCalendarDaysPage } from "./pages/workCalendar/WorkCalendarDaysPage";
import { HolidayCalendarSourcesPage } from "./pages/workCalendar/HolidayCalendarSourcesPage";
import { OnboardingCalendarPage } from "./pages/workCalendar/OnboardingCalendarPage";
import { CalendarBulkOperationsPage } from "./pages/workCalendar/CalendarBulkOperationsPage";
import { WorkStylesPage } from "./pages/workCalendar/WorkStylesPage";
import { ShiftsPage } from "./pages/workCalendar/ShiftsPage";
import { PaidLeaveAdminPage } from "./pages/paidLeave/PaidLeaveAdminPage";
import { PaidLeaveHistoryAdminPage } from "./pages/paidLeave/PaidLeaveHistoryAdminPage";
import { SpecialLeaveAdminPage } from "./pages/specialLeave/SpecialLeaveAdminPage";
import { SpecialLeaveHistoryAdminPage } from "./pages/specialLeave/SpecialLeaveHistoryAdminPage";
import { AttendanceReferencePage } from "./pages/attendance/AttendanceReferencePage";
import { AuditLogPage } from "./pages/admin/AuditLogPage";
import { AttendanceExportPage } from "./pages/attendance/AttendanceExportPage";
import { SystemSettingsPage } from "./pages/admin/SystemSettingsPage";
import { AdminDashboardPage } from "./pages/admin/AdminDashboardPage";
import { DeviceListPage } from "./pages/admin/DeviceListPage";
import { MyIntegrationsPage } from "./pages/integrations/MyIntegrationsPage";
import { NotificationsPage } from "./pages/notifications/NotificationsPage";
import { AccountSettingsPage } from "./pages/account/AccountSettingsPage";
import {
  AccessControlManagementPage,
  IdentitySettingsPage,
  MembershipChangesPage,
  UserManagementAccessPage,
  UserOperationsPage,
} from "./pages/admin/UserManagementAccessPage";
import { GroupTypeManagementPage } from "./pages/admin/GroupTypeManagementPage";
import { GroupDetailPage } from "./pages/admin/GroupDetailPage";

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
        <Route index element={<TodayAttendancePage />} />
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
            element={<AccessControlManagementPage />}
          />
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
          <Route path="work-calendars" element={<WorkCalendarListPage />} />
          <Route
            path="onboarding/calendar"
            element={<OnboardingCalendarPage />}
          />
          <Route
            path="work-calendars/:id/years"
            element={<WorkCalendarYearsPage />}
          />
          <Route
            path="work-calendar-years/:yearId/days"
            element={<WorkCalendarDaysPage />}
          />
          <Route
            path="holiday-calendar-sources"
            element={<HolidayCalendarSourcesPage />}
          />
          <Route
            path="calendar-bulk-operations"
            element={<CalendarBulkOperationsPage />}
          />
          <Route path="work-styles" element={<WorkStylesPage />} />
          <Route path="shifts" element={<ShiftsPage />} />
          <Route path="paid-leave" element={<PaidLeaveAdminPage />} />
          <Route
            path="paid-leave/history"
            element={<PaidLeaveHistoryAdminPage />}
          />
          <Route path="special-leave" element={<SpecialLeaveAdminPage />} />
          <Route
            path="special-leave/history"
            element={<SpecialLeaveHistoryAdminPage />}
          />
          <Route path="attendance" element={<AttendanceReferencePage />} />
          <Route path="devices" element={<DeviceListPage />} />
          <Route path="audit-log" element={<AuditLogPage />} />
          <Route path="attendance-export" element={<AttendanceExportPage />} />
          <Route path="system-settings" element={<SystemSettingsPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
