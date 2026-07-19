import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext } from '../../auth/AuthContext'
import { LoginPage } from './LoginPage'

function withAuth(login: () => Promise<void>) {
  return function Decorator() {
    return (
      <MemoryRouter initialEntries={['/login']}>
        <AuthContext.Provider
          value={{ user: null, status: 'unauthenticated', login, completeLogin: fn(), applySession: fn(), logout: fn() }}
        >
          <LoginPage />
        </AuthContext.Provider>
      </MemoryRouter>
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
