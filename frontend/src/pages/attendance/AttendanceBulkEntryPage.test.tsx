import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { User } from '../../api/types'
import { AttendanceBulkEntryPage } from './AttendanceBulkEntryPage'

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  hire_date: '2026-01-15',
  last_login_at: null,
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <AttendanceBulkEntryPage />
    </QueryClientProvider>,
  )
}

describe('AttendanceBulkEntryPage', () => {
  it('previews and confirms a weekly attendance pattern for the current user', async () => {
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

    renderPage()

    await userEvent.type(screen.getByLabelText('適用開始日'), '2026-08-01')
    await userEvent.type(screen.getByLabelText('適用終了日'), '2026-08-31')
    await userEvent.click(screen.getByRole('button', { name: '週次パターンをプレビューする' }))

    const mondayToFriday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    await waitFor(() =>
      expect(attendanceApi.previewAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          from: '2026-08-01',
          to: '2026-08-31',
          weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
        }),
      ),
    )
    expect(await screen.findByText('2026-08-03: 09:00〜18:00')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('確定理由'), '打刻漏れの週をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '週次パターンで確定する' }))

    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          from: '2026-08-01',
          to: '2026-08-31',
          weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
          overwrite_mode: 'skip_existing',
          reason: '打刻漏れの週をまとめて入力',
        }),
      ),
    )
    expect(await screen.findByText('5件作成・0件更新しました。')).toBeInTheDocument()
  })

  it('overrides a single day when confirming a monthly attendance pattern', async () => {
    vi.spyOn(attendanceApi, 'previewAttendancePattern').mockResolvedValue({
      days: [
        {
          date: '2026-08-04',
          weekday: 2,
          start_time: '10:00',
          end_time: '15:00',
          break_start_time: null,
          break_end_time: null,
          has_existing_day: false,
          is_locked: false,
        },
      ],
    })
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-04', status: 'updated', message: null }],
      created_count: 20,
      updated_count: 1,
      skipped_count: 0,
      rejected_count: 0,
    })

    renderPage()

    await userEvent.type(screen.getByLabelText('対象年月(実績一括入力)'), '2026-08')

    await userEvent.click(await screen.findByRole('checkbox', { name: '2026-08-04(火)' }))
    await userEvent.clear(screen.getByLabelText('2026-08-04の出勤時刻'))
    await userEvent.type(screen.getByLabelText('2026-08-04の出勤時刻'), '10:00')
    await userEvent.clear(screen.getByLabelText('2026-08-04の退勤時刻'))
    await userEvent.type(screen.getByLabelText('2026-08-04の退勤時刻'), '15:00')
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-08-04の休憩' }))
    await userEvent.click(screen.getByRole('button', { name: '月次パターンをプレビューする' }))

    await waitFor(() =>
      expect(attendanceApi.previewAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          from: '2026-08-01',
          to: '2026-08-31',
          day_overrides: { '2026-08-04': { start_time: '10:00', end_time: '15:00' } },
        }),
      ),
    )
    expect(await screen.findByText('2026-08-04: 10:00〜15:00')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('確定理由(月次)'), '出張中の実績をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '月次パターンで確定する' }))

    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          day_overrides: { '2026-08-04': { start_time: '10:00', end_time: '15:00' } },
        }),
      ),
    )
    expect(await screen.findByText('20件作成・1件更新しました。')).toBeInTheDocument()
  })
})
