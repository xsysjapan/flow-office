import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import * as userManagementApi from '../../api/userManagement'
import type { Paginated, User } from '../../api/types'
import { UserListPage } from './UserListPage'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function LocationStateProbe() {
  const location = useLocation()
  return <div data-testid="location-state">{`${location.pathname}|${location.state ? 'with-state' : 'no-state'}`}</div>
}

function renderPage(initialEntry = '/') {
  vi.spyOn(userManagementApi, 'fetchManagedGroups').mockResolvedValue([
    {
      id: 'group-1',
      group_type_id: 1,
      name: '総務部',
      code: 'GENERAL_AFFAIRS',
      status: 'active',
      parent_group_id: null,
      memberships_count: 0,
      type: { id: 1, code: 'ORGANIZATION', name: '組織', display_order: 0, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, primary_membership_required: false, max_primary_memberships: null },
      features: [],
      memberships: [],
      role_assignments: [],
    },
    {
      id: 'group-2',
      group_type_id: 2,
      name: '安全衛生委員会',
      code: 'SAFETY_COMMITTEE',
      status: 'active',
      parent_group_id: null,
      memberships_count: 0,
      type: { id: 2, code: 'COMMITTEE', name: '委員会', display_order: 1, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, primary_membership_required: false, max_primary_memberships: null },
      features: [],
      memberships: [],
      role_assignments: [],
    },
  ])
  vi.spyOn(userManagementApi, 'fetchGroupTypes').mockResolvedValue([
    { id: 1, code: 'ORGANIZATION', name: '組織', display_order: 0, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, primary_membership_required: false, max_primary_memberships: null },
    { id: 2, code: 'COMMITTEE', name: '委員会', display_order: 1, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, primary_membership_required: false, max_primary_memberships: null },
  ])

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <UserListPage />
        <LocationProbe />
        <LocationStateProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UserListPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('shows an empty state when there are no users', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)

    renderPage()

    expect(await screen.findByText('該当するユーザーはいません。')).toBeInTheDocument()
  })

  it('opens the HR import page from the header link without passing the back state', async () => {
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

    renderPage('/admin/users')

    await userEvent.click(await screen.findByRole('link', { name: '外部ID・HR連携' }))

    await waitFor(() => expect(screen.getByTestId('location-state')).toHaveTextContent(/^\/admin\/hr-import\|no-state$/))
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

  it('creates a user from the create form and notifies', async () => {
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })
    const create = vi
      .spyOn(usersApi, 'createUser')
      .mockResolvedValue({ id: 'user-9', name: '新入社員', email: 'new@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'ユーザーを作成' }))
    await userEvent.type(screen.getByLabelText('氏名'), '新入社員')
    await userEvent.type(screen.getByLabelText('メールアドレス'), 'new@example.com')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() => expect(create).toHaveBeenCalledWith(expect.objectContaining({ name: '新入社員', email: 'new@example.com' })))
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('ユーザーを作成しました'))
  })

  it('queries users with the current search text', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)

    renderPage()
    await screen.findByText('該当するユーザーはいません。')

    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '山田')
    await screen.findByDisplayValue('山田')

    await waitFor(() => expect(usersApi.fetchUsers).toHaveBeenCalledWith('山田', 20, {}, 1))
  })

  it('drops invalid group_type_id/group_id/account_status/page from the URL and shows the default', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)

    renderPage('/?group_type_id=abc&group_id=12&account_status=bogus&page=0&q=yama')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=yama$/))
    await waitFor(() => expect(usersApi.fetchUsers).toHaveBeenLastCalledWith('yama', 20, {}, 1))
  })

  it('keeps a valid upper-case UUID group_id in the URL', async () => {
    const empty: Paginated<User> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(empty)
    const groupId = '0A1B2C3D-0000-4000-8000-ABCDEF123456'

    renderPage(`/?group_id=${groupId}&account_status=active&group_type_id=2&page=2`)

    await screen.findByText('条件に一致するユーザーはいません。')
    expect(screen.getByTestId('location-search').textContent).toBe(`?group_id=${groupId}&account_status=active&group_type_id=2&page=2`)
    expect(usersApi.fetchUsers).toHaveBeenLastCalledWith('', 20, expect.objectContaining({ group_id: groupId, account_status: 'active', group_type_id: 2 }), 2)
  })
})
