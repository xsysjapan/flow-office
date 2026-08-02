import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import { useCloseMonth, useMonthsToApprove } from '../../hooks/useAttendance'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../../utils/statusLabels'
import { hasAnyRole, ROLE } from '../../utils/roles'
import { datesInMonth } from '../../utils/weekDates'
import { DailyReferenceView, MonthlyReferenceView } from './AttendanceReferencePage'

/**
 * 承認者が対象社員の実際の勤務表(月次・打刻ログ)を確認するための展開領域。
 * レビュー対象はこの月の年月のみのため、他の月には遷移できないようにする
 * (MonthlyReferenceViewの前月・次月ナビは表示しない)。日別の行を選ぶとその日の
 * 詳細に遷移し、そこでも前日・翌日への移動は対象月の範囲内に限定する
 * (DailyReferenceViewのdateRange)。「月次に戻る」で一覧にも戻れる(オブジェクト指向UI)。
 * 行ごとに独立させるため、呼び出し側で`key`に対象月のidを渡してリセットさせる想定。
 */
function MonthAttendanceReview({ userId, yearMonth }: { userId: string; yearMonth: string }) {
  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const dates = datesInMonth(yearMonth)
  const dateRange = { min: dates[0], max: dates[dates.length - 1] }

  return (
    <div className="mt-3 flex flex-col gap-6 rounded-md border border-border p-3">
      {selectedDate === null ? (
        <MonthlyReferenceView userId={userId} restrictToYearMonth={yearMonth} onSelectDate={setSelectedDate} />
      ) : (
        <DailyReferenceView
          userId={userId}
          initialDate={selectedDate}
          dateRange={dateRange}
          onBack={() => setSelectedDate(null)}
        />
      )}
    </div>
  )
}

/**
 * UC-A010: 管理者/人事による月次勤怠の締め処理。承認済み(approved)の月次のうち、
 * まだ締めていないものを一覧・絞り込みし、行ごとに対象社員の実際の勤務表
 * (月次・週次・日次・打刻ログ)を確認したうえで締め処理を行う。承認・差戻しは
 * 統合承認画面(/approvals)側の役割のため、ここでは扱わない。
 * admin・hr_staffロールのみアクセスできる。
 */
export function AttendanceMonthCloseoutPage() {
  const { user } = useAuth()
  const canClose = hasAnyRole(user?.roles, [ROLE.ADMIN, ROLE.HR_STAFF])

  const [yearMonth, setYearMonth] = useState('')
  const [filterUserId, setFilterUserId] = useState<string | undefined>(undefined)
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useMonthsToApprove({
    status: 'approved',
    yearMonth: yearMonth || undefined,
    userId: filterUserId,
    page,
  })
  const closeMonth = useCloseMonth()
  const [expandedMonthId, setExpandedMonthId] = useState<string | null>(null)

  if (!canClose) {
    return <Navigate to="/" replace />
  }

  function handleFilterChange(next: { yearMonth?: string; userId?: string }) {
    if (next.yearMonth !== undefined) setYearMonth(next.yearMonth)
    if (next.userId !== undefined) setFilterUserId(next.userId)
    setPage(1)
  }

  const months = data?.data ?? []

  return (
    <Card title="月次締め処理">
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <div className="w-48">
          <FormField label="年月" htmlFor="month-closeout-year-month">
            <YearMonthPicker
              id="month-closeout-year-month"
              value={yearMonth || undefined}
              onChange={(value) => handleFilterChange({ yearMonth: value ?? '' })}
            />
          </FormField>
        </div>
        <div className="max-w-sm flex-1">
          <FormField label="対象社員" htmlFor="month-closeout-user">
            <UserPicker
              id="month-closeout-user"
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

      {closeMonth.error && <ErrorMessage error={closeMonth.error} />}

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="承認済みの月次勤怠の取得に失敗しました。" />
      ) : months.length === 0 ? (
        <p className="text-sm text-muted-foreground">締め処理待ちの月次勤怠はありません。</p>
      ) : (
        <>
          <ul className="divide-y divide-border">
            {months.map((month) => {
              const { label, tone } = attendanceMonthStatusLabel(month.status)
              const employeeName = month.user?.name ?? `社員ID: ${month.user_id}`
              const isExpanded = expandedMonthId === month.id

              return (
                <li key={month.id} className="py-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
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

                    <Button isLoading={closeMonth.isPending} onClick={() => closeMonth.mutate(month.id)}>
                      締め処理
                    </Button>
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
