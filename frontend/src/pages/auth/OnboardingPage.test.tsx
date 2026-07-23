import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as onboardingApi from '../../api/onboarding'
import { AuthContext } from '../../auth/AuthContext'
import { OnboardingPage } from './OnboardingPage'

const applySession = vi.fn()
const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider
        value={{
          user: null,
          status: 'unauthenticated',
          login: vi.fn(),
          completeLogin: vi.fn(),
          applySession,
          logout: vi.fn(),
        }}
      >
        <OnboardingPage />
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('OnboardingPage', () => {
  beforeEach(() => {
    applySession.mockReset()
    navigate.mockReset()
  })

  it('shows the SSO mode by default with all M365 fields required', () => {
    renderPage()

    expect(screen.getByLabelText('テナントID')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '保存してMicrosoftにログインする' })).toBeDisabled()
  })

  it('submits SSO onboarding and navigates the browser to the returned redirect url', async () => {
    vi.spyOn(onboardingApi, 'startOnboardingSso').mockResolvedValue({
      redirect_url: 'https://login.microsoftonline.com/authorize?state=onboarding-sso-link',
    })

    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, href: '' },
    })

    renderPage()

    await userEvent.type(screen.getByLabelText('テナントID'), 'tenant-1')
    await userEvent.type(screen.getByLabelText('クライアントID'), 'client-1')
    await userEvent.type(screen.getByLabelText('クライアントシークレット'), 'secret-1')

    await userEvent.click(screen.getByRole('button', { name: '保存してMicrosoftにログインする' }))

    await waitFor(() =>
      expect(window.location.href).toBe('https://login.microsoftonline.com/authorize?state=onboarding-sso-link'),
    )
    expect(applySession).not.toHaveBeenCalled()

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })

  it('switches to local mode and submits a local admin, then logs in', async () => {
    const user = { id: 'user-1', name: 'テスト管理者', email: 'admin@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null }
    vi.spyOn(onboardingApi, 'completeOnboardingLocal').mockResolvedValue({ token: 'test-token', user })

    renderPage()

    await userEvent.click(screen.getByRole('button', { name: 'ローカルパスワードで作成する' }))

    await userEvent.type(screen.getByLabelText('氏名'), 'テスト管理者')
    await userEvent.type(screen.getByLabelText('メールアドレス'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('パスワード(8文字以上)'), 'correct-horse-battery-staple')
    await userEvent.type(screen.getByLabelText('パスワード(確認)'), 'correct-horse-battery-staple')

    await userEvent.click(screen.getByRole('button', { name: 'セットアップを完了する' }))

    await waitFor(() =>
      expect(onboardingApi.completeOnboardingLocal).toHaveBeenCalledWith({
        admin_name: 'テスト管理者',
        admin_email: 'admin@example.com',
        admin_password: 'correct-horse-battery-staple',
      }),
    )
    await waitFor(() => expect(applySession).toHaveBeenCalledWith('test-token', user))
    expect(navigate).toHaveBeenCalledWith('/', { replace: true })
  })

  it('disables the local submit button until passwords match', async () => {
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: 'ローカルパスワードで作成する' }))
    await userEvent.type(screen.getByLabelText('氏名'), 'テスト管理者')
    await userEvent.type(screen.getByLabelText('メールアドレス'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('パスワード(8文字以上)'), 'correct-horse-battery-staple')
    await userEvent.type(screen.getByLabelText('パスワード(確認)'), 'different-password')

    expect(screen.getByRole('button', { name: 'セットアップを完了する' })).toBeDisabled()
  })
})
