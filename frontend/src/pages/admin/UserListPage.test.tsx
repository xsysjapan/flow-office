import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import type { Paginated, User } from '../../api/types'
import { UserListPage } from './UserListPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <UserListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UserListPage', () => {
  it('shows an empty state when there are no users', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)

    renderPage()

    expect(await screen.findByText('該当するユーザーはいません。')).toBeInTheDocument()
  })

  it('lists users with their roles and links to the detail page', async () => {
    const withData: Paginated<User> = {
      data: [
        {
          id: 1,
          name: '山田太郎',
          email: 'yamada@example.com',
          department: '総務部',
          job_title: '主任',
          employment_status: 'active',
          roles: ['employee', 'general_affairs_staff'],
          last_login_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(withData)

    renderPage()

    expect(await screen.findByRole('link', { name: '山田太郎' })).toHaveAttribute('href', '/admin/users/1')
    expect(screen.getByText('yamada@example.com')).toBeInTheDocument()
    expect(screen.getByText('employee')).toBeInTheDocument()
    expect(screen.getByText('general_affairs_staff')).toBeInTheDocument()
  })

  it('queries users with the current search text', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)

    renderPage()
    await screen.findByText('該当するユーザーはいません。')

    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '山田')
    await screen.findByDisplayValue('山田')

    await waitFor(() => expect(usersApi.fetchUsers).toHaveBeenCalledWith('山田'))
  })
})
