import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as usersApi from '../../api/users'
import type { AttendanceMonth, AttendanceMonthlyCalculationTotals, Paginated, User } from '../../api/types'
import { MonthsToApprovePage } from './MonthsToApprovePage'

const approverUser: User = {
  id: 'approver-1',
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  roles: ['employee'],
  last_login_at: null,
}

const hrStaffUser: User = {
  ...approverUser,
  id: 'hr-1',
  name: '人事一郎',
  roles: ['hr_staff'],
}

const targetEmployeeUser: User = {
  id: 'user-1',
  name: '対象社員太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

let currentUser: User = approverUser

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const submittedMonth: AttendanceMonth = {
  id: 'month-1',
  user_id: 'user-1',
  year_month: '2026-07',
  status: 'submitted',
  approver: approverUser,
  submitted_at: '2026-07-05T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  return_comment: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const approvedMonth: AttendanceMonth = {
  ...submittedMonth,
  id: 'month-2',
  status: 'approved',
  approved_at: '2026-07-06T00:00:00+09:00',
}

function paginated(data: AttendanceMonth[], overrides: Partial<Paginated<AttendanceMonth>['meta']> = {}): Paginated<AttendanceMonth> {
  return {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length, ...overrides },
    links: { next: null, prev: null },
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MonthsToApprovePage />
    </QueryClientProvider>,
  )
}

describe('MonthsToApprovePage', () => {
  beforeEach(() => {
    currentUser = approverUser
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no months to approve', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([]))

    renderPage()

    expect(await screen.findByText('承認待ちの月次勤怠はありません。')).toBeInTheDocument()
  })

  it('shows the employee name when available, falling back to the employee id otherwise', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(
      paginated([{ ...submittedMonth, user: targetEmployeeUser }, { ...approvedMonth, user: undefined }]),
    )

    renderPage()

    expect(await screen.findByText('対象社員太郎')).toBeInTheDocument()
    expect(screen.getByText('社員ID: user-1')).toBeInTheDocument()
  })

  it('shows approve and return actions for a submitted month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth]))

    renderPage()

    expect(await screen.findByRole('button', { name: '承認する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '差戻す' })).toBeInTheDocument()
  })

  it('approves the month when the approver clicks approve', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth]))
    vi.spyOn(attendanceApi, 'approveMonth').mockResolvedValue({ ...submittedMonth, status: 'approved' })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '承認する' }))

    await waitFor(() => expect(attendanceApi.approveMonth).toHaveBeenCalledWith('month-1'))
  })

  it('disables the return button until a comment is entered', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth]))

    renderPage()

    expect(await screen.findByRole('button', { name: '差戻す' })).toBeDisabled()
  })

  it('returns the month with a comment', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth]))
    vi.spyOn(attendanceApi, 'returnMonth').mockResolvedValue({ ...submittedMonth, status: 'returned' })

    renderPage()
    await userEvent.type(await screen.findByPlaceholderText('差戻しコメント'), '不備があります')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() => expect(attendanceApi.returnMonth).toHaveBeenCalledWith('month-1', '不備があります'))
  })

  it('does not show a close button for a regular approver', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('button', { name: '締め処理' })).not.toBeInTheDocument()
  })

  it('shows a close button for hr_staff on an approved month', async () => {
    currentUser = hrStaffUser
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))

    renderPage()

    expect(await screen.findByRole('button', { name: '締め処理' })).toBeInTheDocument()
  })

  it('closes the month when hr_staff clicks close', async () => {
    currentUser = hrStaffUser
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))
    vi.spyOn(attendanceApi, 'closeMonth').mockResolvedValue({ ...approvedMonth, status: 'closed' })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '締め処理' }))

    await waitFor(() => expect(attendanceApi.closeMonth).toHaveBeenCalledWith('month-2'))
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  it('bulk-approves selected submitted months', async () => {
    const secondSubmittedMonth: AttendanceMonth = { ...submittedMonth, id: 'month-4', year_month: '2026-06', user_id: 'user-2' }
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth, secondSubmittedMonth]))
    const approveSpy = vi.spyOn(attendanceApi, 'approveMonth').mockResolvedValue({ ...submittedMonth, status: 'approved' })

    renderPage()
    await screen.findByText('2026-07')

    await userEvent.click(screen.getByRole('checkbox', { name: '2026-07(社員ID: user-1)を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-06(社員ID: user-2)を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'まとめて承認する' }))

    await waitFor(() => expect(approveSpy).toHaveBeenCalledTimes(2))
    expect(approveSpy).toHaveBeenCalledWith('month-1')
    expect(approveSpy).toHaveBeenCalledWith('month-4')
  })

  it('does not show a selection checkbox for an already-approved month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('shows pagination controls and requests the next page', async () => {
    const fetchSpy = vi
      .spyOn(attendanceApi, 'fetchMonthsToApprove')
      .mockResolvedValue(paginated([submittedMonth], { current_page: 1, last_page: 2, total: 21 }))

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.getByText('21件中 1 / 2 ページ')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    await waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })

  it('requests the filtered status, year-month, and user when the filters change', async () => {
    const fetchSpy = vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([submittedMonth]))
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
      data: [targetEmployeeUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    })

    renderPage()
    await screen.findByText('2026-07')

    await userEvent.selectOptions(screen.getByLabelText('ステータス'), '承認済み')
    await waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'approved', page: 1 })))

    await userEvent.type(screen.getByLabelText('年月'), '2026-06')
    await waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith(expect.objectContaining({ yearMonth: '2026-06' })))

    const userCombobox = screen.getByRole('combobox', { name: '対象社員' })
    await userEvent.click(userCombobox)
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '太郎')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員太郎(taro@example.com)' }))

    await waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith(expect.objectContaining({ userId: 'user-1' })))
  })

  it('expands the actual attendance record for the target employee, defaulting to the monthly view', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([{ ...submittedMonth, user: targetEmployeeUser }]))
    const zeroTotals: AttendanceMonthlyCalculationTotals = {
      work_minutes: 0,
      payroll_work_minutes: 0,
      prescribed_work_minutes: 0,
      statutory_within_overtime_minutes: 0,
      statutory_excess_overtime_minutes: 0,
      statutory_excess_overtime_within_60h_minutes: 0,
      statutory_excess_overtime_over_60h_minutes: 0,
      late_night_work_minutes: 0,
      late_night_prescribed_work_minutes: 0,
      late_night_statutory_within_overtime_minutes: 0,
      late_night_statutory_excess_overtime_minutes: 0,
      legal_holiday_work_minutes: 0,
      prescribed_holiday_work_minutes: 0,
      late_night_legal_holiday_work_minutes: 0,
    }
    const fetchMonthSpy = vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...submittedMonth, user: targetEmployeeUser },
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroTotals,
    })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '実際の勤務表を確認' }))

    expect(await screen.findByRole('heading', { name: '月次勤怠' })).toBeInTheDocument()
    await waitFor(() => expect(fetchMonthSpy).toHaveBeenCalledWith('2026-07', 'user-1'))

    await userEvent.click(screen.getByRole('button', { name: '勤務表を閉じる' }))
    expect(screen.queryByRole('heading', { name: '月次勤怠' })).not.toBeInTheDocument()
  })
})
