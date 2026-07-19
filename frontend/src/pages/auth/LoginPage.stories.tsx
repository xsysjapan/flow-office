import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext } from '../../auth/AuthContext'
import { LoginPage } from './LoginPage'

function withAuth(login: () => Promise<void>, ssoConfigured = true) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['onboarding-status'], { needs_onboarding: false, sso_configured: ssoConfigured })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/login']}>
          <AuthContext.Provider
            value={{ user: null, status: 'unauthenticated', login, completeLogin: fn(), applySession: fn(), logout: fn() }}
          >
            <LoginPage />
          </AuthContext.Provider>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Auth/LoginPage',
  component: LoginPage,
  tags: ['autodocs'],
} satisfies Meta<typeof LoginPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withAuth(fn(() => Promise.resolve())),
}

export const LoginFailed: Story = {
  render: withAuth(fn(() => Promise.reject(new Error('failed')))),
}

export const LocalPasswordMode: Story = {
  render: withAuth(fn(() => Promise.resolve()), false),
}
