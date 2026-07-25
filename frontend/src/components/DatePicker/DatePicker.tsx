import { useState } from 'react'
import { CalendarIcon } from 'lucide-react'
import { cn } from '../../lib/utils'
import { addDays, formatDate } from '../../utils/weekDates'
import { Button } from '../Button/Button'
import { Calendar } from '../ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

export interface DatePickerProps {
  id?: string
  /** "YYYY-MM-DD" 形式。 */
  value: string | undefined
  onChange: (date: string | undefined) => void
  placeholder?: string
  disabled?: boolean
  /** 「今日」「明日」等の相対日付ショートカットを表示するか。既定は表示する。 */
  showRelativeShortcuts?: boolean
}

/** "YYYY-MM-DD" をタイムゾーン変換をせず、その日のローカル日付として`Date`に変換する。 */
function parseDateValue(value: string | undefined): Date | undefined {
  if (!value) return undefined
  const parsed = new Date(`${value}T00:00:00`)
  return Number.isNaN(parsed.getTime()) ? undefined : parsed
}

/** 「今日」を基準にした相対日付のショートカット一覧。 */
function relativeDateShortcuts(): { label: string; date: string }[] {
  const today = formatDate(new Date())
  return [
    { label: '昨日', date: addDays(today, -1) },
    { label: '今日', date: today },
    { label: '明日', date: addDays(today, 1) },
    { label: '明後日', date: addDays(today, 2) },
  ]
}

/**
 * カレンダーから日付を1件選ぶ入力。値は`<input type="date">`と同じ"YYYY-MM-DD"文字列。
 * 勤務日・適用期間の開始/終了日など、カレンダーから選びたい日付入力全般で使う。
 * 「今日」「明日」等、よく使う相対日付をワンクリックで選べるショートカットを備える。
 */
export function DatePicker({
  id,
  value,
  onChange,
  placeholder = '日付を選択',
  disabled,
  showRelativeShortcuts = true,
}: DatePickerProps) {
  const [open, setOpen] = useState(false)
  const selected = parseDateValue(value)

  const selectDate = (date: string | undefined) => {
    onChange(date)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          id={id}
          type="button"
          disabled={disabled}
          className={cn(
            'flex h-9 w-full items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50',
            !value && 'text-muted-foreground',
          )}
        >
          <CalendarIcon className="size-4 shrink-0 text-muted-foreground" />
          <span className="truncate">{value ?? placeholder}</span>
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        {showRelativeShortcuts && (
          <div className="flex flex-wrap gap-1.5 border-b border-border p-2">
            {relativeDateShortcuts().map((shortcut) => (
              <Button
                key={shortcut.label}
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => selectDate(shortcut.date)}
              >
                {shortcut.label}
              </Button>
            ))}
          </div>
        )}
        <Calendar
          mode="single"
          selected={selected}
          defaultMonth={selected}
          onSelect={(date) => selectDate(date ? formatDate(date) : undefined)}
        />
      </PopoverContent>
    </Popover>
  )
}
