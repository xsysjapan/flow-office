import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, WorkflowRequest } from '../../api/types'
import { WorkflowRequestListPage } from './WorkflowRequestListPage'

const sample: WorkflowRequest[] = [
  {
    id: 1,
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
    id: 2,
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
  queryClient.setQueryData(['workflow-requests', 'mine'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <WorkflowRequestListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Workflow/WorkflowRequestListPage',
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
