import { useEffect, useMemo, type ReactNode } from 'react'
import { Label } from '../ui/label'
import { cn } from '../../lib/utils'
import { FormFieldContext, type FormFieldContextValue } from './formFieldControl'

export interface FormFieldProps {
  label: string
  htmlFor: string
  required?: boolean
  error?: string
  /** ラベルの下・入力の上に出す説明文。句点で終える。 */
  description?: ReactNode
  children: ReactNode
  className?: string
}

export function FormField({ label, htmlFor, required, error, description, children, className }: FormFieldProps) {
  const hasDescription = Boolean(description)
  const hasError = Boolean(error)
  const contextValue = useMemo<FormFieldContextValue>(
    () => ({
      id: htmlFor,
      required: Boolean(required),
      describedBy:
        [hasDescription ? `${htmlFor}-description` : undefined, hasError ? `${htmlFor}-error` : undefined]
          .filter(Boolean)
          .join(' ') || undefined,
      invalid: hasError,
      requiredNoteId: required ? `${htmlFor}-required` : undefined,
    }),
    [htmlFor, required, hasDescription, hasError],
  )

  // 開発時だけ、htmlForに一致する入力が無い(属性が結ばれない)ことを知らせる。
  useEffect(() => {
    if (!import.meta.env.DEV || import.meta.env.MODE === 'test') return
    if (!document.getElementById(htmlFor)) {
      console.warn(`FormField: htmlFor="${htmlFor}" に一致するid の要素がありません(必須・説明文・エラーが入力に結ばれません)。`)
    }
  }, [htmlFor])

  return (
    <FormFieldContext.Provider value={contextValue}>
      <div className={cn('mb-4 flex flex-col gap-1.5 text-left', className)}>
        <div className="flex items-baseline gap-1">
          <Label htmlFor={htmlFor}>{label}</Label>
          {required && (
            <span className="text-xs text-destructive" aria-hidden="true">
              *
            </span>
          )}
          {required && (
            <span id={`${htmlFor}-required`} className="sr-only">
              必須
            </span>
          )}
        </div>
        {description && (
          <p id={`${htmlFor}-description`} className="text-xs text-muted-foreground">
            {description}
          </p>
        )}
        {children}
        {error && (
          <p id={`${htmlFor}-error`} className="text-xs text-destructive">
            {error}
          </p>
        )}
      </div>
    </FormFieldContext.Provider>
  )
}
