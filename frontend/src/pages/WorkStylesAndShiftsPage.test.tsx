import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as employeeRotationAssignmentsApi from '../api/employeeRotationAssignments'
import * as employeeShiftAssignmentsApi from '../api/employeeShiftAssignments'
import * as rotationPatternsApi from '../api/rotationPatterns'
import * as shiftPatternsApi from '../api/shiftPatterns'
import * as userWorkStyleMonthlyAssignmentsApi from '../api/userWorkStyleMonthlyAssignments'
import * as usersApi from '../api/users'
import * as workCalendarsApi from '../api/workCalendars'
import * as workStylesApi from '../api/workStyles'
import type { Paginated, RotationPattern, ShiftPattern, User, WorkCalendar, WorkStyle } from '../api/types'
import { WorkStylesAndShiftsPage } from './WorkStylesAndShiftsPage'

const calendar: WorkCalendar = {
  id: 1,
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'published',
}

const workStyle: WorkStyle = {
  id: 1,
  code: 'standard',
  name: '標準勤務',
  work_time_system: '通常労働時間制',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  calendar_id: 1,
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
  applied_employee_count: 3,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

const targetUser: User = {
  id: 5,
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage({
  workStyles = [workStyle],
  shiftPatterns = [],
  rotationPatterns = [],
}: {
  workStyles?: WorkStyle[]
  shiftPatterns?: ShiftPattern[]
  rotationPatterns?: RotationPattern[]
} = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workStylesApi, 'fetchWorkStyles').mockResolvedValue(workStyles)
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])
  vi.spyOn(shiftPatternsApi, 'fetchShiftPatterns').mockResolvedValue(shiftPatterns)
  vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
  vi.spyOn(rotationPatternsApi, 'fetchRotationPatterns').mockResolvedValue(rotationPatterns)
  vi.spyOn(employeeRotationAssignmentsApi, 'fetchEmployeeRotationAssignment').mockResolvedValue(null)

  return render(
    <QueryClientProvider client={queryClient}>
      <WorkStylesAndShiftsPage />
    </QueryClientProvider>,
  )
}

describe('WorkStylesAndShiftsPage', () => {
  it('lists existing work styles', async () => {
    renderPage()

    expect(await screen.findByText('標準勤務', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByText('通常労働時間制')).toBeInTheDocument()
  })

  it('shows the applied employee count, last updated date, and configuration warnings', async () => {
    const shiftWorkStyle: WorkStyle = {
      ...workStyle,
      id: 2,
      code: 'shift-3',
      name: '3交代制',
      is_shift_based: true,
      is_default: false,
      applied_employee_count: 12,
      active_shift_pattern_count: 0,
      updated_at: '2026-07-05T09:00:00+09:00',
      configuration_warnings: ['シフトパターンが割り当てられた勤務予定がまだありません。'],
    }
    renderPage({ workStyles: [workStyle, shiftWorkStyle] })

    await screen.findByText('標準勤務', { selector: 'strong' })
    expect(screen.getByText('適用社員数 3名')).toBeInTheDocument()
    expect(screen.getByText('適用社員数 12名')).toBeInTheDocument()
    expect(screen.getByText('最終更新 2026-07-01')).toBeInTheDocument()
    expect(screen.getByText('最終更新 2026-07-05')).toBeInTheDocument()
    expect(screen.getByText('使用中の勤務シフト 0件')).toBeInTheDocument()
    expect(screen.getByText('シフトパターンが割り当てられた勤務予定がまだありません。')).toBeInTheDocument()
  })

  it('does not show the onboarding card once a default work style exists', async () => {
    renderPage()

    await screen.findByText('標準勤務', { selector: 'strong' })
    expect(screen.queryByText('一般的な勤務設定を用意しました')).not.toBeInTheDocument()
  })

  it('shows the onboarding card and creates the standard default work style', async () => {
    vi.spyOn(workStylesApi, 'createDefaultWorkStyle').mockResolvedValue({
      ...workStyle,
      id: 9,
      code: 'standard',
    })
    renderPage({ workStyles: [] })

    await userEvent.click(await screen.findByRole('button', { name: 'この設定で始める' }))

    await waitFor(() => expect(workStylesApi.createDefaultWorkStyle).toHaveBeenCalledWith({}))
  })

  it('creates the default work style with edited values from the onboarding card', async () => {
    vi.spyOn(workStylesApi, 'createDefaultWorkStyle').mockResolvedValue({
      ...workStyle,
      id: 9,
      code: 'standard',
      name: '標準勤務(編集済み)',
    })
    renderPage({ workStyles: [] })

    await userEvent.click(await screen.findByRole('button', { name: '内容を変更する' }))
    const nameInput = screen.getAllByLabelText('名称')[0]
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, '標準勤務(編集済み)')
    await userEvent.click(screen.getByRole('button', { name: '保存して開始する' }))

    await waitFor(() =>
      expect(workStylesApi.createDefaultWorkStyle).toHaveBeenCalledWith({
        name: '標準勤務(編集済み)',
        default_start_time: '09:00',
        default_end_time: '18:00',
        default_break_minutes: 60,
      }),
    )
  })

  it('switches the default work style from the list', async () => {
    const otherWorkStyle: WorkStyle = {
      ...workStyle,
      id: 2,
      code: 'flex',
      name: 'フレックス標準',
      is_default: false,
      system_generated: false,
    }
    vi.spyOn(workStylesApi, 'setDefaultWorkStyle').mockResolvedValue({ ...otherWorkStyle, is_default: true })
    renderPage({ workStyles: [workStyle, otherWorkStyle] })

    await screen.findByText('フレックス標準', { selector: 'strong' })
    await userEvent.click(screen.getByRole('button', { name: 'デフォルトに設定' }))

    await waitFor(() => expect(workStylesApi.setDefaultWorkStyle).toHaveBeenCalledWith(2))
  })

  it('creates a new work style with the entered values', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 2, code: 'discretionary' })
    renderPage()

    await userEvent.type(await screen.findByLabelText('コード'), 'discretionary')
    await userEvent.type(screen.getByLabelText('名称'), '裁量労働制勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith({
        code: 'discretionary',
        name: '裁量労働制勤務',
        work_time_system: 'discretionary',
        prescribed_daily_minutes: 480,
        prescribed_weekly_minutes: 2400,
        default_start_time: undefined,
        default_end_time: undefined,
        default_break_minutes: undefined,
        calendar_id: 1,
        is_shift_based: false,
        legal_holiday_rule: undefined,
        four_week_period_start_date: undefined,
      }),
    )
  })

  it('creates a shift-based work style with a four-weeks-four-days legal holiday rule', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 3, code: 'shift' })
    renderPage()

    await userEvent.type(await screen.findByLabelText('コード'), 'shift')
    await userEvent.type(screen.getByLabelText('名称'), 'シフト勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '通常勤務')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByLabelText('シフト制'))
    await userEvent.selectOptions(screen.getByLabelText('法定休日の与え方'), '4週4日以上(変形休日制)')
    await userEvent.type(screen.getByLabelText('4週間の起算日'), '2026-06-01')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({
          is_shift_based: true,
          legal_holiday_rule: 'four_weeks_four_days',
          four_week_period_start_date: '2026-06-01',
        }),
      ),
    )
  })

  it('creates a flex work style with core time and flexible time settings', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 4, code: 'flex' })
    renderPage()

    await userEvent.type(await screen.findByLabelText('コード'), 'flex')
    await userEvent.type(screen.getByLabelText('名称'), 'フレックスタイム制')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), 'フレックスタイム制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    fireEvent.change(screen.getByLabelText('勤務可能開始時刻'), { target: { value: '05:00' } })
    fireEvent.change(screen.getByLabelText('勤務可能終了時刻'), { target: { value: '22:00' } })
    await userEvent.click(screen.getByLabelText('コアタイムあり'))
    fireEvent.change(screen.getByLabelText('コアタイム開始時刻'), { target: { value: '10:00' } })
    fireEvent.change(screen.getByLabelText('コアタイム終了時刻'), { target: { value: '15:00' } })
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({
          work_time_system: 'flex',
          core_time_enabled: true,
          core_time_start: '10:00',
          core_time_end: '15:00',
          flexible_time_start: '05:00',
          flexible_time_end: '22:00',
        }),
      ),
    )
  })

  it('generates and shows shifts for the selected user and period', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'generateShiftAssignments').mockResolvedValue([
      {
        id: 1,
        user_id: 5,
        work_date: '2026-08-01',
        work_style_id: 1,
        shift_pattern_id: null,
        day_type: 'weekday',
        is_working_day: true,
        is_legal_holiday: false,
        is_company_holiday: false,
        planned_start_at: '2026-08-01T09:00:00+09:00',
        planned_end_at: '2026-08-01T18:00:00+09:00',
        planned_break_minutes: 60,
        planned_break_start_at: null,
        planned_break_end_at: null,
        is_published: true,
        is_manually_overridden: false,
      },
    ])
    vi.spyOn(employeeShiftAssignmentsApi, 'fetchShiftAssignments').mockResolvedValue([])

    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態'), '標準勤務')
    await userEvent.type(screen.getByLabelText('開始日'), '2026-08-01')
    await userEvent.type(screen.getByLabelText('終了日'), '2026-08-31')
    await userEvent.click(screen.getByRole('button', { name: '生成する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generateShiftAssignments).toHaveBeenCalledWith({
        user_id: 5,
        work_style_id: 1,
        from: '2026-08-01',
        to: '2026-08-31',
      }),
    )
  })

  it('assigns a monthly work style to a user and shows the assignment history', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'assignUserWorkStyleForMonth').mockResolvedValue({
      id: 1,
      user_id: 5,
      year_month: '2026-11',
      work_style_id: 1,
      work_style: { id: 1, code: 'standard', name: '標準勤務' },
      assigned_by_user_id: 1,
    })
    renderPage()
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.type(screen.getByLabelText('対象年月'), '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))

    expect(await screen.findByText('変更内容の確認')).toBeInTheDocument()
    expect(screen.getByText('未設定(会社のデフォルトにフォールバック)')).toBeInTheDocument()

    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([
      {
        id: 1,
        user_id: 5,
        year_month: '2026-11',
        work_style_id: 1,
        work_style: { id: 1, code: 'standard', name: '標準勤務' },
        assigned_by_user_id: 1,
      },
    ])
    await userEvent.click(screen.getByRole('button', { name: 'この内容で保存する' }))

    await waitFor(() =>
      expect(userWorkStyleMonthlyAssignmentsApi.assignUserWorkStyleForMonth).toHaveBeenCalledWith({
        user_id: 5,
        year_month: '2026-11',
        work_style_id: 1,
      }),
    )

    expect(await screen.findByText('2026-11: 標準勤務')).toBeInTheDocument()
  })

  it('shows the current work style for the month and hides the confirmation when an input changes', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([
      {
        id: 1,
        user_id: 5,
        year_month: '2026-11',
        work_style_id: 1,
        work_style: { id: 1, code: 'standard', name: '標準勤務' },
        assigned_by_user_id: 1,
      },
    ])
    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.type(screen.getByLabelText('対象年月'), '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))

    expect(await screen.findByText('変更内容の確認')).toBeInTheDocument()
    expect(screen.getAllByText('標準勤務').some((el) => el.tagName === 'DD')).toBe(true)

    await userEvent.type(screen.getByLabelText('対象年月'), '2026-12')

    expect(screen.queryByText('変更内容の確認')).not.toBeInTheDocument()
  })

  it('auto-generates shift assignments for the month when the option is checked', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'assignUserWorkStyleForMonth').mockResolvedValue({
      id: 2,
      user_id: 5,
      year_month: '2026-11',
      work_style_id: 1,
      work_style: { id: 1, code: 'standard', name: '標準勤務' },
      assigned_by_user_id: 1,
    })
    vi.spyOn(employeeShiftAssignmentsApi, 'generateShiftAssignments').mockResolvedValue([])
    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.type(screen.getByLabelText('対象年月'), '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))
    await userEvent.click(await screen.findByLabelText(/この働き方をもとに2026-11の勤務予定を自動生成する/))
    await userEvent.click(screen.getByRole('button', { name: 'この内容で保存する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generateShiftAssignments).toHaveBeenCalledWith({
        user_id: 5,
        work_style_id: 1,
        from: '2026-11-01',
        to: '2026-11-30',
      }),
    )
  })

  it('creates a shift pattern with the entered values', async () => {
    const pattern = {
      id: 1,
      code: 'night_shift',
      name: '深夜勤',
      start_time: '22:00',
      end_time: '06:00',
      crosses_midnight: true,
      break_minutes: 60,
      break_start_time: null,
      break_end_time: null,
      prescribed_work_minutes: 420,
    }
    vi.spyOn(shiftPatternsApi, 'createShiftPattern').mockResolvedValue(pattern)
    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.type(screen.getByLabelText('パターンコード'), 'night_shift')
    await userEvent.type(screen.getByLabelText('パターン名称'), '深夜勤')
    fireEvent.change(screen.getByLabelText('開始時刻'), { target: { value: '22:00' } })
    fireEvent.change(screen.getByLabelText('終了時刻'), { target: { value: '06:00' } })
    await userEvent.type(screen.getByLabelText('休憩(分)'), '60')
    await userEvent.type(screen.getByLabelText('所定労働時間(分)'), '420')
    await userEvent.click(screen.getByLabelText('日跨ぎ勤務(終了時刻は翌日)'))
    await userEvent.click(screen.getByRole('button', { name: 'シフトパターンを作成する' }))

    await waitFor(() =>
      expect(shiftPatternsApi.createShiftPattern).toHaveBeenCalledWith({
        code: 'night_shift',
        name: '深夜勤',
        start_time: '22:00',
        end_time: '06:00',
        crosses_midnight: true,
        break_minutes: 60,
        prescribed_work_minutes: 420,
      }),
    )
  })

  it('assigns a shift pattern to an employee day on the shift schedule board', async () => {
    const shiftWorkStyle: WorkStyle = {
      ...workStyle,
      id: 2,
      code: 'shift-3',
      name: '3交代制',
      is_shift_based: true,
      is_default: false,
      system_generated: false,
    }
    const pattern = {
      id: 1,
      code: 'day_shift',
      name: '日勤',
      start_time: '09:00',
      end_time: '18:00',
      crosses_midnight: false,
      break_minutes: 60,
      break_start_time: null,
      break_end_time: null,
      prescribed_work_minutes: 480,
    }
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'assignShiftPatternDay').mockResolvedValue({
      id: 10,
      user_id: 5,
      work_date: '2026-08-10',
      work_style_id: 2,
      shift_pattern_id: 1,
      day_type: 'day_shift',
      is_working_day: true,
      is_legal_holiday: false,
      is_company_holiday: false,
      planned_start_at: '2026-08-10T09:00:00+09:00',
      planned_end_at: '2026-08-10T18:00:00+09:00',
      planned_break_minutes: 60,
      planned_break_start_at: null,
      planned_break_end_at: null,
      is_published: false,
      is_manually_overridden: true,
    })

    renderPage({ workStyles: [workStyle, shiftWorkStyle], shiftPatterns: [pattern] })
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(シフト表)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態(シフト表)'), '3交代制')
    await userEvent.type(screen.getByLabelText('対象日'), '2026-08-10')
    await userEvent.selectOptions(screen.getByLabelText('シフトパターン'), '日勤')
    await userEvent.click(screen.getByRole('button', { name: '割り当てる(下書き)' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.assignShiftPatternDay).toHaveBeenCalledWith({
        user_id: 5,
        work_style_id: 2,
        work_date: '2026-08-10',
        shift_pattern_id: 1,
        is_legal_holiday: false,
      }),
    )
  })

  it('creates a rotation pattern from a sequence of shift patterns', async () => {
    const shiftWorkStyle: WorkStyle = { ...workStyle, id: 2, code: 'shift-3', name: '3交代制', is_shift_based: true }
    const aShift: ShiftPattern = {
      id: 1,
      code: 'a-shift',
      name: 'A勤',
      start_time: '06:00',
      end_time: '14:00',
      crosses_midnight: false,
      break_minutes: 45,
      break_start_time: null,
      break_end_time: null,
      prescribed_work_minutes: 435,
    }
    const offShift: ShiftPattern = {
      id: 2,
      code: 'off',
      name: '休日',
      start_time: null,
      end_time: null,
      crosses_midnight: false,
      break_minutes: 0,
      break_start_time: null,
      break_end_time: null,
      prescribed_work_minutes: 0,
    }
    vi.spyOn(rotationPatternsApi, 'createRotationPattern').mockResolvedValue({
      id: 1,
      work_style_id: 2,
      name: '2交代3班ローテーション',
      cycle_length: 2,
      items: [],
    })
    renderPage({ workStyles: [workStyle, shiftWorkStyle], shiftPatterns: [aShift, offShift] })
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.selectOptions(screen.getByLabelText('対象の働き方(シフト制のみ)'), '3交代制')
    await userEvent.type(screen.getByLabelText('ローテーションパターン名称'), '2交代3班ローテーション')
    await userEvent.selectOptions(screen.getByLabelText('1日目のシフトパターン'), 'A勤')
    await userEvent.click(screen.getByRole('button', { name: '周期に追加する' }))
    await userEvent.selectOptions(screen.getByLabelText('2日目のシフトパターン'), '休日')
    await userEvent.click(screen.getByRole('button', { name: 'ローテーションパターンを作成する' }))

    await waitFor(() =>
      expect(rotationPatternsApi.createRotationPattern).toHaveBeenCalledWith({
        work_style_id: 2,
        name: '2交代3班ローテーション',
        items: [
          { sequence: 0, shift_pattern_id: 1 },
          { sequence: 1, shift_pattern_id: 2 },
        ],
      }),
    )
  })

  it('assigns a rotation, previews it, and generates shifts for the period', async () => {
    const pattern: RotationPattern = {
      id: 1,
      work_style_id: 2,
      name: '2交代3班ローテーション',
      cycle_length: 2,
      items: [
        { sequence: 0, shift_pattern_id: 1, shift_pattern_name: 'A勤', shift_pattern_code: 'a-shift' },
        { sequence: 1, shift_pattern_id: 2, shift_pattern_name: '休日', shift_pattern_code: 'off' },
      ],
    }
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeRotationAssignmentsApi, 'assignEmployeeRotation').mockResolvedValue({
      id: 1,
      user_id: 5,
      rotation_pattern_id: 1,
      rotation_pattern_name: '2交代3班ローテーション',
      rotation_start_date: '2026-08-01',
      rotation_start_position: 0,
    })
    vi.spyOn(rotationPatternsApi, 'previewRotationPattern').mockResolvedValue({
      days: [
        { date: '2026-08-01', sequence: 0, shift_pattern_id: 1, shift_pattern_name: 'A勤', shift_pattern_code: 'a-shift' },
        { date: '2026-08-02', sequence: 1, shift_pattern_id: 2, shift_pattern_name: '休日', shift_pattern_code: 'off' },
      ],
    })
    vi.spyOn(employeeRotationAssignmentsApi, 'generateRotationShiftAssignments').mockResolvedValue({
      generated: [],
      generated_count: 2,
      skipped_dates: [],
    })
    renderPage({ rotationPatterns: [pattern] })
    await screen.findByText('標準勤務', { selector: 'strong' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(ローテーション)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('ローテーションパターン'), '2交代3班ローテーション')
    await userEvent.type(screen.getByLabelText('ローテーション開始日'), '2026-08-01')
    await userEvent.click(screen.getByRole('button', { name: 'ローテーションを割り当てる' }))

    await waitFor(() =>
      expect(employeeRotationAssignmentsApi.assignEmployeeRotation).toHaveBeenCalledWith({
        user_id: 5,
        rotation_pattern_id: 1,
        rotation_start_date: '2026-08-01',
        rotation_start_position: 0,
      }),
    )

    await userEvent.type(screen.getByLabelText('生成開始日'), '2026-08-01')
    await userEvent.type(screen.getByLabelText('生成終了日'), '2026-08-02')
    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    expect(await screen.findByText('2026-08-01: A勤')).toBeInTheDocument()
    expect(screen.getByText('2026-08-02: 休日')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '勤務予定を生成する' }))

    await waitFor(() =>
      expect(employeeRotationAssignmentsApi.generateRotationShiftAssignments).toHaveBeenCalledWith({
        user_id: 5,
        from: '2026-08-01',
        to: '2026-08-02',
        overwrite_mode: 'skip_edited',
      }),
    )
    expect(await screen.findByText('2件生成しました。')).toBeInTheDocument()
  })
})
