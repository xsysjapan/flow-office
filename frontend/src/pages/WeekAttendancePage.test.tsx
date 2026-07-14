import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceDay } from '../api/types'
import { addDays, formatDate, mondayOf } from '../utils/weekDates'
import { WeekAttendancePage } from './WeekAttendancePage'

const weekStart = formatDate(mondayOf(new Date()))

const mondayRecord: AttendanceDay = {
  id: 1,
  user_id: 1,
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
    actual_work_minutes: 480,
    prescribed_work_minutes: 480,
    non_statutory_overtime_minutes: 0,
    statutory_overtime_minutes: 0,
    late_night_minutes: 0,
    legal_holiday_work_minutes: 0,
    company_holiday_work_minutes: 0,
    legal_holiday_late_night_minutes: 0,
    core_time_violation: false,
  },
}

function renderPage(days: AttendanceDay[] = [mondayRecord]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <WeekAttendancePage />
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

  it('links each day to its day detail page', async () => {
    renderPage([mondayRecord])

    const link = (await screen.findByText(`${weekStart}(月)`)).closest('a')
    expect(link).toHaveAttribute('href', `/attendance/days/${weekStart}`)
  })

  it('moves to the next and previous week when the nav buttons are clicked', async () => {
    renderPage([])
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))

    await userEvent.click(screen.getByRole('button', { name: '次週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, 7)))

    await userEvent.click(screen.getByRole('button', { name: '前週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))
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
})
