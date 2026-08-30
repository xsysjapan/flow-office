import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as exportsApi from '../../api/exports'
import * as usersApi from '../../api/users'
import type { AttendanceDay, AttendanceMonth, AttendanceMonthlyCalculationTotals, Paginated, User } from '../../api/types'
import { datesInMonth, formatDate, mondayOf } from '../../utils/weekDates'
import { AttendanceReferencePage } from './AttendanceReferencePage'

const targetUser: User = {
  id: 'user-3',
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const adminUser: User = {
  id: 'admin-1',
  name: '管理者花子',
  email: 'admin@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  effective_permissions: ['attendance.month_reopen', 'backoffice_task.execute', 'attendance.export'],
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: adminUser }),
}))

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
  weekly_statutory_excess_overtime_minutes: 0,
  late_night_work_minutes: 0,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  prescribed_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
  late_night_prescribed_holiday_work_minutes: 0,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AttendanceReferencePage />
      </MemoryRouter>
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
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
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
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
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
    // 週次ビューは選択中の年月(既定では今月)の最初の週に限定される(他画面と共通の
    // AttendanceMonthReferenceTabsの挙動。「今週」ではなく対象月内の週になる)。
    const currentYearMonth = formatDate(new Date()).slice(0, 7)
    const firstDateOfMonth = datesInMonth(currentYearMonth)[0]
    const weekStart = formatDate(mondayOf(new Date(`${firstDateOfMonth}T00:00:00`)))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart, targetUser.id))
  })

  it('switches to the daily view and shows a message when there is no record', async () => {
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
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
    // 日次ビューは選択中の年月(既定では今月)の1日目が初期表示になる(他画面と共通の
    // AttendanceMonthReferenceTabsの挙動。「今日」ではなく対象月の1日目になる)。
    const currentYearMonth = formatDate(new Date()).slice(0, 7)
    const firstDateOfMonth = datesInMonth(currentYearMonth)[0]
    const day: AttendanceDay = {
      id: 'day-1',
      user_id: targetUser.id,
      work_date: firstDateOfMonth,
      status: 'clocked_out',
      actual_start_at: `${firstDateOfMonth}T09:00:00+09:00`,
      actual_end_at: `${firstDateOfMonth}T18:00:00+09:00`,
      work_type: null,
      note: null,
      is_locked: false,
      breaks: [],
      calculation: null,
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
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

  it('lets an admin with attendance.month_reopen reopen a closed month', async () => {
    const closedMonth: AttendanceMonth = {
      id: 'month-1',
      user_id: targetUser.id,
      year_month: formatDate(new Date()).slice(0, 7),
      status: 'closed',
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      return_comment: null,
      closed_at: `${formatDate(new Date())}T00:00:00+09:00`,
      snapshot: null,
      legal_holiday_warnings: [],
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: closedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    const reopenMonth = vi.spyOn(attendanceApi, 'reopenMonth').mockResolvedValue({ ...closedMonth, status: 'approved' })

    renderPage()
    await selectTargetUser()

    const reopenButton = await screen.findByRole('button', { name: '締めを取り消す' })
    await userEvent.click(reopenButton)

    await userEvent.type(await screen.findByLabelText('取消理由'), '対象社員の取り違え')
    await userEvent.click(screen.getByRole('button', { name: '締めを取り消す' }))

    await waitFor(() => expect(reopenMonth).toHaveBeenCalledWith('month-1', '対象社員の取り違え'))
  })

  it('lets an admin with backoffice_task.execute close a not-yet-closed month', async () => {
    const approvedMonth: AttendanceMonth = {
      id: 'month-2',
      user_id: targetUser.id,
      year_month: formatDate(new Date()).slice(0, 7),
      status: 'approved',
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      return_comment: null,
      closed_at: null,
      snapshot: null,
      legal_holiday_warnings: [],
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: approvedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    const closeMonth = vi.spyOn(attendanceApi, 'closeMonth').mockResolvedValue({ ...approvedMonth, status: 'closed' })

    renderPage()
    await selectTargetUser()

    await userEvent.click(await screen.findByRole('button', { name: '締める' }))
    await userEvent.click(screen.getByRole('button', { name: '締めを確定する' }))

    await waitFor(() => expect(closeMonth).toHaveBeenCalledWith('month-2'))
  })

  it('lets an admin with attendance.export download CSV/Excel for the selected employee', async () => {
    const approvedMonth: AttendanceMonth = {
      id: 'month-3',
      user_id: targetUser.id,
      year_month: formatDate(new Date()).slice(0, 7),
      status: 'approved',
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      return_comment: null,
      closed_at: null,
      snapshot: null,
      legal_holiday_warnings: [],
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: approvedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    const csvSpy = vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    const excelSpy = vi.spyOn(exportsApi, 'downloadAttendanceExcel').mockResolvedValue(undefined)

    renderPage()
    await selectTargetUser()

    await userEvent.click(await screen.findByRole('button', { name: 'CSV出力' }))
    await userEvent.click(screen.getByRole('button', { name: 'Excel出力' }))

    await waitFor(() =>
      expect(csvSpy).toHaveBeenCalledWith({
        year_month: [approvedMonth.year_month],
        user_id: [targetUser.id],
        format: 'generic',
      }),
    )
    expect(excelSpy).toHaveBeenCalledWith({ year_month: [approvedMonth.year_month], user_id: [targetUser.id] })
  })
})
