import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as employeeShiftAssignmentsApi from '../../api/employeeShiftAssignments'
import type { AttendanceDay, AttendanceMonth, User } from '../../api/types'
import { AppSettingsContext } from '../../contexts/AppSettingsContext'
import { renderWithGuard } from '../../test/renderWithGuard'
import { AttendanceDayPage } from './AttendanceDayPage'

const date = '2026-07-06'

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const recordedDay: AttendanceDay = {
  id: 'day-1',
  user_id: 'user-1',
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
    work_minutes: 480,
    prescribed_work_minutes: 480,
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
    has_core_time_violation: false,
    is_manually_adjusted: false,
  },
}

const nextDay: AttendanceDay = {
  ...recordedDay,
  id: 'day-2',
  work_date: '2026-07-07',
  actual_start_at: '2026-07-07T09:00:00+09:00',
  actual_end_at: '2026-07-07T18:00:00+09:00',
  breaks: [],
}

function renderPage(days: AttendanceDay[] = [recordedDay, nextDay]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)
  vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
  vi.spyOn(employeeShiftAssignmentsApi, 'fetchShiftAssignments').mockResolvedValue([])

  return renderWithGuard(
    <QueryClientProvider client={queryClient}>
      <AppSettingsContext.Provider
        value={{
          systemSettings: {
            paid_leave_requires_approval: true,
            special_leave_requires_approval: true,
            shift_swap_requires_approval: true,
            attendance_requires_approval: true,
            expense_claim_requires_approval: true,
            compensatory_leave_requires_approval: true,
            default_timezone: 'Asia/Tokyo',
            default_work_style_id: null,
            default_work_style: null,
            attendance_submission_deadline_day: 5,
            attendance_month_close_deadline_day: 10,
          },
          isLoading: false,
        }}
      >
        <Routes>
          <Route path="/attendance/days/:date" element={<AttendanceDayPage />} />
        </Routes>
      </AppSettingsContext.Provider>
    </QueryClientProvider>,
    { initialEntries: [`/attendance/days/${date}`] },
  )
}

const discardDialogText = '編集をやめると、入力した内容は失われます。'

describe('AttendanceDayPage leave guard', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { id: 'month-1', user_id: 'user-1', year_month: '2026-07', status: 'not_submitted' } as AttendanceMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: {} as never,
    })
  })

  it('cancels the day edit without a dialog when nothing changed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(screen.queryByText(discardDialogText)).not.toBeInTheDocument()
    expect(await screen.findByRole('button', { name: '編集' })).toBeInTheDocument()
  })

  it('asks before discarding the day edit when something changed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.type(screen.getByLabelText('備考'), 'メモ')
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(await screen.findByText(discardDialogText)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '編集を続ける' }))
    expect(screen.getByLabelText('備考')).toHaveValue('メモ')
  })

  it('cancels the calculation adjustment without a dialog when nothing changed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '集計値を修正' }))
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(screen.queryByText(discardDialogText)).not.toBeInTheDocument()
    expect(await screen.findByRole('button', { name: '集計値を修正' })).toBeInTheDocument()
  })

  it('asks before discarding the calculation adjustment when something changed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '集計値を修正' }))
    await userEvent.type(screen.getByLabelText('補正理由'), '補正')
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(await screen.findByText(discardDialogText)).toBeInTheDocument()
  })

  it('does not keep the editing state when moving to the next day', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    expect(screen.getByLabelText('修正理由')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('link', { name: '翌日' }))

    expect(await screen.findByText('7月7日の勤怠')).toBeInTheDocument()
    expect(screen.queryByLabelText('修正理由')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '編集' })).toBeInTheDocument()
  })

  describe('create form with suggested defaults', () => {
    beforeEach(() => {
      vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockResolvedValue({
        source: 'schedule',
        actual_start_at: '2026-07-06T09:00:00+09:00',
        actual_end_at: '2026-07-06T18:00:00+09:00',
        breaks: [{ start: '2026-07-06T12:00:00+09:00', end: '2026-07-06T13:00:00+09:00' }],
      })
    })

    it('does not show the dialog when only the suggested defaults were applied', async () => {
      renderPage([])

      await screen.findByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')
      await screen.findByText('勤務予定(休憩を含む)を初期値として反映しました。')
      await userEvent.click(screen.getByRole('link', { name: '翌日' }))

      expect(await screen.findByText('7月7日の勤怠')).toBeInTheDocument()
      expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
    })

    it('shows the dialog when one field is changed after the defaults were applied', async () => {
      renderPage([])

      await screen.findByText('勤務予定(休憩を含む)を初期値として反映しました。')
      await userEvent.type(screen.getByLabelText('備考'), 'メモ')
      await userEvent.click(screen.getByRole('link', { name: '翌日' }))

      expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
      expect(screen.getByText('7月6日の勤怠')).toBeInTheDocument()
    })
  })
})
