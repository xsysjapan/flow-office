import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { User } from '../../api/types'
import { WeeklyAttendanceBulkEntryModal } from './WeeklyAttendanceBulkEntryModal'

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

function renderModal() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <WeeklyAttendanceBulkEntryModal defaultFrom="2026-08-03" defaultTo="2026-08-09" />
    </QueryClientProvider>,
  )
}

describe('WeeklyAttendanceBulkEntryModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('opens from its own trigger button and previews/confirms a weekly attendance pattern', async () => {
    vi.spyOn(attendanceApi, 'previewAttendancePattern').mockResolvedValue({
      days: [
        {
          date: '2026-08-03',
          weekday: 1,
          start_time: '09:00',
          end_time: '18:00',
          break_start_time: '12:00',
          break_end_time: '13:00',
          has_existing_day: false,
          is_locked: false,
        },
      ],
    })
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-03', status: 'created', message: null }],
      created_count: 5,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })

    renderModal()

    expect(screen.queryByText('週次の一括入力')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    expect(await screen.findByText('週次の一括入力')).toBeInTheDocument()
    expect(screen.getByText('適用期間: 2026-08-03 〜 2026-08-09')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    const mondayToFriday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    await waitFor(() =>
      expect(attendanceApi.previewAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          from: '2026-08-03',
          to: '2026-08-09',
          weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
        }),
      ),
    )
    expect(await screen.findByText('2026-08-03: 09:00〜18:00')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('確定理由'), '打刻漏れの週をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          from: '2026-08-03',
          to: '2026-08-09',
          overwrite_mode: 'skip_existing',
          reason: '打刻漏れの週をまとめて入力',
        }),
      ),
    )
    expect(await screen.findByText('5件作成・0件更新しました。')).toBeInTheDocument()
  })

  it('applies a single time range to only the selected weekdays on the simple tab', async () => {
    vi.spyOn(attendanceApi, 'previewAttendancePattern').mockResolvedValue({ days: [] })
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await screen.findByText('週次の一括入力')

    await userEvent.click(screen.getByRole('checkbox', { name: '土' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '月' }))
    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    const workday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    await waitFor(() =>
      expect(attendanceApi.previewAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          weekly_pattern: { 1: null, 2: workday, 3: workday, 4: workday, 5: workday, 6: workday, 7: null },
        }),
      ),
    )
  })

  it('switches to the detailed tab and sets an individual weekday time', async () => {
    vi.spyOn(attendanceApi, 'previewAttendancePattern').mockResolvedValue({ days: [] })
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '曜日ごとに設定' }))

    await userEvent.click(screen.getByRole('checkbox', { name: '土曜日' }))
    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    const weekday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    // 土曜日は既定で休憩なし(WeekdayScheduleFieldsの初期状態は平日のみ休憩を有効にするため)。
    const saturday = { start_time: '09:00', end_time: '18:00' }
    await waitFor(() =>
      expect(attendanceApi.previewAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          weekly_pattern: { 1: weekday, 2: weekday, 3: weekday, 4: weekday, 5: weekday, 6: saturday, 7: null },
        }),
      ),
    )
  })
})
