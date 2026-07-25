import { useEffect, useRef, useState } from 'react'
import { Clock } from 'lucide-react'
import { cn } from '../../lib/utils'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

export interface TimePickerProps {
  id?: string
  /** "HH:mm" 形式。 */
  value: string | undefined
  onChange: (time: string | undefined) => void
  placeholder?: string
  disabled?: boolean
  /** 分の選択肢の間隔(分)。既定は1分刻み(全60分)。 */
  minuteStep?: number
}

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

const HOURS = Array.from({ length: 24 }, (_, hour) => pad(hour))

function minuteOptions(step: number): string[] {
  const options: string[] = []
  for (let minute = 0; minute < 60; minute += step) options.push(pad(minute))
  return options
}

function splitTime(value: string | undefined): { hour: string | undefined; minute: string | undefined } {
  if (!value) return { hour: undefined, minute: undefined }
  const [hour, minute] = value.split(':')
  return { hour, minute }
}

/** 選択中の項目を持つ列の中央にスクロールする(初回表示時のみ)。 */
function useScrollToSelected(selectedValue: string | undefined) {
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!selectedValue || !containerRef.current) return
    const selectedButton = containerRef.current.querySelector<HTMLButtonElement>('[data-selected="true"]')
    selectedButton?.scrollIntoView({ block: 'center' })
    // 初回表示時にだけ中央へスクロールしたいので、依存配列は空にする。
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return containerRef
}

/**
 * 時・分をリストから選ぶ時刻入力。値は`<input type="time">`と同じ"HH:mm"文字列。
 * 出退勤時刻・休憩時刻など、時刻をリストから選びたい入力全般で使う。
 */
export function TimePicker({ id, value, onChange, placeholder = '時刻を選択', disabled, minuteStep = 1 }: TimePickerProps) {
  const [open, setOpen] = useState(false)
  const { hour, minute } = splitTime(value)
  const minutes = minuteOptions(minuteStep)

  const hourColumnRef = useScrollToSelected(hour)
  const minuteColumnRef = useScrollToSelected(minute)

  const selectHour = (nextHour: string) => {
    onChange(`${nextHour}:${minute ?? '00'}`)
  }

  const selectMinute = (nextMinute: string) => {
    onChange(`${hour ?? '00'}:${nextMinute}`)
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
          <Clock className="size-4 shrink-0 text-muted-foreground" />
          <span className="truncate">{value ?? placeholder}</span>
        </button>
      </PopoverTrigger>
      <PopoverContent className="flex w-32 gap-1 p-1" align="start">
        <div ref={hourColumnRef} className="flex max-h-60 flex-1 flex-col overflow-y-auto" role="listbox" aria-label="時">
          {HOURS.map((h) => (
            <button
              key={h}
              type="button"
              role="option"
              aria-selected={h === hour}
              data-selected={h === hour}
              className={cn(
                'rounded-sm px-2 py-1 text-center text-sm text-foreground outline-none hover:bg-accent focus-visible:bg-accent',
                h === hour && 'bg-primary text-primary-foreground hover:bg-primary',
              )}
              onClick={() => selectHour(h)}
            >
              {h}
            </button>
          ))}
        </div>
        <div ref={minuteColumnRef} className="flex max-h-60 flex-1 flex-col overflow-y-auto" role="listbox" aria-label="分">
          {minutes.map((m) => (
            <button
              key={m}
              type="button"
              role="option"
              aria-selected={m === minute}
              data-selected={m === minute}
              className={cn(
                'rounded-sm px-2 py-1 text-center text-sm text-foreground outline-none hover:bg-accent focus-visible:bg-accent',
                m === minute && 'bg-primary text-primary-foreground hover:bg-primary',
              )}
              onClick={() => selectMinute(m)}
            >
              {m}
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  )
}
