import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceDay, AttendanceMonthlyCalculationTotals } from '../api/types'
import { TodayAttendancePage } from './TodayAttendancePage'

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

const notStartedDay: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: '2026-07-09',
  status: 'not_started',
  actual_start_at: null,
  actual_end_at: null,
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <TodayAttendancePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TodayAttendancePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows a clock-in button when the day has not started', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(notStartedDay)

    renderPage()

    expect(await screen.findByRole('button', { name: '出勤' })).toBeInTheDocument()
    expect(screen.getByText('未出勤')).toBeInTheDocument()
  })

  it('links to the current day attendance detail', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(notStartedDay)

    renderPage()

    expect(await screen.findByRole('link', { name: '日次勤怠' })).toHaveAttribute('href', '/attendance/days/2026-07-09')
  })

  it('clocks in when the button is clicked', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(notStartedDay)
    vi.spyOn(attendanceApi, 'clockIn').mockResolvedValue({
      ...notStartedDay,
      status: 'working',
      actual_start_at: '2026-07-09T09:00:00+09:00',
    })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '出勤' }))

    await waitFor(() => expect(attendanceApi.clockIn).toHaveBeenCalledOnce())
  })

  it('shows break and clock-out actions while working', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue({
      ...notStartedDay,
      status: 'working',
      actual_start_at: '2026-07-09T09:00:00+09:00',
    })

    renderPage()

    expect(await screen.findByRole('button', { name: '休憩開始' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '退勤' })).toBeInTheDocument()
  })

  it('shows an end-break action while on break', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue({
      ...notStartedDay,
      status: 'on_break',
      breaks: [{ id: 1, break_start_at: '2026-07-09T12:00:00+09:00', break_end_at: null }],
    })

    renderPage()

    expect(await screen.findByRole('button', { name: '休憩終了' })).toBeInTheDocument()
  })

  it('shows a completion message once clocked out', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue({
      ...notStartedDay,
      status: 'clocked_out',
      actual_start_at: '2026-07-09T09:00:00+09:00',
      actual_end_at: '2026-07-09T18:00:00+09:00',
    })

    renderPage()

    expect(await screen.findByText('本日の勤怠は完了しています。')).toBeInTheDocument()
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  it('shows the flex settlement summary card when the user is on a flex work style', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(notStartedDay)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: {
        settlement_period_start: '2026-07-01',
        settlement_period_end: '2026-07-31',
        required_minutes: 10560,
        actual_minutes: 4800,
        remaining_minutes: 5760,
        remaining_working_days: 12,
        per_day_required_minutes: 480,
        core_time_violation_days: 1,
        late_night_work_minutes: 0,
        legal_holiday_work_minutes: 0,
      },
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()

    expect(await screen.findByText('今月の清算期間(フレックスタイム制)')).toBeInTheDocument()
    expect(screen.getByText('176時間')).toBeInTheDocument()
    expect(screen.getByText('1日')).toBeInTheDocument()
  })

  it('does not show the flex settlement summary card for non-flex work styles', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(notStartedDay)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })

    renderPage()

    await screen.findByRole('button', { name: '出勤' })
    expect(screen.queryByText('今月の清算期間(フレックスタイム制)')).not.toBeInTheDocument()
  })
})
