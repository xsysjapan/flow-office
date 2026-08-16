import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { User } from '../../api/types'
import { pickTime } from '../../test-support/pickerInteractions'
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

function renderModal(onCompleted?: (message: string) => void) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MonthlyAttendanceBulkEntryModal yearMonth="2026-08" onCompleted={onCompleted} />
    </QueryClientProvider>,
  )
}

describe('MonthlyAttendanceBulkEntryModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('disables its trigger button when the month is locked (e.g. submitted)', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <MonthlyAttendanceBulkEntryModal yearMonth="2026-08" disabled />
      </QueryClientProvider>,
    )

    expect(await screen.findByRole('button', { name: '一括入力' })).toBeDisabled()
  })

  it('does not confirm directly from the simple tab: it expands into the day-by-day tab first, then notifies the caller and closes', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-03', status: 'created', message: null }],
      created_count: 20,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })
    const onCompleted = vi.fn()
    renderModal(onCompleted)

    expect(screen.queryByText('月次の一括入力')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    expect(await screen.findByText('月次の一括入力')).toBeInTheDocument()
    expect(screen.getByText('出退勤・休憩時刻を指定して一括で確定する。')).toBeInTheDocument()
    expect(screen.getByText('適用期間: 2026-08-01 〜 2026-08-31')).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: '確定する' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '次へ(日にちごとの内容を確認)' }))

    expect(await screen.findByRole('tab', { name: '日にちごとに設定', selected: true })).toBeInTheDocument()
    expect(attendanceApi.generateAttendancePattern).not.toHaveBeenCalled()
    // 平日(2026-08-03は月曜)は「まとめて設定」の既定どおり有効化されて展開される。
    expect(screen.getByRole('checkbox', { name: '08-03 (月)' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '08-01 (土)' })).not.toBeChecked()

    await userEvent.type(screen.getByLabelText('確定理由'), '出張中の実績をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    const workday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          from: '2026-08-01',
          to: '2026-08-31',
          weekly_pattern: { 1: null, 2: null, 3: null, 4: null, 5: null, 6: null, 7: null },
          day_overrides: expect.objectContaining({ '2026-08-03': workday, '2026-08-04': workday }),
          reason: '出張中の実績をまとめて入力',
        }),
      ),
    )

    // 完了後はモーダルを閉じ、結果は呼び出し元(onCompleted)に渡すだけでモーダル内には表示しない。
    await waitFor(() => expect(onCompleted).toHaveBeenCalledWith('20件作成・0件更新しました。'))
    await waitFor(() => expect(screen.queryByText('月次の一括入力')).not.toBeInTheDocument())
  }, 15000)

  it('switches to the day-by-day tab directly and overrides a single date, independent of the weekday pattern', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-04', status: 'updated', message: null }],
      created_count: 0,
      updated_count: 1,
      skipped_count: 0,
      rejected_count: 0,
    })

    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '日にちごとに設定' }))

    await userEvent.click(await screen.findByRole('checkbox', { name: '08-04 (火)' }))
    await pickTime(userEvent.setup(), '2026-08-04の出勤時刻', '10:00')
    await pickTime(userEvent.setup(), '2026-08-04の退勤時刻', '15:00')
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-08-04の休憩' }))

    await userEvent.type(screen.getByLabelText('確定理由'), '出張中の実績をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    const emptyWeeklyPattern = { 1: null, 2: null, 3: null, 4: null, 5: null, 6: null, 7: null }
    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          weekly_pattern: emptyWeeklyPattern,
          day_overrides: { '2026-08-04': { start_time: '10:00', end_time: '15:00' } },
        }),
      ),
    )
  }, 15000)
})
