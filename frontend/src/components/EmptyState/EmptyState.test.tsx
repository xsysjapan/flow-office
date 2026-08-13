import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { EmptyState } from './EmptyState'

describe('EmptyState', () => {
  it('shows the title', () => {
    render(<EmptyState title="グループがまだありません。" />)
    expect(screen.getByText('グループがまだありません。')).toBeInTheDocument()
  })

  it('shows an optional description', () => {
    render(<EmptyState title="タイトル" description="補足説明です。" />)
    expect(screen.getByText('補足説明です。')).toBeInTheDocument()
  })

  it('does not render a description when omitted', () => {
    render(<EmptyState title="タイトル" />)
    expect(screen.queryByText('補足説明です。')).not.toBeInTheDocument()
  })

  it('renders an optional action', () => {
    render(<EmptyState title="タイトル" action={<button type="button">グループを作成</button>} />)
    expect(screen.getByRole('button', { name: 'グループを作成' })).toBeInTheDocument()
  })
})
