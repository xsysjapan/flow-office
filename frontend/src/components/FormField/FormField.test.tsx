import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Input } from '../ui/input'
import { Textarea } from '../ui/textarea'
import { NativeSelect } from '../ui/native-select'
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
        <Input id="title" />
      </FormField>,
    )
    const description = screen.getByText('説明です。')
    expect(description).toHaveAttribute('id', 'title-description')
    expect(description).toHaveClass('text-xs', 'text-muted-foreground')
    const label = screen.getByText('タイトル')
    const input = screen.getByLabelText('タイトル')
    expect(label.compareDocumentPosition(description) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(description.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(input).toHaveAttribute('aria-describedby', 'title-description')
    expect(input).toHaveAccessibleDescription('説明です。')
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

describe('FormField aria attributes', () => {
  it('sets aria-required on a native input when required, without changing the accessible label', () => {
    render(
      <FormField label="氏名" htmlFor="name" required>
        <Input id="name" />
      </FormField>,
    )
    const input = screen.getByLabelText('氏名')
    expect(input).toHaveAttribute('aria-required', 'true')
    expect(input).not.toHaveAttribute('required')
    expect(screen.getByText('必須')).toHaveAttribute('id', 'name-required')
    expect(screen.getByText('必須')).toHaveClass('sr-only')
    expect(screen.getByText('*')).toHaveAttribute('aria-hidden', 'true')
  })

  it('does not set aria-required when not required', () => {
    render(
      <FormField label="氏名" htmlFor="name">
        <Input id="name" />
      </FormField>,
    )
    expect(screen.getByLabelText('氏名')).not.toHaveAttribute('aria-required')
    expect(screen.getByLabelText('氏名')).not.toHaveAttribute('aria-invalid')
    expect(screen.getByLabelText('氏名')).not.toHaveAttribute('aria-describedby')
  })

  it('links the error to the input with aria-invalid and aria-describedby (after the description)', () => {
    render(
      <FormField label="氏名" htmlFor="name" description="説明です。" error="入力してください。">
        <Input id="name" />
      </FormField>,
    )
    const input = screen.getByLabelText('氏名')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAttribute('aria-describedby', 'name-description name-error')
    expect(input).toHaveAccessibleDescription('説明です。 入力してください。')
    expect(screen.getByText('入力してください。')).toHaveAttribute('id', 'name-error')
  })

  it('works for textarea and native select', () => {
    render(
      <>
        <FormField label="備考" htmlFor="memo" required error="エラーです。">
          <Textarea id="memo" />
        </FormField>
        <FormField label="種別" htmlFor="kind" required description="説明です。">
          <NativeSelect id="kind">
            <option value="a">A</option>
          </NativeSelect>
        </FormField>
      </>,
    )
    expect(screen.getByLabelText('備考')).toHaveAttribute('aria-required', 'true')
    expect(screen.getByLabelText('備考')).toHaveAccessibleDescription(/エラーです。/)
    expect(screen.getByLabelText('種別')).toHaveAttribute('aria-required', 'true')
    expect(screen.getByLabelText('種別')).toHaveAccessibleDescription('説明です。')
  })

  it('does not set attributes on an input whose id differs from htmlFor', () => {
    render(
      <FormField label="氏名" htmlFor="name" required description="説明です。" error="エラーです。">
        <Input id="name" />
        <Input id="other" aria-label="別の入力" />
      </FormField>,
    )
    const other = screen.getByLabelText('別の入力')
    expect(other).not.toHaveAttribute('aria-required')
    expect(other).not.toHaveAttribute('aria-invalid')
    expect(other).not.toHaveAttribute('aria-describedby')
  })

  it('does nothing outside a FormField', () => {
    render(<Input aria-label="単独" id="alone" />)
    const input = screen.getByLabelText('単独')
    expect(input).not.toHaveAttribute('aria-required')
    expect(input).not.toHaveAttribute('aria-describedby')
  })

  it('appends an explicit aria-describedby and prefers explicit aria-required/aria-invalid', () => {
    render(
      <>
        <p id="extra">補足です。</p>
        <FormField label="氏名" htmlFor="name" required error="エラーです。">
          <Input id="name" aria-describedby="extra" aria-required="false" aria-invalid="false" />
        </FormField>
      </>,
    )
    const input = screen.getByLabelText('氏名')
    expect(input).toHaveAttribute('aria-describedby', 'name-error extra')
    expect(input).toHaveAttribute('aria-required', 'false')
    expect(input).toHaveAttribute('aria-invalid', 'false')
  })
})
