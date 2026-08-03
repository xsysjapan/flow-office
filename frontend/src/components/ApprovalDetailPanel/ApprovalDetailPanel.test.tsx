import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as attachmentsApi from '../../api/attachments'
import * as attendanceApi from '../../api/attendance'
import type { AttendanceMonthlyCalculationTotals, WorkflowRequest } from '../../api/types'
import { ApprovalDetailPanel } from './ApprovalDetailPanel'

const zeroMonthlyCalculationTotals: AttendanceMonthlyCalculationTotals = {
  work_minutes: 0,
  payroll_work_minutes: 0,
  prescribed_work_minutes: 0,
  statutory_within_overtime_minutes: 0,
  statutory_excess_overtime_minutes: 0,
  statutory_excess_overtime_within_60h_minutes: 0,
  statutory_excess_overtime_over_60h_minutes: 0,
  weekly_statutory_excess_overtime_minutes: 0,
  late_night_work_minutes: 0,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  prescribed_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
}

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

function renderPanel(request: WorkflowRequest, overrides: Partial<Parameters<typeof ApprovalDetailPanel>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attachmentsApi, 'fetchAttachments').mockResolvedValue([])

  const onApprove = vi.fn()
  const onReturn = vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <ApprovalDetailPanel request={request} onApprove={onApprove} onReturn={onReturn} {...overrides} />
    </QueryClientProvider>,
  )

  return { onApprove, onReturn }
}

describe('ApprovalDetailPanel', () => {
  it('shows form_data and attachments for a generic workflow request (subject_type=null)', async () => {
    renderPanel(genericRequest)

    expect(await screen.findByText('部数')).toBeInTheDocument()
    expect(screen.getByText('100')).toBeInTheDocument()
    expect(await screen.findByLabelText('添付ファイル')).toBeInTheDocument()
  })

  it('shows the monthly drilldown by default for an attendance month subject', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPanel(attendanceRequest)

    expect(screen.getAllByText('2026-07').length).toBeGreaterThan(0)
    expect(await screen.findByRole('heading', { name: '月次勤怠' })).toBeInTheDocument()
    expect(await screen.findByText('2026-07-01(水)')).toBeInTheDocument()
  })

  it('switches between 月次・週次・日次 for an attendance month subject', async () => {
    const user = userEvent.setup()
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])

    renderPanel(attendanceRequest)

    await screen.findByRole('heading', { name: '月次勤怠' })

    await user.click(screen.getByRole('button', { name: '週次' }))
    expect(await screen.findByRole('heading', { name: '週次勤怠' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '日次' }))
    expect(await screen.findByRole('heading', { name: '日次勤怠' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '月次' }))
    expect(await screen.findByRole('heading', { name: '月次勤怠' })).toBeInTheDocument()
  })

  it('drills into the daily view when a day row is selected from the monthly view', async () => {
    const user = userEvent.setup()
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [
        {
          id: 'day-1',
          user_id: 'applicant-1',
          work_date: '2026-07-01',
          status: 'clocked_out',
          actual_start_at: '2026-07-01T09:00:00+09:00',
          actual_end_at: '2026-07-01T18:00:00+09:00',
          work_type: null,
          note: null,
          is_locked: false,
          breaks: [],
          calculation: null,
        },
      ],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])

    renderPanel(attendanceRequest)

    await user.click(await screen.findByText('2026-07-01(水)'))

    expect(await screen.findByRole('heading', { name: '日次勤怠' })).toBeInTheDocument()
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalled())
  })

  it('shows the expense items for an expense claim subject', () => {
    renderPanel(expenseRequest)

    expect(screen.getByText('タクシー代')).toBeInTheDocument()
    // 合計金額(dl)と明細1件分の金額(テーブル行)の両方に同じ表記が出る。
    expect(screen.getAllByText('3,000円')).toHaveLength(2)
    expect(screen.getByText('個人立替')).toBeInTheDocument()
  })

  it('calls onApprove when the approve button is clicked', async () => {
    const user = userEvent.setup()
    const { onApprove } = renderPanel(genericRequest)

    await user.click(screen.getByRole('button', { name: '承認する' }))

    expect(onApprove).toHaveBeenCalledTimes(1)
  })

  it('calls onReturn with the entered comment when returning', async () => {
    const user = userEvent.setup()
    const { onReturn } = renderPanel(genericRequest)

    await user.type(screen.getByPlaceholderText('差戻しコメント'), '要修正')
    await user.click(screen.getByRole('button', { name: '差戻す' }))

    expect(onReturn).toHaveBeenCalledWith('要修正')
  })

  it('disables actions when the subject is not in an actionable state', () => {
    const approvedExpense: WorkflowRequest = {
      ...expenseRequest,
      subject: { ...expenseRequest.subject!, status: 'approved' },
    }
    renderPanel(approvedExpense)

    expect(screen.getByText('この申請は現在操作できません。')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
  })
})
