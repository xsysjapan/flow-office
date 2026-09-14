import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ErrorBoundary } from './ErrorBoundary'

function Boom(): never {
  throw new Error('boom')
}

describe('ErrorBoundary', () => {
  it('renders its children when there is no error', () => {
    render(
      <ErrorBoundary>
        <p>正常な子要素</p>
      </ErrorBoundary>,
    )

    expect(screen.getByText('正常な子要素')).toBeInTheDocument()
  })

  it('renders a fallback with a reload button when a child throws during render', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})

    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    )

    expect(screen.getByText('予期しないエラーが発生しました。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '再読み込み' })).toBeInTheDocument()

    vi.restoreAllMocks()
  })

  it('reloads the page when the reload button is clicked', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const reloadMock = vi.fn()
    Object.defineProperty(window, 'location', {
      value: { ...window.location, reload: reloadMock },
      writable: true,
    })

    const { default: userEvent } = await import('@testing-library/user-event')
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    )

    await userEvent.click(screen.getByRole('button', { name: '再読み込み' }))

    expect(reloadMock).toHaveBeenCalledOnce()

    vi.restoreAllMocks()
  })
})
