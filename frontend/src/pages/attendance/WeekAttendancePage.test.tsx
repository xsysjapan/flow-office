import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as employeeShiftAssignmentsApi from '../../api/employeeShiftAssignments'
import type { AttendanceDay } from '../../api/types'
import { addDays, formatDate, mondayOf } from '../../utils/weekDates'
import { WeekAttendancePage } from './WeekAttendancePage'

const currentUser = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active' as const,
  last_login_at: null,
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const weekStart = formatDate(mondayOf(new Date()))

const mondayRecord: AttendanceDay = {
  id: 'day-1',
  user_id: '11111111-1111-1111-1111-111111111111',
  work_date: weekStart,
  status: 'clocked_out',
  actual_start_at: `${weekStart}T09:00:00+09:00`,
  actual_end_at: `${weekStart}T18:00:00+09:00`,
  utc_offset_minutes: 540,
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [{ id: 1, break_start_at: `${weekStart}T12:00:00+09:00`, break_end_at: `${weekStart}T12:45:00+09:00` }],
  calculation: {
    planned_work_minutes: 480,
    work_minutes: 480,
    prescribed_work_minutes: 480,
    prescribed_statutory_within_work_minutes: 480,
    non_prescribed_statutory_within_work_minutes: 0,
    prescribed_statutory_excess_work_minutes: 0,
    non_prescribed_statutory_excess_work_minutes: 0,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_work_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    legal_holiday_work_minutes: 0,
    prescribed_holiday_work_minutes: 0,
    late_night_legal_holiday_work_minutes: 0,
    late_night_prescribed_holiday_work_minutes: 0,
    core_time_violation: false,
    is_manually_adjusted: false,
  },
}

function LocationProbe() {
  const location = useLocation()
  return (
    <>
      <div data-testid="location-search">{location.search}</div>
      <div data-testid="location-state">{JSON.stringify(location.state ?? null)}</div>
    </>
  )
}

const drilledFromMonthWarning = {
  pathname: '/attendance/week',
  search: `?start=${weekStart}`,
  state: { backTo: { pathname: '/attendance/months/2026-07', search: '', hash: '' } },
}

function renderPage(
  days: AttendanceDay[] = [mondayRecord],
  entry: string | { pathname: string; search?: string; state?: unknown } = '/attendance/week',
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)
  vi.spyOn(attendanceApi, 'fetchWeekOvertime').mockResolvedValue({
    week_start_date: weekStart,
    week_end_date: addDays(weekStart, 6),
    weekly_statutory_excess_overtime_minutes: 60,
  })
  vi.spyOn(employeeShiftAssignmentsApi, 'fetchShiftAssignments').mockResolvedValue([])

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[entry]}>
        <WeekAttendancePage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WeekAttendancePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('requests the current week starting on Monday', async () => {
    renderPage([])

    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))
  })

  it('shows a record day with its status and a missing day as 未入力', async () => {
    renderPage([mondayRecord])

    expect(await screen.findByText(`${weekStart}(月)`)).toBeInTheDocument()
    expect(screen.getByText('退勤済み')).toBeInTheDocument()
    expect(screen.getAllByText('未入力').length).toBeGreaterThan(0)
  })

  it('shows the summed weekly calculation in the common summary layout', async () => {
    renderPage([
      mondayRecord,
      {
        ...mondayRecord,
        id: 'day-2',
        work_date: addDays(weekStart, 1),
        calculation: {
          ...mondayRecord.calculation!,
          prescribed_work_minutes: 240,
          prescribed_statutory_within_work_minutes: 240,
          absence_minutes: 480,
          special_leave_days: 1,
        },
      },
    ])

    await screen.findByText(`${weekStart}(月)`)
    expect(screen.getByRole('heading', { name: '今週の集計' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '詳しく表示' }))
    expect(screen.getByText('12時間')).toBeInTheDocument()
    expect(screen.getByText('所定内法定内労働時間').closest('dl')).toHaveClass('grid-cols-[minmax(0,1fr)_auto]', 'sm:grid-cols-[auto_1fr_auto_1fr]')
    expect(screen.getByText('うち週40時間超').nextElementSibling).toHaveTextContent('1時間')
    expect(screen.getByText('欠勤日数').nextElementSibling).toHaveTextContent('1日')
    expect(screen.getByText('特別休暇日数').nextElementSibling).toHaveTextContent('1日')
  })

  it('links each day to its day detail page', async () => {
    renderPage([mondayRecord])

    const link = (await screen.findByText(`${weekStart}(月)`)).closest('a')
    expect(link).toHaveAttribute('href', `/attendance/days/${weekStart}`)
    expect(screen.getByRole('heading', { name: '日別の内訳' })).toBeInTheDocument()
  })

  it('moves to the next and previous week when the nav buttons are clicked', async () => {
    renderPage([])
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))

    await userEvent.click(screen.getByRole('button', { name: '次週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, 7)))

    await userEvent.click(screen.getByRole('button', { name: '前週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))
  })

  it('shows no back link when opened from the menu (no origin)', async () => {
    renderPage([])

    await screen.findByText('週次勤怠')
    expect(screen.queryByRole('link', { name: /に戻る$/ })).not.toBeInTheDocument()
  })

  it('shows a back link to the month when drilled down from the month warning', async () => {
    renderPage([], drilledFromMonthWarning)

    expect(await screen.findByRole('link', { name: '2026年7月の勤怠に戻る' })).toHaveAttribute('href', '/attendance/months/2026-07')
  })

  it('keeps the back link to the origin when moving to another week', async () => {
    renderPage([], drilledFromMonthWarning)
    await userEvent.click(await screen.findByRole('button', { name: '前週' }))

    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, -7)))
    expect(screen.getByRole('link', { name: '2026年7月の勤怠に戻る' })).toBeInTheDocument()
  })

  it('opens the month page from the 月次 link without the origin state', async () => {
    renderPage([], drilledFromMonthWarning)

    await userEvent.click(await screen.findByRole('link', { name: '月次' }))

    expect(screen.getByTestId('location-state')).toHaveTextContent('null')
    expect(screen.queryByRole('link', { name: /に戻る$/ })).not.toBeInTheDocument()
  })

  it('links to the displayed week month and disables 今週 for the current week', async () => {
    renderPage([])

    await screen.findByText('週次勤怠')
    expect(screen.getByRole('link', { name: '月次' })).toHaveAttribute('href', `/attendance/months/${weekStart.slice(0, 7)}`)
    expect(screen.getByRole('button', { name: '今週' })).toBeDisabled()
  })

  it('opens the week that contains the ?start= date, when navigated from the day page', async () => {
    const otherWeekStart = addDays(weekStart, 7)
    vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/attendance/week?start=${otherWeekStart}`]}>
          <Routes>
            <Route path="/attendance/week" element={<WeekAttendancePage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(otherWeekStart))
    expect(screen.getByRole('button', { name: '今週' })).not.toBeDisabled()
  })

  it('shows an error message when the week fails to load', async () => {
    vi.spyOn(attendanceApi, 'fetchWeek').mockRejectedValue(new Error('network down'))
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <WeekAttendancePage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  it('shows the week containing a non-Monday ?start= date without rewriting the URL', async () => {
    const wednesday = addDays(weekStart, 9)
    renderPage([], `/attendance/week?start=${wednesday}`)

    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, 7)))
    expect(screen.getByTestId('location-search')).toHaveTextContent(`?start=${wednesday}`)
  })

  it('writes the Monday of the destination week to ?start= when moving weeks', async () => {
    renderPage([])
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))
    expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/)

    await userEvent.click(screen.getByRole('button', { name: '次週' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(`?start=${addDays(weekStart, 7)}`))
    expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, 7))
  })

  it('treats an invalid ?start= as the current week and removes the key', async () => {
    renderPage([], '/attendance/week?start=2026-02-30')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
    expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart)
    expect(attendanceApi.fetchWeek).not.toHaveBeenCalledWith('Invalid')
  })

  it('resets the selection mode when the displayed week changes', async () => {
    renderPage([mondayRecord])
    await userEvent.click(await screen.findByRole('button', { name: '選択' }))
    expect(screen.queryByRole('button', { name: '選択' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次週' }))

    expect(await screen.findByRole('button', { name: '選択' })).toBeInTheDocument()
  })
})
