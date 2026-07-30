import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { WorkflowRequest } from '../../api/types'
import { ApprovalDetailPanel } from './ApprovalDetailPanel'

const applicant = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const genericRequest: WorkflowRequest = {
  id: 'workflow-request-1',
  title: '名刺作成申請',
  status: 'submitted',
  form_data: { 部数: '100', 部署: '営業部' },
  applicant,
  submitted_at: '2026-07-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00+09:00',
  subject_type: null,
}

const attendanceRequest: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-2',
  title: '2026-07 月次勤怠',
  subject_type: 'attendance_month',
  subject: {
    type: 'attendance_month',
    id: 'attendance-month-1',
    user_id: 'applicant-1',
    year_month: '2026-07',
    status: 'submitted',
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    return_comment: null,
    days: [
      {
        id: 'day-1',
        work_date: '2026-07-01',
        status: 'clocked_out',
        actual_start_at: '2026-07-01T09:00:00+09:00',
        actual_end_at: '2026-07-01T18:00:00+09:00',
        breaks: [{ id: 1, break_start_at: '2026-07-01T12:00:00+09:00', break_end_at: '2026-07-01T13:00:00+09:00' }],
      },
    ],
  },
}

const expenseRequest: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-3',
  title: '7月分の立替経費',
  subject_type: 'expense_claim',
  subject: {
    type: 'expense_claim',
    id: 'expense-claim-1',
    employee_id: 'applicant-1',
    title: '7月分の立替経費',
    status: 'in_review',
    total_amount: 3000,
    period_from: '2026-07-01',
    period_to: '2026-07-31',
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    items: [
      {
        id: 'item-1',
        category_id: 1,
        category_name: 'タクシー代',
        usage_date: '2026-07-10',
        description: '来客対応',
        amount: 3000,
        commuting_deduction_amount: null,
        reimbursement_amount: 3000,
        payment_bearer: 'employee',
      },
    ],
  },
}

const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
queryClient.setQueryData(['attachments', 'workflow_request', 'workflow-request-1'], [])

const meta = {
  title: 'Components/ApprovalDetailPanel',
  component: ApprovalDetailPanel,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <QueryClientProvider client={queryClient}>
        <Story />
      </QueryClientProvider>
    ),
  ],
  args: {
    onApprove: () => {},
    onReturn: () => {},
  },
} satisfies Meta<typeof ApprovalDetailPanel>

export default meta
type Story = StoryObj<typeof meta>

export const GenericRequest: Story = {
  args: { request: genericRequest },
}

export const AttendanceMonth: Story = {
  args: { request: attendanceRequest },
}

export const ExpenseClaim: Story = {
  args: { request: expenseRequest },
}

export const NotActionable: Story = {
  args: { request: { ...expenseRequest, subject: { ...expenseRequest.subject!, status: 'approved' } } },
}
