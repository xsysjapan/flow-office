import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as onboardingApi from '../../api/onboarding'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { LoginPage } from './LoginPage'

function renderPage(overrides: Partial<AuthContextValue> = {}) {
  const value: AuthContextValue = {
    user: null,
    status: 'unauthenticated',
    login: vi.fn().mockResolvedValue(undefined),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
    ...overrides,
  }

  render(
    <MemoryRouter initialEntries={['/login']}>
      <AuthContext.Provider value={value}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/onboarding" element={<p>オンボーディング</p>} />
        </Routes>
      </AuthContext.Provider>
    </MemoryRouter>,
  )

  return value
}

describe('LoginPage', () => {
  beforeEach(() => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({ needs_onboarding: false })
  })

  it('renders a login button', () => {
    renderPage()
    expect(screen.getByRole('button', { name: 'Microsoftでログイン' })).toBeInTheDocument()
  })

  it('calls login when the button is clicked', async () => {
    const { login } = renderPage()

    await userEvent.click(screen.getByRole('button', { name: 'Microsoftでログイン' }))

    expect(login).toHaveBeenCalledOnce()
  })

  it('shows an error message when login fails', async () => {
    const login = vi.fn().mockRejectedValue(new Error('network error'))
    renderPage({ login })

    await userEvent.click(screen.getByRole('button', { name: 'Microsoftでログイン' }))

    expect(await screen.findByText('ログインURLの取得に失敗しました。時間をおいて再度お試しください。')).toBeInTheDocument()
  })

  it('redirects to onboarding when onboarding is not yet complete', async () => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({ needs_onboarding: true })
    renderPage()

    expect(await screen.findByText('オンボーディング')).toBeInTheDocument()
  })
})
