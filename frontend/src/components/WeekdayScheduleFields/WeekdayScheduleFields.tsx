import { Checkbox } from '../ui/checkbox'
import { Input } from '../ui/input'
import type { AttendanceWeekdayEntry, WeeklyAttendancePattern } from '../../api/attendance'

export const WEEKDAYS: { iso: number; label: string }[] = [
  { iso: 1, label: '月' },
  { iso: 2, label: '火' },
  { iso: 3, label: '水' },
  { iso: 4, label: '木' },
  { iso: 5, label: '金' },
  { iso: 6, label: '土' },
  { iso: 7, label: '日' },
]

export interface WeekdayRowState {
  enabled: boolean
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}

/** 平日(月〜金)9:00〜18:00・休憩12:00〜13:00、土日は入力しない、を初期値にする。 */
export function defaultWeeklyPatternState(): Record<number, WeekdayRowState> {
  const state: Record<number, WeekdayRowState> = {}
  for (const { iso } of WEEKDAYS) {
    state[iso] = {
      enabled: iso <= 5,
      startTime: '09:00',
      endTime: '18:00',
      breakEnabled: iso <= 5,
      breakStartTime: '12:00',
      breakEndTime: '13:00',
    }
  }
  return state
}

export function buildWeeklyPattern(state: Record<number, WeekdayRowState>): WeeklyAttendancePattern {
  const pattern: WeeklyAttendancePattern = {}
  for (const { iso } of WEEKDAYS) {
    const row = state[iso]
    pattern[iso] = row.enabled ? weekdayEntry(row) : null
  }
  return pattern
}

export function weekdayEntry(row: {
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}): AttendanceWeekdayEntry {
  return {
    start_time: row.startTime,
    end_time: row.endTime,
    ...(row.breakEnabled ? { break_start_time: row.breakStartTime, break_end_time: row.breakEndTime } : {}),
  }
}

/**
 * 週次・月次一括入力で共通して使う「曜日ごとに入力する/しない・出退勤時刻・休憩時刻」の入力行。
 */
export function WeekdayScheduleFields({
  state,
  onChange,
}: {
  state: Record<number, WeekdayRowState>
  onChange: (iso: number, patch: Partial<WeekdayRowState>) => void
}) {
  return (
    <div className="grid grid-cols-1 gap-2">
      {WEEKDAYS.map(({ iso, label }) => {
        const row = state[iso]
        return (
          <div key={iso} className="flex flex-wrap items-center gap-3 rounded-md border border-border p-2">
            <label className="flex w-16 items-center gap-2 text-sm font-medium text-foreground">
              <Checkbox
                checked={row.enabled}
                onCheckedChange={(checked) => onChange(iso, { enabled: checked === true })}
              />
              {label}曜日
            </label>
            <Input
              type="time"
              aria-label={`${label}曜日の出勤時刻`}
              className="w-28"
              disabled={!row.enabled}
              value={row.startTime}
              onChange={(e) => onChange(iso, { startTime: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">〜</span>
            <Input
              type="time"
              aria-label={`${label}曜日の退勤時刻`}
              className="w-28"
              disabled={!row.enabled}
              value={row.endTime}
              onChange={(e) => onChange(iso, { endTime: e.target.value })}
            />
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                checked={row.breakEnabled}
                aria-label={`${label}曜日の休憩`}
                disabled={!row.enabled}
                onCheckedChange={(checked) => onChange(iso, { breakEnabled: checked === true })}
              />
              休憩
            </label>
            <Input
              type="time"
              aria-label={`${label}曜日の休憩開始時刻`}
              className="w-28"
              disabled={!row.enabled || !row.breakEnabled}
              value={row.breakStartTime}
              onChange={(e) => onChange(iso, { breakStartTime: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">〜</span>
            <Input
              type="time"
              aria-label={`${label}曜日の休憩終了時刻`}
              className="w-28"
              disabled={!row.enabled || !row.breakEnabled}
              value={row.breakEndTime}
              onChange={(e) => onChange(iso, { breakEndTime: e.target.value })}
            />
          </div>
        )
      })}
    </div>
  )
}
