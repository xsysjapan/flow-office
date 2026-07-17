import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { AttendanceCalculationSummary } from '../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { Duration } from '../components/Duration/Duration'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import type { AttendanceDay } from '../api/types'
import { useAttendanceMonth, useWeek } from '../hooks/useAttendance'
import { dayWarnings } from '../utils/attendanceDayWarnings'
import { weeklyAttendanceTotals } from '../utils/attendanceWeeklyTotals'
import { isoToTimeLiteral } from '../utils/offsetDateTime'
import {
  attendanceDayStatusLabel,
  attendanceLeaveSegmentCategoryLabel,
  attendanceMonthStatusLabel,
  legalHolidayWarningLabel,
} from '../utils/statusLabels'
import { addDays, addMonths, datesInMonth, formatDate, mondayOf, weekDates } from '../utils/weekDates'

type ViewMode = 'month' | 'week' | 'day'

const VIEW_MODES: Array<{ key: ViewMode; label: string }> = [
  { key: 'month', label: '月次' },
  { key: 'week', label: '週次' },
  { key: 'day', label: '日次' },
]

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function weekdayLabel(date: string): string {
  const dow = new Date(`${date}T00:00:00`).getDay()
  return WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
}

/** 月次・週次一覧の1日分。管理者が参照するだけの画面のため、自分の日次編集画面への
 *  リンクにはしない。 */
function ReadOnlyDayRow({ date, day }: { date: string; day: AttendanceDay | undefined }) {
  const { label, tone } = day ? attendanceDayStatusLabel(day.status) : { label: '未入力', tone: 'neutral' as const }
  const warnings = dayWarnings(date, day, formatDate(new Date()))

  return (
    <li className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 px-2 py-3 sm:flex sm:items-center sm:gap-2.5">
      <div className="flex min-w-0 items-center gap-2 sm:contents">
        <span className="whitespace-nowrap text-sm font-semibold text-foreground">
          {date}({weekdayLabel(date)})
        </span>
        <Badge tone={tone}>{label}</Badge>
      </div>
      <div className="col-start-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground sm:contents">
        {day && (day.actual_start_at || day.actual_end_at) && (
          <span className="whitespace-nowrap text-sm">
            {isoToTimeLiteral(day.actual_start_at) || '--:--'} 〜 {isoToTimeLiteral(day.actual_end_at) || '--:--'}
          </span>
        )}
        {day?.calculation && (
          <span className="whitespace-nowrap text-sm">
            労働時間 <Duration minutes={day.calculation.work_minutes} />
          </span>
        )}
        {warnings.map((warning) => (
          <Badge key={warning} tone="warning">
            {warning}
          </Badge>
        ))}
      </div>
    </li>
  )
}

function MonthlyReferenceView({ userId }: { userId: number }) {
  const [yearMonth, setYearMonth] = useState(() => formatDate(new Date()).slice(0, 7))
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const { data, isLoading, error } = useAttendanceMonth(yearMonth, userId)

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const dates = datesInMonth(yearMonth)

  return (
    <>
      <Card
        title="月次勤怠"
        actions={monthMeta && <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge>}
        navigation={
          <div className="flex gap-2">
            <Button variant="secondary" size="icon" title="前月" aria-label="前月" onClick={() => setYearMonth((ym) => addMonths(ym, -1))}>
              <ChevronLeft aria-hidden="true" />
            </Button>
            <Button variant="secondary" disabled={yearMonth === currentYearMonth} onClick={() => setYearMonth(currentYearMonth)}>
              今月
            </Button>
            <Button variant="secondary" size="icon" title="次月" aria-label="次月" onClick={() => setYearMonth((ym) => addMonths(ym, 1))}>
              <ChevronRight aria-hidden="true" />
            </Button>
          </div>
        }
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

            {data?.monthly_calculation_totals && (
              <div className="border-t border-border pt-4">
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
              <ReadOnlyDayRow key={date} date={date} day={daysByDate.get(date)} />
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}

function WeeklyReferenceView({ userId }: { userId: number }) {
  const [weekStart, setWeekStart] = useState(() => formatDate(mondayOf(new Date())))
  const currentWeekStart = formatDate(mondayOf(new Date()))
  const { data, isLoading, error } = useWeek(weekStart, userId)

  const dates = weekDates(weekStart)
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))
  const { totals, absenceDays, specialLeaveDays } = weeklyAttendanceTotals(data ?? [])

  return (
    <>
      <Card
        title="週次勤怠"
        navigation={
          <div className="flex gap-2">
            <Button variant="secondary" size="icon" title="前週" aria-label="前週" onClick={() => setWeekStart((prev) => addDays(prev, -7))}>
              <ChevronLeft aria-hidden="true" />
            </Button>
            <Button variant="secondary" disabled={weekStart === currentWeekStart} onClick={() => setWeekStart(currentWeekStart)}>
              今週
            </Button>
            <Button variant="secondary" size="icon" title="次週" aria-label="次週" onClick={() => setWeekStart((prev) => addDays(prev, 7))}>
              <ChevronRight aria-hidden="true" />
            </Button>
          </div>
        }
      >
        <p className="mb-3 text-sm text-muted-foreground">
          {dates[0]} 〜 {dates[6]}
        </p>

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="週次勤怠の取得に失敗しました。" />
        ) : (
          <div className="border-t border-border pt-4">
            <AttendanceCalculationSummary
              title="今週の集計"
              totals={totals}
              absenceDays={absenceDays}
              specialLeaveDays={specialLeaveDays}
              showAllLeaveTotals
            />
          </div>
        )}
      </Card>

      {!isLoading && !error && (
        <Card title="日別の内訳">
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <ReadOnlyDayRow key={date} date={date} day={daysByDate.get(date)} />
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}

function DailyReferenceView({ userId }: { userId: number }) {
  const [date, setDate] = useState(() => formatDate(new Date()))
  const today = formatDate(new Date())
  const monday = formatDate(mondayOf(new Date(`${date}T00:00:00`)))
  const { data, isLoading, error } = useWeek(monday, userId)
  const day = data?.find((d) => d.work_date === date)
  const statusMeta = day ? attendanceDayStatusLabel(day.status) : null

  return (
    <Card
      title="日次勤怠"
      actions={statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
      navigation={
        <div className="flex gap-2">
          <Button variant="secondary" size="icon" title="前日" aria-label="前日" onClick={() => setDate((prev) => addDays(prev, -1))}>
            <ChevronLeft aria-hidden="true" />
          </Button>
          <Button variant="secondary" disabled={date === today} onClick={() => setDate(today)}>
            今日
          </Button>
          <Button variant="secondary" size="icon" title="翌日" aria-label="翌日" onClick={() => setDate((prev) => addDays(prev, 1))}>
            <ChevronRight aria-hidden="true" />
          </Button>
        </div>
      }
    >
      <p className="mb-3 text-sm text-muted-foreground">
        {date}({weekdayLabel(date)})
      </p>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="日次勤怠の取得に失敗しました。" />
      ) : !day ? (
        <p className="text-sm text-muted-foreground">この日の勤怠記録はありません。</p>
      ) : (
        <div className="flex flex-col gap-4 border-t border-border pt-4">
          <dl className="grid grid-cols-[auto_1fr_auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            {day.planned_start_at && (
              <>
                <dt className="font-medium text-muted-foreground">勤務予定</dt>
                <dd className="text-foreground">
                  {isoToTimeLiteral(day.planned_start_at) || '--:--'} 〜 {isoToTimeLiteral(day.planned_end_at) || '--:--'}
                </dd>
              </>
            )}
            <dt className="font-medium text-muted-foreground">出勤</dt>
            <dd className="text-foreground">{isoToTimeLiteral(day.actual_start_at) || '--:--'}</dd>
            <dt className="font-medium text-muted-foreground">退勤</dt>
            <dd className="text-foreground">{isoToTimeLiteral(day.actual_end_at) || '--:--'}</dd>
          </dl>

          {day.breaks.length > 0 && (
            <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
              {day.breaks.map((b) => (
                <li key={b.id}>
                  休憩 {isoToTimeLiteral(b.break_start_at) || '--:--'} 〜 {isoToTimeLiteral(b.break_end_at) || '--:--'}
                </li>
              ))}
            </ul>
          )}

          {!!day.leave_segments?.length && (
            <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
              {day.leave_segments.map((segment) => (
                <li key={segment.id}>
                  {attendanceLeaveSegmentCategoryLabel(segment.category)} {isoToTimeLiteral(segment.start_at) || '--:--'} 〜{' '}
                  {isoToTimeLiteral(segment.end_at) || '--:--'}
                  {segment.note && ` (${segment.note})`}
                </li>
              ))}
            </ul>
          )}

          {(day.work_type || day.note) && (
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
              {day.work_type && (
                <>
                  <dt className="font-medium text-muted-foreground">作業内容</dt>
                  <dd className="text-foreground">{day.work_type}</dd>
                </>
              )}
              {day.note && (
                <>
                  <dt className="font-medium text-muted-foreground">備考</dt>
                  <dd className="text-foreground">{day.note}</dd>
                </>
              )}
            </dl>
          )}

          {day.calculation && (
            <AttendanceCalculationSummary
              title="この日の集計"
              totals={day.calculation}
              absenceDays={day.calculation.absence_minutes ? 1 : undefined}
              specialLeaveDays={day.calculation.special_leave_minutes ? 1 : undefined}
            />
          )}
        </div>
      )}
    </Card>
  )
}

/**
 * 管理者が自分以外の社員の勤怠を月次・週次・日次で参照する画面(閲覧専用。編集は行わない)。
 * 対象社員は`UserPicker`で選び、選択後は月次・週次・日次を切り替えて確認できる。
 */
export function AttendanceReferencePage() {
  const [userId, setUserId] = useState<number | undefined>(undefined)
  const [viewMode, setViewMode] = useState<ViewMode>('month')

  return (
    <div className="flex flex-col gap-6">
      <Card title="勤怠参照">
        <div className="flex flex-wrap items-end gap-4">
          <div className="max-w-sm flex-1">
            <FormField label="対象社員" htmlFor="attendance-reference-user">
              <UserPicker id="attendance-reference-user" value={userId} onChange={setUserId} />
            </FormField>
          </div>
          <div className="mb-4 flex gap-2">
            {VIEW_MODES.map((mode) => (
              <Button
                key={mode.key}
                type="button"
                variant={viewMode === mode.key ? 'primary' : 'secondary'}
                onClick={() => setViewMode(mode.key)}
              >
                {mode.label}
              </Button>
            ))}
          </div>
        </div>
      </Card>

      {userId !== undefined && viewMode === 'month' && <MonthlyReferenceView userId={userId} />}
      {userId !== undefined && viewMode === 'week' && <WeeklyReferenceView userId={userId} />}
      {userId !== undefined && viewMode === 'day' && <DailyReferenceView userId={userId} />}
    </div>
  )
}
