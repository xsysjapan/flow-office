import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AdminDashboardPage } from './AdminDashboardPage'
import { adminNavGroups } from '../../components/AdminLayout/adminNavGroups'

const mockUser: User = {
  id: 'user-1',
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '人事部',
  job_title: '人事担当',
  employment_status: 'active',
  last_login_at: null,
  effective_features: ['administration.users', 'administration.settings', 'attendance.entry', 'attendance.timesheet', 'workflow.requests', 'paid_leave.requests', 'backoffice.expenses'],
  effective_permissions: adminNavGroups.flatMap((group) => group.items.flatMap((item) => [item.permission, ...(item.permissions ?? [])].filter((permission): permission is string => Boolean(permission)))),
}

const mockAuthValue: AuthContextValue = {
  user: mockUser,
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const hrAuthValue: AuthContextValue = {
  ...mockAuthValue,
  user: { ...mockUser, effective_features: ['administration.users'], effective_permissions: ['user.view', 'group.view'] },
}

const meta = {
  title: 'Pages/Admin/AdminDashboardPage',
  component: AdminDashboardPage,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <AuthContext.Provider value={mockAuthValue}>
        <MemoryRouter>
          <Story />
        </MemoryRouter>
      </AuthContext.Provider>
    ),
  ],
} satisfies Meta<typeof AdminDashboardPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const AsHrStaff: Story = {
  decorators: [(Story) => <AuthContext.Provider value={hrAuthValue}>{<Story />}</AuthContext.Provider>],
}
