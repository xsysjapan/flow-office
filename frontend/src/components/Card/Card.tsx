import type { ReactNode } from 'react'
import { Card as UiCard, CardAction, CardContent, CardHeader, CardTitle } from '../ui/card'

export interface CardProps {
  id?: string
  title?: ReactNode
  actions?: ReactNode
  navigation?: ReactNode
  children: ReactNode
}

export function Card({ id, title, actions, navigation, children }: CardProps) {
  return (
    <UiCard id={id}>
      {(title || actions || navigation) && (
        <CardHeader className="flex-col items-stretch md:flex-row md:items-center">
          {navigation && <CardAction className="mb-3 md:mb-0 md:order-3 md:w-auto">{navigation}</CardAction>}
          {(title || actions) && (
            <div className="flex min-h-9 w-full items-center justify-between gap-3 md:flex-1">
              {title && <CardTitle>{title}</CardTitle>}
              {actions && <CardAction>{actions}</CardAction>}
            </div>
          )}
        </CardHeader>
      )}
      <CardContent>{children}</CardContent>
    </UiCard>
  )
}
