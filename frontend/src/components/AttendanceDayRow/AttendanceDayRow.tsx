import { ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { AttendanceDay } from '../../api/types'
import { isoToTimeLiteral } from '../../utils/offsetDateTime'
import { attendanceDayStatusLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Duration } from '../Duration/Duration'

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function weekdayLabel(date: string): string {
  const dow = new Date(`${date}T00:00:00`).getDay()
  return WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
}

export interface AttendanceDayRowProps {
  date: string
  day: AttendanceDay | undefined
  warnings?: string[]
}

/**
 * 週次・月次画面で使う、1日分の勤怠を要約した行。行全体がリンクになっており、
 * クリックすると該当日の日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI: 日という対象を選んでから操作する)。
 */
export function AttendanceDayRow({ date, day, warnings = [] }: AttendanceDayRowProps) {
  const { label, tone } = day ? attendanceDayStatusLabel(day.status) : { label: '未入力', tone: 'neutral' as const }

  return (
    <li>
      <Link
        to={`/attendance/days/${date}`}
        className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 rounded-md px-2 py-3 transition-colors hover:bg-accent sm:flex sm:items-center sm:gap-2.5"
      >
        <div className="flex min-w-0 items-center gap-2 sm:contents">
          <span className="whitespace-nowrap text-sm font-semibold text-foreground">
            {date}({weekdayLabel(date)})
          </span>
          <Badge tone={tone}>{label}</Badge>
        </div>
        <div className="col-start-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground sm:contents">
          {day && (day.actual_start_at || day.actual_end_at) && (
            <span className="whitespace-nowrap text-sm sm:text-sm">
              {isoToTimeLiteral(day.actual_start_at) || '--:--'} 〜 {isoToTimeLiteral(day.actual_end_at) || '--:--'}
            </span>
          )}
          {day?.calculation && (
            <span className="whitespace-nowrap text-sm sm:text-sm">労働時間 <Duration minutes={day.calculation.work_minutes} /></span>
          )}
          {warnings.map((warning) => (
            <Badge key={warning} tone="warning">
              {warning}
            </Badge>
          ))}
        </div>
        <ChevronRight className="col-start-2 row-span-2 size-4 self-center text-muted-foreground sm:order-last sm:ml-auto" aria-hidden="true" />
      </Link>
    </li>
  )
}
