import { useState } from 'react'
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { AttendanceDayRow } from '../components/AttendanceDayRow/AttendanceDayRow'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useWeek } from '../hooks/useAttendance'
import { dayWarnings } from '../utils/attendanceDayWarnings'
import { addDays, formatDate, mondayOf, weekDates } from '../utils/weekDates'

/**
 * UC-A006: 週次勤怠を編集する。日次勤怠(attendance_days)の編集ビューであり、独立データ
 * としては持たない。各日を選ぶと日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI)。日次画面から「週次」で戻ってきた場合、その日が属する週を
 * `?start=`(週初め)で指定できる(未指定なら今週)。
 */
export function WeekAttendancePage() {
  const [searchParams] = useSearchParams()
  const startParam = searchParams.get('start')
  const [weekStart, setWeekStart] = useState(() =>
    formatDate(mondayOf(startParam ? new Date(`${startParam}T00:00:00`) : new Date())),
  )
  const { data, isLoading, error } = useWeek(weekStart)

  const today = formatDate(new Date())
  const currentWeekStart = formatDate(mondayOf(new Date()))
  const dates = weekDates(weekStart)
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))

  return (
    <Card
      title="週次勤怠"
      actions={
        <div className="flex gap-2">
          <Button variant="secondary" size="icon" title="前週" aria-label="前週" onClick={() => setWeekStart((prev) => addDays(prev, -7))}>
            <ChevronLeft aria-hidden="true" />
          </Button>
          <Button variant="secondary" disabled={weekStart === currentWeekStart} onClick={() => setWeekStart(currentWeekStart)}>
            今週
          </Button>
          <Button asChild variant="secondary" title="月次で見る">
            <Link to={`/attendance/months/${weekStart.slice(0, 7)}`}>
              <CalendarRange aria-hidden="true" />
              月次
            </Link>
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
      )}
    </Card>
  )
}
