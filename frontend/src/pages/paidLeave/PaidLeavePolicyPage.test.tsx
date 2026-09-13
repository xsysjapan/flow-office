import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../../api/paidLeave'
import * as usersApi from '../../api/users'
import type {
  Paginated,
  PaidLeaveGrant,
  PaidLeaveGrantPolicies,
  PaidLeaveGrantRule,
  PaidLeaveGrantRuleTargetUser,
  PaidLeaveUsage,
  User,
} from '../../api/types'
import { pickDate } from '../../test-support/pickerInteractions'
import { PaidLeavePolicyPage } from './PaidLeavePolicyPage'

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

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [{ continuous_service_months: 6, grant_days: 10 }],
  proportional_version: 'v1',
  proportional: [{ weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 }],
}

const targetUser: User = {
  id: 'user-3',
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage(rules: PaidLeaveGrantRule[] = [rule], initialPath = '/admin/paid-leave') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRules').mockResolvedValue(rules)
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantPolicies').mockResolvedValue(policies)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <PaidLeavePolicyPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PaidLeavePolicyPage', () => {
  it('lists existing grant rules with a sentence preview and steps table', async () => {
    renderPage()

    expect(await screen.findByText('正社員標準ルール')).toBeInTheDocument()
    const ruleItem = screen.getByText('正社員標準ルール').closest('li')!
    expect(
      within(ruleItem).getByText('入社日から6か月後に最初の付与。以後12か月ごとに、付与テーブルに沿って日数が増えていきます。出勤率が0.8%未満の月は付与されません。'),
    ).toBeInTheDocument()
    expect(within(ruleItem).getByText('6か月')).toBeInTheDocument()
    expect(within(ruleItem).getByText('10日')).toBeInTheDocument()
  })

  it('shows the statutory grant policy matrix as a read-only reference (no toggle)', async () => {
    renderPage()

    expect(await screen.findByText('法定付与日数(参考・自動適用)')).toBeInTheDocument()
    expect(await screen.findByText('週4日')).toBeInTheDocument()
    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
  })

  it('disables deleting an active rule with a reason, and allows deleting an inactive one', async () => {
    renderPage([rule, { ...rule, id: 2, name: '無効ルール', is_active: false }])

    await screen.findByText('正社員標準ルール')
    const deleteButtons = screen.getAllByRole('button', { name: '削除' })
    expect(deleteButtons[0]).toBeDisabled()
    expect(screen.getByText('有効なルールは削除できません。先に無効化してください。')).toBeInTheDocument()
    expect(deleteButtons[1]).toBeEnabled()
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
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantsForUser').mockResolvedValue([])
    vi.spyOn(paidLeaveApi, 'grantPaidLeave').mockResolvedValue({
      id: 'grant-1',
      user_id: 'user-3',
      granted_on: '2026-07-01',
      expires_on: '2027-06-30',
      granted_days: 10,
      used_days: 0,
      remaining_days: 10,
      grant_reason: null,
      status: 'active',
      revoked_at: null,
      revoked_by_user_id: null,
      revoke_reason: null,
    } as PaidLeaveGrant)

    renderPage()

    await userEvent.click(document.getElementById('grant-target-users')!)
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await pickDate(userEvent.setup(), '付与日', '2026-07-01')
    await pickDate(userEvent.setup(), '失効日', '2027-06-30')
    await userEvent.type(screen.getByLabelText('付与日数', { selector: '#grant-granted-days' }), '10')
    await userEvent.click(screen.getByRole('button', { name: '1名に付与する' }))

    await waitFor(() =>
      expect(paidLeaveApi.grantPaidLeave).toHaveBeenCalledWith({
        user_id: 'user-3',
        granted_on: '2026-07-01',
        expires_on: '2027-06-30',
        granted_days: 10,
        grant_reason: undefined,
      }),
    )
    expect(await screen.findByText('1件成功 / 0件失敗')).toBeInTheDocument()
  })

  it('shows the selected users usage records from the ?userId= URL query param', async () => {
    const usage: PaidLeaveUsage = {
      id: 'usage-1',
      user_id: 'user-3',
      used_on: '2026-07-10',
      used_days: 1,
      used_minutes: null,
      usage_type: 'full',
      is_confirmed: true,
      paid_leave_grant_id: 'grant-1',
      paid_leave_request_id: 'request-1',
      request_status: 'approved',
    }
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveUsagesForUser').mockResolvedValue([usage])

    renderPage([rule], '/admin/paid-leave?userId=user-3')

    expect(await screen.findByText('2026-07-10')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '取消' })).toBeInTheDocument()
    expect(paidLeaveApi.fetchPaidLeaveUsagesForUser).toHaveBeenCalledWith('user-3')
  })

  it('shows the target users of a grant rule, filters by name, and toggles auto-grant instantly', async () => {
    const targetUsers: PaidLeaveGrantRuleTargetUser[] = [
      { id: 'user-3', name: '鈴木一郎', work_style: '通常勤務', paid_leave_auto_grant_enabled: true },
      { id: 'user-4', name: '他の社員', work_style: null, paid_leave_auto_grant_enabled: false },
    ]
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRuleTargetUsers').mockResolvedValue(targetUsers)
    vi.spyOn(usersApi, 'updatePaidLeaveAutoGrantEnabled').mockResolvedValue({
      ...targetUser,
      paid_leave_auto_grant_enabled: false,
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '対象社員' }))

    expect(await screen.findByText('鈴木一郎')).toBeInTheDocument()
    expect(screen.getByText('他の社員')).toBeInTheDocument()
    expect(screen.getByText('自動付与:無効')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('社員名で絞り込み'), '他の')
    expect(screen.queryByText('鈴木一郎')).not.toBeInTheDocument()
    expect(screen.getByText('他の社員')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: '他の社員の有給自動付与' }))

    await waitFor(() =>
      expect(usersApi.updatePaidLeaveAutoGrantEnabled).toHaveBeenCalledWith('user-4', true),
    )
  })
})
