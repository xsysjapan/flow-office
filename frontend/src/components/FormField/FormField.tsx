import type { ReactNode } from 'react'
import { Label } from '../ui/label'
import { cn } from '../../lib/utils'

export interface FormFieldProps {
  label: string
  htmlFor: string
  required?: boolean
  error?: string
  children: ReactNode
  className?: string
}

export function FormField({ label, htmlFor, required, error, children, className }: FormFieldProps) {
  return (
    <div className={cn('mb-4 flex flex-col gap-1.5 text-left', className)}>
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
