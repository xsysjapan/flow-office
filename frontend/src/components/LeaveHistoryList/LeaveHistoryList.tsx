import { Badge } from '../Badge/Badge'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { LoadingState } from '../LoadingState/LoadingState'
import type { StoredEvent } from '../../api/types'
import {
  paidLeaveEventDetail,
  paidLeaveEventTypeLabel,
  specialLeaveEventDetail,
  specialLeaveEventTypeLabel,
} from '../../utils/statusLabels'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

const DOMAIN_TEXT = {
  paid_leave: {
    label: '有給履歴',
    empty: '有給履歴はまだありません。',
    errorFallback: '有給履歴の取得に失敗しました。',
    eventTypeLabel: paidLeaveEventTypeLabel,
    eventDetail: paidLeaveEventDetail,
  },
  special_leave: {
    label: '特別休暇履歴',
    empty: '特別休暇履歴はまだありません。',
    errorFallback: '特別休暇履歴の取得に失敗しました。',
    eventTypeLabel: specialLeaveEventTypeLabel,
    eventDetail: specialLeaveEventDetail,
  },
} as const

export interface LeaveHistoryListProps {
  domain: keyof typeof DOMAIN_TEXT
  events: StoredEvent[] | undefined
  isLoading: boolean
  error?: unknown
}

/**
 * 有給休暇・特別休暇の履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に表示する。
 * ビジネスロジック(Command/Handler)は有給・特別休暇で完全に分離しているが、履歴の表示は
 * どちらも「stored_eventsを時系列で見せる」という同じ形のQueryのため共通のコンポーネントを使う。
 * 自分用・管理者用のどちらの画面からも使う純粋な表示コンポーネント(データ取得は
 * 呼び出し側のhookが行う)。
 */
export function LeaveHistoryList({ domain, events, isLoading, error }: LeaveHistoryListProps) {
  const text = DOMAIN_TEXT[domain]

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback={text.errorFallback} />

  if (!events || events.length === 0) {
    return <p className="text-sm text-muted-foreground">{text.empty}</p>
  }

  return (
    <ul className="flex flex-col gap-1" aria-label={text.label}>
      {events.map((event) => {
        const { label, tone } = text.eventTypeLabel(event.event_type)
        return (
          <li key={event.id} className="flex flex-wrap items-center gap-3 py-1.5 text-sm">
            <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(event.occurred_at)}</span>
            <Badge tone={tone}>{label}</Badge>
            <span className="text-foreground">{text.eventDetail(event)}</span>
          </li>
        )
      })}
    </ul>
  )
}
