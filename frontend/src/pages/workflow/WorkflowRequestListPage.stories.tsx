import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { Paginated, User, WorkflowRequest } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { WorkflowRequestListPage } from './WorkflowRequestListPage'

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const authValue: AuthContextValue = {
  user: currentUser,
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const sample: WorkflowRequest[] = [
  {
    id: 'workflow-request-1',
    title: 'タクシー代',
    status: 'approved',
    form_data: { amount: 1200 },
    request_type: { id: 1, code: 'expense_reimbursement', name: '経費精算', description: null, form_schema: [], requires_attachment: false, attachment_max_size_kb: null, attachment_allowed_extensions: null, eligible_role_codes: null, requires_backoffice_task: true, backoffice_task_type: 'expense_reimbursement', backoffice_department: null, export_amount_field: null, allowed_status_transitions: null, is_active: true },
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: '2026-07-02T00:00:00+09:00',
    returned_at: null,
    cancelled_at: null,
    created_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 'workflow-request-2',
    title: '名刺の再作成',
    status: 'submitted',
    form_data: { quantity: 100 },
    request_type: { id: 2, code: 'business_card', name: '名刺申請', description: null, form_schema: [], requires_attachment: false, attachment_max_size_kb: null, attachment_allowed_extensions: null, eligible_role_codes: null, requires_backoffice_task: true, backoffice_task_type: 'business_card', backoffice_department: null, export_amount_field: null, allowed_status_transitions: null, is_active: true },
    submitted_at: '2026-07-05T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
    created_at: '2026-07-05T00:00:00+09:00',
  },
]

function withSeededList(data: Paginated<WorkflowRequest>) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['workflow-requests', 'mine', 'all', 'all', 1], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={authValue}>
          <MemoryRouter>
            <WorkflowRequestListPage />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Workflow/RequestCenter',
  component: WorkflowRequestListPage,
} satisfies Meta<typeof WorkflowRequestListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRequests: Story = {
  render: withSeededList({ data: sample, meta: { current_page: 1, last_page: 1, total: 2 }, links: { next: null, prev: null } }),
}

export const Empty: Story = {
  render: withSeededList({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }),
}
