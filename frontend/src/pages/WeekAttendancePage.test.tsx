import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceDay, AttendancePunch } from '../api/types'
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

  it('sends actual_start_at/actual_end_at with the days recorded offset by default', async () => {
    vi.spyOn(attendanceApi, 'updateAttendanceDay').mockResolvedValue(mondayRecord)
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    expect(screen.getByLabelText('現地時刻オフセット(海外出張時などに変更)')).toHaveValue('+09:00')

    await userEvent.type(screen.getByLabelText('修正理由(必須)'), '確認')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() => expect(attendanceApi.updateAttendanceDay).toHaveBeenCalled())
    const input = vi.mocked(attendanceApi.updateAttendanceDay).mock.calls.at(-1)![1]
    expect(input.actual_start_at).toBe(`${weekStart}T09:00:00+09:00`)
    expect(input.actual_end_at).toBe(`${weekStart}T18:00:00+09:00`)
    expect(input.breaks?.[0]?.start).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/)
  })

  it('records a day with a different offset when editing for a business trip', async () => {
    vi.spyOn(attendanceApi, 'updateAttendanceDay').mockResolvedValue(mondayRecord)
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))

    const offsetInput = screen.getByLabelText('現地時刻オフセット(海外出張時などに変更)')
    await userEvent.clear(offsetInput)
    await userEvent.type(offsetInput, '-05:00')
    expect(offsetInput).toHaveValue('-05:00')
    await userEvent.type(screen.getByLabelText('修正理由(必須)'), '出張のため現地時刻で記録')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() => expect(attendanceApi.updateAttendanceDay).toHaveBeenCalled())
    const input = vi.mocked(attendanceApi.updateAttendanceDay).mock.calls.at(-1)![1]
    expect(input.actual_start_at).toBe(`${weekStart}T09:00:00-05:00`)
    expect(input.actual_end_at).toBe(`${weekStart}T18:00:00-05:00`)
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

  it('deletes a day after confirming with a reason (UC-A015)', async () => {
    vi.spyOn(attendanceApi, 'deleteAttendanceDay').mockResolvedValue({ deleted: true })
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    await userEvent.type(screen.getByLabelText('削除理由'), '二重入力の削除')
    await userEvent.click(screen.getByRole('button', { name: '削除する' }))

    await waitFor(() =>
      expect(attendanceApi.deleteAttendanceDay).toHaveBeenCalledWith(1, '二重入力の削除'),
    )
  })

  it('disables the delete confirmation until a reason is entered', async () => {
    renderPage([mondayRecord])

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    expect(screen.getByRole('button', { name: '削除する' })).toBeDisabled()
  })

  it('shows the punch log for a day and corrects an active punch (UC-A013)', async () => {
    const punch: AttendancePunch = {
      id: 10,
      user_id: 1,
      work_date: weekStart,
      punch_type: 'clock_in',
      punched_at: `${weekStart}T09:30:00+09:00`,
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
    renderPage([mondayRecord])

    // 週の7日分それぞれに打刻ログのトグルがあるため、月曜(weekStart, 先頭行)のものを開く。
    await userEvent.click((await screen.findAllByRole('button', { name: '打刻ログを表示' }))[0])
    // 「有効」バッジは打刻ログ行にのみ現れる(日次サマリーの「出勤」表記と衝突しないため)。
    expect(await screen.findByText('有効')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '訂正' }))
    await userEvent.type(screen.getByLabelText('訂正理由'), '打刻時刻の入力ミス')
    await userEvent.click(screen.getByRole('button', { name: '訂正を保存' }))

    await waitFor(() =>
      expect(attendanceApi.correctPunch).toHaveBeenCalledWith(
        10,
        expect.objectContaining({ reason: '打刻時刻の入力ミス' }),
      ),
    )
  })

  it('deletes a punch after entering a reason (UC-A014)', async () => {
    const punch: AttendancePunch = {
      id: 20,
      user_id: 1,
      work_date: weekStart,
      punch_type: 'clock_out',
      punched_at: `${weekStart}T18:05:00+09:00`,
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
    vi.spyOn(attendanceApi, 'deletePunch').mockResolvedValue({ ...punch, status: 'deleted' })
    renderPage([mondayRecord])

    await userEvent.click((await screen.findAllByRole('button', { name: '打刻ログを表示' }))[0])
    await screen.findByText('有効')
    // 「削除」ボタンは日次削除(先頭)と打刻削除(打刻ログ内)の両方にあるため、後者を選ぶ。
    const deleteButtons = screen.getAllByRole('button', { name: '削除' })
    await userEvent.click(deleteButtons[deleteButtons.length - 1])
    await userEvent.type(screen.getByLabelText('削除理由'), '二重打刻の削除')
    await userEvent.click(screen.getByRole('button', { name: '削除する' }))

    await waitFor(() => expect(attendanceApi.deletePunch).toHaveBeenCalledWith(20, '二重打刻の削除'))
  })
})
