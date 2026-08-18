import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as compensatoryLeaveApi from '../../api/compensatoryLeave'
import * as usersApi from '../../api/users'
import type { CompensatoryLeaveGrant, CompensatoryLeaveUsage, Paginated, User } from '../../api/types'
import { pickDate } from '../../test-support/pickerInteractions'
import { CompensatoryLeaveAdminPage } from './CompensatoryLeaveAdminPage'

const targetUser: User = {
  id: 'user-3',
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage(initialPath = '/admin/compensatory-leave') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <CompensatoryLeaveAdminPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CompensatoryLeaveAdminPage', () => {
  it('grants compensatory leave for the specified work date', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(compensatoryLeaveApi, 'fetchCompensatoryLeaveGrantsForUser').mockResolvedValue([])
    vi.spyOn(compensatoryLeaveApi, 'grantCompensatoryLeave').mockResolvedValue({
      id: 'grant-1',
      user_id: 'user-3',
      source: 'manual',
      attendance_day_id: 'day-1',
      work_date: '2026-08-01',
      status: 'confirmed',
      granted_days: 1,
      granted_minutes: null,
      used_days: 0,
      used_minutes: null,
      remaining_days: 1,
      remaining_minutes: null,
      confirmed_at: '2026-08-16T00:00:00Z',
      expires_on: null,
      grant_reason: null,
    } as CompensatoryLeaveGrant)

    renderPage()

    await userEvent.click(screen.getByLabelText('付与対象'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await pickDate(userEvent.setup(), '休日出勤の実績日', '2026-08-01')
    await userEvent.click(screen.getByRole('button', { name: '1名に付与する' }))

    await waitFor(() =>
      expect(compensatoryLeaveApi.grantCompensatoryLeave).toHaveBeenCalledWith({
        user_id: 'user-3',
        work_date: '2026-08-01',
        expires_on: undefined,
        grant_reason: undefined,
      }),
    )
    expect(await screen.findByText('1件成功 / 0件失敗')).toBeInTheDocument()
  })

  it('shows the selected users usage records from the ?userId= URL query param', async () => {
    const usage: CompensatoryLeaveUsage = {
      id: 'usage-1',
      user_id: 'user-3',
      used_on: '2026-08-16',
      used_days: 1,
      used_minutes: null,
      usage_type: 'full',
      is_confirmed: true,
      compensatory_leave_grant_id: 'grant-1',
      compensatory_leave_request_id: 'request-1',
      request_status: 'approved',
    }
    vi.spyOn(compensatoryLeaveApi, 'fetchCompensatoryLeaveUsagesForUser').mockResolvedValue([usage])

    renderPage('/admin/compensatory-leave?userId=user-3')

    expect(await screen.findByText('2026-08-16')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '取消' })).toBeInTheDocument()
    expect(compensatoryLeaveApi.fetchCompensatoryLeaveUsagesForUser).toHaveBeenCalledWith('user-3')
  })
})
