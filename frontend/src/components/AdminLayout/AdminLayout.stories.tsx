import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AdminLayout } from './AdminLayout'

const mockUser: User = {
  id: 1,
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '人事部',
  job_title: '人事担当',
  employment_status: 'active',
  last_login_at: null,
  roles: ['admin'],
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
  user: { ...mockUser, roles: ['hr_staff'] },
}

const meta = {
  title: 'Components/AdminLayout',
  component: AdminLayout,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <AuthContext.Provider value={mockAuthValue}>
        <MemoryRouter initialEntries={['/admin']}>
          <Routes>
            <Route path="/admin" element={<Story />}>
              <Route index element={<p>管理メニューの中身がここに表示されます。</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    ),
  ],
} satisfies Meta<typeof AdminLayout>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const AsHrStaff: Story = {
  decorators: [(Story) => <AuthContext.Provider value={hrAuthValue}>{<Story />}</AuthContext.Provider>],
}
