import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { CalendarIcon, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '../ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

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

const MONTH_LABELS = Array.from({ length: 12 }, (_, index) => `${index + 1}月`)

function currentYearMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function parseYear(value: string | undefined): number | undefined {
  if (!value || !/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return undefined
  return Number(value.slice(0, 4))
}

function formatYearMonth(year: number, monthIndex: number): string {
  return `${year}-${String(monthIndex + 1).padStart(2, '0')}`
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
  const [visibleYear, setVisibleYear] = useState(() => parseYear(value) ?? new Date().getFullYear())
  const [activeMonthIndex, setActiveMonthIndex] = useState(() =>
    value && parseYear(value) !== undefined ? Number(value.slice(5, 7)) - 1 : new Date().getMonth(),
  )
  const monthListRef = useRef<HTMLDivElement | null>(null)
  const thisMonth = currentYearMonth()
  const minYear = parseYear(min)
  const maxYear = parseYear(max)

  const handleOpenChange = (nextOpen: boolean) => {
    if (nextOpen) {
      setVisibleYear(parseYear(value) ?? new Date().getFullYear())
      setActiveMonthIndex(value && parseYear(value) !== undefined ? Number(value.slice(5, 7)) - 1 : new Date().getMonth())
    }
    setOpen(nextOpen)
  }

  useEffect(() => {
    if (open) monthListRef.current?.querySelector<HTMLButtonElement>(`[data-month-index="${activeMonthIndex}"]`)?.focus()
  }, [activeMonthIndex, open, visibleYear])

  const select = (nextValue: string | undefined) => {
    onChange(nextValue)
    setOpen(false)
  }

  const moveMonthFocus = (nextIndex: number) => {
    const direction = nextIndex >= activeMonthIndex ? 1 : -1
    let candidate = Math.max(0, Math.min(11, nextIndex))
    while (candidate >= 0 && candidate <= 11) {
      const yearMonth = formatYearMonth(visibleYear, candidate)
      if ((!min || yearMonth >= min) && (!max || yearMonth <= max)) {
        setActiveMonthIndex(candidate)
        return
      }
      candidate += direction
    }
  }

  const handleMonthKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    const movement = {
      ArrowLeft: -1,
      ArrowRight: 1,
      ArrowUp: -3,
      ArrowDown: 3,
    }[event.key]

    if (movement !== undefined) {
      event.preventDefault()
      moveMonthFocus(activeMonthIndex + movement)
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      moveMonthFocus(event.key === 'Home' ? 0 : 11)
    } else if (event.key === 'PageUp' || event.key === 'PageDown') {
      event.preventDefault()
      const nextYear = visibleYear + (event.key === 'PageUp' ? -1 : 1)
      if ((minYear === undefined || nextYear >= minYear) && (maxYear === undefined || nextYear <= maxYear)) {
        setVisibleYear(nextYear)
      }
    }
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
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
      <PopoverContent
        className="w-72 p-0"
        align="start"
        collisionPadding={16}
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          monthListRef.current
            ?.querySelector<HTMLButtonElement>(`[data-month-index="${activeMonthIndex}"]`)
            ?.focus()
        }}
      >
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
        <div className="p-3">
          <div className="mb-3 flex items-center justify-between">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label="前年へ"
              disabled={minYear !== undefined && visibleYear <= minYear}
              onClick={() => setVisibleYear((year) => year - 1)}
            >
              <ChevronLeft aria-hidden="true" />
            </Button>
            <span className="text-sm font-semibold" aria-live="polite">
              {visibleYear}年
            </span>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label="翌年へ"
              disabled={maxYear !== undefined && visibleYear >= maxYear}
              onClick={() => setVisibleYear((year) => year + 1)}
            >
              <ChevronRight aria-hidden="true" />
            </Button>
          </div>
          <div
            ref={monthListRef}
            className="grid grid-cols-3 gap-2"
            role="listbox"
            aria-label={`${visibleYear}年の月`}
          >
            {MONTH_LABELS.map((label, monthIndex) => {
              const yearMonth = formatYearMonth(visibleYear, monthIndex)
              const unavailable = (min !== undefined && yearMonth < min) || (max !== undefined && yearMonth > max)
              return (
                <Button
                  key={yearMonth}
                  type="button"
                  data-month-index={monthIndex}
                  variant={value === yearMonth ? 'default' : 'ghost'}
                  role="option"
                  aria-label={`${visibleYear}年${label}`}
                  aria-selected={value === yearMonth}
                  disabled={unavailable}
                  tabIndex={activeMonthIndex === monthIndex ? 0 : -1}
                  onFocus={() => setActiveMonthIndex(monthIndex)}
                  onKeyDown={handleMonthKeyDown}
                  onClick={() => select(yearMonth)}
                >
                  {label}
                </Button>
              )
            })}
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}
