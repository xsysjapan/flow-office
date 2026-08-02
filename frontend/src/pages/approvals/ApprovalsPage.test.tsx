import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

const specialLeaveRow: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-4',
  title: '特別休暇申請',
  subject_type: 'special_leave_request',
  subject_summary: {
    target_date: '2026-08-10',
    leave_type: 'full',
    leave_type_label: '全休',
    special_leave_type_name: '慶弔休暇(忌引)',
    hours: null,
    requested_days: 1,
    reason: null,
  },
}

const paidLeaveRow: WorkflowRequest = {
  ...genericRequest,
  id: 'workflow-request-5',
  title: '有給申請',
  subject_type: 'paid_leave_request',
  subject_summary: {
    target_date: '2026-08-12',
    leave_type: 'am_half',
    leave_type_label: '半休',
    hours: null,
    requested_days: 0.5,
    reason: null,
  },
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
  beforeEach(() => {
    vi.restoreAllMocks()
  })

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

  it('sends status=submitted on initial load', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    const fetchToApprove = vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('名刺作成申請')

    expect(fetchToApprove).toHaveBeenCalledWith({ status: 'submitted', yearMonth: undefined, page: 1 })
  })

  it('changing the status filter refetches with the new status and resets to page 1', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    const fetchToApprove = vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()
    await screen.findByText('名刺作成申請')

    await user.selectOptions(screen.getByLabelText('状態'), '承認済み')

    expect(fetchToApprove).toHaveBeenLastCalledWith({ status: 'approved', yearMonth: undefined, page: 1 })
  })

  it('the "すべて" option omits filtering by sending status=all', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    const fetchToApprove = vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()
    await screen.findByText('名刺作成申請')

    await user.selectOptions(screen.getByLabelText('状態'), 'すべて')

    expect(fetchToApprove).toHaveBeenLastCalledWith({ status: 'all', yearMonth: undefined, page: 1 })
  })

  it('pagination controls call onPageChange and refetch with the new page', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 2, total: 2 },
      links: { next: null, prev: null },
    }
    const fetchToApprove = vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()
    await screen.findByText('名刺作成申請')

    await user.click(screen.getByRole('button', { name: '次のページ' }))

    expect(fetchToApprove).toHaveBeenLastCalledWith({ status: 'submitted', yearMonth: undefined, page: 2 })
  })

  it('shows a subtitle line with the specific special-leave type name, distinguishing it from other special leaves', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [specialLeaveRow, paidLeaveRow],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('特別休暇申請')
    expect(screen.getByText('2026-08-10 慶弔休暇(忌引)')).toBeInTheDocument()
    expect(screen.getByText('2026-08-12 半休')).toBeInTheDocument()
  })

  it('shows the year_month subtitle for an attendance month row', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [attendanceRow],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('2026-07 月次勤怠')
    expect(screen.getAllByText('2026-07').length).toBeGreaterThan(0)
  })

  it('only shows a selection checkbox for actionable rows', async () => {
    const approvedAttendanceRow: WorkflowRequest = {
      ...attendanceRow,
      id: 'workflow-request-approved',
      title: '2026-06 月次勤怠(承認済み)',
      subject_summary: { year_month: '2026-06', status: 'approved' },
    }
    const withData: Paginated<WorkflowRequest> = {
      data: [attendanceRow, approvedAttendanceRow],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('2026-07 月次勤怠')
    expect(screen.getByLabelText('2026-07 月次勤怠を選択')).toBeInTheDocument()
    expect(screen.queryByLabelText('2026-06 月次勤怠(承認済み)を選択')).not.toBeInTheDocument()
  })

  it('bulk-approves selected rows, calling the approve mutation once per selected id and clearing selection', async () => {
    const user = userEvent.setup()
    const secondGenericRequest: WorkflowRequest = {
      ...genericRequest,
      id: 'workflow-request-generic-2',
      title: '交通費申請',
    }
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest, secondGenericRequest],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockImplementation((id: string) =>
      Promise.resolve(id === genericRequest.id ? genericRequest : secondGenericRequest),
    )
    const approveWorkflowRequest = vi.spyOn(workflowRequestsApi, 'approveWorkflowRequest').mockResolvedValue({} as never)

    renderPage()

    await screen.findByText('名刺作成申請')

    await user.click(screen.getByLabelText('名刺作成申請を選択'))
    await user.click(screen.getByLabelText('交通費申請を選択'))
    expect(await screen.findByText('2件を選択中')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'まとめて承認する' }))

    await waitFor(() => expect(approveWorkflowRequest).toHaveBeenCalledTimes(2))
    expect(approveWorkflowRequest).toHaveBeenCalledWith(genericRequest.id)
    expect(approveWorkflowRequest).toHaveBeenCalledWith(secondGenericRequest.id)
    await waitFor(() => expect(screen.queryByText(/件を選択中/)).not.toBeInTheDocument())
  })

  it('clears selection when the status filter changes', async () => {
    const user = userEvent.setup()
    const secondGenericRequest: WorkflowRequest = {
      ...genericRequest,
      id: 'workflow-request-generic-2',
      title: '交通費申請',
    }
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest, secondGenericRequest],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('名刺作成申請')
    await user.click(screen.getByLabelText('名刺作成申請を選択'))
    expect(await screen.findByText('1件を選択中')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('状態'), '承認済み')

    expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument()
  })

  it('clears selection when the year-month filter changes', async () => {
    const user = userEvent.setup()
    const withData: Paginated<WorkflowRequest> = {
      data: [genericRequest],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    await screen.findByText('名刺作成申請')
    await user.click(screen.getByLabelText('名刺作成申請を選択'))
    expect(await screen.findByText('1件を選択中')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '年月' }))
    await user.click(screen.getByRole('button', { name: '今月' }))

    await waitFor(() => expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument())
  })
})
