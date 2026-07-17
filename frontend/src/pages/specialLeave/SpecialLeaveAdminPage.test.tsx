import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import * as usersApi from '../../api/users'
import type { Paginated, SpecialLeaveGrant, SpecialLeaveGrantRule, SpecialLeaveType, User } from '../../api/types'
import { SpecialLeaveAdminPage } from './SpecialLeaveAdminPage'

const birthdayType: SpecialLeaveType = { id: 1, name: '誕生日休暇', is_active: true }

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
  id: 3,
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage(types: SpecialLeaveType[] = [birthdayType], rules: SpecialLeaveGrantRule[] = [rule]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveTypes').mockResolvedValue(types)
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantRules').mockResolvedValue(rules)

  return render(
    <QueryClientProvider client={queryClient}>
      <SpecialLeaveAdminPage />
    </QueryClientProvider>,
  )
}

describe('SpecialLeaveAdminPage', () => {
  it('lists existing special leave types', async () => {
    renderPage()

    expect((await screen.findAllByText('誕生日休暇')).length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: '無効にする' })).toBeInTheDocument()
  })

  it('creates a new special leave type with the entered name', async () => {
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveType').mockResolvedValue({ id: 2, name: 'リフレッシュ休暇', is_active: true })
    renderPage([])

    await screen.findByText('特別休暇の種類はまだありません。作成するまで特別休暇メニューは表示されません。')
    await userEvent.type(screen.getByLabelText('種類名(例: 誕生日休暇)'), 'リフレッシュ休暇')
    await userEvent.click(screen.getByRole('button', { name: '追加する' }))

    await waitFor(() => expect(specialLeaveApi.createSpecialLeaveType).toHaveBeenCalledWith({ name: 'リフレッシュ休暇' }))
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

  it('grants special leave to the selected user without an expiry date', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveGrantsForUser').mockResolvedValue([])
    vi.spyOn(specialLeaveApi, 'grantSpecialLeave').mockResolvedValue({
      id: 1,
      user_id: 3,
      special_leave_type_id: birthdayType.id,
      special_leave_type_name: birthdayType.name,
      granted_on: '2026-07-01',
      expires_on: null,
      granted_days: 3,
      used_days: 0,
      remaining_days: 3,
      grant_reason: null,
    } as SpecialLeaveGrant)

    renderPage()
    await screen.findByText('誕生日休暇: 誕生日休暇ルール')

    await userEvent.click(screen.getByLabelText('対象社員'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getAllByLabelText('特別休暇の種類')[1], '誕生日休暇')
    await userEvent.type(screen.getByLabelText('付与日'), '2026-07-01')
    await userEvent.type(screen.getAllByLabelText('付与日数')[1], '3')
    await userEvent.click(screen.getByRole('button', { name: '付与する' }))

    await waitFor(() =>
      expect(specialLeaveApi.grantSpecialLeave).toHaveBeenCalledWith({
        user_id: 3,
        special_leave_type_id: birthdayType.id,
        granted_on: '2026-07-01',
        expires_on: undefined,
        granted_days: 3,
        grant_reason: undefined,
      }),
    )
  })
})
