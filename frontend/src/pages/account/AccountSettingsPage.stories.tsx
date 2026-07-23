import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AccountSettingsPage } from './AccountSettingsPage'

const baseUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  sso_linked: false,
}

function withUser(user: User) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: fn(),
    completeLogin: fn(),
    applySession: fn(),
    logout: fn(),
  }

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <AccountSettingsPage />
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Account/AccountSettingsPage',
  component: AccountSettingsPage,
} satisfies Meta<typeof AccountSettingsPage>

export default meta
type Story = StoryObj<typeof meta>

export const NotLinked: Story = {
  render: withUser(baseUser),
}

export const Linked: Story = {
  render: withUser({ ...baseUser, sso_linked: true }),
}
