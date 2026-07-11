import type { ReactNode } from 'react'
import { Label } from '../ui/label'

export interface FormFieldProps {
  label: string
  htmlFor: string
  required?: boolean
  error?: string
  children: ReactNode
}

export function FormField({ label, htmlFor, required, error, children }: FormFieldProps) {
  return (
    <div className="mb-4 flex flex-col gap-1.5 text-left">
      <div className="flex items-baseline gap-1">
        <Label htmlFor={htmlFor}>{label}</Label>
        {required && (
          <span className="text-xs text-destructive" aria-hidden="true">
            *
          </span>
        )}
      </div>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  )
}
