import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import type { AttendanceMonthStatus } from '../../api/types'
import { useApproveMonth, useCloseMonth, useMonthsToApprove, useReturnMonth } from '../../hooks/useAttendance'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../../utils/statusLabels'
import { hasAnyRole, ROLE } from '../../utils/roles'
import { DailyReferenceView, MonthlyReferenceView, WeeklyReferenceView, type ViewMode } from './AttendanceReferencePage'

const VIEW_MODES: Array<{ key: ViewMode; label: string }> = [
  { key: 'month', label: '月次' },
  { key: 'week', label: '週次' },
  { key: 'day', label: '日次' },
]

const STATUS_FILTER_OPTIONS: Array<{ value: AttendanceMonthStatus | ''; label: string }> = [
  { value: '', label: 'すべて' },
  { value: 'submitted', label: '提出済み' },
  { value: 'approved', label: '承認済み' },
]

/** 承認者が対象社員の実際の勤務表(月次・週次・日次・打刻ログ)を確認するための展開領域。
 *  行ごとに独立させるため、呼び出し側で`key`に対象月のidを渡してリセットさせる想定。 */
function MonthAttendanceReview({ userId, yearMonth }: { userId: string; yearMonth: string }) {
  const [viewMode, setViewMode] = useState<ViewMode>('month')

  return (
    <div className="mt-3 flex flex-col gap-3 rounded-md border border-border p-3">
      <div className="flex gap-2">
        {VIEW_MODES.map((mode) => (
          <Button
            key={mode.key}
            type="button"
            size="sm"
            variant={viewMode === mode.key ? 'primary' : 'secondary'}
            onClick={() => setViewMode(mode.key)}
          >
            {mode.label}
          </Button>
        ))}
      </div>

      {viewMode === 'month' && <MonthlyReferenceView userId={userId} initialYearMonth={yearMonth} />}
      {viewMode === 'week' && <WeeklyReferenceView userId={userId} />}
      {viewMode === 'day' && <DailyReferenceView userId={userId} />}
    </div>
  )
}

/**
 * UC-A009: 承認者向けの月次勤怠の承認・差戻し。
 * UC-A010: 管理者/人事による締め処理(admin・hr_staffロールのみ)。
 * 提出済みの月次は複数選択し、まとめて承認できる(個別の差戻し/締め処理は行ごとに残す)。
 * ステータス・年月・対象社員での絞り込みとページングに対応し、行ごとに対象社員の
 * 実際の勤務表(月次・週次・日次・打刻ログ)を展開して確認できる。
 */
export function MonthsToApprovePage() {
  const { user } = useAuth()
  const [status, setStatus] = useState<AttendanceMonthStatus | ''>('')
  const [yearMonth, setYearMonth] = useState('')
  const [filterUserId, setFilterUserId] = useState<string | undefined>(undefined)
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useMonthsToApprove({
    status: status || undefined,
    yearMonth: yearMonth || undefined,
    userId: filterUserId,
    page,
  })
  const approveMonth = useApproveMonth()
  const returnMonth = useReturnMonth()
  const closeMonth = useCloseMonth()

  const [comments, setComments] = useState<Record<string, string>>({})
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [isBulkApproving, setIsBulkApproving] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)
  const [expandedMonthId, setExpandedMonthId] = useState<string | null>(null)

  const canClose = hasAnyRole(user?.roles, [ROLE.ADMIN, ROLE.HR_STAFF])
  const actionError = approveMonth.error ?? returnMonth.error ?? closeMonth.error ?? bulkError

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function handleFilterChange(next: { status?: AttendanceMonthStatus | ''; yearMonth?: string; userId?: string }) {
    if (next.status !== undefined) setStatus(next.status)
    if (next.yearMonth !== undefined) setYearMonth(next.yearMonth)
    if (next.userId !== undefined) setFilterUserId(next.userId)
    setPage(1)
  }

  async function handleBulkApprove() {
    if (selectedIds.size === 0) return
    setBulkError(null)
    setIsBulkApproving(true)
    try {
      await Promise.all(Array.from(selectedIds).map((id) => approveMonth.mutateAsync(id)))
      setSelectedIds(new Set())
    } catch (e) {
      setBulkError(e as Error)
    } finally {
      setIsBulkApproving(false)
    }
  }

  const months = data?.data ?? []

  return (
    <Card
      title="承認待ちの月次勤怠"
      actions={
        selectedIds.size > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
            <Button onClick={() => void handleBulkApprove()} isLoading={isBulkApproving}>
              まとめて承認する
            </Button>
          </div>
        ) : undefined
      }
    >
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <div className="w-40">
          <FormField label="ステータス" htmlFor="months-to-approve-status">
            <NativeSelect
              id="months-to-approve-status"
              value={status}
              onChange={(e) => handleFilterChange({ status: e.target.value as AttendanceMonthStatus | '' })}
            >
              {STATUS_FILTER_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>
        <div className="w-36">
          <FormField label="年月" htmlFor="months-to-approve-year-month">
            <Input
              id="months-to-approve-year-month"
              type="month"
              value={yearMonth}
              onChange={(e) => handleFilterChange({ yearMonth: e.target.value })}
            />
          </FormField>
        </div>
        <div className="max-w-sm flex-1">
          <FormField label="対象社員" htmlFor="months-to-approve-user">
            <UserPicker
              id="months-to-approve-user"
              value={filterUserId}
              onChange={(userId) => handleFilterChange({ userId })}
            />
          </FormField>
        </div>
        {filterUserId && (
          <Button variant="secondary" onClick={() => handleFilterChange({ userId: undefined })}>
            対象社員の絞り込みを解除
          </Button>
        )}
      </div>

      {actionError && <ErrorMessage error={actionError} />}

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="承認待ちの月次勤怠の取得に失敗しました。" />
      ) : months.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの月次勤怠はありません。</p>
      ) : (
        <>
          <ul className="divide-y divide-border">
            {months.map((month) => {
              const { label, tone } = attendanceMonthStatusLabel(month.status)
              const comment = comments[month.id] ?? ''
              const selectable = month.status === 'submitted'
              const selected = selectedIds.has(month.id)
              const employeeName = month.user?.name ?? `社員ID: ${month.user_id}`
              const isExpanded = expandedMonthId === month.id

              return (
                <li key={month.id} className="py-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      {selectable && (
                        <Checkbox
                          checked={selected}
                          onCheckedChange={() => toggleRow(month.id)}
                          aria-label={`${month.year_month}(${employeeName})を選択`}
                        />
                      )}
                      <span className="text-sm font-semibold text-foreground">{month.year_month}</span>
                      <span className="text-sm text-muted-foreground">{employeeName}</span>
                    </div>
                    <Badge tone={tone}>{label}</Badge>
                  </div>

                  {month.legal_holiday_warnings.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-2">
                      {month.legal_holiday_warnings.map((warning) => (
                        <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                          {legalHolidayWarningLabel(warning)}
                        </Badge>
                      ))}
                    </div>
                  )}

                  <div className="mt-2 flex flex-wrap items-center gap-3">
                    <Button
                      variant="secondary"
                      onClick={() => setExpandedMonthId(isExpanded ? null : month.id)}
                    >
                      {isExpanded ? '勤務表を閉じる' : '実際の勤務表を確認'}
                    </Button>

                    {month.status === 'submitted' && (
                      <>
                        <Button isLoading={approveMonth.isPending} onClick={() => approveMonth.mutate(month.id)}>
                          承認する
                        </Button>
                        <div className="flex items-center gap-2">
                          <Input
                            placeholder="差戻しコメント"
                            value={comment}
                            onChange={(e) => setComments((prev) => ({ ...prev, [month.id]: e.target.value }))}
                          />
                          <Button
                            variant="secondary"
                            isLoading={returnMonth.isPending}
                            disabled={!comment}
                            onClick={() => returnMonth.mutate({ id: month.id, comment })}
                          >
                            差戻す
                          </Button>
                        </div>
                      </>
                    )}

                    {canClose && month.status === 'approved' && (
                      <Button isLoading={closeMonth.isPending} onClick={() => closeMonth.mutate(month.id)}>
                        締め処理
                      </Button>
                    )}
                  </div>

                  {isExpanded && (
                    <MonthAttendanceReview key={month.id} userId={month.user_id} yearMonth={month.year_month} />
                  )}
                </li>
              )
            })}
          </ul>

          {data && <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPageChange={setPage} />}
        </>
      )}
    </Card>
  )
}
