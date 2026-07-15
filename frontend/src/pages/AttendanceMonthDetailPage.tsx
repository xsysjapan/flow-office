import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { AttendanceDayRow } from '../components/AttendanceDayRow/AttendanceDayRow'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { NativeSelect } from '../components/ui/native-select'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { useAttendanceMonth, useSubmitMonth } from '../hooks/useAttendance'
import { useUserWorkStyleMonthlyAssignments } from '../hooks/useUserWorkStyleMonthlyAssignments'
import { dayWarnings } from '../utils/attendanceDayWarnings'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'
import { datesInMonth, formatDate } from '../utils/weekDates'

/**
 * 在籍期間(hire_date以降)かつ働き方のマスタ設定(user_work_style_monthly_assignments)が
 * ある月だけに遷移できるよう、選択・前後移動の対象を絞り込む。
 */
function useNavigableYearMonths(yearMonth: string) {
  const { user } = useAuth()
  const { data: assignments } = useUserWorkStyleMonthlyAssignments(user?.id)
  const hireYearMonth = user?.hire_date?.slice(0, 7)

  const navigable = (assignments ?? [])
    .map((assignment) => assignment.year_month)
    .filter((ym) => !hireYearMonth || ym >= hireYearMonth)
    .sort()

  const selectable = navigable.includes(yearMonth) ? navigable : [...navigable, yearMonth].sort()
  const prevMonth = [...navigable].reverse().find((ym) => ym < yearMonth)
  const nextMonth = navigable.find((ym) => ym > yearMonth)

  return { selectable, prevMonth, nextMonth }
}

function MonthNav({ yearMonth }: { yearMonth: string }) {
  const navigate = useNavigate()
  const { selectable, prevMonth, nextMonth } = useNavigableYearMonths(yearMonth)

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button variant="secondary" disabled={!prevMonth} onClick={() => prevMonth && navigate(`/attendance/months/${prevMonth}`)}>
        前月
      </Button>
      <NativeSelect
        aria-label="表示する月"
        className="w-auto"
        value={yearMonth}
        onChange={(e) => navigate(`/attendance/months/${e.target.value}`)}
      >
        {[...selectable].reverse().map((ym) => (
          <option key={ym} value={ym}>
            {ym}
          </option>
        ))}
      </NativeSelect>
      <Button variant="secondary" disabled={!nextMonth} onClick={() => nextMonth && navigate(`/attendance/months/${nextMonth}`)}>
        次月
      </Button>
    </div>
  )
}

/**
 * UC-A007: 月次勤怠を確認する。日別の内訳を一覧表示し、問題がある日は行を選んで
 * 日次画面(実績の作成・編集・打刻履歴)に遷移できる(オブジェクト指向UI)。
 * 前月・次月への移動、特定の月への直接ジャンプは、在籍期間かつ働き方のマスタ設定が
 * ある月だけに制限する。
 */
export function AttendanceMonthDetailPage() {
  const { yearMonth } = useParams<{ yearMonth: string }>()
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)
  const { data, isLoading, error } = useAttendanceMonth(yearMonth ?? '')
  const submitMonth = useSubmitMonth(yearMonth ?? '')

  if (!yearMonth) return null
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠月次の取得に失敗しました。" />

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const canSubmit = month?.status === 'not_submitted' || month?.status === 'returned'
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const dates = datesInMonth(yearMonth)
  const today = formatDate(new Date())

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={`${yearMonth}の勤怠月次`}
        actions={
          <div className="flex flex-wrap items-center gap-3">
            <MonthNav yearMonth={yearMonth} />
            {monthMeta && <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge>}
          </div>
        }
      >
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
      </Card>

      {data?.monthly_calculation_totals && (
        <Card title="今月の集計">
          <dl className="grid grid-cols-[auto_1fr_auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="font-medium text-muted-foreground">所定労働時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.prescribed_work_minutes}分</dd>
            <dt className="font-medium text-muted-foreground">法定内残業時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.statutory_within_overtime_minutes}分</dd>

            <dt className="font-medium text-muted-foreground">法定外残業時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.statutory_excess_overtime_minutes}分</dd>
            <dt className="font-medium text-muted-foreground">うち月60時間超</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.statutory_excess_overtime_over_60h_minutes}分</dd>

            <dt className="font-medium text-muted-foreground">法定休日労働時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.legal_holiday_work_minutes}分</dd>
            <dt className="font-medium text-muted-foreground">深夜所定労働時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.late_night_prescribed_work_minutes}分</dd>

            <dt className="font-medium text-muted-foreground">深夜法定内残業時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.late_night_statutory_within_overtime_minutes}分</dd>
            <dt className="font-medium text-muted-foreground">深夜法定外残業時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.late_night_statutory_excess_overtime_minutes}分</dd>

            <dt className="font-medium text-muted-foreground">深夜法定休日労働時間</dt>
            <dd className="text-foreground">{data.monthly_calculation_totals.late_night_legal_holiday_work_minutes}分</dd>
          </dl>
        </Card>
      )}

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
    </div>
  )
}
