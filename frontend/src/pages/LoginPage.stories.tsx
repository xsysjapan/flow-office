import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { AuthContext } from '../auth/AuthContext'
import { LoginPage } from './LoginPage'

function withAuth(login: () => Promise<void>) {
  return function Decorator() {
    return (
      <AuthContext.Provider
        value={{ user: null, status: 'unauthenticated', login, completeLogin: fn(), logout: fn() }}
      >
        <LoginPage />
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/LoginPage',
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
