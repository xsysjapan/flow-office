import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveScheduleAssessmentPanel } from '../../components/PaidLeaveScheduleAssessmentPanel/PaidLeaveScheduleAssessmentPanel'
import { PaidLeaveScheduleBulkGrantBar } from '../../components/PaidLeaveScheduleBulkGrantBar/PaidLeaveScheduleBulkGrantBar'
import { Checkbox } from '../../components/ui/checkbox'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { PaidLeaveScheduleBulkGrantResponse } from '../../api/types'
import type { PaidLeaveScheduleFilter } from '../../api/paidLeave'
import {
  useBulkGrantPaidLeaveScheduleEntries,
  useOverridePaidLeaveScheduleAssessment,
  usePaidLeaveScheduleEntries,
  usePaidLeaveScheduleEntry,
  useReassessPaidLeaveScheduleEntry,
} from '../../hooks/usePaidLeave'
import { paidLeaveScheduleCategoryLabel, paidLeaveScheduleStatusLabel } from '../../utils/statusLabels'

const DEFAULT_FILTER: PaidLeaveScheduleFilter = 'all'

const FILTER_OPTIONS: Array<{ value: PaidLeaveScheduleFilter; label: string }> = [
  { value: 'all', label: 'すべて' },
  { value: 'eligible', label: '付与対象' },
  { value: 'not_eligible', label: '対象外' },
  { value: 'needs_review', label: '要確認' },
  { value: 'changed', label: '変更あり' },
]

/** 一括付与実行結果(ManualGrantCardのResultSummaryと同じ「全体件数+失敗内訳」パターン、spec.md論点15-6)。 */
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
 * 付与予定Schedule一覧(依頼書§39・spec.md論点12/15)。フィルタタブ・行選択+一括付与・
 * 行クリックで開くAssessment詳細Sheetを提供する管理者向け画面。
 */
export function PaidLeaveSchedulePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filter = (searchParams.get('filter') as PaidLeaveScheduleFilter | null) ?? DEFAULT_FILTER
  const selectedEntryId = searchParams.get('scheduleEntryId')

  const { data: entries, isLoading, error } = usePaidLeaveScheduleEntries(filter)
  const { data: selectedEntry, isLoading: isLoadingDetail, error: detailError } = usePaidLeaveScheduleEntry(selectedEntryId)

  const reassess = useReassessPaidLeaveScheduleEntry()
  const override = useOverridePaidLeaveScheduleAssessment()
  const bulkGrant = useBulkGrantPaidLeaveScheduleEntries()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkResult, setBulkResult] = useState<PaidLeaveScheduleBulkGrantResponse | null>(null)

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

  function handleFilterChange(next: PaidLeaveScheduleFilter) {
    updateParams({ filter: next === DEFAULT_FILTER ? null : next })
    setSelectedIds(new Set())
    setBulkResult(null)
  }

  function openDetail(id: string) {
    updateParams({ scheduleEntryId: id })
  }

  function closeDetail() {
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

  async function handleBulkGrant() {
    setBulkResult(null)
    const result = await bulkGrant.mutateAsync(Array.from(selectedIds))
    setBulkResult(result)
    setSelectedIds(new Set())
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="付与予定一覧の取得に失敗しました。" />

  const rows = entries ?? []

  return (
    <Card title="付与予定">
      <div className="mb-4 flex flex-wrap gap-2">
        {FILTER_OPTIONS.map((option) => (
          <Button
            key={option.value}
            variant={filter === option.value ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => handleFilterChange(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>

      {bulkGrant.error && <ErrorMessage error={bulkGrant.error} fallback="一括付与に失敗しました。" />}
      {bulkResult && <BulkGrantResultSummary result={bulkResult} />}

      {selectedIds.size > 0 && (
        <div className="mb-4">
          <PaidLeaveScheduleBulkGrantBar
            selectedCount={selectedIds.size}
            isSubmitting={bulkGrant.isPending}
            onCancel={() => setSelectedIds(new Set())}
            onBulkGrant={() => void handleBulkGrant()}
          />
        </div>
      )}

      {rows.length === 0 ? (
        filter === DEFAULT_FILTER ? (
          <EmptyState title="付与予定はまだありません。" description="日次の付与予定生成バッチが実行されると、ここに一覧が表示されます。" />
        ) : (
          <EmptyState title="条件に一致する付与予定はありません。" />
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
              entry={selectedEntry}
              onReassess={() => reassess.mutate(selectedEntry.id)}
              reassessIsPending={reassess.isPending}
              reassessError={reassess.error}
              onOverride={(input) => override.mutate({ scheduleEntryId: selectedEntry.id, input })}
              overrideIsPending={override.isPending}
              overrideError={override.error}
              workStyleSettingsHref="/admin/work-styles"
            />
          ) : null}
        </SheetContent>
      </Sheet>
    </Card>
  )
}
