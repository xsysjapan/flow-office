import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { AttendanceDay, AttendanceMonth, AttendanceMonthlyCalculationTotals, AttendancePunch, User } from '../../api/types'
import { pickDateTime } from '../../test-support/pickerInteractions'
import { AttendanceDayPage } from './AttendanceDayPage'
import { formatDate } from '../../utils/weekDates'

const date = '2026-07-06'

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

const notSubmittedMonth: AttendanceMonth = {
  id: 'month-1',
  user_id: 'user-1',
  year_month: date.slice(0, 7),
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  return_comment: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

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
    core_time_violation: false,
    is_manually_adjusted: false,
  },
}

function renderPage(days: AttendanceDay[] = [recordedDay], routeDate = date) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue(days)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/attendance/days/${routeDate}`]}>
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
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
  })

  it('shows the recorded day summary and status', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([recordedDay])

    expect(await screen.findByText('日次勤怠')).toBeInTheDocument()
    expect(screen.getByText(`${date}(月)`)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: '日別の内訳' })).toBeInTheDocument()
    expect(screen.getByText('退勤済み')).toBeInTheDocument()
    expect(screen.getByText('09:00')).toBeInTheDocument()
    expect(screen.getByText('18:00')).toBeInTheDocument()
  })

  it('links to the previous day, the next day, and the containing week', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([recordedDay])

    await screen.findByText('日次勤怠')

    expect(screen.getByRole('link', { name: '前日' })).toHaveAttribute('href', '/attendance/days/2026-07-05')
    expect(screen.getByRole('link', { name: '翌日' })).toHaveAttribute('href', '/attendance/days/2026-07-07')
    expect(screen.getByRole('link', { name: '週次' })).toHaveAttribute('href', `/attendance/week?start=${date}`)
    expect(screen.getByRole('link', { name: '今日' })).toHaveAttribute('href', `/attendance/days/${formatDate(new Date())}`)
  })

  it('disables 今日 when displaying today', async () => {
    const today = formatDate(new Date())
    renderPage([], today)

    await screen.findByText('日次勤怠')
    expect(screen.getByRole('button', { name: '今日' })).toBeDisabled()
  })

  it('edits the day and saves it as a decomposed daily edit', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'updateAttendanceDay').mockResolvedValue({ ...recordedDay, note: '修正済み' })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.type(screen.getByLabelText('修正理由(必須)'), '打刻ミスの修正')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(attendanceApi.updateAttendanceDay).toHaveBeenCalledWith('day-1', expect.objectContaining({ reason: '打刻ミスの修正' })),
    )
  })

  it('deletes the day after confirming with a reason (UC-A015)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'deleteAttendanceDay').mockResolvedValue({ deleted: true })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    await userEvent.type(screen.getByLabelText('削除理由'), '二重入力の削除')
    await userEvent.selectOptions(screen.getByLabelText('打刻ログの扱い'), 'delete_punches')
    await userEvent.click(screen.getByRole('button', { name: '削除する' }))

    await waitFor(() =>
      expect(attendanceApi.deleteAttendanceDay).toHaveBeenCalledWith('day-1', {
        reason: '二重入力の削除',
        punch_log_action: 'delete_punches',
      }),
    )
  })

  it('shows the punch log and corrects an active punch (UC-A013)', async () => {
    const punch: AttendancePunch = {
      id: 'punch-10',
      user_id: 'user-1',
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
    vi.spyOn(attendanceApi, 'correctPunch').mockResolvedValue({ ...punch, id: 'punch-11', status: 'active' })
    renderPage([recordedDay])

    expect(await screen.findByText('有効')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '訂正' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: '削除' })).toHaveLength(1)

    await userEvent.click(screen.getByRole('button', { name: 'ログを編集' }))
    await userEvent.click(screen.getByRole('button', { name: '訂正' }))
    await userEvent.type(screen.getByLabelText('訂正理由'), '打刻時刻の入力ミス')
    await userEvent.click(screen.getByRole('button', { name: '訂正を保存' }))

    await waitFor(() =>
      expect(attendanceApi.correctPunch).toHaveBeenCalledWith('punch-10', expect.objectContaining({ reason: '打刻時刻の入力ミス' })),
    )
  })

  it('adds a punch from the punch log edit mode', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'createPunch').mockResolvedValue({
      id: 'punch-10',
      user_id: 'user-1',
      work_date: date,
      punch_type: 'clock_in',
      punched_at: `${date}T09:00:00+09:00`,
      source: 'web',
      note: null,
      status: 'active',
      correction_reason: null,
      corrected_by_user_id: null,
      corrected_at: null,
      superseded_by_punch_id: null,
      created_at: null,
    })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: 'ログを編集' }))
    await userEvent.selectOptions(screen.getByLabelText('追加する打刻種別'), 'clock_out')
    await pickDateTime(userEvent.setup(), '追加する日時(日付)', '追加する日時(時刻)', `${date}T18:00`)
    await userEvent.clear(screen.getByLabelText('追加するオフセット'))
    await userEvent.type(screen.getByLabelText('追加するオフセット'), '+09:00')
    await userEvent.click(screen.getByRole('button', { name: '打刻を追加' }))

    await waitFor(() =>
      expect(attendanceApi.createPunch).toHaveBeenCalledWith({
        work_date: date,
        punch_type: 'clock_out',
        punched_at: `${date}T18:00:00+09:00`,
        source: 'web',
      }),
    )
  })

  it('shows only active punches by default and marks a corrected replacement as edited', async () => {
    const originalPunch: AttendancePunch = {
      id: 'punch-10',
      user_id: 'user-1',
      work_date: date,
      punch_type: 'clock_in',
      punched_at: `${date}T09:30:00+09:00`,
      source: 'web',
      note: null,
      status: 'corrected',
      correction_reason: '打刻時刻の入力ミス',
      corrected_by_user_id: 'user-1',
      corrected_at: `${date}T10:00:00+09:00`,
      superseded_by_punch_id: 'punch-11',
      created_at: null,
    }
    const correctedPunch: AttendancePunch = {
      ...originalPunch,
      id: 'punch-11',
      punched_at: `${date}T09:00:00+09:00`,
      status: 'active',
      correction_reason: null,
      corrected_by_user_id: null,
      corrected_at: null,
      superseded_by_punch_id: null,
    }
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([originalPunch, correctedPunch])
    renderPage([recordedDay])

    expect(await screen.findByText('(編集済)')).toBeInTheDocument()
    expect(screen.queryByText('訂正済み')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'ログを編集' }))

    expect(screen.getByText('訂正済み')).toBeInTheDocument()
    expect(screen.getByText('理由: 打刻時刻の入力ミス')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '閲覧に戻る' })).toBeInTheDocument()
  })

  it('cancels an in-progress punch correction when returning to view mode', async () => {
    const punch: AttendancePunch = {
      id: 'punch-10',
      user_id: 'user-1',
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
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: 'ログを編集' }))
    await userEvent.click(screen.getByRole('button', { name: '訂正' }))
    await userEvent.type(screen.getByLabelText('訂正理由'), '保存しない入力')

    await userEvent.click(screen.getByRole('button', { name: '閲覧に戻る' }))

    expect(screen.queryByLabelText('訂正理由')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '訂正を保存' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '訂正' })).not.toBeInTheDocument()
  })

  it('shows a create form and creates a day when there is no record yet (UC-A016)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'createAttendanceDay').mockResolvedValue({ ...recordedDay, id: 'day-2' })
    vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockResolvedValue({
      source: 'none',
      actual_start_at: null,
      actual_end_at: null,
      breaks: [],
    })
    renderPage([])

    expect(await screen.findByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('作成理由(必須)'), '実績の入力漏れ')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(attendanceApi.createAttendanceDay).toHaveBeenCalledWith(
        expect.objectContaining({ user_id: 'user-1', work_date: date, reason: '実績の入力漏れ' }),
      ),
    )
  })

  it('reflects the schedule (including its break) as initial values when there is no record yet', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockResolvedValue({
      source: 'schedule',
      actual_start_at: `${date}T09:00:00+09:00`,
      actual_end_at: `${date}T18:00:00+09:00`,
      breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
    })
    renderPage([])

    expect(await screen.findByText('勤務予定(休憩を含む)を初期値として反映しました。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '出勤(日付)' })).toHaveTextContent(`${date}`)
    expect(screen.getByRole('button', { name: '出勤(時刻)' })).toHaveTextContent('09:00')
    expect(screen.getByRole('button', { name: '退勤(日付)' })).toHaveTextContent(`${date}`)
    expect(screen.getByRole('button', { name: '退勤(時刻)' })).toHaveTextContent('18:00')
    expect(screen.getByRole('button', { name: '休憩開始(日付)' })).toHaveTextContent(`${date}`)
    expect(screen.getByRole('button', { name: '休憩開始(時刻)' })).toHaveTextContent('12:00')
    expect(screen.getByRole('button', { name: '休憩終了(日付)' })).toHaveTextContent(`${date}`)
    expect(screen.getByRole('button', { name: '休憩終了(時刻)' })).toHaveTextContent('13:00')
  })

  it('replaces the create-form defaults when navigating to another day in the same week', async () => {
    const initialDate = '2026-07-25'
    const nextDate = '2026-07-26'
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockImplementation(async (_userId, workDate) => ({
      source: 'system_default',
      actual_start_at: `${workDate}T${workDate === initialDate ? '09:00' : '10:00'}:00+09:00`,
      actual_end_at: `${workDate}T${workDate === initialDate ? '18:00' : '19:00'}:00+09:00`,
      breaks: [],
    }))
    renderPage([], initialDate)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: '出勤(日付)' })).toHaveTextContent(initialDate)
      expect(screen.getByRole('button', { name: '出勤(時刻)' })).toHaveTextContent('09:00')
    })

    await userEvent.click(screen.getByRole('link', { name: '翌日' }))

    expect(await screen.findByText(`${nextDate}(日)`)).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByRole('button', { name: '出勤(日付)' })).toHaveTextContent(nextDate)
      expect(screen.getByRole('button', { name: '出勤(時刻)' })).toHaveTextContent('10:00')
    })
    expect(attendanceApi.fetchAttendanceDayDefaults).toHaveBeenLastCalledWith('user-1', nextDate)
  })

  it('warns of insufficient break time before saving, without blocking the save (labor law article 34)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'createAttendanceDay').mockResolvedValue({ ...recordedDay, id: 'day-2' })
    vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockResolvedValue({
      source: 'none',
      actual_start_at: null,
      actual_end_at: null,
      breaks: [],
    })
    renderPage([])

    await screen.findByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')
    await pickDateTime(userEvent.setup(), '出勤(日付)', '出勤(時刻)', `${date}T09:00`)
    await pickDateTime(userEvent.setup(), '退勤(日付)', '退勤(時刻)', `${date}T18:00`)

    expect(await screen.findByText(/休憩が60分未満です/)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('作成理由(必須)'), '休憩なしで退勤した')
    expect(screen.getByRole('button', { name: '作成する' })).not.toBeDisabled()
  })

  it('adjusts the calculated breakdown after registering, without touching the raw punch data (manual override)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'adjustAttendanceDailyCalculation').mockResolvedValue({
      ...recordedDay,
      calculation: { ...recordedDay.calculation!, statutory_within_overtime_minutes: 30, is_manually_adjusted: true },
    })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '集計値を修正' }))
    const overtimeInput = screen.getByLabelText('法定内残業時間(分)')
    await userEvent.clear(overtimeInput)
    await userEvent.type(overtimeInput, '30')
    await userEvent.type(screen.getByLabelText('補正理由(必須)'), '休憩の取り方を考慮して補正')
    await userEvent.click(screen.getByRole('button', { name: '補正を保存する' }))

    await waitFor(() =>
      expect(attendanceApi.adjustAttendanceDailyCalculation).toHaveBeenCalledWith(
        'day-1',
        expect.objectContaining({ statutory_within_overtime_minutes: 30, reason: '休憩の取り方を考慮して補正' }),
      ),
    )
  })

  it('adjusts the payroll work minutes (deemed-time override) alongside the calculated breakdown', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'adjustAttendanceDailyCalculation').mockResolvedValue({
      ...recordedDay,
      calculation: { ...recordedDay.calculation!, payroll_work_minutes: 540, is_manually_adjusted: true },
    })
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '集計値を修正' }))
    const payrollInput = screen.getByLabelText('給与計算上の労働時間(分)(みなし時間等)')
    await userEvent.clear(payrollInput)
    await userEvent.type(payrollInput, '540')
    await userEvent.type(screen.getByLabelText('補正理由(必須)'), 'みなし時間の個別補正')
    await userEvent.click(screen.getByRole('button', { name: '補正を保存する' }))

    await waitFor(() =>
      expect(attendanceApi.adjustAttendanceDailyCalculation).toHaveBeenCalledWith(
        'day-1',
        expect.objectContaining({ payroll_work_minutes: 540, reason: 'みなし時間の個別補正' }),
      ),
    )
  })

  it('shows leave segments and their aggregated minutes on the summary', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([
      {
        ...recordedDay,
        leave_segments: [
          { id: 1, start_at: `${date}T09:00:00+09:00`, end_at: `${date}T11:00:00+09:00`, note: '寝坊のため' },
        ],
        calculation: { ...recordedDay.calculation!, absence_minutes: 120, special_leave_days: 1 },
      },
    ])

    expect(await screen.findByText(/遅刻・早退 09:00 〜 11:00 \(寝坊のため\)/)).toBeInTheDocument()
    expect(screen.getByText('欠勤時間')).toBeInTheDocument()
    expect(screen.getByText('欠勤日数').nextElementSibling).toHaveTextContent('0日')
    expect(screen.getByText('特別休暇日数').nextElementSibling).toHaveTextContent('1日')
    expect(screen.getByText('欠勤あり')).toBeInTheDocument()
  })

  it('adds an absence segment on the create form and submits it (UC-A016)', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'createAttendanceDay').mockResolvedValue({ ...recordedDay, id: 'day-2' })
    vi.spyOn(attendanceApi, 'fetchAttendanceDayDefaults').mockResolvedValue({
      source: 'none',
      actual_start_at: null,
      actual_end_at: null,
      breaks: [],
    })
    renderPage([])

    await screen.findByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')
    await userEvent.click(screen.getByRole('button', { name: '遅刻・早退を追加' }))
    await pickDateTime(userEvent.setup(), '遅刻・早退開始(日付)', '遅刻・早退開始(時刻)', `${date}T09:00`)
    await pickDateTime(userEvent.setup(), '遅刻・早退終了(日付)', '遅刻・早退終了(時刻)', `${date}T11:00`)
    await userEvent.type(screen.getByLabelText('作成理由(必須)'), '午前は欠勤')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(attendanceApi.createAttendanceDay).toHaveBeenCalledWith(
        expect.objectContaining({
          leave_segments: [expect.objectContaining({ start: expect.any(String), end: expect.any(String) })],
        }),
      ),
    )
  })

  it('shows the eight calculation adjustment inputs', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([recordedDay])

    await userEvent.click(await screen.findByRole('button', { name: '集計値を修正' }))
    expect(screen.getByLabelText('所定労働時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('法定内残業時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('法定外残業時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('法定休日労働時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('給与計算上の労働時間(分)(みなし時間等)')).toBeInTheDocument()
    expect(screen.getByLabelText('うち深夜所定労働時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('うち深夜法定内残業時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('うち深夜法定外残業時間(分)')).toBeInTheDocument()
    expect(screen.getByLabelText('うち深夜法定休日労働時間(分)')).toBeInTheDocument()
  })

  it('hides edit/delete/adjust/punch-log-edit controls and shows a notice once the month is submitted', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...notSubmittedMonth, status: 'submitted' },
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    renderPage([recordedDay])

    expect(await screen.findByText('日次勤怠')).toBeInTheDocument()
    expect(await screen.findAllByText(/提出済みのため、この日は編集できません/)).not.toHaveLength(0)
    expect(screen.queryByRole('button', { name: '編集' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '削除' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '集計値を修正' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ログを編集' })).not.toBeInTheDocument()
  })

  it('hides the create form and shows a notice for a missing day once the month is submitted', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...notSubmittedMonth, status: 'submitted' },
      flex_settlement_summary: null,
      monthly_calculation_totals: zeroMonthlyCalculationTotals,
    })
    renderPage([])

    expect(await screen.findByText('日次勤怠')).toBeInTheDocument()
    expect(screen.queryByText('この日の勤怠記録はまだありません。実績を入力して作成できます。')).not.toBeInTheDocument()
    expect(await screen.findAllByText(/提出済みのため、この日は編集できません/)).not.toHaveLength(0)
  })

  it('locks a day whose own is_locked flag is true even if the month projection has not caught up', async () => {
    vi.spyOn(attendanceApi, 'fetchPunches').mockResolvedValue([])
    renderPage([{ ...recordedDay, is_locked: true }])

    expect(await screen.findByText('日次勤怠')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '編集' })).not.toBeInTheDocument()
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
