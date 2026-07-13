import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { Paginated, RequestType, User } from '../api/types'
import { WorkflowRequestNewPage } from './WorkflowRequestNewPage'

const expenseType: RequestType = {
  id: 1,
  code: 'expense_reimbursement',
  name: '経費精算',
  description: null,
  form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
  requires_attachment: false,
  attachment_max_size_kb: null,
  attachment_allowed_extensions: null,
  eligible_role_codes: null,
  requires_backoffice_task: true,
  backoffice_task_type: 'expense_reimbursement',
  backoffice_department: null,
  export_amount_field: null,
  allowed_status_transitions: null,
  is_active: true,
}

const businessCardType: RequestType = {
  id: 2,
  code: 'business_card',
  name: '名刺申請',
  description: null,
  form_schema: [{ key: 'quantity', label: '枚数', type: 'number', required: true }],
  requires_attachment: false,
  attachment_max_size_kb: null,
  attachment_allowed_extensions: null,
  eligible_role_codes: null,
  requires_backoffice_task: true,
  backoffice_task_type: 'business_card',
  backoffice_department: null,
  export_amount_field: null,
  allowed_status_transitions: null,
  is_active: true,
}

const requestTypes: RequestType[] = [expenseType, businessCardType]

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['request-types', false], requestTypes)
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requests/new']}>
          <Routes>
            <Route path="/requests/new" element={<WorkflowRequestNewPage />} />
            <Route path="/requests/:id" element={<p>申請詳細ページ</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkflowRequestNewPage',
  component: WorkflowRequestNewPage,
} satisfies Meta<typeof WorkflowRequestNewPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
