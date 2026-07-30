import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as attachmentsApi from '../../api/attachments'
import * as attendanceApi from '../../api/attendance'
import * as expenseClaimsApi from '../../api/expenseClaims'
import type { Paginated, WorkflowRequest } from '../../api/types'
import * as workflowRequestsApi from '../../api/workflowRequests'
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

const genericRequest: WorkflowRequest = {
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
}

const attendanceRow: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-2',
  title: '2026-07 月次勤怠',
  subject_type: 'attendance_month',
  subject_summary: { year_month: '2026-07', status: 'submitted' },
}

const attendanceDetail: WorkflowRequest = {
  ...attendanceRow,
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
    days: [],
  },
}

const expenseRow: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-3',
  title: '7月分の立替経費',
  subject_type: 'expense_claim',
  subject_summary: { title: '7月分の立替経費', status: 'in_review', total_amount: 3000 },
}

const expenseDetail: WorkflowRequest = {
  ...expenseRow,
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
    items: [],
  },
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attachmentsApi, 'fetchAttachments').mockResolvedValue([])
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ApprovalsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ApprovalsPage', () => {
  it('shows an empty state when there is nothing to approve', async () => {
    const empty: Paginated<WorkflowRequest> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(empty)

    renderPage()

    expect(await screen.findByText('承認待ちの申請はありません。')).toBeInTheDocument()
  })

  it('lists requests of every subject_type with a type badge', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest, attendanceRow, expenseRow],
      meta: { current_page: 1, last_page: 1, total: 3 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    expect(await screen.findByText('名刺作成申請')).toBeInTheDocument()
    expect(screen.getByText('2026-07 月次勤怠')).toBeInTheDocument()
    expect(screen.getByText('7月分の立替経費')).toBeInTheDocument()
    expect(screen.getByText('申請')).toBeInTheDocument()
    expect(screen.getByText('勤怠')).toBeInTheDocument()
    expect(screen.getByText('経費')).toBeInTheDocument()
  })

  it('opens the detail panel and approves an attendance month via the attendance API, not the workflow-request API', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [attendanceRow],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(attendanceDetail)
    const approveMonth = vi.spyOn(attendanceApi, 'approveMonth').mockResolvedValue({} as never)
    const approveWorkflowRequest = vi.spyOn(workflowRequestsApi, 'approveWorkflowRequest').mockResolvedValue({} as never)

    renderPage()

    await user.click(await screen.findByText('2026-07 月次勤怠'))
    await user.click(await screen.findByRole('button', { name: '承認する' }))

    expect(approveMonth).toHaveBeenCalledWith('attendance-month-1')
    expect(approveWorkflowRequest).not.toHaveBeenCalled()
  })

  it('opens the detail panel and returns an expense claim via the expense-claims API, not the workflow-request API', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [expenseRow],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(expenseDetail)
    const returnExpenseClaim = vi.spyOn(expenseClaimsApi, 'returnExpenseClaim').mockResolvedValue({} as never)
    const returnWorkflowRequest = vi.spyOn(workflowRequestsApi, 'returnWorkflowRequest').mockResolvedValue({} as never)

    renderPage()

    await user.click(await screen.findByText('7月分の立替経費'))
    await user.type(await screen.findByPlaceholderText('差戻しコメント'), '要修正')
    await user.click(screen.getByRole('button', { name: '差戻す' }))

    expect(returnExpenseClaim).toHaveBeenCalledWith('expense-claim-1', '要修正')
    expect(returnWorkflowRequest).not.toHaveBeenCalled()
  })

  it('approves a generic workflow request (subject_type=null) via the workflow-request API', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(genericRequest)
    const approveWorkflowRequest = vi.spyOn(workflowRequestsApi, 'approveWorkflowRequest').mockResolvedValue({} as never)

    renderPage()

    await user.click(await screen.findByText('名刺作成申請'))
    await user.click(await screen.findByRole('button', { name: '承認する' }))

    expect(approveWorkflowRequest).toHaveBeenCalledWith('workflow-request-1')
  })
})
