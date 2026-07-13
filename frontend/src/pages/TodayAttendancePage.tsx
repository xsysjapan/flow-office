import { useEffect, useState } from 'react'
import { CheckCircle2, Clock, Coffee, LogIn, type LucideIcon } from 'lucide-react'
import { Badge, type BadgeTone } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useAttendanceMonth, useClockIn, useClockOut, useEndBreak, useStartBreak, useTodayAttendance } from '../hooks/useAttendance'
import { cn } from '../lib/utils'
import { formatDate } from '../utils/weekDates'
import { isoToTimeLiteral } from '../utils/offsetDateTime'
import { attendanceDayStatusLabel } from '../utils/statusLabels'
import type { AttendanceDay } from '../api/types'

/** 1秒ごとに現在時刻を更新し、画面上部のライブクロックと経過時間計算に使う。 */
function useNow(intervalMs = 1000): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), intervalMs)
    return () => clearInterval(id)
  }, [intervalMs])
  return now
}

const statusIcons: Record<AttendanceDay['status'], LucideIcon> = {
  not_started: Clock,
  working: LogIn,
  on_break: Coffee,
  clocked_out: CheckCircle2,
}

const toneTextClass: Record<BadgeTone, string> = {
  neutral: 'text-muted-foreground',
  info: 'text-info',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-destructive',
}

function completedBreakMinutes(breaks: AttendanceDay['breaks']): number {
  return breaks.reduce((sum, b) => {
    if (!b.break_start_at || !b.break_end_at) return sum
    return sum + (new Date(b.break_end_at).getTime() - new Date(b.break_start_at).getTime()) / 60000
  }, 0)
}

/** 出勤時刻から現在までの経過時間から、完了済みの休憩時間を差し引いた実働時間(分)。 */
function elapsedWorkedMinutes(day: AttendanceDay, now: Date): number | null {
  if (!day.actual_start_at) return null
  const grossMinutes = (now.getTime() - new Date(day.actual_start_at).getTime()) / 60000
  return Math.max(0, Math.round(grossMinutes - completedBreakMinutes(day.breaks)))
}

function formatMinutes(totalMinutes: number): string {
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  return hours > 0 ? `${hours}時間${minutes}分` : `${minutes}分`
}

function statusDescription(day: AttendanceDay, now: Date): string {
  switch (day.status) {
    case 'not_started':
      return 'まだ出勤していません'
    case 'working': {
      const minutes = elapsedWorkedMinutes(day, now)
      return `${formatTime(day.actual_start_at)}から勤務中${minutes !== null ? `(実働 ${formatMinutes(minutes)})` : ''}`
    }
    case 'on_break':
      return `${formatTime(day.actual_start_at)}から勤務中・現在休憩中です`
    case 'clocked_out':
      return `${formatTime(day.actual_start_at)}〜${formatTime(day.actual_end_at)}で退勤済みです`
  }
}

/**
 * 勤務時刻はその勤務日自身のUTCオフセットで記録された値であり、ブラウザのローカル
 * タイムゾーンに変換せず記録された通りの時刻を表示する(docs/03-architecture.md 3.4)。
 */
function formatTime(value: string | null | undefined): string {
  const literal = isoToTimeLiteral(value)
  return literal || '--:--'
}

/**
 * 指示書 7.6節: フレックスタイム制の社員のホーム画面では、固定勤務と異なる情報
 * (清算期間の必要労働時間・残り労働時間・残り勤務日数)を優先表示する。
 * フレックス以外の働き方の社員には何も表示しない(flex_settlement_summaryがnull)。
 */
function FlexSettlementSummaryCard() {
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const { data } = useAttendanceMonth(currentYearMonth)
  const summary = data?.flex_settlement_summary

  if (!summary) return null

  return (
    <Card title="今月の清算期間(フレックスタイム制)">
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="text-muted-foreground">清算期間</dt>
        <dd className="text-foreground">
          {summary.settlement_period_start} 〜 {summary.settlement_period_end}
        </dd>
        <dt className="text-muted-foreground">必要労働時間</dt>
        <dd className="text-foreground">{summary.required_minutes}分</dd>
        <dt className="text-muted-foreground">現在の実労働時間</dt>
        <dd className="text-foreground">{summary.actual_minutes}分</dd>
        <dt className="text-muted-foreground">残り必要時間</dt>
        <dd className="text-foreground">{summary.remaining_minutes}分</dd>
        <dt className="text-muted-foreground">勤務残日数</dt>
        <dd className="text-foreground">{summary.remaining_working_days}日</dd>
        <dt className="text-muted-foreground">1日あたり必要時間</dt>
        <dd className="text-foreground">{summary.per_day_required_minutes}分</dd>
        {summary.core_time_violation_days > 0 && (
          <>
            <dt className="text-muted-foreground">コアタイム違反日数</dt>
            <dd className="text-destructive">{summary.core_time_violation_days}日</dd>
          </>
        )}
      </dl>
    </Card>
  )
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
  const now = useNow()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠情報の取得に失敗しました。" />
  if (!day) return null

  const actionError = clockIn.error ?? startBreak.error ?? endBreak.error ?? clockOut.error
  const { label, tone } = attendanceDayStatusLabel(day.status)
  const StatusIcon = statusIcons[day.status]

  return (
    <div className="flex flex-col gap-6">
      <FlexSettlementSummaryCard />
      <Card title="今日の勤怠" actions={<Badge tone={tone}>{label}</Badge>}>
        {actionError && <ErrorMessage error={actionError} />}

        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
            <div className="flex items-center gap-2">
              <StatusIcon className={cn('size-5 shrink-0', toneTextClass[tone])} aria-hidden="true" />
              <p className="text-sm text-foreground">{statusDescription(day, now)}</p>
            </div>
            <div className="text-right leading-tight">
              <p className="text-2xl font-semibold text-foreground tabular-nums">
                {now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })}
              </p>
              <p className="text-xs text-muted-foreground">
                {now.toLocaleDateString('ja-JP', { month: 'long', day: 'numeric', weekday: 'short' })}
              </p>
            </div>
          </div>

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
              {day.calculation.core_time_violation && (
                <>
                  <dt className="text-muted-foreground">コアタイム</dt>
                  <dd className="text-destructive">違反(勤務がコアタイムを全てカバーしていません)</dd>
                </>
              )}
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
    </div>
  )
}
