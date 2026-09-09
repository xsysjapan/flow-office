import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import { PaidLeaveScheduleEntryDetailPanel } from './PaidLeaveScheduleEntryDetailPanel'

const entry: PaidLeaveScheduleEntry = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '加藤 由美',
  scheduled_on: '2026-10-12',
  category: '通常',
  candidate_grant_days: 12,
  status: 'NeedsReview',
  needs_review_due_to_conflict: false,
  manual_override_reason: null,
  manual_override_by_user_id: null,
  manual_override_at: null,
  granted_paid_leave_grant_id: null,
  assessment: {
    period_start: '2025-10-12',
    period_end: '2026-10-11',
    denominator_days: 243,
    attendance_days: null,
    excluded_days: null,
    attendance_rate: null,
    policy_version: 'v1',
    automatic_result: 'NeedsReview',
    final_result: 'NeedsReview',
    override_reason: null,
  },
}

describe('PaidLeaveScheduleEntryDetailPanel', () => {
  it('判定状態・区分・Assessment内訳を表示する', () => {
    render(<PaidLeaveScheduleEntryDetailPanel entry={entry} onReassess={vi.fn()} onOverride={vi.fn()} />)

    expect(screen.getAllByText('要確認').length).toBeGreaterThan(0)
    expect(screen.getByText('通常')).toBeInTheDocument()
    expect(screen.getByText('2025-10-12 〜 2026-10-11')).toBeInTheDocument()
    expect(screen.getByText('算出不可')).toBeInTheDocument()
  })

  it('「再判定」ボタンでonReassessが呼ばれる', async () => {
    const user = userEvent.setup()
    const onReassess = vi.fn()
    render(<PaidLeaveScheduleEntryDetailPanel entry={entry} onReassess={onReassess} onOverride={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: '再判定' }))
    expect(onReassess).toHaveBeenCalledOnce()
  })

  it('Override欄は既定で折りたたまれ、ボタン押下時のみ展開する', async () => {
    const user = userEvent.setup()
    render(<PaidLeaveScheduleEntryDetailPanel entry={entry} onReassess={vi.fn()} onOverride={vi.fn()} />)

    expect(screen.queryByLabelText('理由')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '判定結果を上書き' }))
    expect(screen.getByLabelText('理由')).toBeInTheDocument()
  })

  it('理由が未入力の間は確定ボタンが無効で、入力すると呼び出せる', async () => {
    const user = userEvent.setup()
    const onOverride = vi.fn()
    render(<PaidLeaveScheduleEntryDetailPanel entry={entry} onReassess={vi.fn()} onOverride={onOverride} />)

    await user.click(screen.getByRole('button', { name: '判定結果を上書き' }))
    const confirmButton = screen.getByRole('button', { name: '確定' })
    expect(confirmButton).toBeDisabled()

    await user.type(screen.getByLabelText('理由'), '育休のため対象外から確定に上書きする。')
    expect(confirmButton).toBeEnabled()

    await user.click(confirmButton)
    expect(onOverride).toHaveBeenCalledWith({ final_result: 'Eligible', reason: '育休のため対象外から確定に上書きする。' })
  })

  it('キャンセルで入力欄を閉じる', async () => {
    const user = userEvent.setup()
    render(<PaidLeaveScheduleEntryDetailPanel entry={entry} onReassess={vi.fn()} onOverride={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: '判定結果を上書き' }))
    await user.click(screen.getByRole('button', { name: 'キャンセル' }))
    expect(screen.queryByLabelText('理由')).not.toBeInTheDocument()
  })
})
