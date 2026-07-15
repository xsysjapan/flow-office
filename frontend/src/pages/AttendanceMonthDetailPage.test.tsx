import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import * as userWorkStyleAssignmentsApi from '../api/userWorkStyleMonthlyAssignments'
import * as usersApi from '../api/users'
import type {
  AttendanceDay,
  AttendanceMonth,
  AttendanceMonthlyCalculationTotals,
  Paginated,
  User,
} from '../api/types'
import { AttendanceMonthDetailPage } from './AttendanceMonthDetailPage'

const yearMonth = '2026-07'

const zeroMonthlyCalculationTotals: AttendanceMonthlyCalculationTotals = {
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

const currentUser: User = {
  id: 1,
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  hire_date: '2026-01-15',
  last_login_at: null,
}

vi.mock('../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const approver: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const paginatedApprover: Paginated<User> = {
  data: [approver],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

const notSubmittedMonth: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: yearMonth,
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const dayRecord: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: '2026-07-01',
  status: 'clocked_out',
  actual_start_at: '2026-07-01T09:00:00+09:00',
  actual_end_at: '2026-07-01T18:00:00+09:00',
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

function renderPage(initialYearMonth = yearMonth) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/attendance/months/${initialYearMonth}`]}>
        <Routes>
          <Route path="/attendance/months/:yearMonth" element={<AttendanceMonthDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AttendanceMonthDetailPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(userWorkStyleAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
  })

  it('shows the days of the month with links to each day page', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [dayRecord],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()

    expect(await screen.findByText('2026-07-01(水)')).toBeInTheDocument()
    const link = screen.getByText('2026-07-01(水)').closest('a')
    expect(link).toHaveAttribute('href', '/attendance/days/2026-07-01')
    expect(screen.getAllByText('未入力').length).toBeGreaterThan(0)
  })

  it('shows the monthly calculation totals summary', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [dayRecord],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: {
        ...zeroMonthlyCalculationTotals,
        work_minutes: 2820,
        prescribed_work_minutes: 2400,
        statutory_within_overtime_minutes: 60,
        statutory_excess_overtime_minutes: 360,
        statutory_excess_overtime_over_60h_minutes: 30,
      },
    })

    renderPage()

    expect(await screen.findByText('今月の集計')).toBeInTheDocument()
    expect(screen.getByText('所定労働時間')).toBeInTheDocument()
    expect(screen.getByText('40時間')).toBeInTheDocument()
    expect(screen.getByText('1時間')).toBeInTheDocument()
    expect(screen.getByText('6時間')).toBeInTheDocument()
    expect(screen.getByText('30分')).toBeInTheDocument()
  })

  it('shows the monthly absence and paid-leave summary (欠勤日数・有給日数・有給時間・特別休暇時間)', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [dayRecord],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: {
        ...zeroMonthlyCalculationTotals,
        absence_days: 1,
        absence_minutes: 480,
        paid_leave_days: 1.5,
        paid_leave_minutes: 120,
        special_leave_minutes: 60,
      },
    })

    renderPage()

    expect(await screen.findByText('欠勤日数')).toBeInTheDocument()
    expect(screen.getByText('1日')).toBeInTheDocument()
    expect(screen.getByText('欠勤時間')).toBeInTheDocument()
    expect(screen.getByText('有給日数')).toBeInTheDocument()
    expect(screen.getByText('1.5日')).toBeInTheDocument()
    expect(screen.getByText('有給時間(時間単位)')).toBeInTheDocument()
    expect(screen.getByText('特別休暇時間')).toBeInTheDocument()
    expect(screen.getByText('1時間')).toBeInTheDocument()
  })

  it('shows a submit control for a not_submitted month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)

    renderPage()

    expect(await screen.findByRole('button', { name: '提出する' })).toBeDisabled()
  })

  it('does not show a submit control for an approved month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...notSubmittedMonth, status: 'approved' },
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()

    await screen.findByText(`${yearMonth}の勤怠月次`)
    expect(screen.queryByRole('button', { name: '提出する' })).not.toBeInTheDocument()
  })

  it('submits the month with the picked approver', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)
    vi.spyOn(attendanceApi, 'submitMonth').mockResolvedValue({ ...notSubmittedMonth, status: 'submitted' })

    renderPage()

    await screen.findByRole('button', { name: '提出する' })
    const approverCombobox = screen.getAllByRole('combobox').find((el) => el.id === 'approver')!
    await userEvent.click(approverCombobox)
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '提出する' }))

    await waitFor(() => expect(attendanceApi.submitMonth).toHaveBeenCalledWith(yearMonth, 2))
  })

  it('shows legal holiday warning badges', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: {
        ...notSubmittedMonth,
        legal_holiday_warnings: [
          { rule: 'weekly', period_start: '2026-07-01', period_end: '2026-07-31', legal_holiday_count: 2, required_count: 4 },
        ],
      },
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()

    expect(await screen.findByText(/法定休日不足/)).toBeInTheDocument()
  })

  it('shows an error message when the fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  describe('month navigation', () => {
    it('allows navigation in the employment period regardless of work-style assignments', async () => {
      vi.spyOn(attendanceApi, 'fetchMonth').mockImplementation((ym) =>
        Promise.resolve({ days: [], month: { ...notSubmittedMonth, year_month: ym }, flex_settlement_summary: null, monthly_calculation_totals: zeroMonthlyCalculationTotals }),
      )
      renderPage()
      await screen.findByText(`${yearMonth}の勤怠月次`)

      expect(screen.getByRole('button', { name: '前月' })).not.toBeDisabled()
      expect(screen.getByRole('button', { name: '次月' })).toBeDisabled()
      expect(screen.getByRole('link', { name: '一覧' })).toHaveAttribute('href', '/attendance/months')
    })

    it('disables 次月 for the current month but allows navigating to earlier employment months', async () => {
      vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
        days: [],
        month: notSubmittedMonth,
        flex_settlement_summary: null,
        monthly_calculation_totals: zeroMonthlyCalculationTotals,
      })
      renderPage()
      await screen.findByText(`${yearMonth}の勤怠月次`)

      expect(screen.getByRole('button', { name: '前月' })).not.toBeDisabled()
      expect(screen.getByRole('button', { name: '次月' })).toBeDisabled()
    })

    it('navigates to the previous employment month', async () => {
      vi.spyOn(attendanceApi, 'fetchMonth').mockImplementation((ym) =>
        Promise.resolve({ days: [], month: { ...notSubmittedMonth, year_month: ym }, flex_settlement_summary: null, monthly_calculation_totals: zeroMonthlyCalculationTotals }),
      )
      renderPage()
      await screen.findByText(`${yearMonth}の勤怠月次`)

      await userEvent.click(screen.getByRole('button', { name: '前月' }))

      expect(await screen.findByText('2026-06の勤怠月次')).toBeInTheDocument()
    })
  })
})
