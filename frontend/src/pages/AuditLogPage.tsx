import { useState } from 'react'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { downloadAuditLogCsv, type AuditLogFilters } from '../api/auditLog'
import { useAuditLog } from '../hooks/useAuditLog'
import './AuditLogPage.css'

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
    user_id: userId ? Number(userId) : undefined,
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
      <div className="audit-log__filters">
        <FormField label="対象タイプ" htmlFor="audit-aggregate-type">
          <input
            id="audit-aggregate-type"
            placeholder="workflow_request"
            value={aggregateType}
            onChange={(e) => setAggregateType(e.target.value)}
          />
        </FormField>
        <FormField label="対象ID" htmlFor="audit-aggregate-id">
          <input id="audit-aggregate-id" value={aggregateId} onChange={(e) => setAggregateId(e.target.value)} />
        </FormField>
        <FormField label="イベント種別" htmlFor="audit-event-type">
          <input id="audit-event-type" value={eventType} onChange={(e) => setEventType(e.target.value)} />
        </FormField>
        <FormField label="ユーザーID" htmlFor="audit-user-id">
          <input id="audit-user-id" type="number" value={userId} onChange={(e) => setUserId(e.target.value)} />
        </FormField>
        <FormField label="期間(開始)" htmlFor="audit-from">
          <input id="audit-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </FormField>
        <FormField label="期間(終了)" htmlFor="audit-to">
          <input id="audit-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </FormField>
      </div>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="監査ログの取得に失敗しました。" />
      ) : (
        <>
          <p className="audit-log__summary">
            全{data?.meta.total ?? 0}件 ({data?.meta.current_page ?? 1}/{data?.meta.last_page ?? 1}ページ)
          </p>
          {(data?.data ?? []).length === 0 ? (
            <p>該当するログはありません。</p>
          ) : (
            <ul className="audit-log__list">
              {data?.data.map((event) => (
                <li key={event.id}>
                  <div className="audit-log__row">
                    <span>{formatDateTime(event.occurred_at)}</span>
                    <span>{event.aggregate_type}</span>
                    <span>#{event.aggregate_id}</span>
                    <span>{event.event_type}</span>
                  </div>
                  <details>
                    <summary>詳細</summary>
                    <pre>{JSON.stringify(event.payload, null, 2)}</pre>
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
