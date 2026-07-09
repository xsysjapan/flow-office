import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
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
  },
}

function renderPage(days: AttendanceDay[] = [mondayRecord]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)

  return render(
    <QueryClientProvider client={queryClient}>
      <WeekAttendancePage />
    </QueryClientProvider>,
  )
}

describe('WeekAttendancePage', () => {
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

  it('moves to the next and previous week when the nav buttons are clicked', async () => {
    renderPage([])
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))

    await userEvent.click(screen.getByRole('button', { name: '次週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(addDays(weekStart, 7)))

    await userEvent.click(screen.getByRole('button', { name: '前週' }))
    await waitFor(() => expect(attendanceApi.fetchWeek).toHaveBeenCalledWith(weekStart))
  })

  it('edits a day and saves it as a decomposed daily edit', async () => {
    vi.spyOn(attendanceApi, 'updateAttendanceDay').mockResolvedValue({ ...mondayRecord, note: '修正済み' })
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.type(screen.getByLabelText('修正理由(必須)'), '打刻ミスの修正')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(attendanceApi.updateAttendanceDay).toHaveBeenCalledWith(
        1,
        expect.objectContaining({ reason: '打刻ミスの修正' }),
      ),
    )
  })

  it('disables saving until a reason is entered', async () => {
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))

    expect(screen.getByRole('button', { name: '保存する' })).toBeDisabled()
  })

  it('adds and removes a break row while editing', async () => {
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    expect(screen.getAllByLabelText('休憩開始')).toHaveLength(1)

    await userEvent.click(screen.getByRole('button', { name: '休憩を追加' }))
    expect(screen.getAllByLabelText('休憩開始')).toHaveLength(2)

    await userEvent.click(screen.getAllByRole('button', { name: '削除' })[0])
    expect(screen.getAllByLabelText('休憩開始')).toHaveLength(1)
  })
})
