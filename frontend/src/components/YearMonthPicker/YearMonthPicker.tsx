import { useState } from 'react'
import { CalendarIcon } from 'lucide-react'
import { Button } from '../ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'
import { YearMonthGrid } from './YearMonthGrid'

export interface YearMonthPickerProps {
  id?: string
  /** "YYYY-MM" 形式。 */
  value: string | undefined
  onChange: (value: string | undefined) => void
  placeholder?: string
  disabled?: boolean
  /** この年月以降のみ選択可。"YYYY-MM"形式。 */
  min?: string
  /** この年月以前のみ選択可。"YYYY-MM"形式。 */
  max?: string
  'aria-label'?: string
}

function currentYearMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function parseYear(value: string | undefined): number | undefined {
  if (!value || !/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return undefined
  return Number(value.slice(0, 4))
}

function displayValue(value: string | undefined): string | undefined {
  const year = parseYear(value)
  if (year === undefined || !value) return undefined
  return `${year}年${Number(value.slice(5, 7))}月`
}

export function YearMonthPicker({
  id,
  value,
  onChange,
  placeholder = '年月を選択',
  disabled,
  min,
  max,
  'aria-label': ariaLabel,
}: YearMonthPickerProps) {
  const [open, setOpen] = useState(false)
  const thisMonth = currentYearMonth()

  const select = (nextValue: string | undefined) => {
    onChange(nextValue)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          disabled={disabled}
          aria-label={ariaLabel}
          className={`w-full justify-start px-3 font-normal ${value ? 'text-foreground' : 'text-muted-foreground'}`}
        >
          <CalendarIcon className="size-4 shrink-0 text-muted-foreground" />
          <span className="truncate">{displayValue(value) ?? placeholder}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start" collisionPadding={16}>
        {(value || (thisMonth >= (min ?? '') && thisMonth <= (max ?? '9999-12'))) && (
          <div className="flex min-h-11 items-center justify-end gap-1 border-b border-border bg-muted/30 px-2 py-1.5">
            {thisMonth >= (min ?? '') && thisMonth <= (max ?? '9999-12') && (
              <Button type="button" variant="ghost" size="sm" onClick={() => select(thisMonth)}>
                今月
              </Button>
            )}
            {value && (
              <Button type="button" variant="ghost" size="sm" onClick={() => select(undefined)}>
                クリア
              </Button>
            )}
          </div>
        )}
        <YearMonthGrid
          key={open ? 'open' : 'closed'}
          initialYearMonth={value ?? thisMonth}
          selectedYearMonth={value}
          min={min}
          max={max}
          onSelect={select}
        />
      </PopoverContent>
    </Popover>
  )
}
