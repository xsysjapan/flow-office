import { useState } from 'react'
import { CalendarIcon } from 'lucide-react'
import type { CaptionLabelProps, DateRange } from 'react-day-picker'
import { ja } from 'react-day-picker/locale'
import { YearMonthGrid } from '../YearMonthPicker/YearMonthGrid'
import { formatDate } from '../../utils/weekDates'
import { Button } from '../ui/button'
import { Calendar } from '../ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

export interface DateRangeValue {
  /** "YYYY-MM-DD" 形式。 */
  from?: string
  /** "YYYY-MM-DD" 形式。 */
  to?: string
}

export interface DateRangePickerProps {
  id?: string
  value: DateRangeValue | undefined
  onChange: (range: DateRangeValue | undefined) => void
  placeholder?: string
  disabled?: boolean
  /** この日付以降のみ選択可にする。"YYYY-MM-DD"形式。 */
  min?: string
  /** この日付以前のみ選択可にする。"YYYY-MM-DD"形式。 */
  max?: string
  /**
   * 値が未選択のとき、最初に開くカレンダーの基準日("YYYY-MM-DD"形式)。選択を制限する
   * `min`/`max`とは異なり、表示位置だけを決める。対象期間が文脈上自明な場合に指定する。
   */
  defaultDate?: string
  /** ラベル要素を使わずアクセシブルネームを付ける場合に指定する。 */
  'aria-label'?: string
}

/** "YYYY-MM-DD" をタイムゾーン変換をせず、その日のローカル日付として`Date`に変換する。 */
function parseDateValue(value: string | undefined): Date | undefined {
  if (!value) return undefined
  const parsed = new Date(`${value}T00:00:00`)
  return Number.isNaN(parsed.getTime()) ? undefined : parsed
}

function formatYearMonth(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function toDateRange(value: DateRangeValue | undefined): DateRange | undefined {
  const from = parseDateValue(value?.from)
  if (!from) return undefined
  return { from, to: parseDateValue(value?.to) }
}

function fromDateRange(range: DateRange | undefined): DateRangeValue | undefined {
  if (!range?.from) return undefined
  return { from: formatDate(range.from), to: range.to ? formatDate(range.to) : undefined }
}

function triggerLabel(value: DateRangeValue | undefined, placeholder: string): string {
  if (!value?.from) return placeholder
  if (!value.to || value.to === value.from) return value.from
  return `${value.from} 〜 ${value.to}`
}

/**
 * カレンダーから日付の範囲を1件選ぶ入力。値は{ from, to }(いずれも"YYYY-MM-DD"文字列)。
 * 複数日にまたがる休暇申請の期間指定など、開始日〜終了日をまとめて選びたい入力で使う
 * (単一日の選択は`DatePicker`を使う)。
 * カレンダー上部の年月表示をクリックすると年月ピッカーに切り替わり、遠い過去の年へも
 * 素早く移動できる(月を選ぶと日付選択画面に戻り、その月の日付を選べる)。
 */
export function DateRangePicker({
  id,
  value,
  onChange,
  placeholder = '期間を選択',
  disabled,
  min,
  max,
  defaultDate,
  'aria-label': ariaLabel,
}: DateRangePickerProps) {
  const [open, setOpen] = useState(false)
  const [mode, setMode] = useState<'day' | 'yearMonth'>('day')
  const selected = toDateRange(value)
  const minDate = parseDateValue(min)
  const maxDate = parseDateValue(max)
  const fallbackDate = parseDateValue(defaultDate)
  const [month, setMonth] = useState<Date>(() => selected?.from ?? minDate ?? maxDate ?? fallbackDate ?? new Date())

  const handleOpenChange = (nextOpen: boolean) => {
    if (nextOpen) {
      setMode('day')
      setMonth(selected?.from ?? minDate ?? maxDate ?? fallbackDate ?? new Date())
    }
    setOpen(nextOpen)
  }

  const selectRange = (range: DateRange | undefined) => {
    onChange(fromDateRange(range))
  }

  const clear = () => {
    onChange(undefined)
    setOpen(false)
  }

  const apply = () => setOpen(false)

  const selectYearMonth = (yearMonth: string) => {
    const [year, monthNumber] = yearMonth.split('-').map(Number)
    setMonth(new Date(year, monthNumber - 1, 1))
    setMode('day')
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          disabled={disabled}
          aria-label={ariaLabel}
          className={`w-full justify-start px-3 font-normal ${value?.from ? 'text-foreground' : 'text-muted-foreground'}`}
        >
          <CalendarIcon className="size-4 shrink-0 text-muted-foreground" />
          <span className="truncate">{triggerLabel(value, placeholder)}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start" collisionPadding={16}>
        {mode === 'day' ? (
          <>
            {value?.from && (
              <div className="flex min-h-11 items-center justify-end gap-1 border-b border-border bg-muted/30 px-2 py-1.5">
                <Button type="button" variant="ghost" size="sm" onClick={clear}>
                  クリア
                </Button>
                <Button type="button" variant="secondary" size="sm" onClick={apply}>
                  適用
                </Button>
              </div>
            )}
            <Calendar
              mode="range"
              locale={ja}
              labels={{
                labelPrevious: () => '前の月へ',
                labelNext: () => '次の月へ',
              }}
              selected={selected}
              month={month}
              onMonthChange={setMonth}
              disabled={[...(minDate ? [{ before: minDate }] : []), ...(maxDate ? [{ after: maxDate }] : [])]}
              onSelect={selectRange}
              components={{
                CaptionLabel: ({ children, className, ...captionProps }: CaptionLabelProps) => (
                  <button
                    type="button"
                    className={`${className ?? ''} rounded-md px-2 hover:bg-accent hover:text-accent-foreground`}
                    onClick={() => setMode('yearMonth')}
                    {...captionProps}
                  >
                    {children}
                  </button>
                ),
              }}
            />
          </>
        ) : (
          <>
            <div className="flex min-h-11 items-center justify-start border-b border-border bg-muted/30 px-2 py-1.5">
              <Button type="button" variant="ghost" size="sm" onClick={() => setMode('day')}>
                日付選択に戻る
              </Button>
            </div>
            <YearMonthGrid
              initialYearMonth={formatYearMonth(month)}
              selectedYearMonth={formatYearMonth(month)}
              min={min?.slice(0, 7)}
              max={max?.slice(0, 7)}
              onSelect={selectYearMonth}
            />
          </>
        )}
      </PopoverContent>
    </Popover>
  )
}
