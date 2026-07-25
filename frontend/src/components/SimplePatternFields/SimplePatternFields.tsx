import { Badge } from '../Badge/Badge'
import { Checkbox } from '../ui/checkbox'
import { Input } from '../ui/input'
import { WEEKDAYS, weekdayEntry } from '../WeekdayScheduleFields/WeekdayScheduleFields'
import type { WeeklyAttendancePattern } from '../../api/attendance'

export interface SimplePatternState {
  weekdays: Record<number, boolean>
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}

/** 平日(月〜金)を選択済み、9:00〜18:00・休憩12:00〜13:00を初期値にする。 */
export function defaultSimplePatternState(): SimplePatternState {
  return {
    weekdays: { 1: true, 2: true, 3: true, 4: true, 5: true, 6: false, 7: false },
    startTime: '09:00',
    endTime: '18:00',
    breakEnabled: true,
    breakStartTime: '12:00',
    breakEndTime: '13:00',
  }
}

/** 終了時刻が開始時刻以下(00:00〜23:59の文字列比較で判定できる)なら日をまたぐとみなす。 */
export function crossesMidnight(state: Pick<SimplePatternState, 'startTime' | 'endTime'>): boolean {
  return Boolean(state.startTime && state.endTime && state.endTime <= state.startTime)
}

/** 選択した曜日すべてに同じ開始/終了・休憩時刻を適用したweekly_patternを組み立てる。 */
export function buildWeeklyPatternFromSimpleState(state: SimplePatternState): WeeklyAttendancePattern {
  const pattern: WeeklyAttendancePattern = {}
  const entry = weekdayEntry(state)
  for (const { iso } of WEEKDAYS) {
    pattern[iso] = state.weekdays[iso] ? entry : null
  }
  return pattern
}

/**
 * 週次・月次一括入力の「まとめて設定」タブ: 開始/終了時刻(+休憩)を1組だけ入力し、
 * それを適用する曜日を選ぶだけの簡易入力。終了時刻が開始時刻以下の場合は日をまたぐ
 * 勤務とみなし、翌日である旨を自動的に示す(既存APIの「終了≦開始なら翌日扱い」を
 * そのまま使うため、ユーザーが個別に翌日/前日を指定する項目は持たない)。
 */
export function SimplePatternFields({
  state,
  onChange,
}: {
  state: SimplePatternState
  onChange: (patch: Partial<SimplePatternState>) => void
}) {
  const toggleWeekday = (iso: number, checked: boolean) => {
    onChange({ weekdays: { ...state.weekdays, [iso]: checked } })
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <p className="mb-2 text-sm font-semibold text-foreground">対象の曜日</p>
        <div className="flex flex-wrap gap-3">
          {WEEKDAYS.map(({ iso, label }) => (
            <label key={iso} className="flex items-center gap-1.5 text-sm text-foreground">
              <Checkbox checked={state.weekdays[iso] ?? false} onCheckedChange={(checked) => toggleWeekday(iso, checked === true)} />
              {label}
            </label>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2 text-sm text-foreground" htmlFor="simple-pattern-start-time">
            開始時刻
            <Input
              id="simple-pattern-start-time"
              type="time"
              className="w-28"
              value={state.startTime}
              onChange={(e) => onChange({ startTime: e.target.value })}
            />
          </label>
          <span className="text-sm text-muted-foreground">〜</span>
          <label className="flex items-center gap-2 text-sm text-foreground" htmlFor="simple-pattern-end-time">
            終了時刻
            <Input
              id="simple-pattern-end-time"
              type="time"
              className="w-28"
              value={state.endTime}
              onChange={(e) => onChange({ endTime: e.target.value })}
            />
          </label>
        </div>
        {crossesMidnight(state) && <Badge tone="neutral">翌日</Badge>}
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
        <label className="flex items-center gap-2 text-sm text-foreground">
          <Checkbox checked={state.breakEnabled} onCheckedChange={(checked) => onChange({ breakEnabled: checked === true })} />
          休憩
        </label>
        <div className="flex items-center gap-2">
          <Input
            type="time"
            aria-label="休憩開始時刻"
            className="w-28"
            disabled={!state.breakEnabled}
            value={state.breakStartTime}
            onChange={(e) => onChange({ breakStartTime: e.target.value })}
          />
          <span className="text-sm text-muted-foreground">〜</span>
          <Input
            type="time"
            aria-label="休憩終了時刻"
            className="w-28"
            disabled={!state.breakEnabled}
            value={state.breakEndTime}
            onChange={(e) => onChange({ breakEndTime: e.target.value })}
          />
        </div>
      </div>
    </div>
  )
}
