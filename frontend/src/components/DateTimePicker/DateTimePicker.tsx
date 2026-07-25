import { formatDate } from '../../utils/weekDates'
import { DatePicker } from '../DatePicker/DatePicker'
import { TimePicker } from '../TimePicker/TimePicker'

export interface DateTimePickerProps {
  id?: string
  /** "YYYY-MM-DDTHH:mm" 形式(`<input type="datetime-local">`と同じ、タイムゾーンを持たない値)。 */
  value: string | undefined
  onChange: (value: string | undefined) => void
  disabled?: boolean
  /** 分の選択肢の間隔(分)。既定は1分刻み(全60分)。TimePickerへそのまま渡す。 */
  minuteStep?: number
  /** 「今日」「明日」等の相対日付ショートカットを表示するか。既定は表示する。DatePickerへそのまま渡す。 */
  showRelativeShortcuts?: boolean
  /**
   * ラベル要素を使わずアクセシブルネームを付ける場合に指定する(native
   * input[type=datetime-local]のaria-labelと同じ用途)。日付側は"{ariaLabel}(日付)"、
   * 時刻側は"{ariaLabel}(時刻)"としてそれぞれのトリガーに付与する。
   */
  'aria-label'?: string
}

function splitValue(value: string | undefined): { date: string | undefined; time: string | undefined } {
  if (!value) return { date: undefined, time: undefined }
  return { date: value.slice(0, 10), time: value.slice(11, 16) }
}

/**
 * 日付+時刻をまとめて選ぶ入力。値は`<input type="datetime-local">`と同じ
 * "YYYY-MM-DDTHH:mm"文字列(タイムゾーンを持たない、その場所の壁時計時刻)。
 * `DatePicker`と`TimePicker`を組み合わせただけで、それぞれを単独で必要とする場面では
 * 各コンポーネントを直接使う。
 */
export function DateTimePicker({
  id,
  value,
  onChange,
  disabled,
  minuteStep,
  showRelativeShortcuts,
  'aria-label': ariaLabel,
}: DateTimePickerProps) {
  const { date, time } = splitValue(value)

  const handleDateChange = (nextDate: string | undefined) => {
    if (!nextDate) {
      onChange(undefined)
      return
    }
    onChange(`${nextDate}T${time ?? '00:00'}`)
  }

  const handleTimeChange = (nextTime: string | undefined) => {
    if (!nextTime) {
      onChange(undefined)
      return
    }
    onChange(`${date ?? formatDate(new Date())}T${nextTime}`)
  }

  return (
    <div className="flex gap-2">
      <div className="flex-[3]">
        <DatePicker
          id={id}
          value={date}
          onChange={handleDateChange}
          disabled={disabled}
          showRelativeShortcuts={showRelativeShortcuts}
          aria-label={ariaLabel ? `${ariaLabel}(日付)` : undefined}
        />
      </div>
      <div className="flex-[2]">
        <TimePicker
          id={id ? `${id}-time` : undefined}
          value={time}
          onChange={handleTimeChange}
          disabled={disabled}
          minuteStep={minuteStep}
          aria-label={ariaLabel ? `${ariaLabel}(時刻)` : undefined}
        />
      </div>
    </div>
  )
}
