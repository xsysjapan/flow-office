import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceDay } from '../api/types'
import { TodayAttendancePage } from './TodayAttendancePage'

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
      <TodayAttendancePage />
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
})
