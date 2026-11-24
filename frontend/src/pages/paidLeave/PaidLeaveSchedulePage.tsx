import { useEffect, useState, type ReactNode } from 'react'
import { BackLink } from '../../components/BackLink/BackLink'
import { useSearchParamsKeepingBackState } from '../../navigation/backState'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionBar } from '../../components/BulkActionBar/BulkActionBar'
import { BulkActionDialog, type BulkActionFailure, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { DateRangePicker, type DateRangeValue } from '../../components/DateRangePicker/DateRangePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { TextFilterInput } from '../../components/TextFilterInput/TextFilterInput'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveScheduleAssessmentPanel } from '../../components/PaidLeaveScheduleAssessmentPanel/PaidLeaveScheduleAssessmentPanel'
import { PaidLeaveScheduleAssessmentRunCard } from '../../components/PaidLeaveScheduleAssessmentRunCard/PaidLeaveScheduleAssessmentRunCard'
import { Checkbox } from '../../components/ui/checkbox'
import { NativeSelect } from '../../components/ui/native-select'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import type { PaidLeaveScheduleEntriesParams, PaidLeaveScheduleFilter } from '../../api/paidLeave'
import {
  useBulkGrantPaidLeaveScheduleEntries,
  useEditPaidLeaveScheduleEntry,
  useOverridePaidLeaveScheduleAssessment,
  usePaidLeaveScheduleAssessmentRunStatus,
  usePaidLeaveScheduleEntries,
  usePaidLeaveScheduleEntry,
  useReassessPaidLeaveScheduleEntry,
  useStartPaidLeaveScheduleAssessmentRun,
} from '../../hooks/usePaidLeave'
import { notify } from '../../lib/notify'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { paidLeaveScheduleCategoryLabel, paidLeaveScheduleStatusLabel } from '../../utils/statusLabels'
import { isValidDateParam } from '../../utils/searchParamValidators'
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

/**
 * 一括付与ダイアログの対象の要約(spec.md論点5・9)。件数・合計日数・付与予定日の範囲に加え、
 * 選んだ行に付与予定日より前(未経過)の行があれば警告を出す。「今日」はローカル日付で比べる。
 */
function buildBulkSummary(targets: PaidLeaveScheduleEntry[]): ReactNode {
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
    </span>
  )
}

const BULK_GRANT_DESCRIPTION = '付与後は付与予定画面から取り消せません。取消は使用状況から付与ごとに行います。'

/**
 * 組の相手(spec.md論点10)。同じ社員・同じ付与予定日で、名目の月数が初回とその+12か月の2行で、
 * どちらも付与対象のときに組になる。組でないときはundefined。
 */
function pairPartnerOf(entry: PaidLeaveScheduleEntry, rows: PaidLeaveScheduleEntry[]): PaidLeaveScheduleEntry | undefined {
  const months = entry.nominal_service_months
  if (entry.status !== 'Eligible' || months === null) return undefined
  return rows.find(
    (other) =>
      other.id !== entry.id &&
      other.status === 'Eligible' &&
      other.user_id === entry.user_id &&
      other.scheduled_on === entry.scheduled_on &&
      other.nominal_service_months !== null &&
      Math.abs(other.nominal_service_months - months) === 12,
  )
}

/**
 * 付与予定一覧(spec.md論点1・2・4・5・6・8・9)。絞り込みはURLクエリに保持し、行クリックで
 * 詳細Sheetを開く。Eligible行だけ選択して一括付与できる管理者向け画面。
 */
export function PaidLeaveSchedulePage() {
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()
  // URLキーは状態=status・社員名=q(標準形)。backendのパラメータ名(filter・user_name)への変換は下のlistParamsで行う。
  const filterParam = searchParams.get('status')
  const filter = parseFilter(filterParam)
  const userName = searchParams.get('q') ?? ''
  const scheduledOnFromParam = searchParams.get('scheduled_on_from')
  const scheduledOnToParam = searchParams.get('scheduled_on_to')
  // 不正な値は既定表示(未指定)として扱い、URLからもキーを消す
  const isInvalidFilter = filterParam !== null && !FILTER_OPTIONS.some((option) => option.value === filterParam)
  const isInvalidFrom = scheduledOnFromParam !== null && !isValidDateParam(scheduledOnFromParam)
  const isInvalidTo = scheduledOnToParam !== null && !isValidDateParam(scheduledOnToParam)
  const scheduledOnFrom = scheduledOnFromParam !== null && !isInvalidFrom ? scheduledOnFromParam : ''
  const scheduledOnTo = scheduledOnToParam !== null && !isInvalidTo ? scheduledOnToParam : ''
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
  const edit = useEditPaidLeaveScheduleEntry()
  const bulkGrant = useBulkGrantPaidLeaveScheduleEntries()
  const runStatus = usePaidLeaveScheduleAssessmentRunStatus()
  const startRun = useStartPaidLeaveScheduleAssessmentRun()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false)
  /** ダイアログを開いた時点の対象。実行中に一覧が再取得されても件数・内訳が変わらないようにする。 */
  const [bulkTargets, setBulkTargets] = useState<PaidLeaveScheduleEntry[]>([])
  const [isDetailDirty, setIsDetailDirty] = useState(false)

  useUnsavedChangesGuard(isDetailDirty)

  useEffect(() => {
    if (!isInvalidFilter && !isInvalidFrom && !isInvalidTo) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (isInvalidFilter) next.delete('status')
        if (isInvalidFrom) next.delete('scheduled_on_from')
        if (isInvalidTo) next.delete('scheduled_on_to')
        return next
      },
      { replace: true },
    )
  }, [isInvalidFilter, isInvalidFrom, isInvalidTo, setSearchParams])

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

  /** 絞り込みを変えたら、選択をリセットする。 */
  function resetSelection() {
    setSelectedIds(new Set())
  }

  function handleFilterChange(next: PaidLeaveScheduleFilter) {
    updateParams({ status: next === DEFAULT_FILTER ? null : next })
    resetSelection()
  }

  function handleUserNameChange(next: string | null) {
    updateParams({ q: next })
    resetSelection()
  }

  function handleDateRangeChange(range: DateRangeValue | undefined) {
    updateParams({ scheduled_on_from: range?.from || null, scheduled_on_to: range?.to || null })
    resetSelection()
  }

  function clearFilters() {
    updateParams({ status: null, q: null, scheduled_on_from: null, scheduled_on_to: null })
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
    edit.reset()
  }

  /** 組の片方を選ぶと相手も選び、外すと両方外す(組は常に両方を一括付与する。spec.md論点10)。 */
  function toggleRow(id: string) {
    const entry = (entries ?? []).find((row) => row.id === id)
    const partner = entry ? pairPartnerOf(entry, entries ?? []) : undefined
    const ids = partner ? [id, partner.id] : [id]
    setSelectedIds((prev) => {
      const next = new Set(prev)
      const adding = !prev.has(id)
      for (const target of ids) {
        if (adding) next.add(target)
        else next.delete(target)
      }
      return next
    })
  }

  const rows = entries ?? []
  const selectedEntries = rows.filter((entry) => selectedIds.has(entry.id) && entry.status === 'Eligible')

  function openBulkDialog() {
    setBulkTargets(selectedEntries)
    setBulkDialogOpen(true)
  }

  function removeFromSelection(ids: string[]) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      for (const id of ids) next.delete(id)
      return next
    })
  }

  /** 一括付与APIが返す1件ずつの結果を、一括操作ダイアログの結果型に詰める。 */
  async function executeBulkGrant(): Promise<BulkActionResult> {
    const response = await bulkGrant.mutateAsync(bulkTargets.map((entry) => entry.id))
    const succeededIds: string[] = []
    const failures: BulkActionFailure[] = []
    for (const target of bulkTargets) {
      const result = response.results.find((r) => r.schedule_entry_id === target.id)
      if (result?.success) {
        succeededIds.push(target.id)
      } else {
        failures.push({
          id: target.id,
          label: `${target.user_name ?? target.user_id}(${target.scheduled_on ?? ''})`,
          message: result?.message ?? '付与できませんでした。',
        })
      }
    }
    return { succeededIds, failures }
  }

  const top = (
    <>
      <BackLink className="mb-0" />
      <PageHeader description="有給の付与予定日と付与候補日数を確認し、自動判定を実行できます。" />
    </>
  )
  if (isLoading)
    return (
      <div className="flex flex-col gap-4">
        {top}
        <LoadingState />
      </div>
    )

  const dateRange: DateRangeValue | undefined =
    scheduledOnFrom || scheduledOnTo ? { from: scheduledOnFrom || undefined, to: scheduledOnTo || undefined } : undefined

  return (
    <div className="flex flex-col gap-4">
      {top}
      <Card>
        <PaidLeaveScheduleAssessmentRunCard
          status={runStatus.data}
          statusError={runStatus.error}
          isStarting={startRun.isPending}
          startError={startRun.error}
          onStart={async () => {
            await startRun.mutateAsync()
            notify.success('付与予定の判定を開始しました')
          }}
          onConfirmOpenChange={(open) => {
            if (open) startRun.reset()
          }}
        />

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

        <ListFilterBar className="mb-4" isFiltered={isFiltered} onClear={clearFilters}>
          <FormField label="状態" htmlFor="paid-leave-schedule-filter" className={filterWidth.select}>
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
          <TextFilterInput
            label="社員名"
            maxLength={200}
            className={filterWidth.keyword}
            value={userName || null}
            onChange={handleUserNameChange}
          />
          <FormField label="付与予定日" htmlFor="paid-leave-schedule-date-range" className={filterWidth.dateRange}>
            <DateRangePicker
              id="paid-leave-schedule-date-range"
              value={dateRange}
              placeholder="期間を指定"
              onChange={handleDateRangeChange}
            />
          </FormField>
        </ListFilterBar>

        <BulkActionBar
          selectedCount={selectedEntries.length}
          onClear={() => setSelectedIds(new Set())}
          actions={[{ key: 'grant', label: '一括付与する', onClick: openBulkDialog }]}
        />

        {error ? (
          <ErrorMessage error={error} fallback="付与予定一覧の取得に失敗しました。" />
        ) : rows.length === 0 ? (
          isFiltered ? (
            <EmptyState
              title="条件に一致する付与予定はありません。"
              description="検索条件を変えると表示される場合があります。"
              action={
                <Button variant="secondary" onClick={clearFilters}>
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
                      {entry.nominal_service_months !== null && (
                        <div className="mt-1 text-xs text-muted-foreground">継続{entry.nominal_service_months}か月分</div>
                      )}
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
                onReassess={() => reassess.mutate(selectedEntry.id, { onSuccess: () => notify.success('付与予定を再判定しました') })}
                reassessIsPending={reassess.isPending}
                reassessError={reassess.error}
                onOverride={(input) =>
                  override.mutate(
                    { scheduleEntryId: selectedEntry.id, input },
                    {
                      onSuccess: () => {
                        setIsDetailDirty(false)
                        notify.success('判定を上書きしました')
                      },
                    },
                  )
                }
                overrideIsPending={override.isPending}
                overrideError={override.error}
                onEdit={(input, callbacks) =>
                  edit.mutate(
                    { scheduleEntryId: selectedEntry.id, input },
                    {
                      onSuccess: () => {
                        callbacks.onSuccess()
                        notify.success('付与予定を修正しました')
                      },
                    },
                  )
                }
                editIsPending={edit.isPending}
                editError={edit.error}
                workStyleSettingsHref="/admin/work-styles"
                onDirtyChange={setIsDetailDirty}
              />
            ) : null}
          </SheetContent>
        </Sheet>
      </Card>

      <BulkActionDialog
        open={bulkDialogOpen}
        onOpenChange={setBulkDialogOpen}
        title="付与予定を一括付与しますか?"
        summary={buildBulkSummary(bulkTargets)}
        description={BULK_GRANT_DESCRIPTION}
        confirmLabel={`${bulkTargets.length}件を付与する`}
        resultTitle="一括付与の結果"
        onExecute={executeBulkGrant}
        onSucceeded={removeFromSelection}
        successMessage={(count) => `${count}件の有給を付与しました`}
      />
    </div>
  )
}
