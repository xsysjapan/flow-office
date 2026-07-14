import { useEffect, useState, type ReactNode } from 'react'
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
import type { AttendanceDay, FlexSettlementSummary } from '../api/types'

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

const toneBorderClass: Record<BadgeTone, string> = {
  neutral: 'border-l-muted-foreground/40',
  info: 'border-l-info',
  success: 'border-l-success',
  warning: 'border-l-warning',
  danger: 'border-l-destructive',
}

/** 数値指標をカード内に横並びのタイルとして表示する(区切り線のみで枠を強調しすぎない)。 */
function StatTileGrid({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <dl className={cn('grid grid-cols-2 divide-y divide-x divide-border rounded-md border border-border', className)}>
      {children}
    </dl>
  )
}

function StatTile({ label, value, tone }: { label: string; value: ReactNode; tone?: 'danger' }) {
  return (
    <div className="flex flex-col gap-1 px-4 py-3">
      <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</dt>
      <dd className={cn('text-lg font-semibold tabular-nums', tone === 'danger' ? 'text-destructive' : 'text-foreground')}>
        {value}
      </dd>
    </div>
  )
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
function FlexSettlementSummaryCard({ summary }: { summary: FlexSettlementSummary }) {
  return (
    <Card title="今月の清算期間(フレックスタイム制)">
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {summary.settlement_period_start} 〜 {summary.settlement_period_end}
        </p>
        <StatTileGrid>
          <StatTile label="必要労働時間" value={`${summary.required_minutes}分`} />
          <StatTile label="実労働時間" value={`${summary.actual_minutes}分`} />
          <StatTile label="残り必要時間" value={`${summary.remaining_minutes}分`} />
          <StatTile label="勤務残日数" value={`${summary.remaining_working_days}日`} />
        </StatTileGrid>
        <p className="text-sm text-muted-foreground">1日あたり必要時間 {summary.per_day_required_minutes}分</p>
        {summary.core_time_violation_days > 0 && (
          <p className="text-sm text-destructive">コアタイム違反日数 <span>{summary.core_time_violation_days}日</span></p>
        )}
      </div>
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
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const { data: month } = useAttendanceMonth(currentYearMonth)
  const flexSummary = month?.flex_settlement_summary ?? null

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠情報の取得に失敗しました。" />
  if (!day) return null

  const actionError = clockIn.error ?? startBreak.error ?? endBreak.error ?? clockOut.error
  const { label, tone } = attendanceDayStatusLabel(day.status)
  const StatusIcon = statusIcons[day.status]

  return (
    <div className={cn('grid grid-cols-1 gap-6', flexSummary && 'lg:grid-cols-3')}>
      <div className={flexSummary ? 'lg:col-span-2' : undefined}>
        <Card title="今日の勤怠" actions={<Badge tone={tone}>{label}</Badge>}>
          {actionError && <ErrorMessage error={actionError} />}

          <div className="flex flex-col gap-5">
            <div
              className={cn(
                'flex flex-wrap items-center justify-between gap-4 rounded-md border-l-4 bg-muted px-4 py-3',
                toneBorderClass[tone],
              )}
            >
              <div className="flex items-center gap-2.5">
                <StatusIcon className={cn('size-5 shrink-0', toneTextClass[tone])} aria-hidden="true" />
                <p className="text-sm font-medium text-foreground">{statusDescription(day, now)}</p>
              </div>
              <div className="text-right leading-tight">
                <p className="text-3xl font-bold tabular-nums text-foreground">
                  {now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })}
                </p>
                <p className="text-xs text-muted-foreground">
                  {now.toLocaleDateString('ja-JP', { month: 'long', day: 'numeric', weekday: 'short' })}
                </p>
              </div>
            </div>

            <StatTileGrid className={day.planned_start_at ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}>
              {day.planned_start_at && (
                <StatTile label="勤務予定" value={`${formatTime(day.planned_start_at)} 〜 ${formatTime(day.planned_end_at)}`} />
              )}
              <StatTile label="出勤" value={formatTime(day.actual_start_at)} />
              <StatTile label="退勤" value={formatTime(day.actual_end_at)} />
            </StatTileGrid>

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
              <>
                <StatTileGrid className="sm:grid-cols-4">
                  <StatTile label="実働" value={`${day.calculation.actual_work_minutes}分`} />
                  <StatTile label="残業(法定内)" value={`${day.calculation.non_statutory_overtime_minutes}分`} />
                  <StatTile label="残業(法定外)" value={`${day.calculation.statutory_overtime_minutes}分`} />
                  <StatTile label="深夜" value={`${day.calculation.late_night_minutes}分`} />
                </StatTileGrid>
                {day.calculation.core_time_violation && (
                  <p className="text-sm text-destructive">コアタイム違反(勤務がコアタイムを全てカバーしていません)</p>
                )}
              </>
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

      {flexSummary && (
        <div className="lg:col-span-1">
          <FlexSettlementSummaryCard summary={flexSummary} />
        </div>
      )}
    </div>
  )
}
