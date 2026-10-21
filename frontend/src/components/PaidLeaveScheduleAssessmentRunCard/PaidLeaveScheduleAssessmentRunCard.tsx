import type { PaidLeaveScheduleAssessmentRun, PaidLeaveScheduleAssessmentRunStatus, PaidLeaveScheduleAssessmentRunSummary } from '../../api/types'
import { Button } from '../Button/Button'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'

export interface PaidLeaveScheduleAssessmentRunCardProps {
  /** 自動判定の実行状況。読み込み中・取得エラーのときはundefined。 */
  status: PaidLeaveScheduleAssessmentRunStatus | undefined
  /** 実行状況の取得エラー。 */
  statusError?: unknown
  /** 「今すぐ判定する」の受付中。 */
  isStarting?: boolean
  /** 「今すぐ判定する」の失敗(409を含む)。確認ダイアログ内に表示する。 */
  startError?: unknown
  /** 確認ダイアログで「判定する」が押されたとき。Promiseを返すと、成功時にダイアログを閉じる。 */
  onStart: () => void | Promise<unknown>
  /** 確認ダイアログの開閉時(開くときに前回の失敗表示をリセットするため)。 */
  onConfirmOpenChange?: (open: boolean) => void
}

/**
 * 表示の状態。「待機中」は実行中でない状態全体を指し、次回の定期実行の行を出す。記録なし・完了・失敗は
 * その中の前回の結果の違いとして表示する(記録なしは前回の行を出さない)。
 */
type CardState = 'loading' | 'error' | 'running' | 'none' | 'succeeded' | 'failed'

/**
 * 日時を「11月1日 3:00」の形で表示する(ブラウザのローカル時刻。既存のAdminCommandsPageと同じ扱い)。
 */
function formatRunDateTime(iso: string): string {
  const date = new Date(iso)
  return `${date.getMonth() + 1}月${date.getDate()}日 ${date.getHours()}:${String(date.getMinutes()).padStart(2, '0')}`
}

function summaryText(summary: PaidLeaveScheduleAssessmentRunSummary): string {
  return `対象${summary.targets}件 / 付与対象${summary.eligible}件 / 対象外${summary.not_eligible}件 / 要確認${summary.needs_review}件 / 失敗${summary.failed}件`
}

/** 前回の実行の1行目(「前回: 10月1日 3:02 完了(対象12件 / …)」)。 */
function previousRunText(run: PaidLeaveScheduleAssessmentRun): string {
  const at = run.finished_at ?? run.started_at
  const when = at ? `${formatRunDateTime(at)} ` : ''
  const label = run.status === 'failed' ? '失敗' : '完了'
  const summary = run.summary ? `(${summaryText(run.summary)})` : ''
  return `前回: ${when}${label}${summary}`
}

function cardState(status: PaidLeaveScheduleAssessmentRunStatus | undefined, statusError: unknown): CardState {
  if (!status) return statusError !== undefined && statusError !== null ? 'error' : 'loading'
  const run = status.latest_run
  if (run?.status === 'queued' || run?.status === 'running') return 'running'
  if (!run) return 'none'
  return run.status === 'failed' ? 'failed' : 'succeeded'
}

/**
 * 付与予定画面の「自動判定」カード(spec.md論点6・7)。次回の定期判定、前回の結果、実行中の表示と、
 * 「今すぐ判定する」(確認ダイアログを経て実行)を置く。実行中は押せない(理由を添える)。
 * 状態の取得(実行中の5秒ポーリング)と受付は呼び出し側のhookが行う。
 */
export function PaidLeaveScheduleAssessmentRunCard({
  status,
  statusError,
  isStarting = false,
  startError,
  onStart,
  onConfirmOpenChange,
}: PaidLeaveScheduleAssessmentRunCardProps) {
  const state = cardState(status, statusError)
  const run = status?.latest_run ?? null

  return (
    <section aria-label="自動判定" data-state={state} className="mb-4 flex flex-col gap-2 rounded-md border border-border p-4 text-sm">
      <h3 className="font-medium text-foreground">自動判定</h3>

      {statusError !== undefined && statusError !== null && (
        <ErrorMessage error={statusError} fallback="自動判定の実行状況を取得できませんでした。" />
      )}

      {state === 'loading' && <p className="text-muted-foreground">読み込み中...</p>}

      {status && <p className="text-foreground">次回の自動判定: {formatRunDateTime(status.next_run_at)}</p>}

      {state === 'running' && run && (
        <>
          <p className="font-medium text-foreground">判定を実行中です</p>
          {run.trigger === 'manual' && run.requested_by_name && (
            <p className="text-muted-foreground">手動で実行(依頼者: {run.requested_by_name})</p>
          )}
        </>
      )}

      {state === 'none' && <p className="text-muted-foreground">自動判定の記録はまだありません。</p>}

      {(state === 'succeeded' || state === 'failed') && run && (
        <>
          <p className="text-foreground">{previousRunText(run)}</p>
          {run.trigger === 'manual' && run.requested_by_name && (
            <p className="text-muted-foreground">手動で実行(依頼者: {run.requested_by_name})</p>
          )}
          {state === 'failed' && (
            <div role="note" className="rounded-md border border-warning/50 bg-warning/10 p-3 text-warning">
              {run.error_message ?? '判定に失敗しました。'}
            </div>
          )}
        </>
      )}

      {state !== 'loading' && (
        <div className="flex flex-wrap items-center gap-2">
          {state === 'running' ? (
            <>
              <Button variant="secondary" size="sm" disabled>
                今すぐ判定する
              </Button>
              <span className="text-xs text-muted-foreground">判定の実行中は操作できません。</span>
            </>
          ) : (
            <ConfirmActionDialog
              triggerLabel="今すぐ判定する"
              triggerVariant="secondary"
              title="今すぐ判定しますか?"
              description="付与予定日を過ぎた『予定』の行を、今すぐ判定します。"
              confirmLabel="判定する"
              isPending={isStarting}
              error={startError}
              onOpenChange={onConfirmOpenChange}
              onConfirm={onStart}
            />
          )}
        </div>
      )}
    </section>
  )
}
