import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useClockIn, useClockOut, useEndBreak, useStartBreak, useTodayAttendance } from '../hooks/useAttendance'
import { isoToTimeLiteral } from '../utils/offsetDateTime'
import { attendanceDayStatusLabel } from '../utils/statusLabels'

/**
 * 勤務時刻はその勤務日自身のUTCオフセットで記録された値であり、ブラウザのローカル
 * タイムゾーンに変換せず記録された通りの時刻を表示する(docs/03-architecture.md 3.4)。
 */
function formatTime(value: string | null | undefined): string {
  const literal = isoToTimeLiteral(value)
  return literal || '--:--'
}

/**
 * UC-A001〜UC-A004: 出勤・休憩開始・休憩終了・退勤。
 */
export function TodayAttendancePage() {
  const { data: day, isLoading, error } = useTodayAttendance()
  const clockIn = useClockIn()
  const startBreak = useStartBreak()
  const endBreak = useEndBreak()
  const clockOut = useClockOut()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠情報の取得に失敗しました。" />
  if (!day) return null

  const actionError = clockIn.error ?? startBreak.error ?? endBreak.error ?? clockOut.error
  const { label, tone } = attendanceDayStatusLabel(day.status)

  return (
    <Card title="今日の勤怠" actions={<Badge tone={tone}>{label}</Badge>}>
      {actionError && <ErrorMessage error={actionError} />}

      <div className="flex flex-col gap-4">
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          {day.planned_start_at && (
            <>
              <dt className="text-muted-foreground">勤務予定</dt>
              <dd className="text-foreground">
                {formatTime(day.planned_start_at)} 〜 {formatTime(day.planned_end_at)}
              </dd>
            </>
          )}
          <dt className="text-muted-foreground">出勤</dt>
          <dd className="text-foreground">{formatTime(day.actual_start_at)}</dd>
          <dt className="text-muted-foreground">退勤</dt>
          <dd className="text-foreground">{formatTime(day.actual_end_at)}</dd>
        </dl>

        {day.breaks.length > 0 && (
          <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
            {day.breaks.map((b) => (
              <li key={b.id}>
                休憩 {formatTime(b.break_start_at)} 〜 {formatTime(b.break_end_at)}
              </li>
            ))}
          </ul>
        )}

        {day.calculation && (
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 border-t border-border pt-4 text-sm">
            <dt className="text-muted-foreground">実働</dt>
            <dd className="text-foreground">{day.calculation.actual_work_minutes}分</dd>
            <dt className="text-muted-foreground">残業(法定内)</dt>
            <dd className="text-foreground">{day.calculation.non_statutory_overtime_minutes}分</dd>
            <dt className="text-muted-foreground">残業(法定外)</dt>
            <dd className="text-foreground">{day.calculation.statutory_overtime_minutes}分</dd>
            <dt className="text-muted-foreground">深夜</dt>
            <dd className="text-foreground">{day.calculation.late_night_minutes}分</dd>
          </dl>
        )}

        <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4">
          {day.status === 'not_started' && (
            <Button onClick={() => clockIn.mutate()} isLoading={clockIn.isPending}>
              出勤
            </Button>
          )}
          {day.status === 'working' && (
            <>
              <Button variant="secondary" onClick={() => startBreak.mutate()} isLoading={startBreak.isPending}>
                休憩開始
              </Button>
              <Button onClick={() => clockOut.mutate()} isLoading={clockOut.isPending}>
                退勤
              </Button>
            </>
          )}
          {day.status === 'on_break' && (
            <Button onClick={() => endBreak.mutate()} isLoading={endBreak.isPending}>
              休憩終了
            </Button>
          )}
          {day.status === 'clocked_out' && <p className="text-sm text-muted-foreground">本日の勤怠は完了しています。</p>}
        </div>
      </div>
    </Card>
  )
}
