import type { ReactNode } from 'react'
import { Card as UiCard, CardAction, CardContent, CardHeader, CardTitle } from '../ui/card'

export interface CardProps {
  id?: string
  title?: ReactNode
  actions?: ReactNode
  children: ReactNode
}

export function Card({ id, title, actions, children }: CardProps) {
  return (
    <UiCard id={id}>
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
