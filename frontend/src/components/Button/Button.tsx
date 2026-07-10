import type { ButtonHTMLAttributes } from 'react'
import './Button.css'

export type ButtonVariant = 'primary' | 'secondary' | 'danger'

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  isLoading?: boolean
}

export function Button({ variant = 'primary', isLoading = false, disabled, children, ...props }: ButtonProps) {
  return (
    <button
      type="button"
      className={`fo-button fo-button--${variant}`}
      disabled={disabled || isLoading}
      {...props}
    >
      {isLoading ? '処理中...' : children}
    </button>
  )
}
