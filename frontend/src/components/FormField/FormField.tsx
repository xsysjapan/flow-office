import type { ReactNode } from 'react'
import './FormField.css'

export interface FormFieldProps {
  label: string
  htmlFor: string
  required?: boolean
  error?: string
  children: ReactNode
}

export function FormField({ label, htmlFor, required, error, children }: FormFieldProps) {
  return (
    <div className="fo-form-field">
      <div className="fo-form-field__label-row">
        <label htmlFor={htmlFor}>{label}</label>
        {required && (
          <span className="fo-form-field__required" aria-hidden="true">
            *
          </span>
        )}
      </div>
      {children}
      {error && <p className="fo-form-field__error">{error}</p>}
    </div>
  )
}
