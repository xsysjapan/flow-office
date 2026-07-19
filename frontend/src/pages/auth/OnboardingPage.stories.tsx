import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { AuthContext } from '../../auth/AuthContext'
import { OnboardingPage } from './OnboardingPage'

const meta = {
  title: 'Pages/Auth/OnboardingPage',
  component: OnboardingPage,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
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
        <Story />
      </AuthContext.Provider>
    ),
  ],
} satisfies Meta<typeof OnboardingPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
