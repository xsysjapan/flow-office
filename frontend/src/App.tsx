import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from './components/AppLayout/AppLayout'
import { AdminLayout } from './components/AdminLayout/AdminLayout'
import { RequireAuth } from './auth/RequireAuth'
import { AuthCallbackPage } from './pages/auth/AuthCallbackPage'
import { LoginPage } from './pages/auth/LoginPage'
import { TodayAttendancePage } from './pages/attendance/TodayAttendancePage'
import { WeekAttendancePage } from './pages/attendance/WeekAttendancePage'
import { AttendanceDayPage } from './pages/attendance/AttendanceDayPage'
import { AttendanceMonthDetailPage } from './pages/attendance/AttendanceMonthDetailPage'
import { WorkflowRequestListPage } from './pages/workflow/WorkflowRequestListPage'
import { WorkflowRequestNewPage } from './pages/workflow/WorkflowRequestNewPage'
import { WorkflowRequestDetailPage } from './pages/workflow/WorkflowRequestDetailPage'
import { ApprovalsPage } from './pages/workflow/ApprovalsPage'
import { AttendanceMonthsPage } from './pages/attendance/AttendanceMonthsPage'
import { MonthsToApprovePage } from './pages/attendance/MonthsToApprovePage'
import { MyPaidLeavePage } from './pages/paidLeave/MyPaidLeavePage'
import { MyPaidLeaveHistoryPage } from './pages/paidLeave/MyPaidLeaveHistoryPage'
import { PaidLeaveRequestsToApprovePage } from './pages/paidLeave/PaidLeaveRequestsToApprovePage'
import { MySpecialLeavePage } from './pages/specialLeave/MySpecialLeavePage'
import { MySpecialLeaveHistoryPage } from './pages/specialLeave/MySpecialLeaveHistoryPage'
import { SpecialLeaveRequestsToApprovePage } from './pages/specialLeave/SpecialLeaveRequestsToApprovePage'
import { BackOfficeTaskListPage } from './pages/backOffice/BackOfficeTaskListPage'
import { BackOfficeTaskDetailPage } from './pages/backOffice/BackOfficeTaskDetailPage'
import { UserListPage } from './pages/admin/UserListPage'
import { UserRoleEditPage } from './pages/admin/UserRoleEditPage'
import { RequestTypeListPage } from './pages/workflow/RequestTypeListPage'
import { RequestTypeEditPage } from './pages/workflow/RequestTypeEditPage'
import { WorkCalendarListPage } from './pages/workCalendar/WorkCalendarListPage'
import { WorkCalendarDaysPage } from './pages/workCalendar/WorkCalendarDaysPage'
import { WorkStylesAndShiftsPage } from './pages/workCalendar/WorkStylesAndShiftsPage'
import { PaidLeaveAdminPage } from './pages/paidLeave/PaidLeaveAdminPage'
import { PaidLeaveHistoryAdminPage } from './pages/paidLeave/PaidLeaveHistoryAdminPage'
import { SpecialLeaveAdminPage } from './pages/specialLeave/SpecialLeaveAdminPage'
import { SpecialLeaveHistoryAdminPage } from './pages/specialLeave/SpecialLeaveHistoryAdminPage'
import { AttendanceReferencePage } from './pages/attendance/AttendanceReferencePage'
import { AuditLogPage } from './pages/admin/AuditLogPage'
import { AttendanceExportPage } from './pages/attendance/AttendanceExportPage'
import { SystemSettingsPage } from './pages/admin/SystemSettingsPage'
import { AdminDashboardPage } from './pages/admin/AdminDashboardPage'
import { DeviceListPage } from './pages/admin/DeviceListPage'
import { MyIntegrationsPage } from './pages/integrations/MyIntegrationsPage'

function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
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
        <Route path="attendance/months" element={<AttendanceMonthsPage />} />
        <Route path="attendance/months/to-approve" element={<MonthsToApprovePage />} />
        <Route path="attendance/months/:yearMonth" element={<AttendanceMonthDetailPage />} />
        <Route path="paid-leave" element={<MyPaidLeavePage />} />
        <Route path="paid-leave/history" element={<MyPaidLeaveHistoryPage />} />
        <Route path="paid-leave/to-approve" element={<PaidLeaveRequestsToApprovePage />} />
        <Route path="special-leave" element={<MySpecialLeavePage />} />
        <Route path="special-leave/history" element={<MySpecialLeaveHistoryPage />} />
        <Route path="special-leave/to-approve" element={<SpecialLeaveRequestsToApprovePage />} />
        <Route path="backoffice-tasks" element={<BackOfficeTaskListPage />} />
        <Route path="backoffice-tasks/:id" element={<BackOfficeTaskDetailPage />} />
        <Route path="integrations" element={<MyIntegrationsPage />} />
        <Route path="admin" element={<AdminLayout />}>
          <Route index element={<AdminDashboardPage />} />
          <Route path="users" element={<UserListPage />} />
          <Route path="users/:id" element={<UserRoleEditPage />} />
          <Route path="request-types" element={<RequestTypeListPage />} />
          <Route path="request-types/:id" element={<RequestTypeEditPage />} />
          <Route path="work-calendars" element={<WorkCalendarListPage />} />
          <Route path="work-calendars/:id/days" element={<WorkCalendarDaysPage />} />
          <Route path="work-styles" element={<WorkStylesAndShiftsPage />} />
          <Route path="paid-leave" element={<PaidLeaveAdminPage />} />
          <Route path="paid-leave/history" element={<PaidLeaveHistoryAdminPage />} />
          <Route path="special-leave" element={<SpecialLeaveAdminPage />} />
          <Route path="special-leave/history" element={<SpecialLeaveHistoryAdminPage />} />
          <Route path="attendance" element={<AttendanceReferencePage />} />
          <Route path="devices" element={<DeviceListPage />} />
          <Route path="audit-log" element={<AuditLogPage />} />
          <Route path="attendance-export" element={<AttendanceExportPage />} />
          <Route path="system-settings" element={<SystemSettingsPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default App
