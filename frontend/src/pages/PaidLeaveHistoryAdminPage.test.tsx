import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../api/paidLeave'
import * as usersApi from '../api/users'
import type { Paginated, StoredEvent, User } from '../api/types'
import { PaidLeaveHistoryAdminPage } from './PaidLeaveHistoryAdminPage'

const targetUser: User = {
  id: 3,
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <PaidLeaveHistoryAdminPage />
    </QueryClientProvider>,
  )
}

describe('PaidLeaveHistoryAdminPage', () => {
  it('does not fetch history until a user is selected', () => {
    const fetchHistory = vi.spyOn(paidLeaveApi, 'fetchPaidLeaveHistoryForUser')

    renderPage()

    expect(fetchHistory).not.toHaveBeenCalled()
    expect(screen.queryByLabelText('有給履歴')).not.toBeInTheDocument()
  })

  it('shows the selected users history', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    const event: StoredEvent = {
      id: 1,
      event_id: 'evt-1',
      aggregate_type: 'paid_leave_grant',
      aggregate_id: '1',
      version: 1,
      event_type: 'paid_leave.granted',
      payload: { granted_days: 10, expires_on: '2027-06-30' },
      occurred_at: '2025-07-01T09:00:00+09:00',
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveHistoryForUser').mockResolvedValue([event])

    renderPage()

    await userEvent.click(await screen.findByRole('combobox'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))

    expect(await screen.findByText('10日を付与(有効期限 2027-06-30)')).toBeInTheDocument()
    expect(paidLeaveApi.fetchPaidLeaveHistoryForUser).toHaveBeenCalledWith(3)
  })
})
