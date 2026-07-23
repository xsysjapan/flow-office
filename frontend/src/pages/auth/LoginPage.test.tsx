import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../../api/auth'
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
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/login']}>
        <AuthContext.Provider value={value}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/onboarding" element={<p>オンボーディング</p>} />
            <Route path="/" element={<p>ホーム</p>} />
          </Routes>
        </AuthContext.Provider>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return value
}

describe('LoginPage', () => {
  beforeEach(() => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({
      needs_onboarding: false,
      sso_configured: true,
    })
  })

  it('renders a login button when SSO is configured', async () => {
    renderPage()
    expect(await screen.findByRole('button', { name: 'Microsoftでログイン' })).toBeInTheDocument()
  })

  it('calls login when the button is clicked', async () => {
    const { login } = renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Microsoftでログイン' }))

    expect(login).toHaveBeenCalledOnce()
  })

  it('shows an error message when login fails', async () => {
    const login = vi.fn().mockRejectedValue(new Error('network error'))
    renderPage({ login })

    await userEvent.click(await screen.findByRole('button', { name: 'Microsoftでログイン' }))

    expect(await screen.findByText('ログインURLの取得に失敗しました。時間をおいて再度お試しください。')).toBeInTheDocument()
  })

  it('redirects to onboarding when onboarding is not yet complete', async () => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({
      needs_onboarding: true,
      sso_configured: false,
    })
    renderPage()

    expect(await screen.findByText('オンボーディング')).toBeInTheDocument()
  })

  it('shows a local login form when SSO is not configured', async () => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({
      needs_onboarding: false,
      sso_configured: false,
    })
    renderPage()

    expect(await screen.findByLabelText('メールアドレス')).toBeInTheDocument()
    expect(screen.getByLabelText('パスワード')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Microsoftでログイン' })).not.toBeInTheDocument()
  })

  it('logs in with local credentials and navigates home', async () => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({
      needs_onboarding: false,
      sso_configured: false,
    })
    const user = { id: 'user-1', name: 'テスト太郎', email: 'taro@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null }
    vi.spyOn(authApi, 'localLogin').mockResolvedValue({ token: 'test-token', user })
    const applySession = vi.fn()

    renderPage({ applySession })

    await userEvent.type(await screen.findByLabelText('メールアドレス'), 'taro@example.com')
    await userEvent.type(screen.getByLabelText('パスワード'), 'correct-horse-battery-staple')
    await userEvent.click(screen.getByRole('button', { name: 'ログイン' }))

    await waitFor(() => expect(authApi.localLogin).toHaveBeenCalledWith('taro@example.com', 'correct-horse-battery-staple'))
    await waitFor(() => expect(applySession).toHaveBeenCalledWith('test-token', user))
    expect(await screen.findByText('ホーム')).toBeInTheDocument()
  })

  it('shows an error message when local login fails', async () => {
    vi.spyOn(onboardingApi, 'fetchOnboardingStatus').mockResolvedValue({
      needs_onboarding: false,
      sso_configured: false,
    })
    vi.spyOn(authApi, 'localLogin').mockRejectedValue(new Error('メールアドレスまたはパスワードが正しくありません。'))

    renderPage()

    await userEvent.type(await screen.findByLabelText('メールアドレス'), 'taro@example.com')
    await userEvent.type(screen.getByLabelText('パスワード'), 'wrong-password')
    await userEvent.click(screen.getByRole('button', { name: 'ログイン' }))

    expect(await screen.findByText('メールアドレスまたはパスワードが正しくありません。')).toBeInTheDocument()
  })
})
