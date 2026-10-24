import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { PaidLeaveScheduleAssessmentRun, PaidLeaveScheduleAssessmentRunStatus } from '../../api/types'
import { PaidLeaveScheduleAssessmentRunCard, type PaidLeaveScheduleAssessmentRunCardProps } from './PaidLeaveScheduleAssessmentRunCard'

const succeededRun: PaidLeaveScheduleAssessmentRun = {
  id: 'run-1',
  status: 'succeeded',
  trigger: 'schedule',
  requested_by_name: null,
  started_at: '2026-10-01T03:00:00',
  finished_at: '2026-10-01T03:02:00',
  summary: { targets: 12, assessed: 12, eligible: 10, not_eligible: 1, needs_review: 1, failed: 0 },
  error_message: null,
}

/** 次回の定期実行。ローカル時刻のISO(オフセットなし)で渡し、表示をタイムゾーンに依存させない。 */
const statusWith = (latestRun: PaidLeaveScheduleAssessmentRun | null): PaidLeaveScheduleAssessmentRunStatus => ({
  next_run_at: '2026-11-01T03:00:00',
  latest_run: latestRun,
})

function renderCard(props: Partial<PaidLeaveScheduleAssessmentRunCardProps> = {}) {
  const onStart = props.onStart ?? vi.fn()
  render(<PaidLeaveScheduleAssessmentRunCard status={statusWith(succeededRun)} onStart={onStart} {...props} />)
  return { onStart }
}

describe('PaidLeaveScheduleAssessmentRunCard', () => {
  it('記録なし: 次回の自動判定と、まだ記録が無いことを表示する', () => {
    renderCard({ status: statusWith(null) })

    expect(screen.getByText('次回の自動判定: 11月1日 3:00')).toBeInTheDocument()
    expect(screen.getByText('自動判定の記録はまだありません。')).toBeInTheDocument()
    expect(screen.queryByText(/^前回:/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '今すぐ判定する' })).toBeEnabled()
  })

  it('待機中: 実行中でなければ次回の自動判定を表示し、今すぐ判定は押せる', () => {
    renderCard()

    expect(screen.getByRole('region', { name: '自動判定' })).toHaveAttribute('data-state', 'succeeded')
    expect(screen.getByText('次回の自動判定: 11月1日 3:00')).toBeInTheDocument()
    expect(screen.queryByText('判定を実行中です')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '今すぐ判定する' })).toBeEnabled()
  })

  it('完了: 前回の日時・結果・件数の内訳を表示する(依頼者は定期の実行では出さない)', () => {
    renderCard()

    expect(
      screen.getByText('前回: 10月1日 3:02 完了(対象12件 / 付与対象10件 / 対象外1件 / 要確認1件 / 失敗0件)'),
    ).toBeInTheDocument()
    expect(screen.queryByText(/手動で実行/)).not.toBeInTheDocument()
  })

  it('完了(手動): 依頼者名を表示する', () => {
    renderCard({ status: statusWith({ ...succeededRun, trigger: 'manual', requested_by_name: '山田花子' }) })

    expect(screen.getByText('手動で実行(依頼者: 山田花子)')).toBeInTheDocument()
  })

  it('失敗: 警告の見た目でerror_messageを表示する', () => {
    renderCard({
      status: statusWith({
        ...succeededRun,
        status: 'failed',
        finished_at: '2026-10-01T03:02:00',
        error_message: '2件の判定に失敗しました。',
      }),
    })

    expect(screen.getByText(/^前回: 10月1日 3:02 失敗/)).toBeInTheDocument()
    const note = screen.getByRole('note')
    expect(note).toHaveTextContent('2件の判定に失敗しました。')
    expect(note).toHaveClass('text-warning')
  })

  it('実行中(queued/running): 「判定を実行中です」を表示し、今すぐ判定は押せず理由を添える', () => {
    for (const runStatus of ['queued', 'running'] as const) {
      const { unmount } = render(
        <PaidLeaveScheduleAssessmentRunCard
          status={statusWith({ ...succeededRun, status: runStatus, finished_at: null, summary: null })}
          onStart={vi.fn()}
        />,
      )

      expect(screen.getByText('判定を実行中です')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: '今すぐ判定する' })).toBeDisabled()
      expect(screen.getByText('判定の実行中は操作できません。')).toBeInTheDocument()
      unmount()
    }
  })

  it('今すぐ判定する: 確認ダイアログを出し、確定で onStart を呼ぶ', async () => {
    const { onStart } = renderCard()

    await userEvent.click(screen.getByRole('button', { name: '今すぐ判定する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('今すぐ判定しますか?')).toBeInTheDocument()
    expect(within(dialog).getByText('付与予定日を過ぎた『予定』の行を、今すぐ判定します。')).toBeInTheDocument()
    expect(onStart).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: '判定する' }))
    expect(onStart).toHaveBeenCalledTimes(1)
  })

  it('確認をキャンセルすると onStart を呼ばない', async () => {
    const { onStart } = renderCard()

    await userEvent.click(screen.getByRole('button', { name: '今すぐ判定する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'キャンセル' }))

    expect(onStart).not.toHaveBeenCalled()
  })

  it('409(実行中)の失敗は確認ダイアログの中にエラーとして表示する', async () => {
    renderCard({ startError: new Error('判定は既に実行中です。') })

    await userEvent.click(screen.getByRole('button', { name: '今すぐ判定する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('判定は既に実行中です。')).toBeInTheDocument()
  })

  it('取得エラー: エラーを表示し、判定の操作は残す', () => {
    renderCard({ status: undefined, statusError: new Error('通信に失敗しました。') })

    expect(screen.getByRole('region', { name: '自動判定' })).toHaveAttribute('data-state', 'error')
    expect(screen.getByText('通信に失敗しました。')).toBeInTheDocument()
    expect(screen.queryByText(/次回の自動判定/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '今すぐ判定する' })).toBeEnabled()
  })

  it('読み込み中は今すぐ判定を出さない', () => {
    renderCard({ status: undefined })

    expect(screen.getByText('読み込み中...')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '今すぐ判定する' })).not.toBeInTheDocument()
  })
})
