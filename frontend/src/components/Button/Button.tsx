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
  /** Linkなど単一の子要素をボタン風に見せる(遷移リンクをボタンとして表示する用途のみ)。isLoadingとは併用しない。 */
  asChild?: boolean
  /** 'icon'は正方形のアイコン専用ボタン(前日/翌日のようなページ送りなど)。必ずaria-labelを付ける。 */
  size?: UiButtonProps['size']
}

export function Button({
  variant = 'primary',
  isLoading = false,
  disabled,
  asChild = false,
  size,
  children,
  ...props
}: ButtonProps) {
  if (asChild) {
    return (
      <UiButton asChild variant={variantMap[variant]} size={size} {...props}>
        {children}
      </UiButton>
    )
  }

  return (
    <UiButton variant={variantMap[variant]} size={size} disabled={disabled || isLoading} {...props}>
      {isLoading && <Loader2 className="animate-spin" aria-hidden="true" />}
      {isLoading ? '処理中...' : children}
    </UiButton>
  )
}
