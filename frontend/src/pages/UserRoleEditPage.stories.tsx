import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { Role, User } from '../api/types'
import { UserRoleEditPage } from './UserRoleEditPage'

const targetUser: User = {
  id: 1,
  name: '山田太郎',
  email: 'yamada@example.com',
  department: '総務部',
  job_title: '主任',
  employment_status: 'active',
  roles: ['employee', 'general_affairs_staff'],
  last_login_at: '2026-07-08T09:00:00+09:00',
}

const roles: Role[] = [
  { id: 1, code: 'employee', name: '一般社員' },
  { id: 2, code: 'backoffice_staff', name: 'バックオフィス担当者' },
  { id: 3, code: 'accounting_staff', name: '経理担当者' },
  { id: 4, code: 'general_affairs_staff', name: '総務担当者' },
  { id: 5, code: 'hr_staff', name: '人事担当者' },
  { id: 6, code: 'admin', name: 'システム管理者' },
]

function withSeeded(user: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', 'detail', user.id], user)
  queryClient.setQueryData(['roles'], roles)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/admin/users/${user.id}`]}>
          <Routes>
            <Route path="/admin/users/:id" element={<UserRoleEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/UserRoleEditPage',
  component: UserRoleEditPage,
} satisfies Meta<typeof UserRoleEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(targetUser),
}

export const NoRoles: Story = {
  render: withSeeded({ ...targetUser, roles: [] }),
}
