import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormField } from './FormField'

describe('FormField', () => {
  it('associates the label with the input via htmlFor/id', () => {
    render(
      <FormField label="タイトル" htmlFor="title">
        <input id="title" />
      </FormField>,
    )
    expect(screen.getByLabelText('タイトル')).toBeInTheDocument()
  })

  it('shows a required marker without polluting the accessible label', () => {
    render(
      <FormField label="承認者" htmlFor="approver" required>
        <input id="approver" />
      </FormField>,
    )
    expect(screen.getByText('*')).toBeInTheDocument()
    expect(screen.getByLabelText('承認者')).toBeInTheDocument()
  })

  it('applies className to the outer wrapper (overrides the default margin)', () => {
    const { container } = render(
      <FormField label="タイトル" htmlFor="title" className="mb-0">
        <input id="title" />
      </FormField>,
    )
    const wrapper = container.firstElementChild
    expect(wrapper).toHaveClass('mb-0')
    expect(wrapper).not.toHaveClass('mb-4')
  })

  it('shows an error message', () => {
    render(
      <FormField label="タイトル" htmlFor="title" error="タイトルは必須です。">
        <input id="title" />
      </FormField>,
    )
    expect(screen.getByText('タイトルは必須です。')).toBeInTheDocument()
  })
})
