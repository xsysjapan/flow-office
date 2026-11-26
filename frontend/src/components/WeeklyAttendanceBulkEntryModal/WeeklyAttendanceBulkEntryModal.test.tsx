import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import { ApiError } from '../../api/client'
import type { User } from '../../api/types'
import { notify } from '../../lib/notify'
import type { StatusMeta } from '../../utils/statusLabels'
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

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

function renderModal(dayOffBadgesByDate?: Record<string, StatusMeta[]>) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <WeeklyAttendanceBulkEntryModal
        defaultFrom="2026-08-03" defaultTo="2026-08-09"
        dayOffBadgesByDate={dayOffBadgesByDate}
      />
    </QueryClientProvider>,
  )
}

describe('WeeklyAttendanceBulkEntryModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.mocked(notify.success).mockClear()
  })

  it('does not confirm directly from the simple tab: it expands into the detailed tab first', async () => {
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

    expect(screen.queryByRole('button', { name: '確定する' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '次へ(曜日ごとの内容を確認)' }))

    expect(await screen.findByRole('tab', { name: '曜日ごとに設定', selected: true })).toBeInTheDocument()
    expect(attendanceApi.generateAttendancePattern).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText('確定理由(任意)'), '打刻漏れの週をまとめて入力')
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

    // 完了後はモーダルを閉じ、結果はトーストで通知しモーダル内には表示しない。
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('5件作成・0件更新しました。'))
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
    await userEvent.type(screen.getByLabelText('確定理由(任意)'), '打刻漏れの週をまとめて入力')
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

  it('shows the day-off badges only on the row of the matching date in the detailed tab', async () => {
    // 2026-08-03(月)に有給、2026-08-09(日)に法定休日と祝日名。
    renderModal({
      '2026-08-03': [{ label: '有給休暇(全休)', tone: 'info' }],
      '2026-08-09': [
        { label: '法定休日', tone: 'danger' },
        { label: '山の日', tone: 'neutral' },
      ],
    })

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '曜日ごとに設定' }))

    const rowOf = (name: string) => screen.getByRole('checkbox', { name }).closest('div.rounded-md') as HTMLElement
    expect(within(rowOf('月曜日')).getByText('有給休暇(全休)')).toBeInTheDocument()
    expect(within(rowOf('日曜日')).getByText('法定休日')).toBeInTheDocument()
    expect(within(rowOf('日曜日')).getByText('山の日')).toBeInTheDocument()
    expect(within(rowOf('火曜日')).queryByRole('status')).not.toBeInTheDocument()
    expect(screen.getAllByRole('status')).toHaveLength(3)
  })

  it('can confirm without a reason and omits the reason key from the request', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [],
      created_count: 0,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '曜日ごとに設定' }))
    expect(screen.getByLabelText('確定理由(任意)')).toHaveAttribute('placeholder', '未入力時は『一括入力』と記録')
    // 空白のみも未入力として扱う。
    await userEvent.type(screen.getByLabelText('確定理由(任意)'), '   ')
    expect(screen.getByRole('button', { name: '確定する' })).toBeEnabled()
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    await waitFor(() => expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledTimes(1))
    expect(vi.mocked(attendanceApi.generateAttendancePattern).mock.calls[0][0]).not.toHaveProperty('reason')
  })

  it('sends the trimmed reason when one is entered', async () => {
    vi.spyOn(attendanceApi, 'generateAttendancePattern').mockResolvedValue({
      results: [],
      created_count: 0,
      updated_count: 0,
      skipped_count: 0,
      rejected_count: 0,
    })
    renderModal()

    await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
    await userEvent.click(await screen.findByRole('tab', { name: '曜日ごとに設定' }))
    await userEvent.type(screen.getByLabelText('確定理由(任意)'), '  出張  ')
    await userEvent.click(screen.getByRole('button', { name: '確定する' }))

    await waitFor(() =>
      expect(attendanceApi.generateAttendancePattern).toHaveBeenCalledWith(expect.objectContaining({ reason: '出張' })),
    )
  })

  describe('422の項目エラー', () => {
    const SERVER_MESSAGE = 'サーバーの最初のエラー文'
    const HEADING = '入力内容を確認してください。'

    function unprocessable(keys: string[]) {
      return new ApiError(422, SERVER_MESSAGE, Object.fromEntries([...keys, 'unknown_key'].map((key) => [key, [`msg:${key}`]])))
    }

    it('shows each error once under its field or weekday row on the detailed tab, leaving unknown keys in the summary', async () => {
      const keys = [
        'utc_offset',
        'reason',
        'overwrite_mode',
        'weekly_pattern.1.start_time',
        'weekly_pattern.1.end_time',
        'weekly_pattern.3.break_start_time',
        'weekly_pattern.7.break_end_time',
        // 画面に入力の無いキーはまとめての表示に残る
        'from',
      ]
      vi.spyOn(attendanceApi, 'generateAttendancePattern').mockRejectedValue(unprocessable(keys))
      renderModal()

      await userEvent.click(screen.getByRole('button', { name: '一括入力' }))
      await userEvent.click(await screen.findByRole('button', { name: '次へ(曜日ごとの内容を確認)' }))
      await userEvent.click(await screen.findByRole('button', { name: '確定する' }))

      await screen.findByText(HEADING)
      for (const key of [...keys, 'unknown_key']) expect(screen.getAllByText(`msg:${key}`)).toHaveLength(1)
      expect(screen.queryByText(SERVER_MESSAGE)).not.toBeInTheDocument()
    })
  })
})
