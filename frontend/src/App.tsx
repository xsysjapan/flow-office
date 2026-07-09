import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from './components/AppLayout/AppLayout'
import { RequireAuth } from './auth/RequireAuth'
import { AuthCallbackPage } from './pages/AuthCallbackPage'
import { LoginPage } from './pages/LoginPage'
import { TodayAttendancePage } from './pages/TodayAttendancePage'
import { WeekAttendancePage } from './pages/WeekAttendancePage'
import { WorkflowRequestListPage } from './pages/WorkflowRequestListPage'
import { WorkflowRequestNewPage } from './pages/WorkflowRequestNewPage'
import { WorkflowRequestDetailPage } from './pages/WorkflowRequestDetailPage'
import { ApprovalsPage } from './pages/ApprovalsPage'
import { AttendanceMonthsPage } from './pages/AttendanceMonthsPage'
import { MonthsToApprovePage } from './pages/MonthsToApprovePage'
import { MyPaidLeavePage } from './pages/MyPaidLeavePage'
import { BackOfficeTaskListPage } from './pages/BackOfficeTaskListPage'
import { BackOfficeTaskDetailPage } from './pages/BackOfficeTaskDetailPage'
import { UserListPage } from './pages/UserListPage'
import { UserRoleEditPage } from './pages/UserRoleEditPage'
import { RequestTypeListPage } from './pages/RequestTypeListPage'
import { RequestTypeEditPage } from './pages/RequestTypeEditPage'
import { WorkCalendarListPage } from './pages/WorkCalendarListPage'
import { WorkCalendarDaysPage } from './pages/WorkCalendarDaysPage'
import { WorkStylesAndShiftsPage } from './pages/WorkStylesAndShiftsPage'
import { PaidLeaveAdminPage } from './pages/PaidLeaveAdminPage'
import { AuditLogPage } from './pages/AuditLogPage'

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
        <Route path="requests" element={<WorkflowRequestListPage />} />
        <Route path="requests/new" element={<WorkflowRequestNewPage />} />
        <Route path="requests/:id" element={<WorkflowRequestDetailPage />} />
        <Route path="approvals" element={<ApprovalsPage />} />
        <Route path="attendance/months" element={<AttendanceMonthsPage />} />
        <Route path="attendance/months/to-approve" element={<MonthsToApprovePage />} />
        <Route path="paid-leave" element={<MyPaidLeavePage />} />
        <Route path="backoffice-tasks" element={<BackOfficeTaskListPage />} />
        <Route path="backoffice-tasks/:id" element={<BackOfficeTaskDetailPage />} />
        <Route path="admin/users" element={<UserListPage />} />
        <Route path="admin/users/:id" element={<UserRoleEditPage />} />
        <Route path="admin/request-types" element={<RequestTypeListPage />} />
        <Route path="admin/request-types/:id" element={<RequestTypeEditPage />} />
        <Route path="admin/work-calendars" element={<WorkCalendarListPage />} />
        <Route path="admin/work-calendars/:id/days" element={<WorkCalendarDaysPage />} />
        <Route path="admin/work-styles" element={<WorkStylesAndShiftsPage />} />
        <Route path="admin/paid-leave" element={<PaidLeaveAdminPage />} />
        <Route path="admin/audit-log" element={<AuditLogPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default App
