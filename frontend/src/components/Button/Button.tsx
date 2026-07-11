import type { ButtonHTMLAttributes } from 'react'
import { Loader2 } from 'lucide-react'
import { Button as UiButton, type ButtonProps as UiButtonProps } from '../ui/button'

export type ButtonVariant = 'primary' | 'secondary' | 'danger'

const variantMap: Record<ButtonVariant, UiButtonProps['variant']> = {
  primary: 'default',
  secondary: 'secondary',
  danger: 'destructive',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  isLoading?: boolean
}

export function Button({ variant = 'primary', isLoading = false, disabled, children, ...props }: ButtonProps) {
  return (
    <UiButton variant={variantMap[variant]} disabled={disabled || isLoading} {...props}>
      {isLoading && <Loader2 className="animate-spin" aria-hidden="true" />}
      {isLoading ? '処理中...' : children}
    </UiButton>
  )
}
