import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import * as userManagementApi from '../../api/userManagement'
import { UserManagementAccessPage } from './UserManagementAccessPage'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

const typeBase = { display_order: 0, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, requires_primary_membership: false, max_primary_memberships: null }
const orgType = { id: 1, code: 'ORGANIZATION', name: '組織', ...typeBase }
const committeeType = { id: 2, code: 'COMMITTEE', name: '委員会', ...typeBase }

function renderPage(initialEntry = '/admin/groups') {
  vi.spyOn(userManagementApi, 'fetchManagedGroups').mockResolvedValue([
    { id: 'group-1', group_type_id: 1, name: '総務部', code: 'GA', status: 'active', parent_group_id: null, memberships_count: 0, type: orgType, features: [], memberships: [], role_assignments: [] },
    { id: 'group-2', group_type_id: 2, name: '安全衛生委員会', code: 'SC', status: 'active', parent_group_id: null, memberships_count: 0, type: committeeType, features: [], memberships: [], role_assignments: [] },
  ] as never)
  vi.spyOn(userManagementApi, 'fetchGroupTypes').mockResolvedValue([orgType, committeeType] as never)
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <UserManagementAccessPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UserManagementAccessPage group type filter', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('shows the page header with a 新規作成 link to the group creation page', async () => {
    renderPage()
    await screen.findByText('総務部')

    const header = within(screen.getByRole('region', { name: 'グループ' }))
    expect(header.getByText('グループの一覧を確認し、基本情報と所属メンバーを管理します。')).toBeInTheDocument()
    expect(header.getByRole('link', { name: '新規作成' })).toHaveAttribute('href', '/admin/groups/new')
    expect(screen.queryByRole('link', { name: '新規グループ' })).not.toBeInTheDocument()
  })

  it('shows the 新規作成 button on the membership changes page header and an empty state', async () => {
    vi.spyOn(userManagementApi, 'fetchManagedGroups').mockResolvedValue([] as never)
    vi.spyOn(userManagementApi, 'fetchMembershipChangeSets').mockResolvedValue([] as never)
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/membership-changes']}>
          <UserManagementAccessPage section="membershipChanges" />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('所属変更はまだありません。')).toBeInTheDocument()
    expect(screen.getByText('「新規作成」から所属変更を即時または日時指定で予約できます。')).toBeInTheDocument()
    const header = within(screen.getByRole('region', { name: '所属変更' }))
    expect(header.getByRole('button', { name: '新規作成' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '変更予約作成' })).not.toBeInTheDocument()
  })

  it('restores the filter from ?group_type_id', async () => {
    renderPage('/admin/groups?group_type_id=2')

    expect(await screen.findByText('安全衛生委員会')).toBeInTheDocument()
    expect(screen.queryByText('総務部')).not.toBeInTheDocument()
    expect(screen.getByLabelText('グループ種別')).toHaveValue('2')
  })

  it('writes the selected group type to the URL', async () => {
    renderPage()
    await screen.findByText('総務部')
    expect(screen.getByTestId('location-search')).toBeEmptyDOMElement()

    await userEvent.selectOptions(screen.getByLabelText('グループ種別'), '1')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?group_type_id=1'))
    expect(screen.queryByText('安全衛生委員会')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('グループ種別'), '')
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
  })

  it('removes page on change and clears the condition with 検索条件をクリア', async () => {
    renderPage('/admin/groups?page=2')
    await screen.findByText('総務部')
    expect(screen.queryByRole('button', { name: '検索条件をクリア' })).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('グループ種別'), '委員会')
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?group_type_id=2$/))

    await userEvent.click(screen.getByRole('button', { name: '検索条件をクリア' }))
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
    expect(screen.getByLabelText('グループ種別')).toHaveValue('')
    expect(screen.getByText('総務部')).toBeInTheDocument()
  })

  it('drops an invalid group_type_id from the URL and shows all groups', async () => {
    renderPage('/admin/groups?group_type_id=abc')

    expect(await screen.findByText('総務部')).toBeInTheDocument()
    expect(screen.getByText('安全衛生委員会')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
  })
})

describe('UserManagementAccessPage identities section', () => {
  it('links an external identity and notifies', async () => {
    vi.spyOn(userManagementApi, 'fetchExternalIdentities').mockResolvedValue([])
    vi.spyOn(userManagementApi, 'fetchFieldAuthorities').mockResolvedValue([])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
      data: [{ id: 'user-1', name: '対象社員', email: 'taisho@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null }],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    })
    const link = vi.spyOn(userManagementApi, 'linkExternalIdentity').mockResolvedValue(undefined as never)
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={["/admin/identity-settings"]}>
          <UserManagementAccessPage section="identities" />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByRole('option', { name: '対象社員' })
    await userEvent.selectOptions(screen.getByLabelText('ユーザー'), 'user-1')
    await userEvent.type(screen.getByPlaceholderText('Provider'), 'microsoft')
    await userEvent.type(screen.getByPlaceholderText('Subject ID'), 'subject-1')
    await userEvent.click(screen.getByRole('button', { name: 'リンク' }))

    await waitFor(() => expect(link).toHaveBeenCalled())
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('外部IDを連携しました'))
  })
})
