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

  it('lists users with their group memberships and links to the detail page', async () => {
    const withData: Paginated<User> = {
      data: [
        {
          id: 'user-1',
          name: '山田太郎',
          email: 'yamada@example.com',
          department: '総務部',
          job_title: '主任',
          employment_status: 'active',
          account_status: 'active',
          effective_features: ['attendance.entry'],
          memberships: [
            {
              id: 1,
              membership_kind: 'member',
              is_primary: true,
              group: {
                id: 'group-1',
                code: 'GENERAL_AFFAIRS',
                name: '総務部',
                group_type: 'ORGANIZATION',
                group_type_name: '組織',
                group_type_id: 1,
              },
            },
            {
              id: 2,
              membership_kind: 'member',
              is_primary: false,
              group: {
                id: 'group-2',
                code: 'SAFETY_COMMITTEE',
                name: '安全衛生委員会',
                group_type: 'COMMITTEE',
                group_type_name: '委員会',
                group_type_id: 2,
              },
            },
          ],
          last_login_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(withData)

    renderPage()

    expect(await screen.findByRole('link', { name: '山田太郎' })).toHaveAttribute('href', '/admin/users/user-1')
    expect(screen.getByText('yamada@example.com')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'グループ（所属）' })).toBeInTheDocument()
    expect(screen.getByText('総務部（主所属）')).toBeInTheDocument()
    expect(screen.getAllByText('安全衛生委員会')).toHaveLength(2)
    expect(screen.getByRole('option', { name: '組織' })).toBeInTheDocument()
    expect(screen.getByText('在籍中')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'アカウント状態' })).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: '権限' })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Feature' })).not.toBeInTheDocument()
    expect(screen.queryByText('employee')).not.toBeInTheDocument()
    expect(screen.queryByText('attendance.entry')).not.toBeInTheDocument()
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
