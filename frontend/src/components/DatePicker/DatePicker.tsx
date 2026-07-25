import { useState } from 'react'
import { CalendarIcon } from 'lucide-react'
import { cn } from '../../lib/utils'
import { Calendar } from '../ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

export interface DatePickerProps {
  id?: string
  /** "YYYY-MM-DD" 形式。 */
  value: string | undefined
  onChange: (date: string | undefined) => void
  placeholder?: string
  disabled?: boolean
}

/** "YYYY-MM-DD" をタイムゾーン変換をせず、その日のローカル日付として`Date`に変換する。 */
function parseDateValue(value: string | undefined): Date | undefined {
  if (!value) return undefined
  const parsed = new Date(`${value}T00:00:00`)
  return Number.isNaN(parsed.getTime()) ? undefined : parsed
}

function formatDateValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/**
 * カレンダーから日付を1件選ぶ入力。値は`<input type="date">`と同じ"YYYY-MM-DD"文字列。
 * 勤務日・適用期間の開始/終了日など、カレンダーから選びたい日付入力全般で使う。
 */
export function DatePicker({ id, value, onChange, placeholder = '日付を選択', disabled }: DatePickerProps) {
  const [open, setOpen] = useState(false)
  const selected = parseDateValue(value)

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
        <Calendar
          mode="single"
          selected={selected}
          defaultMonth={selected}
          onSelect={(date) => {
            onChange(date ? formatDateValue(date) : undefined)
            setOpen(false)
          }}
        />
      </PopoverContent>
    </Popover>
  )
}
