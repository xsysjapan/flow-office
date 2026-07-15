import { useState } from 'react'
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { AttendanceCalculationSummary } from '../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { AttendanceDayRow } from '../components/AttendanceDayRow/AttendanceDayRow'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { useAttendanceMonth, useSubmitMonth } from '../hooks/useAttendance'
import { dayWarnings } from '../utils/attendanceDayWarnings'
import { employmentYearMonths } from '../utils/employmentPeriod'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'
import { datesInMonth, formatDate } from '../utils/weekDates'

/**
 * 在籍期間内の全月を前後移動の対象にする。月別の働き方割当や実績の有無は、
 * 月次勤怠の閲覧可否に影響しない。
 */
function useNavigableYearMonths(yearMonth: string) {
  const { user } = useAuth()
  const currentYearMonth = formatDate(new Date()).slice(0, 7)

  const navigable = employmentYearMonths(user?.hire_date, user?.termination_date, currentYearMonth)

  const prevMonth = [...navigable].reverse().find((ym) => ym < yearMonth)
  const nextMonth = navigable.find((ym) => ym > yearMonth)

  return { prevMonth, nextMonth }
}

function MonthNav({ yearMonth }: { yearMonth: string }) {
  const navigate = useNavigate()
  const { prevMonth, nextMonth } = useNavigableYearMonths(yearMonth)
  const currentYearMonth = formatDate(new Date()).slice(0, 7)

  return (
    <div className="flex gap-2">
      <Button variant="secondary" size="icon" title="前月" aria-label="前月" disabled={!prevMonth} onClick={() => prevMonth && navigate(`/attendance/months/${prevMonth}`)}>
        <ChevronLeft aria-hidden="true" />
      </Button>
      {yearMonth === currentYearMonth ? (
        <Button variant="secondary" disabled>
          今月
        </Button>
      ) : (
        <Button asChild variant="secondary">
          <Link to={`/attendance/months/${currentYearMonth}`}>今月</Link>
        </Button>
      )}
      <Button asChild variant="secondary" title="月次一覧へ戻る">
        <Link to="/attendance/months">
          <CalendarRange aria-hidden="true" />
          一覧
        </Link>
      </Button>
      <Button variant="secondary" size="icon" title="次月" aria-label="次月" disabled={!nextMonth} onClick={() => nextMonth && navigate(`/attendance/months/${nextMonth}`)}>
        <ChevronRight aria-hidden="true" />
      </Button>
    </div>
  )
}

/**
 * UC-A007: 月次勤怠を確認する。日別の内訳を一覧表示し、問題がある日は行を選んで
 * 日次画面(実績の作成・編集・打刻履歴)に遷移できる(オブジェクト指向UI)。
 * 前月・次月への移動は在籍期間内の全月で行える。
 */
export function AttendanceMonthDetailPage() {
  const { yearMonth } = useParams<{ yearMonth: string }>()
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)
  const { data, isLoading, error } = useAttendanceMonth(yearMonth ?? '')
  const submitMonth = useSubmitMonth(yearMonth ?? '')

  if (!yearMonth) return null

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const canSubmit = month?.status === 'not_submitted' || month?.status === 'returned'
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const dates = datesInMonth(yearMonth)
  const today = formatDate(new Date())

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="月次勤怠"
        actions={monthMeta && <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge>}
        navigation={<MonthNav yearMonth={yearMonth} />}
      >
        <p className="mb-3 text-sm text-muted-foreground">{yearMonth}</p>

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="勤怠月次の取得に失敗しました。" />
        ) : (
          <>
        {month && month.legal_holiday_warnings.length > 0 && (
          <div className="mb-3 flex flex-wrap gap-2">
            {month.legal_holiday_warnings.map((warning) => (
              <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                {legalHolidayWarningLabel(warning)}
              </Badge>
            ))}
          </div>
        )}

        {submitMonth.error && <ErrorMessage error={submitMonth.error} />}

        {canSubmit && (
          <div className="flex items-center gap-2">
            <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
            <Button
              isLoading={submitMonth.isPending}
              disabled={!approverUserId}
              onClick={() => submitMonth.mutate(approverUserId as number)}
            >
              提出する
            </Button>
          </div>
        )}

        {data?.monthly_calculation_totals && (
          <div className="mt-4 border-t border-border pt-4">
            <AttendanceCalculationSummary
              title="今月の集計"
              totals={data.monthly_calculation_totals}
              statutoryExcessOver60hMinutes={data.monthly_calculation_totals.statutory_excess_overtime_over_60h_minutes}
              absenceDays={data.monthly_calculation_totals.absence_days ?? 0}
              specialLeaveDays={data.monthly_calculation_totals.special_leave_days ?? 0}
              showAllLeaveTotals
            />
          </div>
        )}
          </>
        )}
      </Card>

      {!isLoading && !error && (
        <Card title="日別の内訳">
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <AttendanceDayRow
                key={date}
                date={date}
                day={daysByDate.get(date)}
                warnings={dayWarnings(date, daysByDate.get(date), today)}
              />
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
