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

function renderModal(onCompleted?: (message: string) => void) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <WeeklyAttendanceBulkEntryModal defaultFrom="2026-08-03" defaultTo="2026-08-09" onCompleted={onCompleted} />
    </QueryClientProvider>,
  )
}

describe('WeeklyAttendanceBulkEntryModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('does not confirm directly from the simple tab: it expands into the detailed tab first', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-03', status: 'created', message: null }],
      created_count: 5,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })
    const onCompleted = vi.fn()
    renderModal(onCompleted)

    expect(screen.queryByText('週次の一括入力')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    expect(await screen.findByText('週次の一括入力')).toBeInTheDocument()
    expect(screen.getByText('適用期間: 2026-08-03 〜 2026-08-09')).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: '確定する' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '次へ(曜日ごとの内容を確認)' }))

    expect(await screen.findByRole('tab', { name: '曜日ごとに設定', selected: true })).toBeInTheDocument()
    expect(attendanceApi.generateAttendancePattern).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText('確定理由'), '打刻漏れの週をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    const mondayToFriday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-1',
          from: '2026-08-03',
          to: '2026-08-09',
          weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
          overwrite_mode: 'skip_existing',
          reason: '打刻漏れの週をまとめて入力',
        }),
      ),
    )

    // 完了後はモーダルを閉じ、結果は呼び出し元(onCompleted)に渡すだけでモーダル内には表示しない。
    await waitFor(() => expect(onCompleted).toHaveBeenCalledWith('5件作成・0件更新しました。'))
    await waitFor(() => expect(screen.queryByText('週次の一括入力')).not.toBeInTheDocument())
  })

  it('carries the selected weekdays from the simple tab into the expanded detailed tab', async () => {
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await screen.findByText('週次の一括入力')

    await userEvent.click(screen.getByRole('checkbox', { name: '土' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '月' }))
    await userEvent.click(screen.getByRole('button', { name: '次へ(曜日ごとの内容を確認)' }))

    await screen.findByRole('tab', { name: '曜日ごとに設定', selected: true })
    expect(screen.getByRole('checkbox', { name: '月曜日' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: '火曜日' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '土曜日' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '日曜日' })).not.toBeChecked()
  })

  it('switches to the detailed tab directly and sets an individual weekday time', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [{ date: '2026-08-08', status: 'created', message: null }],
      created_count: 1,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '曜日ごとに設定' }))

    await userEvent.click(screen.getByRole('checkbox', { name: '土曜日' }))
    await userEvent.type(screen.getByLabelText('確定理由'), '打刻漏れの週をまとめて入力')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    const weekday = { start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' }
    const saturday = { start_time: '09:00', end_time: '18:00' }
    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(
        expect.objectContaining({
          weekly_pattern: { 1: weekday, 2: weekday, 3: weekday, 4: weekday, 5: weekday, 6: saturday, 7: null },
        }),
      ),
    )
  })
})
