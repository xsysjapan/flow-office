import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '../ui/button'

export interface YearMonthGridProps {
  /** 初期表示・フォーカス開始位置の基準となる"YYYY-MM"。 */
  initialYearMonth: string
  /** ハイライト表示する選択中の"YYYY-MM"(未選択ならundefined)。 */
  selectedYearMonth?: string
  /** この年月以降のみ選択可。"YYYY-MM"形式。 */
  min?: string
  /** この年月以前のみ選択可。"YYYY-MM"形式。 */
  max?: string
  onSelect: (yearMonth: string) => void
}

const MONTH_LABELS = Array.from({ length: 12 }, (_, index) => `${index + 1}月`)

function formatYearMonth(year: number, monthIndex: number): string {
  return `${year}-${String(monthIndex + 1).padStart(2, '0')}`
}

/**
 * 年送りナビ + 月グリッドだけの選択UI(Popoverトリガー等は持たない)。
 * `YearMonthPicker`と`DatePicker`(年月クリック時の切り替え表示)の両方から使う。
 */
export function YearMonthGrid({ initialYearMonth, selectedYearMonth, min, max, onSelect }: YearMonthGridProps) {
  const [visibleYear, setVisibleYear] = useState(() => Number(initialYearMonth.slice(0, 4)))
  const [activeMonthIndex, setActiveMonthIndex] = useState(() => Number(initialYearMonth.slice(5, 7)) - 1)
  const monthListRef = useRef<HTMLDivElement | null>(null)
  const minYear = min ? Number(min.slice(0, 4)) : undefined
  const maxYear = max ? Number(max.slice(0, 4)) : undefined

  useEffect(() => {
    monthListRef.current?.querySelector<HTMLButtonElement>(`[data-month-index="${activeMonthIndex}"]`)?.focus()
  }, [activeMonthIndex, visibleYear])

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
      <div ref={monthListRef} className="grid grid-cols-3 gap-2" role="listbox" aria-label={`${visibleYear}年の月`}>
        {MONTH_LABELS.map((label, monthIndex) => {
          const yearMonth = formatYearMonth(visibleYear, monthIndex)
          const unavailable = (min !== undefined && yearMonth < min) || (max !== undefined && yearMonth > max)
          return (
            <Button
              key={yearMonth}
              type="button"
              data-month-index={monthIndex}
              variant={selectedYearMonth === yearMonth ? 'default' : 'ghost'}
              role="option"
              aria-label={`${visibleYear}年${label}`}
              aria-selected={selectedYearMonth === yearMonth}
              disabled={unavailable}
              tabIndex={activeMonthIndex === monthIndex ? 0 : -1}
              onFocus={() => setActiveMonthIndex(monthIndex)}
              onKeyDown={handleMonthKeyDown}
              onClick={() => onSelect(yearMonth)}
            >
              {label}
            </Button>
          )
        })}
      </div>
    </div>
  )
}
