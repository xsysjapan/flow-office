import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from './components/AppLayout/AppLayout'
import { RequireAuth } from './auth/RequireAuth'
import { AuthCallbackPage } from './pages/AuthCallbackPage'
import { LoginPage } from './pages/LoginPage'
import { TodayAttendancePage } from './pages/TodayAttendancePage'
import { WorkflowRequestListPage } from './pages/WorkflowRequestListPage'
import { WorkflowRequestNewPage } from './pages/WorkflowRequestNewPage'
import { WorkflowRequestDetailPage } from './pages/WorkflowRequestDetailPage'
import { ApprovalsPage } from './pages/ApprovalsPage'

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
        <Route path="requests" element={<WorkflowRequestListPage />} />
        <Route path="requests/new" element={<WorkflowRequestNewPage />} />
        <Route path="requests/:id" element={<WorkflowRequestDetailPage />} />
        <Route path="approvals" element={<ApprovalsPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default App
