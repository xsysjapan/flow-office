import { ChevronRight } from 'lucide-react'
import type { KeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import type { AttendanceDay, EmployeeShiftAssignment } from '../../api/types'
import { isoToTimeLiteral } from '../../utils/offsetDateTime'
import { attendanceRowDisplayLabel, attendanceScheduleHolidayLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Duration } from '../Duration/Duration'
import { Checkbox } from '../ui/checkbox'
import { cn } from '../../lib/utils'

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function weekdayLabel(date: string): string {
  const dow = new Date(`${date}T00:00:00`).getDay()
  return WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
}

export interface AttendanceDayRowProps {
  date: string
  day: AttendanceDay | undefined
  schedule?: EmployeeShiftAssignment
  warnings?: string[]
  /** trueの場合、選択モードで描画する(チェックボックス表示・行クリックで選択トグル・
   *  日次画面への遷移を無効化)。週次・月次画面の「選択」ボタンで一括切り替えする。 */
  selectionMode?: boolean
  /** 選択モード中、この日が選択されているか。 */
  selected?: boolean
  /** 選択モード中、チェックボックスまたは行クリックで選択状態を切り替えたときのコールバック。 */
  onToggleSelected?: (date: string) => void
}

/**
 * 週次・月次画面で使う、1日分の勤怠を要約した行。通常時は行本体がリンクになっており、
 * クリックすると該当日の日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI: 日という対象を選んでから操作する)。
 *
 * 選択モード中(`selectionMode`)は行左端にチェックボックスを表示し、行クリック・
 * チェックボックスのどちらでも選択をトグルできる(iOS Mailライクな一括選択UI)。
 * 選択モードでは日次画面への遷移は行わない。選択した日をまとめて休暇申請するのは
 * 週次・月次画面側の一括操作バーの役割で、この行自体は選択状態の保持のみを担う。
 */
export function AttendanceDayRow({
  date,
  day,
  schedule,
  warnings = [],
  selectionMode = false,
  selected = false,
  onToggleSelected,
}: AttendanceDayRowProps) {
  const { label, tone } = attendanceRowDisplayLabel(day, schedule)
  const holiday = attendanceScheduleHolidayLabel(schedule)
  const showHolidayAlongsideStatus = holiday !== null && day !== undefined
    && (day.status !== 'not_started' || Boolean(day.actual_start_at) || Boolean(day.actual_end_at))

  const content = (
    <>
      <div className="flex min-w-0 items-center gap-2 sm:contents">
        <span className="whitespace-nowrap text-sm font-semibold text-foreground">
          {date}({weekdayLabel(date)})
        </span>
        <Badge tone={tone}>{label}</Badge>
        {showHolidayAlongsideStatus && <Badge tone={holiday.tone}>{holiday.label}</Badge>}
        {schedule?.public_holiday_name && (
          <span className="text-xs text-muted-foreground">{schedule.public_holiday_name}</span>
        )}
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
    </>
  )

  if (selectionMode) {
    const toggle = () => onToggleSelected?.(date)
    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault()
        toggle()
      }
    }

    return (
      <li className="flex items-center gap-3">
        <Checkbox
          checked={selected}
          onCheckedChange={toggle}
          aria-label={`${date}を選択`}
          onClick={(e) => e.stopPropagation()}
        />
        <div
          role="button"
          tabIndex={0}
          aria-pressed={selected}
          aria-label={`${date}を選択`}
          onClick={toggle}
          onKeyDown={handleKeyDown}
          className={cn(
            'grid min-w-0 flex-1 cursor-pointer grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-2 rounded-md px-2 py-3 transition-colors hover:bg-accent sm:flex sm:items-center sm:gap-2.5',
            selected && 'bg-accent',
          )}
        >
          {content}
        </div>
      </li>
    )
  }

  return (
    <li className="flex items-center gap-1">
      <Link
        to={`/attendance/days/${date}`}
        className="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 rounded-md px-2 py-3 transition-colors hover:bg-accent sm:flex sm:items-center sm:gap-2.5"
      >
        {content}
        <ChevronRight className="col-start-2 row-span-2 size-4 self-center text-muted-foreground sm:order-last sm:ml-auto" aria-hidden="true" />
      </Link>
    </li>
  )
}
