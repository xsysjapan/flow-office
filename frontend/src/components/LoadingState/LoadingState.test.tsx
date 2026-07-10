import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { LoadingState } from './LoadingState'

describe('LoadingState', () => {
  it('shows a default label', () => {
    render(<LoadingState />)
    expect(screen.getByRole('status')).toHaveTextContent('読み込み中...')
  })

  it('shows a custom label', () => {
    render(<LoadingState label="送信中..." />)
    expect(screen.getByRole('status')).toHaveTextContent('送信中...')
  })
})
