import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormLayout } from './FormLayout'

describe('FormLayout', () => {
  it('limits the width and is a container query root', () => {
    render(<FormLayout>中身</FormLayout>)
    const el = screen.getByText('中身')
    expect(el).toHaveClass('max-w-2xl', '@container', 'w-full', 'flex', 'flex-col')
  })

  it('merges className', () => {
    render(<FormLayout className="mt-2">中身</FormLayout>)
    expect(screen.getByText('中身')).toHaveClass('max-w-2xl', 'mt-2')
  })
})
