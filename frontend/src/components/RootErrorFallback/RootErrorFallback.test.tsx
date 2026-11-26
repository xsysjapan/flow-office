import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RootErrorFallback } from './RootErrorFallback'

describe('RootErrorFallback', () => {
  const original = window.location

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  it('shows the message and reloads the page when the button is pressed', async () => {
    const reload = vi.fn()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, reload } })
    render(<RootErrorFallback />)

    expect(screen.getByText('画面の表示中にエラーが発生しました。')).toBeInTheDocument()
    await userEvent.setup().click(screen.getByRole('button', { name: '再読み込み' }))

    expect(reload).toHaveBeenCalledTimes(1)
  })
})
