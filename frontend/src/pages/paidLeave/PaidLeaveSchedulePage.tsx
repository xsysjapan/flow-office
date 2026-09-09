import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import type { PaidLeaveScheduleStatusFilter } from '../../api/paidLeaveSchedule'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { PaidLeaveScheduleEntryDetailPanel } from '../../components/PaidLeaveScheduleEntryDetailPanel/PaidLeaveScheduleEntryDetailPanel'
import { Checkbox } from '../../components/ui/checkbox'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { Tabs, TabsList, TabsTrigger } from '../../components/ui/tabs'
import {
  useApplyScheduledGrants,
  useOverridePaidLeaveScheduleEntry,
  usePaidLeaveScheduleEntries,
  useReassessPaidLeaveScheduleEntry,
} from '../../hooks/usePaidLeaveSchedule'
import { paidLeaveScheduleEntryStatusLabel } from '../../utils/statusLabels'

const DEFAULT_STATUS: PaidLeaveScheduleStatusFilter = 'all'

const STATUS_TABS: Array<{ value: PaidLeaveScheduleStatusFilter; label: string }> = [
  { value: 'all', label: 'すべて' },
  { value: 'eligible', label: '付与対象' },
  { value: 'not_eligible', label: '対象外' },
  { value: 'needs_review', label: '要確認' },
  { value: 'changed', label: '変更あり' },
]

const CATEGORY_LABELS: Record<string, string> = {
  regular: '通常',
  proportional: '比例',
  shift: 'シフト',
}

function categoryLabel(category: string | null): string {
  if (!category) return '—'
  return CATEGORY_LABELS[category] ?? category
}

function formatDays(days: number | null): string {
  return days !== null ? days.toFixed(1) : '—'
}

function formatRate(rate: number | null): string {
  return rate !== null ? `${Math.round(rate * 100)}%` : '—'
}

/**
 * 付与予定(Schedule)一覧画面。`docs/changesets/20260906-paid-leave-schedule-assessment/
 * spec.md`の画面設計(実装前メモ・ワイヤーフレーム)通り、既存`ApprovalsPage`と同型の
 * List Pattern(フィルタタブ+テーブル+選択時のみのBulk Action Bar+行クリックで開く
 * 詳細パネル)を踏襲する。フィルタ・選択中の行・詳細対象IDはすべてURLに載せ、
 * ローカルstateを正にしない(`ApprovalsPage`と同じ考え方)。
 */
export function PaidLeaveSchedulePage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const status = (searchParams.get('status') as PaidLeaveScheduleStatusFilter | null) ?? DEFAULT_STATUS
  const pageParam = Number(searchParams.get('page'))
  const page = Number.isInteger(pageParam) && pageParam > 0 ? pageParam : 1
  const selectedEntryId = searchParams.get('scheduleEntryId')

  const { data, isLoading, error } = usePaidLeaveScheduleEntries({ status, page })

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

  function setSelectedEntryId(id: string | null) {
    updateParams({ scheduleEntryId: id })
  }

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const reassess = useReassessPaidLeaveScheduleEntry()
  const overrideEntry = useOverridePaidLeaveScheduleEntry()
  const applyGrants = useApplyScheduledGrants()

  const entries = data?.data ?? []
  const isFiltered = status !== DEFAULT_STATUS
  const selectedEntry: PaidLeaveScheduleEntry | undefined = entries.find((e) => e.id === selectedEntryId)

  function handleStatusChange(next: PaidLeaveScheduleStatusFilter) {
    updateParams({ status: next === DEFAULT_STATUS ? null : next, page: null })
    setSelectedIds(new Set())
  }

  function handlePageChange(nextPage: number) {
    updateParams({ page: String(nextPage) })
    setSelectedIds(new Set())
  }

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function closePanel() {
    setSelectedEntryId(null)
    reassess.reset()
    overrideEntry.reset()
  }

  async function handleBulkApply() {
    if (selectedIds.size === 0) return
    await applyGrants.mutateAsync(Array.from(selectedIds))
    setSelectedIds(new Set())
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="付与予定一覧の取得に失敗しました。" />

  return (
    <Card title="付与予定">
      <Tabs value={status} onValueChange={(value) => handleStatusChange(value as PaidLeaveScheduleStatusFilter)}>
        <TabsList>
          {STATUS_TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {selectedIds.size > 0 && (
        <div className="mt-4 flex w-full basis-full flex-col gap-2 rounded-md border border-border bg-muted/40 p-3 sm:w-auto sm:basis-auto sm:flex-row sm:items-center">
          <div className="flex items-center justify-between gap-2 sm:contents">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件選択中</span>
            <Button variant="secondary" size="sm" className="sm:order-last" onClick={() => setSelectedIds(new Set())}>
              キャンセル
            </Button>
          </div>
          <Button size="sm" isLoading={applyGrants.isPending} onClick={() => void handleBulkApply()}>
            一括付与
          </Button>
        </div>
      )}

      {applyGrants.error && <ErrorMessage error={applyGrants.error} fallback="一括付与に失敗しました。" />}
      {applyGrants.data && applyGrants.data.failure_count > 0 && (
        <p className="mt-2 text-sm text-destructive">
          {applyGrants.data.success_count}件成功 / {applyGrants.data.failure_count}件失敗しました。
        </p>
      )}

      <div className="mt-4">
        {entries.length === 0 ? (
          isFiltered ? (
            <EmptyState
              title="条件に一致する付与予定はありません。"
              description="フィルタを変えると表示される場合があります。"
              action={
                <Button variant="secondary" size="sm" onClick={() => handleStatusChange(DEFAULT_STATUS)}>
                  フィルターをクリア
                </Button>
              }
            />
          ) : (
            <EmptyState title="付与予定はまだありません。" description="対象社員のScheduleが生成されると、ここに一覧が表示されます。" />
          )
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead aria-hidden="true" />
                  <TableHead>社員</TableHead>
                  <TableHead>付与予定日</TableHead>
                  <TableHead>区分</TableHead>
                  <TableHead>候補日数</TableHead>
                  <TableHead>出勤率</TableHead>
                  <TableHead>判定状態</TableHead>
                  <TableHead>変更</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {entries.map((entry) => {
                  const { label, tone } = paidLeaveScheduleEntryStatusLabel(entry.status)
                  const selected = selectedIds.has(entry.id)
                  const selectable = entry.status === 'Eligible'
                  return (
                    <ClickableTableRow
                      key={entry.id}
                      data-state={selected ? 'selected' : undefined}
                      onRowClick={() => setSelectedEntryId(entry.id)}
                      rowLabel={`${entry.user_name ?? '社員'}の付与予定の詳細を開く`}
                    >
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        {selectable ? (
                          <Checkbox
                            checked={selected}
                            disabled={applyGrants.isPending}
                            onCheckedChange={() => toggleRow(entry.id)}
                            aria-label={`${entry.user_name ?? '社員'}を選択`}
                          />
                        ) : (
                          <span title="付与対象のみ選択できます">
                            <Checkbox checked={false} disabled aria-label={`${entry.user_name ?? '社員'}を選択(付与対象のみ選択できます)`} />
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        <button
                          type="button"
                          className="font-medium text-foreground hover:text-primary hover:underline"
                          onClick={(e) => {
                            e.stopPropagation()
                            setSelectedEntryId(entry.id)
                          }}
                        >
                          {entry.user_name ?? '—'}
                        </button>
                      </TableCell>
                      <TableCell className="text-muted-foreground">{entry.scheduled_on ?? '—'}</TableCell>
                      <TableCell className="text-muted-foreground">{categoryLabel(entry.category)}</TableCell>
                      <TableCell className="text-muted-foreground">{formatDays(entry.candidate_grant_days)}</TableCell>
                      <TableCell className="text-muted-foreground">{formatRate(entry.assessment.attendance_rate)}</TableCell>
                      <TableCell>
                        <Badge tone={tone}>{label}</Badge>
                      </TableCell>
                      <TableCell>{entry.needs_review_due_to_conflict && <Badge tone="danger">変更</Badge>}</TableCell>
                    </ClickableTableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        )}
      </div>

      {data && (
        <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPageChange={handlePageChange} />
      )}

      <Sheet open={selectedEntryId !== null} onOpenChange={(open) => !open && closePanel()}>
        <SheetContent side="right" className="w-full max-w-md sm:max-w-lg">
          <SheetHeader>
            <SheetTitle>
              {selectedEntry ? `${selectedEntry.user_name ?? '社員'} ${selectedEntry.scheduled_on ?? ''}` : '付与予定の詳細'}
            </SheetTitle>
          </SheetHeader>
          {selectedEntry && (
            <PaidLeaveScheduleEntryDetailPanel
              entry={selectedEntry}
              onReassess={() => reassess.mutate(selectedEntry.id)}
              reassessIsPending={reassess.isPending}
              reassessError={reassess.error}
              onOverride={(input) => overrideEntry.mutate({ entryId: selectedEntry.id, input })}
              overrideIsPending={overrideEntry.isPending}
              overrideError={overrideEntry.error}
            />
          )}
        </SheetContent>
      </Sheet>
    </Card>
  )
}
