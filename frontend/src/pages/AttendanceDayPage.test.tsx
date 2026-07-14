import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceDay, AttendancePunch, User } from '../api/types'
import { AttendanceDayPage } from './AttendanceDayPage'

const date = '2026-07-06'

const currentUser: User = {
  id: 1,
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

vi.mock('../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const recordedDay: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: date,
  status: 'clocked_out',
  actual_start_at: `${date}T09:00:00+09:00`,
  actual_end_at: `${date}T18:00:00+09:00`,
  utc_offset_minutes: 540,
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [{ id: 1, break_start_at: `${date}T12:00:00+09:00`, break_end_at: `${date}T12:45:00+09:00` }],
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

function renderPage(days: AttendanceDay[] = [recordedDay]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/attendance/days/${date}`]}>
        <Routes>
          <Route path="/attendance/days/:date" element={<AttendanceDayPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AttendanceDayPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the recorded day summary and status', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([recordedDay])

    expect(await screen.findByText(`${date}(月)の勤怠`)).toBeInTheDocument()
    expect(screen.getByText('退勤済み')).toBeInTheDocument()
    expect(screen.getByText('09:00')).toBeInTheDocument()
    expect(screen.getByText('18:00')).toBeInTheDocument()
  })

  it('edits the day and saves it as a decomposed daily edit', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'updateAttendanceDay').mockResolvedValue({ ...recordedDay, note: '修正済み' })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.type(screen.getByLabelText('修正理由(必須)'), '打刻ミスの修正')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(attendanceApi.updateAttendanceDay).toHaveBeenCalledWith(1, expect.objectContaining({ reason: '打刻ミスの修正' })),
    )
  })

  it('deletes the day after confirming with a reason (UC-A015)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'deleteAttendanceDay').mockResolvedValue({ deleted: true })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    await userEvent.type(screen.getByLabelText('削除理由'), '二重入力の削除')
    await userEvent.click(screen.getByRole('button', { name: '削除する' }))

    await waitFor(() => expect(attendanceApi.deleteAttendanceDay).toHaveBeenCalledWith(1, '二重入力の削除'))
  })

  it('shows the punch log and corrects an active punch (UC-A013)', async () => {
    const punch: AttendancePunch = {
      id: 10,
      user_id: 1,
      work_date: date,
      punch_type: 'clock_in',
      punched_at: `${date}T09:30:00+09:00`,
      source: 'web',
      note: null,
      status: 'active',
      correction_reason: null,
      corrected_by_user_id: null,
      corrected_at: null,
      superseded_by_punch_id: null,
      created_at: null,
    }
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([punch])
    vi.spyOn(attendanceApi, 'correctPunch').mockResolvedValue({ ...punch, id: 11, status: 'active' })
    renderPage([recordedDay])

    expect(await screen.findByText('有効')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '訂正' }))
    await userEvent.type(screen.getByLabelText('訂正理由'), '打刻時刻の入力ミス')
    await userEvent.click(screen.getByRole('button', { name: '訂正を保存' }))

    await waitFor(() =>
      expect(attendanceApi.correctPunch).toHaveBeenCalledWith(10, expect.objectContaining({ reason: '打刻時刻の入力ミス' })),
    )
  })

  it('shows a create form and creates a day when there is no record yet (UC-A016)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'createAttendanceDay').mockResolvedValue({ ...recordedDay, id: 2 })
    renderPage([])

    expect(await screen.findByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('作成理由(必須)'), '実績の入力漏れ')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(attendanceApi.createAttendanceDay).toHaveBeenCalledWith(
        expect.objectContaining({ user_id: 1, work_date: date, reason: '実績の入力漏れ' }),
      ),
    )
  })

  it('shows an error message when the week fails to load', async () => {
    vi.spyOn(attendanceApi, 'fetchWeek').mockRejectedValue(new Error('network down'))
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/attendance/days/${date}`]}>
          <Routes>
            <Route path="/attendance/days/:date" element={<AttendanceDayPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })
})
