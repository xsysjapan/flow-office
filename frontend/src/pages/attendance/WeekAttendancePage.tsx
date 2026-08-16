import { useState } from 'react'
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttendanceCalculationSummary } from '../../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { AttendanceDayRow } from '../../components/AttendanceDayRow/AttendanceDayRow'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { WeeklyAttendanceBulkEntryModal } from '../../components/WeeklyAttendanceBulkEntryModal/WeeklyAttendanceBulkEntryModal'
import { useWeek } from '../../hooks/useAttendance'
import { useShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { dayWarnings } from '../../utils/attendanceDayWarnings'
import { weeklyAttendanceTotals } from '../../utils/attendanceWeeklyTotals'
import { addDays, formatDate, mondayOf, weekDates } from '../../utils/weekDates'

/**
 * UC-A006: 週次勤怠を編集する。日次勤怠(attendance_days)の編集ビューであり、独立データ
 * としては持たない。各日を選ぶと日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI)。日次画面から「週次」で戻ってきた場合、その日が属する週を
 * `?start=`(週初め)で指定できる(未指定なら今週)。
 *
 * 「選択」ボタンでiOS Mailライクな選択モードに入ると、各行がチェックボックス表示になり、
 * 複数日を選んで有給休暇・特別休暇・代休の申請へまとめて遷移できる(選択した日は
 * `?dates=`にカンマ区切りで渡し、各申請画面側でプレフィルする)。
 */
export function WeekAttendancePage() {
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const startParam = searchParams.get('start')
  const [weekStart, setWeekStart] = useState(() =>
    formatDate(mondayOf(startParam ? new Date(`${startParam}T00:00:00`) : new Date())),
  )
  const [bulkEntryMessage, setBulkEntryMessage] = useState<string | null>(null)
  const [selectionMode, setSelectionMode] = useState(false)
  const [selectedDates, setSelectedDates] = useState<Set<string>>(new Set())
  const { data, isLoading, error } = useWeek(weekStart)

  const today = formatDate(new Date())
  const currentWeekStart = formatDate(mondayOf(new Date()))
  const dates = weekDates(weekStart)
  const { data: schedule } = useShiftAssignments(user?.id ?? '', dates[0], dates[6])
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))
  const scheduleByDate = new Map((schedule ?? []).map((entry) => [entry.work_date, entry]))
  const { totals: weeklyTotals, absenceDays, specialLeaveBreakdown } = weeklyAttendanceTotals(data ?? [])

  function toggleDate(date: string) {
    setSelectedDates((prev) => {
      const next = new Set(prev)
      if (next.has(date)) next.delete(date)
      else next.add(date)
      return next
    })
  }

  function exitSelectionMode() {
    setSelectionMode(false)
    setSelectedDates(new Set())
  }

  const sortedSelectedDates = Array.from(selectedDates).sort()
  const datesQuery = sortedSelectedDates.join(',')

  return (
    <div className="flex flex-col gap-6">
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
          <div className="border-t border-border pt-4">
            <AttendanceCalculationSummary
              title="今週の集計"
              totals={weeklyTotals}
              absenceDays={absenceDays}
              specialLeaveBreakdown={specialLeaveBreakdown}
              showAllLeaveTotals
            />
          </div>
        )}
      </Card>

      {!isLoading && !error && (
        <Card
          title="日別の内訳"
          actions={
            selectionMode ? (
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm whitespace-nowrap text-muted-foreground">
                  {selectedDates.size}件を選択中
                </span>
                {selectedDates.size > 0 ? (
                  <>
                    <Button asChild variant="secondary" size="sm">
                      <Link to={`/paid-leave?dates=${datesQuery}`}>有給休暇を申請する</Link>
                    </Button>
                    <Button asChild variant="secondary" size="sm">
                      <Link to={`/special-leave?dates=${datesQuery}`}>特別休暇を申請する</Link>
                    </Button>
                    <Button asChild variant="secondary" size="sm">
                      <Link to={`/compensatory-leave?dates=${datesQuery}`}>代休を申請する</Link>
                    </Button>
                  </>
                ) : (
                  <>
                    <Button variant="secondary" size="sm" disabled>
                      有給休暇を申請する
                    </Button>
                    <Button variant="secondary" size="sm" disabled>
                      特別休暇を申請する
                    </Button>
                    <Button variant="secondary" size="sm" disabled>
                      代休を申請する
                    </Button>
                  </>
                )}
                <Button variant="secondary" size="sm" onClick={exitSelectionMode}>
                  キャンセル
                </Button>
              </div>
            ) : (
              <div className="flex items-center gap-3">
                {bulkEntryMessage && <Badge tone="success">{bulkEntryMessage}</Badge>}
                <Button variant="secondary" onClick={() => setSelectionMode(true)}>
                  選択
                </Button>
                <WeeklyAttendanceBulkEntryModal
                  defaultFrom={dates[0]}
                  defaultTo={dates[6]}
                  onCompleted={setBulkEntryMessage}
                />
              </div>
            )
          }
        >
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <AttendanceDayRow
                key={date}
                date={date}
                day={daysByDate.get(date)}
                schedule={scheduleByDate.get(date)}
                warnings={dayWarnings(date, daysByDate.get(date), today)}
                selectionMode={selectionMode}
                selected={selectedDates.has(date)}
                onToggleSelected={toggleDate}
              />
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
