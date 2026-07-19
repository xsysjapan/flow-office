import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { AuthContext } from '../../auth/AuthContext'
import { OnboardingPage } from './OnboardingPage'

function withProviders() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider
          value={{
            user: null,
            status: 'unauthenticated',
            login: fn(),
            completeLogin: fn(),
            applySession: fn(),
            logout: fn(),
          }}
        >
          <OnboardingPage />
        </AuthContext.Provider>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Auth/OnboardingPage',
  component: OnboardingPage,
  tags: ['autodocs'],
} satisfies Meta<typeof OnboardingPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withProviders(),
}
