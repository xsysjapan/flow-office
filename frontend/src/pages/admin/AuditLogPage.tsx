import { useEffect } from 'react'
import { useSearchParamsKeepingBackState } from '../../navigation/backState'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DateRangePicker } from '../../components/DateRangePicker/DateRangePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { TextFilterInput } from '../../components/TextFilterInput/TextFilterInput'
import { downloadAuditLogCsv, type AuditLogFilters } from '../../api/auditLog'
import { useAuditLog } from '../../hooks/useAuditLog'
import { isValidDateParam, isValidPageParam } from '../../utils/searchParamValidators'

const FILTER_KEYS = ['aggregate_type', 'aggregate_id', 'event_type', 'user_id', 'from', 'to'] as const

const TEXT_MAX_LENGTH = 100

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * UC-M003: 管理者がイベントストアの操作履歴を検索・CSV出力する。
 * 検索条件・ページはURL(`?aggregate_type=...&page=2`)へ反映し、ブラウザの戻る/リロード/URL
 * 共有で状態が壊れないようにする(SKILL.md §2.10)。
 */
export function AuditLogPage() {
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()

  const aggregateType = searchParams.get('aggregate_type') ?? ''
  const aggregateId = searchParams.get('aggregate_id') ?? ''
  const eventType = searchParams.get('event_type') ?? ''
  const userId = searchParams.get('user_id') ?? ''
  const fromParam = searchParams.get('from')
  const toParam = searchParams.get('to')
  const pageParam = searchParams.get('page')
  const isInvalidFrom = fromParam !== null && !isValidDateParam(fromParam)
  const isInvalidTo = toParam !== null && !isValidDateParam(toParam)
  const isInvalidPage = pageParam !== null && !isValidPageParam(pageParam)
  // 不正な値は既定表示として扱い、URLからもキーを消す。DateRangePickerは開始日が無いと値を持てないため、
  // 開始日の無い`to`だけのURLは期間なしとして扱いtoも消す
  const from = isInvalidFrom ? '' : (fromParam ?? '')
  const isOrphanTo = toParam !== null && !isInvalidTo && from === ''
  const to = isInvalidTo || isOrphanTo ? '' : (toParam ?? '')
  const shouldDropTo = isInvalidTo || isOrphanTo
  const page = isInvalidPage ? 1 : Number(pageParam ?? '1')

  useEffect(() => {
    if (!isInvalidFrom && !shouldDropTo && !isInvalidPage) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (isInvalidFrom) next.delete('from')
        if (shouldDropTo) next.delete('to')
        if (isInvalidPage) next.delete('page')
        return next
      },
      { replace: true },
    )
  }, [isInvalidFrom, shouldDropTo, isInvalidPage, setSearchParams])

  const isFiltered = Boolean(aggregateType || aggregateId || eventType || userId || from || to)
  const advancedActiveCount = (aggregateType ? 1 : 0) + (aggregateId ? 1 : 0)

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

  function changeRange(range: { from?: string; to?: string } | undefined) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (range?.from) next.set('from', range.from)
        else next.delete('from')
        if (range?.from && range.to) next.set('to', range.to)
        else next.delete('to')
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

  /** 詳細条件を含む全条件とpageを消す。 */
  function clearFilters() {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        for (const key of FILTER_KEYS) next.delete(key)
        next.delete('page')
        return next
      },
      { replace: true },
    )
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
      <ListFilterBar
        className="mb-4"
        isFiltered={isFiltered}
        onClear={clearFilters}
        advancedActiveCount={advancedActiveCount}
        advanced={
          <>
            <TextFilterInput
              label="対象タイプ"
              placeholder="workflow_request"
              maxLength={TEXT_MAX_LENGTH}
              className={filterWidth.select}
              value={aggregateType || null}
              onChange={(next) => updateParam('aggregate_type', next ?? '')}
            />
            <TextFilterInput
              label="対象ID"
              maxLength={TEXT_MAX_LENGTH}
              className={filterWidth.keyword}
              value={aggregateId || null}
              onChange={(next) => updateParam('aggregate_id', next ?? '')}
            />
          </>
        }
      >
        <TextFilterInput
          label="イベント種別"
          maxLength={TEXT_MAX_LENGTH}
          className={filterWidth.keyword}
          value={eventType || null}
          onChange={(next) => updateParam('event_type', next ?? '')}
        />
        <TextFilterInput
          label="ユーザーID"
          maxLength={TEXT_MAX_LENGTH}
          className={filterWidth.keyword}
          value={userId || null}
          onChange={(next) => updateParam('user_id', next ?? '')}
        />
        <FormField label="期間" htmlFor="audit-range" className={filterWidth.dateRange}>
          <DateRangePicker
            id="audit-range"
            value={from ? { from, to: to || undefined } : undefined}
            onChange={changeRange}
          />
        </FormField>
      </ListFilterBar>

      {isLoading ? (
        <LoadingState />
      ) : error && !data ? (
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
                  ? '検索条件を変更するか、「検索条件をクリア」から条件を解除してください。'
                  : '操作(作成・更新・削除など)が行われると、ここに記録されます。'
              }
              action={
                isFiltered ? (
                  <Button variant="secondary" size="sm" onClick={clearFilters}>
                    検索条件をクリア
                  </Button>
                ) : undefined
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
