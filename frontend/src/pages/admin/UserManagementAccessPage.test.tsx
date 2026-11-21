import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import * as userManagementApi from '../../api/userManagement'
import { UserManagementAccessPage } from './UserManagementAccessPage'

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

const typeBase = { display_order: 0, status: 'active', is_system: true, membership_limit_type: 'unlimited', max_memberships_per_user: null, primary_membership_required: false, max_primary_memberships: null }
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
  it('restores the filter from ?group_type_id', async () => {
    renderPage('/admin/groups?group_type_id=2')

    expect(await screen.findByText('安全衛生委員会')).toBeInTheDocument()
    expect(screen.queryByText('総務部')).not.toBeInTheDocument()
    expect(screen.getByLabelText('表示するグループ種別')).toHaveValue('2')
  })

  it('writes the selected group type to the URL', async () => {
    renderPage()
    await screen.findByText('総務部')
    expect(screen.getByTestId('location-search')).toBeEmptyDOMElement()

    await userEvent.selectOptions(screen.getByLabelText('表示するグループ種別'), '1')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?group_type_id=1'))
    expect(screen.queryByText('安全衛生委員会')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('表示するグループ種別'), '')
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
  })

  it('drops an invalid group_type_id from the URL and shows all groups', async () => {
    renderPage('/admin/groups?group_type_id=abc')

    expect(await screen.findByText('総務部')).toBeInTheDocument()
    expect(screen.getByText('安全衛生委員会')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
  })
})
