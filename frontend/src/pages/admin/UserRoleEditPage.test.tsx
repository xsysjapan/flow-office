import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as rolesApi from '../../api/roles'
import * as userWorkStyleMonthlyAssignmentsApi from '../../api/userWorkStyleMonthlyAssignments'
import * as usersApi from '../../api/users'
import * as workStylesApi from '../../api/workStyles'
import type { Role, User, UserWorkStyleMonthlyAssignment, WorkStyle } from '../../api/types'
import { formatDate } from '../../utils/weekDates'
import { UserRoleEditPage } from './UserRoleEditPage'

const targetUser: User = {
  id: 'user-1',
  name: '山田太郎',
  email: 'yamada@example.com',
  department: '総務部',
  job_title: '主任',
  employment_status: 'active',
  roles: ['employee', 'general_affairs_staff'],
  last_login_at: null,
}

const roles: Role[] = [
  { id: 1, code: 'employee', name: '一般社員' },
  { id: 2, code: 'backoffice_staff', name: 'バックオフィス担当者' },
  { id: 3, code: 'accounting_staff', name: '経理担当者' },
  { id: 4, code: 'general_affairs_staff', name: '総務担当者' },
  { id: 5, code: 'hr_staff', name: '人事担当者' },
  { id: 6, code: 'admin', name: 'システム管理者' },
]

const defaultWorkStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '通常勤務',
  work_time_system: 'fixed',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  calendar_id: 'calendar-1',
  is_shift_based: false,
  is_default: true,
  system_generated: true,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  applied_employee_count: null,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: null,
}

const flexWorkStyle: WorkStyle = { ...defaultWorkStyle, id: 'work-style-2', code: 'flex', name: 'フレックスタイム制', is_default: false }

function renderPage(
  user: User,
  {
    workStyles = [defaultWorkStyle, flexWorkStyle],
    workStyleHistory = [],
  }: { workStyles?: WorkStyle[]; workStyleHistory?: UserWorkStyleMonthlyAssignment[] } = {},
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(user)
  vi.spyOn(rolesApi, 'fetchRoles').mockResolvedValue(roles)
  vi.spyOn(workStylesApi, 'fetchWorkStyles').mockResolvedValue(workStyles)
  vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue(
    workStyleHistory,
  )

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/admin/users/${user.id}`]}>
        <Routes>
          <Route path="/admin/users/:id" element={<UserRoleEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UserRoleEditPage', () => {
  it('checks the roles the user currently has', async () => {
    renderPage(targetUser)

    expect(await screen.findByRole('checkbox', { name: '一般社員' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '総務担当者' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '経理担当者' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'システム管理者' })).not.toBeChecked()
  })

  it('saves the updated role selection', async () => {
    vi.spyOn(usersApi, 'updateUserRoles').mockResolvedValue({
      ...targetUser,
      roles: ['employee', 'general_affairs_staff', 'admin'],
    })

    renderPage(targetUser)

    await userEvent.click(await screen.findByRole('checkbox', { name: 'システム管理者' }))
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(usersApi.updateUserRoles).toHaveBeenCalledWith('user-1', ['employee', 'general_affairs_staff', 'admin']),
    )
    expect(await screen.findByText('保存しました')).toBeInTheDocument()
  })

  it('unchecking a role removes it before saving', async () => {
    vi.spyOn(usersApi, 'updateUserRoles').mockResolvedValue({ ...targetUser, roles: ['general_affairs_staff'] })

    renderPage(targetUser)

    await userEvent.click(await screen.findByRole('checkbox', { name: '一般社員' }))
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(usersApi.updateUserRoles).toHaveBeenCalledWith('user-1', ['general_affairs_staff']),
    )
  })

  it('prefills the hire date when the user already has one', async () => {
    renderPage({ ...targetUser, hire_date: '2024-04-01' })

    expect(await screen.findByLabelText('入社日(有給の自動付与に使用)')).toHaveValue('2024-04-01')
  })

  it('saves the entered hire date', async () => {
    vi.spyOn(usersApi, 'updateUserHireDate').mockResolvedValue({ ...targetUser, hire_date: '2024-04-01' })

    renderPage(targetUser)

    await userEvent.type(await screen.findByLabelText('入社日(有給の自動付与に使用)'), '2024-04-01')
    await userEvent.click(screen.getByRole('button', { name: '入社日を保存する' }))

    await waitFor(() =>
      expect(usersApi.updateUserHireDate).toHaveBeenCalledWith('user-1', '2024-04-01'),
    )
  })

  it('prefills and saves the termination date', async () => {
    vi.spyOn(usersApi, 'updateUserTerminationDate').mockResolvedValue({ ...targetUser, termination_date: '2026-03-31' })
    renderPage({ ...targetUser, termination_date: '2026-03-31' })

    expect(await screen.findByLabelText('退社日(未設定なら在籍中)')).toHaveValue('2026-03-31')
    await userEvent.clear(screen.getByLabelText('退社日(未設定なら在籍中)'))
    await userEvent.click(screen.getByRole('button', { name: '退社日を保存する' }))

    await waitFor(() => expect(usersApi.updateUserTerminationDate).toHaveBeenCalledWith('user-1', null))
  })

  it('defaults to using the company default work style when no monthly assignment exists', async () => {
    renderPage(targetUser)

    expect(await screen.findByText('働き方(' + formatDate(new Date()).slice(0, 7) + ')')).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /会社のデフォルトを使用/ })).toBeChecked()
    expect(screen.getByText(/通常勤務/)).toBeInTheDocument()
  })

  it('assigns a specific work style for the current month', async () => {
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'assignUserWorkStyleForMonth').mockResolvedValue({
      id: 'assignment-10',
      user_id: 'user-1',
      year_month: formatDate(new Date()).slice(0, 7),
      work_style_id: 'work-style-2',
      work_style: { id: 'work-style-2', code: 'flex', name: 'フレックスタイム制' },
      assigned_by_user_id: 'admin-1',
    })
    renderPage(targetUser)

    await userEvent.click(await screen.findByRole('radio', { name: '別の働き方を指定' }))
    await userEvent.selectOptions(screen.getByLabelText('指定する働き方'), 'フレックスタイム制')
    await userEvent.click(screen.getByRole('button', { name: '働き方を保存する' }))

    await waitFor(() =>
      expect(userWorkStyleMonthlyAssignmentsApi.assignUserWorkStyleForMonth).toHaveBeenCalledWith({
        user_id: 'user-1',
        year_month: formatDate(new Date()).slice(0, 7),
        work_style_id: 'work-style-2',
      }),
    )
  })

  it('reverts to the company default by removing the current months assignment', async () => {
    const currentYearMonth = formatDate(new Date()).slice(0, 7)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'removeUserWorkStyleMonthlyAssignment').mockResolvedValue(undefined)
    renderPage(targetUser, {
      workStyleHistory: [
        {
          id: 'assignment-42',
          user_id: 'user-1',
          year_month: currentYearMonth,
          work_style_id: 'work-style-2',
          work_style: { id: 'work-style-2', code: 'flex', name: 'フレックスタイム制' },
          assigned_by_user_id: 'admin-1',
        },
      ],
    })

    expect(await screen.findByRole('radio', { name: '別の働き方を指定' })).toBeChecked()

    await userEvent.click(screen.getByRole('radio', { name: /会社のデフォルトを使用/ }))
    await userEvent.click(screen.getByRole('button', { name: '働き方を保存する' }))

    await waitFor(() => expect(userWorkStyleMonthlyAssignmentsApi.removeUserWorkStyleMonthlyAssignment).toHaveBeenCalledWith('assignment-42'))
  })
})
