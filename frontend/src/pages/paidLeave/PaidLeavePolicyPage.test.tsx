import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as paidLeaveApi from '../../api/paidLeave'
import * as usersApi from '../../api/users'
import * as workCalendarsApi from '../../api/workCalendars'
import type {
  Paginated,
  PaidLeaveGrant,
  PaidLeaveGrantPolicies,
  PaidLeaveGrantRule,
  PaidLeaveGrantRuleTargetUser,
  PaidLeaveScheduleEntry,
  PaidLeaveUsage,
  User,
  WorkCalendar,
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
  grant_cycle_type: 'mass_grant_month',
  is_active: true,
  steps: [{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }],
}

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [{ continuous_service_months: 6, grant_days: 10 }],
  proportional_version: 'v1',
  proportional: [{ weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 }],
}

function workCalendar(overrides: Partial<WorkCalendar>): WorkCalendar {
  return {
    id: 'calendar-1',
    name: '標準カレンダー',
    week_starts_on: 1,
    fiscal_year_start_month: 4,
    fiscal_year_start_day: 1,
    holiday_calendar_source_id: null,
    is_default: true,
    status: 'active',
    weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'legal_holiday', '7': 'legal_holiday' },
    allow_daily_holiday_override: false,
    ...overrides,
  }
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

const overdueEntry: PaidLeaveScheduleEntry = {
  id: 'entry-overdue-1',
  user_id: 'user-1',
  user_name: '鈴木一郎',
  scheduled_on: '2026-09-20',
  category: 'normal',
  candidate_grant_days: 10,
  nominal_service_months: null,
  status: 'Eligible',
  latest_assessment_id: 'assessment-1',
  is_manually_overridden: false,
  attendance_rate: 90,
}

function renderPage(
  rules: PaidLeaveGrantRule[] = [rule],
  initialPath = '/admin/paid-leave',
  overdue: PaidLeaveScheduleEntry[] = [],
  calendars: WorkCalendar[] = [workCalendar({})],
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRules').mockResolvedValue(rules)
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantPolicies').mockResolvedValue(policies)
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue(calendars)
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntries').mockImplementation(async (params) =>
    params.filter === 'overdue' ? overdue : [],
  )

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <PaidLeavePolicyPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PaidLeavePolicyPage', () => {
  it('links to the schedule page from the grant rules card', async () => {
    renderPage()
    expect(await screen.findByRole('link', { name: '付与予定を確認' })).toHaveAttribute('href', '/admin/paid-leave/schedule')
  })

  it('shows the overdue count as a badge and in the accessible name of the schedule link', async () => {
    renderPage([rule], '/admin/paid-leave', [overdueEntry, { ...overdueEntry, id: 'entry-overdue-2' }])

    const link = await screen.findByRole('link', { name: '付与予定を確認(付与予定日超過 2件)' })
    expect(link).toHaveAttribute('href', '/admin/paid-leave/schedule')
    expect(within(link).getByText('2件')).toBeInTheDocument()
  })

  it('lists existing grant rules with a sentence preview and steps table', async () => {
    renderPage()

    expect(await screen.findByText('正社員標準ルール')).toBeInTheDocument()
    const ruleItem = screen.getByText('正社員標準ルール').closest('li')!
    expect(within(ruleItem).getByText('年度はじめに一斉付与')).toBeInTheDocument()
    expect(
      within(ruleItem).getByText(
        '入社後最初の年度開始日(4月1日)と、入社から6か月の早い方に初回を付与。初回が前倒しになるときは、次の段もまとめて付与。以後は毎年の年度開始日。出勤率が0.8%未満の月は付与されません。',
      ),
    ).toBeInTheDocument()
    expect(within(ruleItem).getByText('6か月')).toBeInTheDocument()
    expect(within(ruleItem).getByText('10日')).toBeInTheDocument()
  })

  it('shows the statutory grant policy matrix as a read-only reference (no toggle)', async () => {
    renderPage()

    expect(await screen.findByText('法定付与日数(参考・自動適用)')).toBeInTheDocument()
    const matrixSection = screen.getByText('法定付与日数(参考・自動適用)').parentElement!
    expect(await within(matrixSection).findByText('週4日')).toBeInTheDocument()
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
    await screen.findByText('(この継続勤務月数の法定最低日数: 10日)')
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    await waitFor(() =>
      expect(paidLeaveApi.createPaidLeaveGrantRule).toHaveBeenCalledWith({
        name: '新ルール',
        min_attendance_rate: undefined,
        first_grant_after_months: undefined,
        grant_cycle_months: undefined,
        grant_cycle_type: 'mass_grant_month',
        is_active: true,
        // 初期値は法定表の写し(全区分)。通常の表が先、比例は週所定日数4→1の順。
        steps: [
          { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
          { grant_category: 'proportional_4', continuous_service_months: 6, grant_days: 7 },
        ],
      }),
    )
  })

  it('selects 年度はじめに一斉付与 by default and shows the default calendar year start', async () => {
    renderPage([])

    expect(await screen.findByLabelText('年度はじめに一斉付与')).toBeChecked()
    expect(screen.getByLabelText('入社日を基準に付与')).not.toBeChecked()
    expect(await screen.findByText('既定の会社カレンダーの年度開始日: 4月1日')).toBeInTheDocument()
  })

  it('shows 4月1日(既定) when there is no default calendar', async () => {
    renderPage([], '/admin/paid-leave', [], [workCalendar({ is_default: false })])

    expect(await screen.findByText('既定の会社カレンダーの年度開始日: 4月1日(既定)')).toBeInTheDocument()
  })

  it('uses the default calendar year start when it differs from 4月1日', async () => {
    renderPage([], '/admin/paid-leave', [], [workCalendar({ fiscal_year_start_month: 10, fiscal_year_start_day: 1 })])

    expect(await screen.findByText('既定の会社カレンダーの年度開始日: 10月1日')).toBeInTheDocument()
  })

  it('sends anniversary when 入社日を基準に付与 is selected', async () => {
    vi.spyOn(paidLeaveApi, 'createPaidLeaveGrantRule').mockResolvedValue({ ...rule, id: 2, name: '新ルール' })
    renderPage([])

    await userEvent.type(await screen.findByLabelText('ルール名'), '新ルール')
    await userEvent.click(screen.getByLabelText('入社日を基準に付与'))
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    await waitFor(() =>
      expect(paidLeaveApi.createPaidLeaveGrantRule).toHaveBeenCalledWith(
        expect.objectContaining({ grant_cycle_type: 'anniversary' }),
      ),
    )
  })

  it('shows a 422 error on the tab of the category whose row failed', async () => {
    vi.spyOn(paidLeaveApi, 'createPaidLeaveGrantRule').mockRejectedValue(
      new ApiError(422, '入力内容に誤りがあります。', { 'steps.1.grant_days': ['週4日の法定最低日数は7日です。'] }),
    )
    renderPage([])

    await userEvent.type(await screen.findByLabelText('ルール名'), '新ルール')
    await screen.findByText('(この継続勤務月数の法定最低日数: 10日)')
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    const weekly4Tab = await screen.findByRole('tab', { name: '週4日(エラーあり)' })
    expect(screen.getByRole('tab', { name: '通常' })).not.toHaveTextContent('エラーあり')
    await userEvent.click(weekly4Tab)
    expect(within(screen.getByRole('tabpanel')).getByText('週4日の法定最低日数は7日です。')).toBeInTheDocument()
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

  describe('revoke button of the target user grants', () => {
    function grantOf(overrides: Partial<PaidLeaveGrant>): PaidLeaveGrant {
      return {
        id: 'grant-1',
        user_id: 'user-3',
        granted_on: '2026-06-01',
        expires_on: '2027-05-31',
        granted_days: 20,
        used_days: 0,
        remaining_days: 20,
        grant_reason: null,
        status: 'active',
        revoked_at: null,
        revoked_by_user_id: null,
        revoke_reason: null,
        ...overrides,
      } as PaidLeaveGrant
    }

    async function showGrantsOfTargetUser(grants: PaidLeaveGrant[]) {
      const paginatedUsers: Paginated<User> = {
        data: [targetUser],
        meta: { current_page: 1, last_page: 1, total: 1 },
        links: { next: null, prev: null },
      }
      vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
      vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantsForUser').mockResolvedValue(grants)
      renderPage()

      await userEvent.click(document.getElementById('grant-target-users')!)
      await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
      await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
      await screen.findByText('対象社員の有給付与状況')
    }

    it('enables revoking the latest grant even when it has been used', async () => {
      await showGrantsOfTargetUser([grantOf({ id: 'grant-1', used_days: 4, remaining_days: 16 })])

      const button = await screen.findByRole('button', { name: '取消' })
      expect(button).toBeEnabled()
      await userEvent.click(button)
      expect(
        await screen.findByText(/消化済みの4日分は他の付与へ付け替えます。付け替え先が足りない分は残高不足\(未充当\)になります。/),
      ).toBeInTheDocument()
    })

    it('disables revoking a grant that is not the latest, with a reason', async () => {
      await showGrantsOfTargetUser([
        grantOf({ id: 'grant-new', granted_on: '2026-06-02', expires_on: '2028-05-31' }),
        grantOf({ id: 'grant-old', granted_on: '2026-06-01' }),
      ])

      await screen.findAllByRole('button', { name: '取消' })
      const buttons = screen.getAllByRole('button', { name: '取消' })
      expect(buttons).toHaveLength(2)
      expect(buttons[0]).toBeEnabled()
      expect(buttons[1]).toBeDisabled()
      expect(buttons[1]).toHaveAttribute('title', '最新の付与のみ取り消せます。')
    })

    it('enables revoking the next grant once the newer one is revoked', async () => {
      await showGrantsOfTargetUser([
        grantOf({
          id: 'grant-new',
          granted_on: '2026-06-02',
          expires_on: '2028-05-31',
          remaining_days: 0,
          status: 'revoked',
          revoked_at: '2026-10-01T00:00:00Z',
        }),
        grantOf({ id: 'grant-old', granted_on: '2026-06-01', used_days: 4, remaining_days: 16 }),
      ])

      const button = await screen.findByRole('button', { name: '取消' })
      expect(screen.getAllByRole('button', { name: '取消' })).toHaveLength(1)
      expect(button).toBeEnabled()
    })
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

  it('creates a new normal grant policy version with a statutory-review warning', async () => {
    vi.spyOn(paidLeaveApi, 'createPaidLeaveGrantPolicyVersion').mockResolvedValue({
      version: 'v2',
      normal: [{ continuous_service_months: 6, grant_days: 11 }],
    })

    renderPage()

    const normalSection = (await screen.findByText(/通常付与\(週所定労働日数5日以上/)).closest('div')!
    await userEvent.click(within(normalSection).getByRole('button', { name: '新しいバージョンを作成' }))

    const dialog = await screen.findByRole('dialog')
    expect(
      within(dialog).getByText('この表の変更は法令に基づく設定です。保存前に社労士等の専門家に確認してください。'),
    ).toBeInTheDocument()

    const monthsInput = within(dialog).getByLabelText('継続勤務(か月)') as HTMLInputElement
    const daysInput = within(dialog).getByLabelText('付与日数') as HTMLInputElement
    expect(monthsInput).toHaveValue(6)
    await userEvent.clear(daysInput)
    await userEvent.type(daysInput, '11')

    await userEvent.click(within(dialog).getByRole('button', { name: '新しいバージョンを保存' }))

    await waitFor(() =>
      expect(paidLeaveApi.createPaidLeaveGrantPolicyVersion).toHaveBeenCalledWith([
        { continuous_service_months: 6, grant_days: 11 },
      ]),
    )
  })

  it('creates a new proportional grant policy version', async () => {
    vi.spyOn(paidLeaveApi, 'createPaidLeaveProportionalGrantPolicyVersion').mockResolvedValue({
      version: 'v2',
      proportional: [{ weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 8 }],
    })

    renderPage()

    const proportionalSection = (await screen.findByText(/比例付与\(週所定労働日数4日以下/)).closest('div')!
    await userEvent.click(within(proportionalSection).getByRole('button', { name: '新しいバージョンを作成' }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('この表の変更は法令に基づく設定です。保存前に社労士等の専門家に確認してください。')

    const daysInput = within(dialog).getByLabelText('付与日数') as HTMLInputElement
    await userEvent.clear(daysInput)
    await userEvent.type(daysInput, '8')

    await userEvent.click(within(dialog).getByRole('button', { name: '新しいバージョンを保存' }))

    await waitFor(() =>
      expect(paidLeaveApi.createPaidLeaveProportionalGrantPolicyVersion).toHaveBeenCalledWith([
        { weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 8 },
      ]),
    )
  })
})
