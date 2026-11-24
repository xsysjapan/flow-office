import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormSection } from './FormSection'

describe('FormSection', () => {
  it('names the region by the title and defaults to h3', () => {
    render(<FormSection title="基本設定">中身</FormSection>)
    expect(screen.getByRole('region', { name: '基本設定' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 3, name: '基本設定' })).toBeInTheDocument()
  })

  it('draws a divider under the heading', () => {
    render(<FormSection title="基本設定">中身</FormSection>)
    expect(screen.getByRole('heading', { name: '基本設定' })).toHaveClass('border-b', 'pb-2')
  })

  it('renders h2 when headingLevel is 2', () => {
    render(
      <FormSection title="基本設定" headingLevel={2}>
        中身
      </FormSection>,
    )
    expect(screen.getByRole('heading', { level: 2, name: '基本設定' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 3 })).not.toBeInTheDocument()
  })

  it('shows the description and children', () => {
    render(
      <FormSection title="基本設定" description="説明です。">
        <input aria-label="項目" />
      </FormSection>,
    )
    expect(screen.getByText('説明です。')).toHaveClass('mt-1', 'text-xs', 'text-muted-foreground')
    expect(screen.getByRole('region', { name: '基本設定' })).toContainElement(screen.getByLabelText('項目'))
  })

  it('omits the description when not given', () => {
    const { container } = render(<FormSection title="基本設定">中身</FormSection>)
    expect(container.querySelector('p')).toBeNull()
    expect(screen.getByRole('region')).toHaveClass('mt-8', 'first:mt-0')
  })
})
