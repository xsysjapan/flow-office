import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { ErrorMessage } from './ErrorMessage'

describe('ErrorMessage', () => {
  it('shows the message from an ApiError', () => {
    render(<ErrorMessage error={new ApiError(422, 'タイトルは必須です。')} />)
    expect(screen.getByRole('alert')).toHaveTextContent('タイトルは必須です。')
  })

  it('lists field validation errors from an ApiError', () => {
    render(
      <ErrorMessage
        error={new ApiError(422, 'validation failed', { title: ['タイトルは必須です。'] })}
      />,
    )
    expect(screen.getByText('タイトルは必須です。')).toBeInTheDocument()
  })

  it('shows the message from a plain Error', () => {
    render(<ErrorMessage error={new Error('通信エラー')} />)
    expect(screen.getByRole('alert')).toHaveTextContent('通信エラー')
  })

  it('falls back to a default message for unknown errors', () => {
    render(<ErrorMessage error="oops" />)
    expect(screen.getByRole('alert')).toHaveTextContent('予期しないエラーが発生しました。')
  })

  it('supports a custom fallback message', () => {
    render(<ErrorMessage error="oops" fallback="読み込みに失敗しました。" />)
    expect(screen.getByRole('alert')).toHaveTextContent('読み込みに失敗しました。')
  })
})
