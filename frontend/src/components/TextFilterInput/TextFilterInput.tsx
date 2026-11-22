import { useEffect, useId, useRef, useState } from 'react'
import type { ChangeEvent, CompositionEvent, FocusEvent, KeyboardEvent } from 'react'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { cn } from '../../lib/utils'

const DEBOUNCE_MS = 300

export interface TextFilterInputProps {
  label: string
  placeholder?: string
  /** URLの値。 */
  value: string | null
  onChange: (value: string | null) => void
  maxLength?: number
  className?: string
  id?: string
}

/**
 * 一覧の自由入力の絞り込み欄。入力停止後300msで反映し、IME変換中は反映しない。
 * Enter・blurでは待たずに即反映する。反映値は前後の空白を除き、空ならnull。
 */
export function TextFilterInput({
  label,
  placeholder,
  value,
  onChange,
  maxLength = 100,
  className,
  id,
}: TextFilterInputProps) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const [display, setDisplay] = useState(value ?? '')
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const composingRef = useRef(false)
  const emittedRef = useRef<string | null>(value)
  const onChangeRef = useRef(onChange)

  useEffect(() => {
    onChangeRef.current = onChange
  })

  const cancelTimer = () => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
  }

  const emit = (raw: string) => {
    const normalized = raw.trim() === '' ? null : raw.trim()
    if (normalized === emittedRef.current) return
    emittedRef.current = normalized
    onChangeRef.current(normalized)
  }

  const schedule = (raw: string) => {
    cancelTimer()
    timerRef.current = setTimeout(() => {
      timerRef.current = null
      emit(raw)
    }, DEBOUNCE_MS)
  }

  // 外からの変化(戻る・クリア・タブ)だけ表示を合わせる。反映待ち・変換中は合わせない。
  useEffect(() => {
    if (timerRef.current !== null || composingRef.current) return
    if (value !== emittedRef.current) {
      emittedRef.current = value
      setDisplay(value ?? '')
    }
  }, [value])

  useEffect(() => cancelTimer, [])

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const next = e.target.value
    setDisplay(next)
    if (composingRef.current) return
    schedule(next)
  }

  const handleCompositionStart = () => {
    composingRef.current = true
    cancelTimer()
  }

  const handleCompositionEnd = (e: CompositionEvent<HTMLInputElement>) => {
    composingRef.current = false
    schedule(e.currentTarget.value)
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return
    if (composingRef.current || e.nativeEvent.isComposing) return
    cancelTimer()
    emit(e.currentTarget.value)
  }

  const handleBlur = (e: FocusEvent<HTMLInputElement>) => {
    cancelTimer()
    emit(e.currentTarget.value)
  }

  return (
    <FormField label={label} htmlFor={inputId} className={cn('mb-0', className)}>
      <Input
        id={inputId}
        type="search"
        placeholder={placeholder}
        maxLength={maxLength}
        value={display}
        onChange={handleChange}
        onCompositionStart={handleCompositionStart}
        onCompositionEnd={handleCompositionEnd}
        onKeyDown={handleKeyDown}
        onBlur={handleBlur}
      />
    </FormField>
  )
}
