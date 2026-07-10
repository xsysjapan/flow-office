import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Badge } from './Badge'

describe('Badge', () => {
  it('renders the given label', () => {
    render(<Badge tone="success">承認済み</Badge>)
    expect(screen.getByText('承認済み')).toBeInTheDocument()
  })

  it('applies the tone class', () => {
    render(<Badge tone="danger">取消</Badge>)
    expect(screen.getByText('取消')).toHaveClass('fo-badge--danger')
  })

  it('defaults to the neutral tone', () => {
    render(<Badge>下書き</Badge>)
    expect(screen.getByText('下書き')).toHaveClass('fo-badge--neutral')
  })
})
