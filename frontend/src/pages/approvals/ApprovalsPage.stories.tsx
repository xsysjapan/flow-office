import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, WorkflowRequest } from '../../api/types'
import { ApprovalsPage } from './ApprovalsPage'

const applicant = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const sample: WorkflowRequest[] = [
  {
    id: 'workflow-request-1',
    title: '名刺作成申請',
    status: 'submitted',
    form_data: { 部数: '100' },
    applicant,
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
    created_at: '2026-07-01T00:00:00+09:00',
    subject_type: null,
  },
  {
    id: 'workflow-request-2',
    title: '2026-07 月次勤怠',
    status: 'submitted',
    form_data: {},
    applicant,
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
    created_at: '2026-08-01T00:00:00+09:00',
    subject_type: 'attendance_month',
    subject_summary: { year_month: '2026-07', status: 'submitted' },
  },
  {
    id: 'workflow-request-3',
    title: '7月分の立替経費',
    status: 'submitted',
    form_data: {},
    applicant,
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
    created_at: '2026-08-01T00:00:00+09:00',
    subject_type: 'expense_claim',
    subject_summary: { title: '7月分の立替経費', status: 'in_review', total_amount: 3000 },
  },
]

function withSeededList(data: Paginated<WorkflowRequest>) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['workflow-requests', 'to-approve'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ApprovalsPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Approvals/ApprovalsPage',
  component: ApprovalsPage,
} satisfies Meta<typeof ApprovalsPage>

export default meta
type Story = StoryObj<typeof meta>

export const MixedSubjectTypes: Story = {
  render: withSeededList({ data: sample, meta: { current_page: 1, last_page: 1, total: sample.length }, links: { next: null, prev: null } }),
}

export const Empty: Story = {
  render: withSeededList({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }),
}
