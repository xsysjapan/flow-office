import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as rolesApi from '../api/roles'
import * as usersApi from '../api/users'
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
  last_login_at: null,
}

const roles: Role[] = [
  { id: 1, code: 'employee', name: '一般社員' },
  { id: 2, code: 'backoffice_staff', name: 'バックオフィス担当者' },
  { id: 3, code: 'accounting_staff', name: '経理担当者' },
  { id: 4, code: 'general_affairs_staff', name: '総務担当者' },
  { id: 5, code: 'hr_staff', name: '人事担当者' },
  { id: 6, code: 'admin', name: 'システム管理者' },
]

function renderPage(user: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(user)
  vi.spyOn(rolesApi, 'fetchRoles').mockResolvedValue(roles)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/admin/users/${user.id}`]}>
        <Routes>
          <Route path="/admin/users/:id" element={<UserRoleEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UserRoleEditPage', () => {
  it('checks the roles the user currently has', async () => {
    renderPage(targetUser)

    expect(await screen.findByRole('checkbox', { name: '一般社員' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '総務担当者' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '経理担当者' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'システム管理者' })).not.toBeChecked()
  })

  it('saves the updated role selection', async () => {
    vi.spyOn(usersApi, 'updateUserRoles').mockResolvedValue({
      ...targetUser,
      roles: ['employee', 'general_affairs_staff', 'admin'],
    })

    renderPage(targetUser)

    await userEvent.click(await screen.findByRole('checkbox', { name: 'システム管理者' }))
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(usersApi.updateUserRoles).toHaveBeenCalledWith(1, ['employee', 'general_affairs_staff', 'admin']),
    )
    expect(await screen.findByText('保存しました')).toBeInTheDocument()
  })

  it('unchecking a role removes it before saving', async () => {
    vi.spyOn(usersApi, 'updateUserRoles').mockResolvedValue({ ...targetUser, roles: ['general_affairs_staff'] })

    renderPage(targetUser)

    await userEvent.click(await screen.findByRole('checkbox', { name: '一般社員' }))
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(usersApi.updateUserRoles).toHaveBeenCalledWith(1, ['general_affairs_staff']),
    )
  })
})
