import type { ReactNode } from 'react'
import './Badge.css'

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

export interface BadgeProps {
  tone?: BadgeTone
  children: ReactNode
}

export function Badge({ tone = 'neutral', children }: BadgeProps) {
  return (
    <span
      role="status"
      aria-label={typeof children === 'string' ? children : undefined}
      className={`fo-badge fo-badge--${tone}`}
    >
      {children}
    </span>
  )
}
