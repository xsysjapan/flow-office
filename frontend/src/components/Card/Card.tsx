import type { ReactNode } from 'react'
import './Card.css'

export interface CardProps {
  title?: ReactNode
  actions?: ReactNode
  children: ReactNode
}

export function Card({ title, actions, children }: CardProps) {
  return (
    <section className="fo-card">
      {(title || actions) && (
        <header className="fo-card__header">
          {title && <h2>{title}</h2>}
          {actions && <div className="fo-card__actions">{actions}</div>}
        </header>
      )}
      <div className="fo-card__body">{children}</div>
    </section>
  )
}
