import { Badge } from '../Badge/Badge'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { LoadingState } from '../LoadingState/LoadingState'
import type { StoredEvent } from '../../api/types'
import { paidLeaveEventDetail, paidLeaveEventTypeLabel } from '../../utils/statusLabels'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

export interface PaidLeaveHistoryListProps {
  events: StoredEvent[] | undefined
  isLoading: boolean
  error?: unknown
}

/**
 * UC-P007: 有給履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に表示する。
 * 自分用・管理者用のどちらの画面からも使う純粋な表示コンポーネント(データ取得は
 * 呼び出し側のhookが行う)。
 */
export function PaidLeaveHistoryList({ events, isLoading, error }: PaidLeaveHistoryListProps) {
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="有給履歴の取得に失敗しました。" />

  if (!events || events.length === 0) {
    return <p className="text-sm text-muted-foreground">有給履歴はまだありません。</p>
  }

  return (
    <ul className="flex flex-col gap-1" aria-label="有給履歴">
      {events.map((event) => {
        const { label, tone } = paidLeaveEventTypeLabel(event.event_type)
        return (
          <li key={event.id} className="flex flex-wrap items-center gap-3 py-1.5 text-sm">
            <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(event.occurred_at)}</span>
            <Badge tone={tone}>{label}</Badge>
            <span className="text-foreground">{paidLeaveEventDetail(event)}</span>
          </li>
        )
      })}
    </ul>
  )
}
