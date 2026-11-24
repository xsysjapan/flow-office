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

describe('FormField description', () => {
  it('shows the description between the label and the input with an id from htmlFor', () => {
    render(
      <FormField label="タイトル" htmlFor="title" description="説明です。">
        <input id="title" />
      </FormField>,
    )
    const description = screen.getByText('説明です。')
    expect(description).toHaveAttribute('id', 'title-description')
    expect(description).toHaveClass('text-xs', 'text-muted-foreground')
    const label = screen.getByText('タイトル')
    const input = screen.getByLabelText('タイトル')
    expect(label.compareDocumentPosition(description) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(description.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(input).not.toHaveAttribute('aria-describedby')
  })

  it('renders no description element when omitted', () => {
    const { container } = render(
      <FormField label="タイトル" htmlFor="title">
        <input id="title" />
      </FormField>,
    )
    expect(container.querySelector('#title-description')).toBeNull()
  })
})
