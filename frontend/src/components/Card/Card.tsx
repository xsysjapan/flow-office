import type { ReactNode } from 'react'
import { Card as UiCard, CardAction, CardContent, CardHeader, CardTitle } from '../ui/card'

export interface CardProps {
  title?: ReactNode
  actions?: ReactNode
  children: ReactNode
}

export function Card({ title, actions, children }: CardProps) {
  return (
    <UiCard>
      {(title || actions) && (
        <CardHeader>
          {title && <CardTitle>{title}</CardTitle>}
          {actions && <CardAction>{actions}</CardAction>}
        </CardHeader>
      )}
      <CardContent>{children}</CardContent>
    </UiCard>
  )
}
