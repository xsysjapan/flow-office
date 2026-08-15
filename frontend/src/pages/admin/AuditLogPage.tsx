import { useSearchParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Input } from '../../components/ui/input'
import { downloadAuditLogCsv, type AuditLogFilters } from '../../api/auditLog'
import { useAuditLog } from '../../hooks/useAuditLog'

const FILTER_KEYS = ['aggregate_type', 'aggregate_id', 'event_type', 'user_id', 'from', 'to'] as const

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * UC-M003: 管理者がイベントストアの操作履歴を検索・CSV出力する。
 * 検索条件・ページはURL(`?aggregate_type=...&page=2`)へ反映し、ブラウザの戻る/リロード/URL
 * 共有で状態が壊れないようにする(SKILL.md §2.10)。
 */
export function AuditLogPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const aggregateType = searchParams.get('aggregate_type') ?? ''
  const aggregateId = searchParams.get('aggregate_id') ?? ''
  const eventType = searchParams.get('event_type') ?? ''
  const userId = searchParams.get('user_id') ?? ''
  const from = searchParams.get('from') ?? ''
  const to = searchParams.get('to') ?? ''
  const page = Number(searchParams.get('page') ?? '1') || 1

  const isFiltered = FILTER_KEYS.some((key) => Boolean(searchParams.get(key)))

  function updateParam(key: (typeof FILTER_KEYS)[number], value: string) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) next.set(key, value)
        else next.delete(key)
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  function handlePageChange(nextPage: number) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.set('page', String(nextPage))
        return next
      },
      { replace: true },
    )
  }

  function clearFilters() {
    setSearchParams({}, { replace: true })
  }

  const filters: AuditLogFilters = {
    aggregate_type: aggregateType || undefined,
    aggregate_id: aggregateId || undefined,
    event_type: eventType || undefined,
    user_id: userId || undefined,
    from: from || undefined,
    to: to || undefined,
  }

  const { data, isLoading, error } = useAuditLog({ ...filters, page })

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
            onChange={(e) => updateParam('aggregate_type', e.target.value)}
          />
        </FormField>
        <FormField label="対象ID" htmlFor="audit-aggregate-id">
          <Input
            id="audit-aggregate-id"
            value={aggregateId}
            onChange={(e) => updateParam('aggregate_id', e.target.value)}
          />
        </FormField>
        <FormField label="イベント種別" htmlFor="audit-event-type">
          <Input id="audit-event-type" value={eventType} onChange={(e) => updateParam('event_type', e.target.value)} />
        </FormField>
        <FormField label="ユーザーID" htmlFor="audit-user-id">
          <Input id="audit-user-id" value={userId} onChange={(e) => updateParam('user_id', e.target.value)} />
        </FormField>
        <FormField label="期間(開始)" htmlFor="audit-from">
          <DatePicker id="audit-from" value={from || undefined} onChange={(date) => updateParam('from', date ?? '')} />
        </FormField>
        <FormField label="期間(終了)" htmlFor="audit-to">
          <DatePicker id="audit-to" value={to || undefined} onChange={(date) => updateParam('to', date ?? '')} />
        </FormField>
      </div>
      {isFiltered && (
        <div className="mb-4">
          <Button variant="secondary" onClick={clearFilters}>
            フィルターをクリア
          </Button>
        </div>
      )}

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
            <EmptyState
              title={isFiltered ? '条件に一致するログはありません。' : '監査ログはまだありません。'}
              description={
                isFiltered
                  ? '検索条件を変更するか、上の「フィルターをクリア」から条件を解除してください。'
                  : '操作(作成・更新・削除など)が行われると、ここに記録されます。'
              }
            />
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
          {data && (
            <Pagination
              currentPage={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              onPageChange={handlePageChange}
            />
          )}
        </>
      )}
    </Card>
  )
}
