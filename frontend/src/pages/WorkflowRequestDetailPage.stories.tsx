import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { User, WorkflowRequest } from '../api/types'
import { AuthContext, type AuthContextValue } from '../auth/AuthContext'
import { WorkflowRequestDetailPage } from './WorkflowRequestDetailPage'

const applicant: User = {
  id: 1,
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const approver: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const submittedRequest: WorkflowRequest = {
  id: 1,
  title: 'タクシー代',
  status: 'submitted',
  form_data: { amount: '1200', purpose: '客先訪問' },
  applicant,
  approver,
  request_type: { id: 1, code: 'expense_reimbursement', name: '経費精算', description: null, form_schema: [], requires_backoffice_task: true, backoffice_task_type: 'expense_reimbursement', is_active: true },
  submitted_at: '2026-07-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00+09:00',
}

function withSeeded(request: WorkflowRequest, viewer: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['workflow-requests', request.id], request)

  const authValue: AuthContextValue = {
    user: viewer,
    status: 'authenticated',
    login: fn(),
    completeLogin: fn(),
    logout: fn(),
  }

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[`/requests/${request.id}`]}>
            <Routes>
              <Route path="/requests/:id" element={<WorkflowRequestDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/WorkflowRequestDetailPage',
  component: WorkflowRequestDetailPage,
} satisfies Meta<typeof WorkflowRequestDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const AsApprover: Story = {
  render: withSeeded(submittedRequest, approver),
}

export const AsApplicant: Story = {
  render: withSeeded(submittedRequest, applicant),
}

export const Draft: Story = {
  render: withSeeded({ ...submittedRequest, status: 'draft', submitted_at: null }, applicant),
}
