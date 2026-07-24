import { useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { downloadAuditLogCsv, type AuditLogFilters } from '../../api/auditLog'
import { useAuditLog } from '../../hooks/useAuditLog'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * UC-M003: 管理者がイベントストアの操作履歴を検索・CSV出力する。
 */
export function AuditLogPage() {
  const [aggregateType, setAggregateType] = useState('')
  const [aggregateId, setAggregateId] = useState('')
  const [eventType, setEventType] = useState('')
  const [userId, setUserId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const filters: AuditLogFilters = {
    aggregate_type: aggregateType || undefined,
    aggregate_id: aggregateId || undefined,
    event_type: eventType || undefined,
    user_id: userId || undefined,
    from: from || undefined,
    to: to || undefined,
  }

  const { data, isLoading, error } = useAuditLog(filters)

  return (
    <Card
      title="監査ログ"
      actions={
        <Button variant="secondary" onClick={() => void downloadAuditLogCsv(filters)}>
          CSVダウンロード
        </Button>
      }
    >
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormField label="対象タイプ" htmlFor="audit-aggregate-type">
          <Input
            id="audit-aggregate-type"
            placeholder="workflow_request"
            value={aggregateType}
            onChange={(e) => setAggregateType(e.target.value)}
          />
        </FormField>
        <FormField label="対象ID" htmlFor="audit-aggregate-id">
          <Input id="audit-aggregate-id" value={aggregateId} onChange={(e) => setAggregateId(e.target.value)} />
        </FormField>
        <FormField label="イベント種別" htmlFor="audit-event-type">
          <Input id="audit-event-type" value={eventType} onChange={(e) => setEventType(e.target.value)} />
        </FormField>
        <FormField label="ユーザーID" htmlFor="audit-user-id">
          <Input id="audit-user-id" value={userId} onChange={(e) => setUserId(e.target.value)} />
        </FormField>
        <FormField label="期間(開始)" htmlFor="audit-from">
          <Input id="audit-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </FormField>
        <FormField label="期間(終了)" htmlFor="audit-to">
          <Input id="audit-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </FormField>
      </div>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="監査ログの取得に失敗しました。" />
      ) : (
        <>
          <p className="mb-3 text-sm text-muted-foreground">
            全{data?.meta.total ?? 0}件 ({data?.meta.current_page ?? 1}/{data?.meta.last_page ?? 1}ページ)
          </p>
          {(data?.data ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">該当するログはありません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {data?.data.map((event) => (
                <li key={event.id} className="py-2">
                  <div className="flex flex-wrap gap-4 text-sm">
                    <span className="text-foreground">{formatDateTime(event.occurred_at)}</span>
                    <span className="text-muted-foreground">{event.aggregate_type}</span>
                    <span className="text-muted-foreground">#{event.aggregate_id}</span>
                    <span className="text-foreground">{event.event_type}</span>
                  </div>
                  <details className="mt-1">
                    <summary className="cursor-pointer text-sm text-muted-foreground hover:text-foreground">
                      詳細
                    </summary>
                    <pre className="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs text-foreground">
                      {JSON.stringify(event.payload, null, 2)}
                    </pre>
                  </details>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </Card>
  )
}
