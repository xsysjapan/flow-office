import { useEffect, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { DateRangePicker, type DateRangeValue } from '../../components/DateRangePicker/DateRangePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveScheduleAssessmentPanel } from '../../components/PaidLeaveScheduleAssessmentPanel/PaidLeaveScheduleAssessmentPanel'
import { PaidLeaveScheduleBulkGrantBar } from '../../components/PaidLeaveScheduleBulkGrantBar/PaidLeaveScheduleBulkGrantBar'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { PaidLeaveScheduleBulkGrantResponse, PaidLeaveScheduleEntry } from '../../api/types'
import type { PaidLeaveScheduleEntriesParams, PaidLeaveScheduleFilter } from '../../api/paidLeave'
import {
  useBulkGrantPaidLeaveScheduleEntries,
  useOverridePaidLeaveScheduleAssessment,
  usePaidLeaveScheduleEntries,
  usePaidLeaveScheduleEntry,
  useReassessPaidLeaveScheduleEntry,
} from '../../hooks/usePaidLeave'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { paidLeaveScheduleCategoryLabel, paidLeaveScheduleStatusLabel } from '../../utils/statusLabels'
import { formatDate } from '../../utils/weekDates'

const DEFAULT_FILTER: PaidLeaveScheduleFilter = 'all'

/** 状態の絞り込み(spec.md論点4・8)。overdueは付与予定日を過ぎて未付与の付与予定。 */
const FILTER_OPTIONS: Array<{ value: PaidLeaveScheduleFilter; label: string }> = [
  { value: 'all', label: 'すべて' },
  { value: 'eligible', label: '付与対象' },
  { value: 'not_eligible', label: '対象外' },
  { value: 'needs_review', label: '要確認' },
  { value: 'changed', label: '変更あり' },
  { value: 'overdue', label: '付与予定日超過(未付与)' },
]

function parseFilter(value: string | null): PaidLeaveScheduleFilter {
  return FILTER_OPTIONS.some((option) => option.value === value) ? (value as PaidLeaveScheduleFilter) : DEFAULT_FILTER
}

/** 一括付与実行結果(ManualGrantCardのResultSummaryと同じ「全体件数+失敗内訳」パターン、spec.md論点5)。 */
function BulkGrantResultSummary({ result }: { result: PaidLeaveScheduleBulkGrantResponse }) {
  return (
    <div className="mt-4 rounded-md border border-border p-3 text-sm">
      <p className="font-medium text-foreground">
        {result.success_count}件成功 / {result.failure_count}件失敗
      </p>
      {result.failure_count > 0 && (
        <ul className="mt-2 list-disc pl-4 text-destructive">
          {result.results
            .filter((r) => !r.success)
            .map((r) => (
              <li key={r.schedule_entry_id}>{r.message}</li>
            ))}
        </ul>
      )}
    </div>
  )
}

/**
 * 一括付与の確認ダイアログの本文(spec.md論点5・9)。件数・合計日数・付与予定日の範囲に加え、
 * 選んだ行に付与予定日より前(未経過)の行があれば警告を出す。「今日」はローカル日付で比べる。
 */
function buildBulkConfirmDescription(targets: PaidLeaveScheduleEntry[]): ReactNode {
  const totalDays = targets.reduce((sum, entry) => sum + (entry.candidate_grant_days ?? 0), 0)
  const dates = targets.map((entry) => entry.scheduled_on ?? '').filter((date) => date !== '').sort()
  const first = dates[0]
  const last = dates[dates.length - 1]
  const range = first === last ? first : `${first}〜${last}`
  const today = formatDate(new Date())
  const beforeScheduledCount = targets.filter((entry) => (entry.scheduled_on ?? '') > today).length

  return (
    <span className="flex flex-col gap-2">
      <span className="block">{`${targets.length}件(合計${totalDays}日)を付与します。`}</span>
      <span className="block">{`付与予定日: ${range}`}</span>
      {beforeScheduledCount > 0 && (
        <span className="block font-medium text-warning">
          {`付与予定日前の付与予定が${beforeScheduledCount}件含まれます。出勤率の判定期間にまだ経過していない日が含まれています。`}
        </span>
      )}
      <span className="block">付与後は付与予定画面から取り消せません。取消は使用状況から付与ごとに行います。</span>
    </span>
  )
}

/**
 * 付与予定一覧(spec.md論点1・2・4・5・6・8・9)。絞り込みはURLクエリに保持し、行クリックで
 * 詳細Sheetを開く。Eligible行だけ選択して一括付与できる管理者向け画面。
 */
export function PaidLeaveSchedulePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filter = parseFilter(searchParams.get('filter'))
  const userName = searchParams.get('user_name') ?? ''
  const scheduledOnFrom = searchParams.get('scheduled_on_from') ?? ''
  const scheduledOnTo = searchParams.get('scheduled_on_to') ?? ''
  const selectedEntryId = searchParams.get('scheduleEntryId')
  const isFiltered = filter !== DEFAULT_FILTER || userName !== '' || scheduledOnFrom !== '' || scheduledOnTo !== ''

  const listParams: PaidLeaveScheduleEntriesParams = {
    filter,
    user_name: userName || undefined,
    scheduled_on_from: scheduledOnFrom || undefined,
    scheduled_on_to: scheduledOnTo || undefined,
  }
  const { data: entries, isLoading, error } = usePaidLeaveScheduleEntries(listParams)
  const { data: overdueEntries } = usePaidLeaveScheduleEntries({ filter: 'overdue' })
  const overdueCount = overdueEntries?.length ?? 0
  const { data: selectedEntry, isLoading: isLoadingDetail, error: detailError } = usePaidLeaveScheduleEntry(selectedEntryId)

  const reassess = useReassessPaidLeaveScheduleEntry()
  const override = useOverridePaidLeaveScheduleAssessment()
  const bulkGrant = useBulkGrantPaidLeaveScheduleEntries()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkResult, setBulkResult] = useState<PaidLeaveScheduleBulkGrantResponse | null>(null)
  const [userNameDraft, setUserNameDraft] = useState(userName)
  const [isDetailDirty, setIsDetailDirty] = useState(false)

  // 社員名の入力欄は確定(EnterまたはblurでURLへ反映)まで手元の値を持つ。URL側が変わったら同期する。
  useEffect(() => {
    setUserNameDraft(userName)
  }, [userName])

  useUnsavedChangesGuard(isDetailDirty)

  /** 指定したキーだけをURLクエリパラメータへ反映する(他のキーはそのまま維持)。値`null`は削除。 */
  function updateParams(patch: Record<string, string | null>) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        for (const [key, value] of Object.entries(patch)) {
          if (value === null) next.delete(key)
          else next.set(key, value)
        }
        return next
      },
      { replace: true },
    )
  }

  /** 絞り込みを変えたら、選択と一括付与の結果をリセットする。 */
  function resetSelection() {
    setSelectedIds(new Set())
    setBulkResult(null)
  }

  function handleFilterChange(next: PaidLeaveScheduleFilter) {
    updateParams({ filter: next === DEFAULT_FILTER ? null : next })
    resetSelection()
  }

  function commitUserName() {
    const next = userNameDraft.trim()
    if (next === userName) return
    updateParams({ user_name: next || null })
    resetSelection()
  }

  function handleDateRangeChange(range: DateRangeValue | undefined) {
    updateParams({ scheduled_on_from: range?.from || null, scheduled_on_to: range?.to || null })
    resetSelection()
  }

  function clearFilters() {
    updateParams({ filter: null, user_name: null, scheduled_on_from: null, scheduled_on_to: null })
    setUserNameDraft('')
    resetSelection()
  }

  function openDetail(id: string) {
    updateParams({ scheduleEntryId: id })
  }

  function closeDetail() {
    if (isDetailDirty && !window.confirm('入力中の内容は破棄されます。閉じますか?')) return
    setIsDetailDirty(false)
    updateParams({ scheduleEntryId: null })
    reassess.reset()
    override.reset()
  }

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const rows = entries ?? []
  const selectedEntries = rows.filter((entry) => selectedIds.has(entry.id) && entry.status === 'Eligible')

  async function handleBulkGrant() {
    setBulkResult(null)
    const result = await bulkGrant.mutateAsync(selectedEntries.map((entry) => entry.id))
    setBulkResult(result)
    setSelectedIds(new Set())
  }

  if (isLoading) return <LoadingState />

  const dateRange: DateRangeValue | undefined =
    scheduledOnFrom || scheduledOnTo ? { from: scheduledOnFrom || undefined, to: scheduledOnTo || undefined } : undefined

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Link to="/admin/paid-leave" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
          ← 有給休暇
        </Link>
      </div>
      <Card title="付与予定">
        {overdueCount > 0 && (
          <Alert className="mb-4 border-warning/50 bg-warning/10">
            <AlertDescription className="flex flex-wrap items-center gap-3">
              <span>付与予定日を過ぎて未付与の付与予定が{overdueCount}件あります。</span>
              <Button variant="secondary" size="sm" onClick={() => handleFilterChange('overdue')}>
                表示する
              </Button>
            </AlertDescription>
          </Alert>
        )}

        <div className="mb-4 flex flex-wrap items-end gap-4">
          <div className="w-52">
            <FormField label="状態" htmlFor="paid-leave-schedule-filter">
              <NativeSelect
                id="paid-leave-schedule-filter"
                value={filter}
                onChange={(event) => handleFilterChange(parseFilter(event.target.value))}
              >
                {FILTER_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          </div>
          <div className="w-48">
            <FormField label="社員名" htmlFor="paid-leave-schedule-user-name">
              <Input
                id="paid-leave-schedule-user-name"
                value={userNameDraft}
                placeholder="社員名で絞り込む"
                onChange={(event) => setUserNameDraft(event.target.value)}
                onBlur={commitUserName}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') commitUserName()
                }}
              />
            </FormField>
          </div>
          <div className="w-64">
            <FormField label="付与予定日" htmlFor="paid-leave-schedule-date-range">
              <DateRangePicker
                id="paid-leave-schedule-date-range"
                value={dateRange}
                placeholder="期間を指定"
                onChange={handleDateRangeChange}
              />
            </FormField>
          </div>
        </div>

        {bulkResult && <BulkGrantResultSummary result={bulkResult} />}

        {selectedEntries.length > 0 && (
          <div className="mb-4">
            <PaidLeaveScheduleBulkGrantBar
              selectedCount={selectedEntries.length}
              isSubmitting={bulkGrant.isPending}
              confirmDescription={buildBulkConfirmDescription(selectedEntries)}
              error={bulkGrant.error}
              onCancel={() => setSelectedIds(new Set())}
              onBulkGrant={handleBulkGrant}
              onConfirmOpenChange={(open) => {
                if (open) bulkGrant.reset()
              }}
            />
          </div>
        )}

        {error ? (
          <ErrorMessage error={error} fallback="付与予定一覧の取得に失敗しました。" />
        ) : rows.length === 0 ? (
          isFiltered ? (
            <EmptyState
              title="条件に一致する付与予定はありません。"
              action={
                <Button variant="secondary" size="sm" onClick={clearFilters}>
                  検索条件をクリア
                </Button>
              }
            />
          ) : (
            <EmptyState
              title="付与予定はまだありません。"
              description="日次の付与予定生成バッチが実行されると、ここに一覧が表示されます。"
            />
          )
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead aria-hidden="true" />
                <TableHead>社員</TableHead>
                <TableHead>付与予定日</TableHead>
                <TableHead>区分</TableHead>
                <TableHead>付与候補日数</TableHead>
                <TableHead>出勤率</TableHead>
                <TableHead>判定状態</TableHead>
                <TableHead aria-hidden="true" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((entry) => {
                const statusMeta = paidLeaveScheduleStatusLabel(entry.status)
                const selectable = entry.status === 'Eligible'
                const selected = selectedIds.has(entry.id)
                return (
                  <ClickableTableRow
                    key={entry.id}
                    data-state={selected ? 'selected' : undefined}
                    onRowClick={() => openDetail(entry.id)}
                    rowLabel={`${entry.user_name ?? entry.user_id}の付与予定詳細を開く`}
                  >
                    <TableCell onClick={(e) => e.stopPropagation()}>
                      {selectable && (
                        <Checkbox
                          checked={selected}
                          onCheckedChange={() => toggleRow(entry.id)}
                          aria-label={`${entry.user_name ?? entry.user_id}を選択`}
                        />
                      )}
                    </TableCell>
                    <TableCell className="font-medium text-foreground">{entry.user_name ?? entry.user_id}</TableCell>
                    <TableCell className="text-muted-foreground">{entry.scheduled_on}</TableCell>
                    <TableCell>
                      <Badge tone="neutral">{paidLeaveScheduleCategoryLabel(entry.category)}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{entry.candidate_grant_days ?? '-'}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {entry.attendance_rate !== null ? `${entry.attendance_rate}%` : '-'}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1">
                        <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>
                        {entry.is_manually_overridden && <Badge tone="info">変更あり</Badge>}
                        {entry.status === 'NeedsReview' && (
                          <a
                            href="/admin/work-styles"
                            className="text-xs text-primary hover:underline"
                            onClick={(e) => e.stopPropagation()}
                          >
                            勤務形態を確認
                          </a>
                        )}
                      </div>
                    </TableCell>
                    <TableCell aria-hidden="true" />
                  </ClickableTableRow>
                )
              })}
            </TableBody>
          </Table>
        )}

        <Sheet open={selectedEntryId !== null} onOpenChange={(open) => !open && closeDetail()}>
          <SheetContent side="right" className="w-full max-w-md sm:max-w-lg">
            <SheetHeader>
              <SheetTitle>付与予定の詳細</SheetTitle>
            </SheetHeader>
            {isLoadingDetail ? (
              <LoadingState />
            ) : detailError ? (
              <ErrorMessage error={detailError} fallback="詳細の取得に失敗しました。" />
            ) : selectedEntry ? (
              <PaidLeaveScheduleAssessmentPanel
                key={selectedEntry.id}
                entry={selectedEntry}
                onReassess={() => reassess.mutate(selectedEntry.id)}
                reassessIsPending={reassess.isPending}
                reassessError={reassess.error}
                onOverride={(input) =>
                  override.mutate({ scheduleEntryId: selectedEntry.id, input }, { onSuccess: () => setIsDetailDirty(false) })
                }
                overrideIsPending={override.isPending}
                overrideError={override.error}
                workStyleSettingsHref="/admin/work-styles"
                onDirtyChange={setIsDetailDirty}
              />
            ) : null}
          </SheetContent>
        </Sheet>
      </Card>
    </div>
  )
}
