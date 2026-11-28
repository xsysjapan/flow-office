import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as specialLeaveApi from '../../api/specialLeave'
import * as groupsApi from '../../api/groups'
import * as usersApi from '../../api/users'
import type {
  Paginated,
  SpecialLeaveGrant,
  SpecialLeaveGrantRule,
  SpecialLeaveGrantRuleTargetUser,
  SpecialLeaveType,
  SpecialLeaveUsage,
  User,
} from '../../api/types'
import { BULK_REJECTED_NOTICE } from '../../components/BulkActionDialog/BulkActionDialog'
import { notify } from '../../lib/notify'
import { pickDate } from '../../test-support/pickerInteractions'
import { SpecialLeaveAdminPage } from './SpecialLeaveAdminPage'

const birthdayType: SpecialLeaveType = { id: 1, name: '誕生日休暇', is_active: true, requires_grant: true }

const rule: SpecialLeaveGrantRule = {
  id: 1,
  special_leave_type_id: birthdayType.id,
  special_leave_type_name: birthdayType.name,
  name: '誕生日休暇ルール',
  work_style_id: null,
  min_attendance_rate: 80,
  first_grant_after_months: 0,
  grant_cycle_months: 12,
  expires_after_months: 6,
  is_active: true,
  steps: [{ continuous_service_months: 0, grant_days: 1 }],
}

const targetUser: User = {
  id: '11111111-1111-4111-8111-111111111111',
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage(
  types: SpecialLeaveType[] = [birthdayType],
  rules: SpecialLeaveGrantRule[] = [rule],
  initialPath = '/admin/special-leave',
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveTypes').mockResolvedValue(types)
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantRules').mockResolvedValue(rules)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <SpecialLeaveAdminPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

describe('SpecialLeaveAdminPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('lists existing special leave types', async () => {
    renderPage()

    expect((await screen.findAllByText('誕生日休暇')).length).toBeGreaterThan(0)
    // 行内の操作は行末の「⋯」メニューにまとめる(行内に切替ボタンを並べない)。
    expect(screen.queryByRole('button', { name: '無効にする' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '誕生日休暇の操作' }))
    expect(await screen.findByRole('menuitem', { name: '無効にする' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: '付与不要にする' })).toBeInTheDocument()
  })

  it('toggles a special leave type from the row menu instantly and notifies', async () => {
    const update = vi
      .spyOn(specialLeaveApi, 'updateSpecialLeaveType')
      .mockResolvedValue({ ...birthdayType, is_active: false })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '誕生日休暇の操作' }))
    await userEvent.click(await screen.findByRole('menuitem', { name: '無効にする' }))

    await waitFor(() =>
      expect(update).toHaveBeenCalledWith(1, { name: '誕生日休暇', is_active: false, requires_grant: true }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('誕生日休暇を無効にしました'))
  })

  it('creates a new special leave type with the entered name', async () => {
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveType').mockResolvedValue({ id: 2, name: 'リフレッシュ休暇', is_active: true, requires_grant: true })
    renderPage([])

    await screen.findByText('特別休暇の種類はまだありません。作成するまで特別休暇メニューは表示されません。')
    await userEvent.type(screen.getByLabelText('種類名(例: 誕生日休暇、代休)'), 'リフレッシュ休暇')
    await userEvent.click(screen.getByRole('button', { name: '追加する' }))

    await waitFor(() =>
      expect(specialLeaveApi.createSpecialLeaveType).toHaveBeenCalledWith({ name: 'リフレッシュ休暇', requires_grant: true }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('特別休暇の種類「リフレッシュ休暇」を追加しました'))
  })

  it('shows the type creation 422 messages once under the name and the checkbox, with the heading', async () => {
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveType').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        name: ['種類名のエラー'],
        requires_grant: ['要付与のエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage([])

    await screen.findByText('特別休暇の種類はまだありません。作成するまで特別休暇メニューは表示されません。')
    await userEvent.type(screen.getByLabelText('種類名(例: 誕生日休暇、代休)'), 'x')
    await userEvent.click(screen.getByRole('button', { name: '追加する' }))

    for (const message of ['種類名のエラー', '要付与のエラー', 'その他のエラー']) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })

  it('shows the rule creation 422 messages once under each field and the added step row, with the heading', async () => {
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveGrantRule').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        special_leave_type_id: ['種類のエラー'],
        name: ['ルール名のエラー'],
        min_attendance_rate: ['出勤率のエラー'],
        first_grant_after_months: ['初回のエラー'],
        grant_cycle_months: ['サイクルのエラー'],
        expires_after_months: ['失効のエラー'],
        is_active: ['有効のエラー'],
        'steps.0.continuous_service_months': ['月数のエラー'],
        'steps.0.grant_days': ['日数のエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')

    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[0], '誕生日休暇')
    await userEvent.type(screen.getByLabelText('ルール名'), 'x')
    await userEvent.type(screen.getByLabelText('継続勤務(か月)'), '3')
    await userEvent.type(screen.getByLabelText('付与日数', { selector: '#special-leave-rule-step-days' }), '2')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    for (const message of [
      '種類のエラー',
      'ルール名のエラー',
      '出勤率のエラー',
      '初回のエラー',
      'サイクルのエラー',
      '失効のエラー',
      '有効のエラー',
      '月数のエラー',
      '日数のエラー',
      'その他のエラー',
    ]) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })

  it('lists existing grant rules with their steps and expiry', async () => {
    renderPage()

    expect(await screen.findByText('誕生日休暇: 誕生日休暇ルール')).toBeInTheDocument()
    expect(screen.getByText('継続勤務0か月→1日')).toBeInTheDocument()
    expect(screen.getByText('付与から6か月後')).toBeInTheDocument()
  })

  it('creates a new grant rule for the selected special leave type', async () => {
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveGrantRule').mockResolvedValue({ ...rule, id: 2, name: '新ルール' })
    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')

    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[0], '誕生日休暇')
    await userEvent.type(screen.getByLabelText('ルール名'), '新ルール')
    await userEvent.click(screen.getByRole('button', { name: 'ルールを作成' }))

    await waitFor(() =>
      expect(specialLeaveApi.createSpecialLeaveGrantRule).toHaveBeenCalledWith({
        special_leave_type_id: birthdayType.id,
        name: '新ルール',
        min_attendance_rate: undefined,
        first_grant_after_months: undefined,
        grant_cycle_months: undefined,
        expires_after_months: undefined,
        is_active: true,
        steps: undefined,
      }),
    )
  })

  it('grants special leave in one bulk call (without an expiry date) after the confirmation', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantsForUser').mockResolvedValue([])
    const bulkSpy = vi.spyOn(specialLeaveApi, 'bulkGrantSpecialLeave').mockResolvedValue({
      data: [{ user_id: '11111111-1111-4111-8111-111111111111', user_name: '対象社員' }],
    })

    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')

    await userEvent.click(screen.getByLabelText('付与対象'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[1], '誕生日休暇')
    await pickDate(userEvent.setup(), '付与日', '2026-07-01')
    await userEvent.type(screen.getAllByLabelText('付与日数')[1], '3')
    await userEvent.click(screen.getByRole('button', { name: '1名に付与する' }))
    const dialog = await screen.findByRole('dialog')
    expect(bulkSpy).not.toHaveBeenCalled()
    await userEvent.click(within(dialog).getByRole('button', { name: '付与する' }))

    await waitFor(() =>
      expect(bulkSpy).toHaveBeenCalledWith({
        request_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
        user_ids: ['11111111-1111-4111-8111-111111111111'],
        special_leave_type_id: birthdayType.id,
        granted_on: '2026-07-01',
        expires_on: undefined,
        granted_days: 3,
        grant_reason: undefined,
      }),
    )
    expect(bulkSpy).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('1名に特別休暇を付与しました')
  })

  it('shows the rejected users with the notice and keeps the selection when the bulk grant is rejected', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantsForUser').mockResolvedValue([])
    vi.spyOn(specialLeaveApi, 'bulkGrantSpecialLeave').mockRejectedValue(
      new ApiError(422, '選んだ対象のうち、処理できないものがあります。', {}, 'bulk_operation_rejected', [
        { id: '11111111-1111-4111-8111-111111111111', label: '対象社員', message: 'この利用者には付与できません。' },
      ]),
    )

    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')
    await userEvent.click(screen.getByLabelText('付与対象'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[1], '誕生日休暇')
    await pickDate(userEvent.setup(), '付与日', '2026-07-01')
    await userEvent.type(screen.getAllByLabelText('付与日数')[1], '3')
    await userEvent.click(screen.getByRole('button', { name: '1名に付与する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: '付与する' }))

    expect(await within(dialog).findByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(within(dialog).getByText('対象社員: この利用者には付与できません。')).toBeInTheDocument()
    expect(notify.success).not.toHaveBeenCalled()
    await userEvent.click(within(dialog).getAllByRole('button', { name: '閉じる' }).at(-1)!)
    expect(screen.getByRole('button', { name: '1名に付与する' })).toBeEnabled()
  })

  it('sends group_id when a group is specified', async () => {
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue({ data: [targetUser], meta: { current_page: 1, last_page: 1, total: 1 }, links: { next: null, prev: null } })
    vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue([{ id: 'group-1', name: '営業部' }])
    vi.spyOn(groupsApi, 'fetchGroupMembers').mockResolvedValue([
      { user_id: 'member-1', name: '営業一郎', email: 'ichiro@example.com', membership_kind: 'primary', is_primary: true },
    ])
    const bulkSpy = vi.spyOn(specialLeaveApi, 'bulkGrantSpecialLeave').mockResolvedValue({
      data: [{ user_id: 'member-1', user_name: '営業一郎' }],
    })
    bulkSpy.mockClear()

    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')
    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: /付与対象/ }), 'group-1')
    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[1], '誕生日休暇')
    await pickDate(userEvent.setup(), '付与日', '2026-07-01')
    await userEvent.type(screen.getAllByLabelText('付与日数')[1], '3')
    await userEvent.click(screen.getByRole('button', { name: 'グループの所属メンバーに付与する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: '付与する' }))

    await waitFor(() => expect(bulkSpy).toHaveBeenCalledTimes(1))
    const sent = bulkSpy.mock.calls[0][0]
    expect(sent).toMatchObject({ group_id: 'group-1', special_leave_type_id: birthdayType.id, granted_days: 3 })
    expect(sent).not.toHaveProperty('user_ids')
  })

  it('shows the selected users usage records from the ?userId= URL query param', async () => {
    const usage: SpecialLeaveUsage = {
      id: 'usage-1',
      user_id: '11111111-1111-4111-8111-111111111111',
      used_on: '2026-07-10',
      used_days: 1,
      used_minutes: null,
      usage_type: 'full',
      is_confirmed: true,
      special_leave_grant_id: 'grant-1',
      special_leave_request_id: 'request-1',
      request_status: 'approved',
    }
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveUsagesForUser').mockResolvedValue([usage])

    renderPage([birthdayType], [rule], '/admin/special-leave?userId=11111111-1111-4111-8111-111111111111')

    expect(await screen.findByText('2026-07-10')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '取消' })).toBeInTheDocument()
    expect(specialLeaveApi.fetchSpecialLeaveUsagesForUser).toHaveBeenCalledWith('11111111-1111-4111-8111-111111111111')
  })

  it('shows the target users of a grant rule, filters by name, and toggles auto-grant instantly', async () => {
    const targetUsers: SpecialLeaveGrantRuleTargetUser[] = [
      { id: '11111111-1111-4111-8111-111111111111', name: '鈴木一郎', work_style: '通常勤務', special_leave_auto_grant_enabled: true },
      { id: 'user-4', name: '他の社員', work_style: null, special_leave_auto_grant_enabled: false },
    ]
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantRuleTargetUsers').mockResolvedValue(targetUsers)
    vi.spyOn(usersApi, 'updateSpecialLeaveAutoGrantEnabled').mockResolvedValue({
      ...targetUser,
      special_leave_auto_grant_enabled: false,
    })

    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')

    await userEvent.click(screen.getByRole('button', { name: '対象社員' }))

    expect(await screen.findByText('鈴木一郎')).toBeInTheDocument()
    expect(screen.getByText('他の社員')).toBeInTheDocument()
    expect(screen.getByText('自動付与:無効')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('社員名で絞り込み'), '他の')
    expect(screen.queryByText('鈴木一郎')).not.toBeInTheDocument()
    expect(screen.getByText('他の社員')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: '他の社員の特別休暇自動付与' }))

    await waitFor(() =>
      expect(usersApi.updateSpecialLeaveAutoGrantEnabled).toHaveBeenCalledWith('user-4', true),
    )
  })

  describe('change days button of the target user grants', () => {
    function grantOf(overrides: Partial<SpecialLeaveGrant>): SpecialLeaveGrant {
      return {
        id: 'grant-1',
        user_id: '11111111-1111-4111-8111-111111111111',
        special_leave_type_id: birthdayType.id,
        special_leave_type_name: birthdayType.name,
        granted_on: '2026-07-01',
        expires_on: null,
        granted_days: 5,
        used_days: 0,
        remaining_days: 5,
        grant_reason: null,
        status: 'active',
        revoked_at: null,
        revoked_by_user_id: null,
        revoke_reason: null,
        ...overrides,
      } as SpecialLeaveGrant
    }

    async function showGrantsOfTargetUser(grants: SpecialLeaveGrant[]) {
      const paginatedUsers: Paginated<User> = {
        data: [targetUser],
        meta: { current_page: 1, last_page: 1, total: 1 },
        links: { next: null, prev: null },
      }
      vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
      vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantsForUser').mockResolvedValue(grants)
      renderPage()
      await screen.findByText('誕生日休暇: 誕生日休暇ルール')

      await userEvent.click(screen.getByLabelText('付与対象'))
      await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
      await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    }

    it('changes the granted days even for a used grant, sending the entered days and reason', async () => {
      vi.spyOn(specialLeaveApi, 'changeSpecialLeaveGrantedDays').mockResolvedValue(grantOf({ granted_days: 7 }))
      await showGrantsOfTargetUser([grantOf({ used_days: 1, remaining_days: 4 })])

      const button = await screen.findByRole('button', { name: '日数を変更' })
      expect(button).toBeEnabled()
      await userEvent.click(button)
      const input = await screen.findByLabelText(/新しい付与日数/)
      expect(input).toHaveValue(5)
      await userEvent.clear(input)
      await userEvent.type(input, '7')
      await userEvent.type(screen.getByLabelText('変更理由(任意)'), '訂正')
      await userEvent.click(screen.getByRole('button', { name: '変更する' }))

      await waitFor(() => expect(specialLeaveApi.changeSpecialLeaveGrantedDays).toHaveBeenCalledWith('grant-1', 7, '訂正'))
    })

    it('does not allow a value below the used days', async () => {
      await showGrantsOfTargetUser([grantOf({ used_days: 2, remaining_days: 3 })])

      await userEvent.click(await screen.findByRole('button', { name: '日数を変更' }))
      const input = await screen.findByLabelText(/新しい付与日数/)
      await userEvent.clear(input)
      await userEvent.type(input, '1.5')

      expect(screen.getByRole('button', { name: '変更する' })).toBeDisabled()
    })

    it('does not show the change button for a revoked grant', async () => {
      await showGrantsOfTargetUser([grantOf({ status: 'revoked', remaining_days: 0 })])

      await screen.findByText(/取消済み/)
      expect(screen.queryByRole('button', { name: '日数を変更' })).not.toBeInTheDocument()
    })
  })
})
