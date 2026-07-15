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
          {(title || actions) && (
            <div className="flex w-full items-center justify-between gap-3 md:flex-1">
              {title && <CardTitle>{title}</CardTitle>}
              {actions && <CardAction>{actions}</CardAction>}
            </div>
          )}
          {navigation && <CardAction className="w-full md:w-auto">{navigation}</CardAction>}
        </CardHeader>
      )}
      <CardContent>{children}</CardContent>
    </UiCard>
  )
}
