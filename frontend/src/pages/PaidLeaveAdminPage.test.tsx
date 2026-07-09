import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../api/paidLeave'
import * as usersApi from '../api/users'
import type { Paginated, PaidLeaveGrant, PaidLeaveGrantRule, User } from '../api/types'
import { PaidLeaveAdminPage } from './PaidLeaveAdminPage'

const rule: PaidLeaveGrantRule = {
  id: 1,
  name: '正社員標準ルール',
  work_style_id: null,
  min_attendance_rate: 0.8,
  first_grant_after_months: 6,
  grant_cycle_months: 12,
  is_active: true,
  steps: [{ continuous_service_months: 6, grant_days: 10 }],
}

const targetUser: User = {
  id: 3,
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage(rules: PaidLeaveGrantRule[] = [rule]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRules').mockResolvedValue(rules)

  return render(
    <QueryClientProvider client={queryClient}>
      <PaidLeaveAdminPage />
    </QueryClientProvider>,
  )
}

describe('PaidLeaveAdminPage', () => {
  it('lists existing grant rules with their steps', async () => {
    renderPage()

    expect(await screen.findByText('正社員標準ルール')).toBeInTheDocument()
    expect(screen.getByText('継続勤務6か月→10日')).toBeInTheDocument()
  })

  it('creates a new grant rule with the entered values', async () => {
    vi.spyOn(paidLeaveApi, 'createPaidLeaveGrantRule').mockResolvedValue({ ...rule, id: 2, name: '新ルール' })
    renderPage([])

    await userEvent.type(await screen.findByLabelText('ルール名'), '新ルール')
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    await waitFor(() =>
      expect(paidLeaveApi.createPaidLeaveGrantRule).toHaveBeenCalledWith({
        name: '新ルール',
        min_attendance_rate: undefined,
        first_grant_after_months: undefined,
        grant_cycle_months: undefined,
        is_active: true,
        steps: undefined,
      }),
    )
  })

  it('grants paid leave to the selected user', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantsForUser').mockResolvedValue([])
    vi.spyOn(paidLeaveApi, 'grantPaidLeave').mockResolvedValue({
      id: 1,
      user_id: 3,
      granted_on: '2026-07-01',
      expires_on: '2027-06-30',
      granted_days: 10,
      used_days: 0,
      remaining_days: 10,
      grant_reason: null,
    } as PaidLeaveGrant)

    renderPage()

    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('button', { name: '対象社員(taisho@example.com)' }))
    await userEvent.type(screen.getByLabelText('付与日'), '2026-07-01')
    await userEvent.type(screen.getByLabelText('失効日'), '2027-06-30')
    await userEvent.type(screen.getByLabelText('付与日数', { selector: '#grant-granted-days' }), '10')
    await userEvent.click(screen.getByRole('button', { name: '付与する' }))

    await waitFor(() =>
      expect(paidLeaveApi.grantPaidLeave).toHaveBeenCalledWith({
        user_id: 3,
        granted_on: '2026-07-01',
        expires_on: '2027-06-30',
        granted_days: 10,
        grant_reason: undefined,
      }),
    )
  })
})
