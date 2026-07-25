import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { User } from '../../api/types'
import { MonthlyAttendanceBulkEntryModal } from './MonthlyAttendanceBulkEntryModal'

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
      <MonthlyAttendanceBulkEntryModal yearMonth="2026-08" />
    </QueryClientProvider>,
  )
}

describe('MonthlyAttendanceBulkEntryModal', () => {
  it('opens from its own trigger button and overrides a single day when confirming', async () => {
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

    renderModal()

    expect(screen.queryByText('月次の一括入力')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    expect(await screen.findByText('月次の一括入力')).toBeInTheDocument()
    expect(screen.getByText('2026-08: 曜日ごとの既定に加えて、日単位でも個別に設定できる。')).toBeInTheDocument()

    await userEvent.click(await screen.findByRole('checkbox', { name: '2026-08-04(火)' }))
    await userEvent.clear(screen.getByLabelText('2026-08-04の出勤時刻'))
    await userEvent.type(screen.getByLabelText('2026-08-04の出勤時刻'), '10:00')
    await userEvent.clear(screen.getByLabelText('2026-08-04の退勤時刻'))
    await userEvent.type(screen.getByLabelText('2026-08-04の退勤時刻'), '15:00')
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-08-04の休憩' }))
    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

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

    await userEvent.type(screen.getByLabelText('確定理由'), '出張中の実績をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

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
