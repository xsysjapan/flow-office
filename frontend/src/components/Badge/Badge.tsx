import type { ReactNode } from 'react'
import { Badge as UiBadge, type BadgeProps as UiBadgeProps } from '../ui/badge'

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

const toneMap: Record<BadgeTone, UiBadgeProps['variant']> = {
  neutral: 'neutral',
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'destructive',
}

export interface BadgeProps {
  tone?: BadgeTone
  children: ReactNode
}

export function Badge({ tone = 'neutral', children }: BadgeProps) {
  return (
    <UiBadge
      role="status"
      aria-label={typeof children === 'string' ? children : undefined}
      variant={toneMap[tone]}
    >
      {children}
    </UiBadge>
  )
}
