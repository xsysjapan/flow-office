import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import * as usersApi from '../../api/users'
import type { Paginated, StoredEvent, User } from '../../api/types'
import { SpecialLeaveHistoryAdminPage } from './SpecialLeaveHistoryAdminPage'

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
      <SpecialLeaveHistoryAdminPage />
    </QueryClientProvider>,
  )
}

describe('SpecialLeaveHistoryAdminPage', () => {
  it('does not fetch history until a user is selected', () => {
    const fetchHistory = vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveHistoryForUser')

    renderPage()

    expect(fetchHistory).not.toHaveBeenCalled()
    expect(screen.queryByLabelText('特別休暇履歴')).not.toBeInTheDocument()
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
      aggregate_type: 'special_leave_grant',
      aggregate_id: '1',
      version: 1,
      event_type: 'special_leave.granted',
      payload: { granted_days: 3, expires_on: null },
      occurred_at: '2026-07-01T09:00:00+09:00',
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveHistoryForUser').mockResolvedValue([event])

    renderPage()

    await userEvent.click(screen.getByLabelText('対象社員'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))

    expect(await screen.findByText('3日を付与(有効期限なし)')).toBeInTheDocument()
    expect(specialLeaveApi.fetchSpecialLeaveHistoryForUser).toHaveBeenCalledWith(3)
  })
})
