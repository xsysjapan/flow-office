import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as usersApi from '../../api/users'
import type { AttendanceDay, AttendanceMonthlyCalculationTotals, Paginated, User } from '../../api/types'
import { formatDate, mondayOf } from '../../utils/weekDates'
import { AttendanceReferencePage } from './AttendanceReferencePage'

const targetUser: User = {
  id: 3,
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const paginatedUsers: Paginated<User> = {
  data: [targetUser],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

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

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <AttendanceReferencePage />
    </QueryClientProvider>,
  )
}

async function selectTargetUser() {
  await userEvent.click(await screen.findByRole('combobox'))
  await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
  await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
}

describe('AttendanceReferencePage', () => {
  it('does not fetch attendance data until an employee is selected', () => {
    const fetchMonth = vi.spyOn(attendanceApi, 'fetchMonth')

    renderPage()

    expect(fetchMonth).not.toHaveBeenCalled()
    expect(screen.queryByText('月次勤怠')).not.toBeInTheDocument()
  })

  it('shows the selected employees monthly attendance by default', async () => {
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()
    await selectTargetUser()

    expect(await screen.findByText('月次勤怠')).toBeInTheDocument()
    const currentYearMonth = formatDate(new Date()).slice(0, 7)
    await waitFor(() => expect(attendanceApi.fetchMonth).toHaveBeenCalledWith(currentYearMonth, targetUser.id))
  })

  it('switches to the weekly view for the selected employee', async () => {
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])

    renderPage()
    await selectTargetUser()
    await screen.findByText('月次勤怠')

    await userEvent.click(screen.getByRole('button', { name: '週次' }))

    expect(await screen.findByText('週次勤怠')).toBeInTheDocument()
    const weekStart = formatDate(mondayOf(new Date()))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart, targetUser.id))
  })

  it('switches to the daily view and shows a message when there is no record', async () => {
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])

    renderPage()
    await selectTargetUser()
    await screen.findByText('月次勤怠')

    await userEvent.click(screen.getByRole('button', { name: '日次' }))

    expect(await screen.findByText('この日の勤怠記録はありません。')).toBeInTheDocument()
  })

  it('shows the daily record read-only, without edit or delete actions', async () => {
    const today = formatDate(new Date())
    const day: AttendanceDay = {
      id: 1,
      user_id: targetUser.id,
      work_date: today,
      status: 'clocked_out',
      actual_start_at: `${today}T09:00:00+09:00`,
      actual_end_at: `${today}T18:00:00+09:00`,
      work_type: null,
      note: null,
      is_locked: false,
      breaks: [],
      calculation: null,
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([day])

    renderPage()
    await selectTargetUser()
    await screen.findByText('月次勤怠')

    await userEvent.click(screen.getByRole('button', { name: '日次' }))

    expect(await screen.findByText('退勤済み')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '編集' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '削除' })).not.toBeInTheDocument()
  })
})
