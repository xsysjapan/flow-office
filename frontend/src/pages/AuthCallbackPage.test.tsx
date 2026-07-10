import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthCallbackPage } from './AuthCallbackPage'

const completeLogin = vi.fn()

vi.mock('../auth/useAuth', () => ({
  useAuth: () => ({ completeLogin }),
}))

function renderWithRoute(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/auth/callback" element={<AuthCallbackPage />} />
        <Route path="/" element={<p>ホーム</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('AuthCallbackPage', () => {
  beforeEach(() => {
    completeLogin.mockReset()
  })

  it('exchanges the code from the query string and navigates home', async () => {
    completeLogin.mockResolvedValue(undefined)

    renderWithRoute('/auth/callback?code=abc123')

    await waitFor(() => expect(completeLogin).toHaveBeenCalledWith('abc123'))
    await waitFor(() => expect(screen.getByText('ホーム')).toBeInTheDocument())
  })

  it('shows an error when no code is present', async () => {
    renderWithRoute('/auth/callback')

    expect(await screen.findByText('ログインコードが見つかりませんでした。')).toBeInTheDocument()
    expect(completeLogin).not.toHaveBeenCalled()
  })

  it('shows an error when the exchange fails', async () => {
    completeLogin.mockRejectedValue(new Error('failed'))

    renderWithRoute('/auth/callback?code=bad-code')

    expect(await screen.findByText('ログインに失敗しました。もう一度お試しください。')).toBeInTheDocument()
  })
})
