import { ChevronRight, MoreVertical } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { AttendanceDay, EmployeeShiftAssignment } from '../../api/types'
import { isoToTimeLiteral } from '../../utils/offsetDateTime'
import { attendanceRowDisplayLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import type { ApprovedLeaveTarget } from '../CancelApprovedLeaveDialog/CancelApprovedLeaveDialog'
import { Duration } from '../Duration/Duration'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../ui/dropdown-menu'

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
  /** この日に承認済みの有給休暇申請があれば、そのID(取消メニュー項目を表示するため)。 */
  approvedPaidLeaveRequestId?: string
  /** この日に承認済みの特別休暇申請があれば、そのID。 */
  approvedSpecialLeaveRequestId?: string
  /** この日に承認済みの代休申請があれば、そのID。 */
  approvedCompensatoryLeaveRequestId?: string
  /** 承認済み休暇の取消メニュー項目が選ばれたときのコールバック。週次・月次画面は
   *  1つの確認ダイアログを画面に1つだけ持ち、ここでどの休暇を取り消すか受け取る。 */
  onRequestCancelApprovedLeave?: (target: ApprovedLeaveTarget) => void
}

/**
 * 週次・月次画面で使う、1日分の勤怠を要約した行。行本体はリンクになっており、
 * クリックすると該当日の日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI: 日という対象を選んでから操作する)。行の右端のケバブメニューから、
 * 日次画面に遷移せずその場で休暇申請(有給・特別休暇・代休)や、承認済み休暇の取消を
 * 行える(日次画面のケバブメニューと同じ導線・同じAPIを使う)。
 */
export function AttendanceDayRow({
  date,
  day,
  schedule,
  warnings = [],
  approvedPaidLeaveRequestId,
  approvedSpecialLeaveRequestId,
  approvedCompensatoryLeaveRequestId,
  onRequestCancelApprovedLeave,
}: AttendanceDayRowProps) {
  const { label, tone } = attendanceRowDisplayLabel(day, schedule)
  const hasApprovedLeaveToCancel =
    !!approvedPaidLeaveRequestId || !!approvedSpecialLeaveRequestId || !!approvedCompensatoryLeaveRequestId

  return (
    <li className="flex items-center gap-1">
      <Link
        to={`/attendance/days/${date}`}
        className="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 rounded-md px-2 py-3 transition-colors hover:bg-accent sm:flex sm:items-center sm:gap-2.5"
      >
        <div className="flex min-w-0 items-center gap-2 sm:contents">
          <span className="whitespace-nowrap text-sm font-semibold text-foreground">
            {date}({weekdayLabel(date)})
          </span>
          <Badge tone={tone}>{label}</Badge>
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
        <ChevronRight className="col-start-2 row-span-2 size-4 self-center text-muted-foreground sm:order-last sm:ml-auto" aria-hidden="true" />
      </Link>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="secondary" size="icon" aria-label={`${date}の操作`}>
            <MoreVertical aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link to={`/paid-leave?date=${date}`}>有給休暇を申請する</Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link to={`/special-leave?date=${date}`}>特別休暇を申請する</Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link to={`/compensatory-leave?date=${date}`}>代休を申請する</Link>
          </DropdownMenuItem>
          {hasApprovedLeaveToCancel && onRequestCancelApprovedLeave && <DropdownMenuSeparator />}
          {approvedPaidLeaveRequestId && onRequestCancelApprovedLeave && (
            <DropdownMenuItem
              onSelect={() =>
                onRequestCancelApprovedLeave({ kind: 'paid', id: approvedPaidLeaveRequestId, label: '有給休暇' })
              }
            >
              有給休暇の承認を取り消す
            </DropdownMenuItem>
          )}
          {approvedSpecialLeaveRequestId && onRequestCancelApprovedLeave && (
            <DropdownMenuItem
              onSelect={() =>
                onRequestCancelApprovedLeave({ kind: 'special', id: approvedSpecialLeaveRequestId, label: '特別休暇' })
              }
            >
              特別休暇の承認を取り消す
            </DropdownMenuItem>
          )}
          {approvedCompensatoryLeaveRequestId && onRequestCancelApprovedLeave && (
            <DropdownMenuItem
              onSelect={() =>
                onRequestCancelApprovedLeave({
                  kind: 'compensatory',
                  id: approvedCompensatoryLeaveRequestId,
                  label: '代休',
                })
              }
            >
              代休の承認を取り消す
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </li>
  )
}
