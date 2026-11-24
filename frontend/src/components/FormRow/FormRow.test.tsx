import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormRow } from './FormRow'

describe('FormRow', () => {
  it('uses 2 columns by default inside a container', () => {
    render(<FormRow>中身</FormRow>)
    const grid = screen.getByText('中身')
    expect(grid).toHaveClass('grid', 'grid-cols-1', 'gap-x-4', '@sm:grid-cols-2')
    expect(grid).not.toHaveClass('@xl:grid-cols-4')
    expect(grid.parentElement).toHaveClass('@container')
  })

  it('adds 4 columns for wide containers', () => {
    render(<FormRow columns={4}>中身</FormRow>)
    expect(screen.getByText('中身')).toHaveClass('@sm:grid-cols-2', '@xl:grid-cols-4')
  })
})
