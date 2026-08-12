import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { pickYearMonth } from '../../test-support/pickerInteractions'
import { describe, expect, it, vi } from 'vitest'
import * as employeeShiftAssignmentsApi from '../../api/employeeShiftAssignments'
import * as userWorkStyleMonthlyAssignmentsApi from '../../api/userWorkStyleMonthlyAssignments'
import * as usersApi from '../../api/users'
import * as workCalendarsApi from '../../api/workCalendars'
import * as workStylesApi from '../../api/workStyles'
import type { Paginated, User, WorkCalendar, WorkStyle } from '../../api/types'
import { pickDate, pickTime } from '../../test-support/pickerInteractions'
import { WorkStylesPage } from './WorkStylesPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
}

const workStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '標準勤務',
  work_time_system: '通常労働時間制',
  prescribed_daily_minutes: 480,
  deemed_daily_minutes: null,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  company_calendar_id: 'calendar-1',
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
  id: 'user-5',
  name: '対象社員',
  email: 'taisho@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage({ workStyles = [workStyle] }: { workStyles?: WorkStyle[] } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workStylesApi, 'fetchWorkStyles').mockResolvedValue(workStyles)
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])
  vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])

  return render(
    <QueryClientProvider client={queryClient}>
      <WorkStylesPage />
    </QueryClientProvider>,
  )
}

describe('WorkStylesPage', () => {
  it('lists existing work styles', async () => {
    renderPage()

    expect(await screen.findByText('標準勤務', { selector: 'strong, td' })).toBeInTheDocument()
    expect(screen.getByText('通常労働時間制')).toBeInTheDocument()
  })

  it('shows the applied employee count, last updated date, and configuration warnings', async () => {
    const shiftWorkStyle: WorkStyle = {
      ...workStyle,
      id: 'work-style-2',
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

    await screen.findByText('標準勤務', { selector: 'strong, td' })
    expect(screen.getByText('適用社員数 3名')).toBeInTheDocument()
    expect(screen.getByText('適用社員数 12名')).toBeInTheDocument()
    expect(screen.getByText('最終更新 2026-07-01')).toBeInTheDocument()
    expect(screen.getByText('最終更新 2026-07-05')).toBeInTheDocument()
    expect(screen.getByText('使用中の勤務シフト 0件')).toBeInTheDocument()
    expect(screen.getByText('シフトパターンが割り当てられた勤務予定がまだありません。')).toBeInTheDocument()
  })

  it('does not show the onboarding card once a default work style exists', async () => {
    renderPage()

    await screen.findByText('標準勤務', { selector: 'strong, td' })
    expect(screen.queryByText('一般的な勤務設定を用意しました')).not.toBeInTheDocument()
  })

  it('shows the onboarding card and creates the standard default work style', async () => {
    vi.spyOn(workStylesApi, 'createDefaultWorkStyle').mockResolvedValue({
      ...workStyle,
      id: 'work-style-9',
      code: 'standard',
    })
    renderPage({ workStyles: [] })

    await userEvent.click(await screen.findByRole('button', { name: 'この設定で始める' }))

    await waitFor(() => expect(workStylesApi.createDefaultWorkStyle).toHaveBeenCalledWith({}))
  })

  it('creates the default work style with edited values from the onboarding card', async () => {
    vi.spyOn(workStylesApi, 'createDefaultWorkStyle').mockResolvedValue({
      ...workStyle,
      id: 'work-style-9',
      code: 'standard',
      name: '標準勤務(編集済み)',
    })
    renderPage({ workStyles: [] })

    await userEvent.click(await screen.findByRole('button', { name: '内容を変更する' }))
    const nameInput = screen.getByLabelText('名称')
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
      id: 'work-style-2',
      code: 'flex',
      name: 'フレックス標準',
      is_default: false,
      system_generated: false,
    }
    vi.spyOn(workStylesApi, 'setDefaultWorkStyle').mockResolvedValue({ ...otherWorkStyle, is_default: true })
    renderPage({ workStyles: [workStyle, otherWorkStyle] })

    await screen.findByText('フレックス標準', { selector: 'strong, td' })
    await userEvent.click(screen.getByRole('button', { name: 'デフォルトに設定' }))

    await waitFor(() => expect(workStylesApi.setDefaultWorkStyle).toHaveBeenCalledWith('work-style-2'))
  })

  it('edits an existing (including system-generated default) work style from a modal', async () => {
    vi.spyOn(workStylesApi, 'updateWorkStyle').mockResolvedValue({ ...workStyle, name: '標準勤務(改)' })
    renderPage()

    await screen.findByText('標準勤務', { selector: 'strong, td' })
    await userEvent.click(screen.getByRole('button', { name: '編集' }))

    expect(await screen.findByRole('heading', { name: '勤務形態を編集(標準勤務)' })).toBeInTheDocument()
    const nameField = screen.getByLabelText('名称')
    expect(nameField).toHaveValue('標準勤務')
    await userEvent.clear(nameField)
    await userEvent.type(nameField, '標準勤務(改)')
    await userEvent.click(screen.getByRole('button', { name: '更新する' }))

    await waitFor(() =>
      expect(workStylesApi.updateWorkStyle).toHaveBeenCalledWith(
        'work-style-1',
        expect.objectContaining({ code: 'standard', name: '標準勤務(改)' }),
      ),
    )
  }, 15000)

  it('creates a new work style with the entered values from the registration modal', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-2', code: 'discretionary' })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新規登録' }))
    expect(await screen.findByRole('heading', { name: '勤務形態を登録' })).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('コード'), 'discretionary')
    await userEvent.type(screen.getByLabelText('名称'), '裁量労働制勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.type(screen.getByLabelText('みなし労働時間(分/日)'), '540')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith({
        code: 'discretionary',
        name: '裁量労働制勤務',
        work_time_system: 'discretionary',
        prescribed_daily_minutes: 480,
        prescribed_weekly_minutes: 2400,
        deemed_daily_minutes: 540,
        default_start_time: undefined,
        default_end_time: undefined,
        default_break_minutes: undefined,
        default_break_start_time: undefined,
        default_break_end_time: undefined,
        auto_break_enabled: false,
        company_calendar_id: 'calendar-1',
        is_shift_based: false,
        legal_holiday_rule: undefined,
        four_week_period_start_date: undefined,
      }),
    )
  }, 15000)

  it('creates a shift-based work style with a four-weeks-four-days legal holiday rule', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-3', code: 'shift' })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新規登録' }))
    await userEvent.type(await screen.findByLabelText('コード'), 'shift')
    await userEvent.type(screen.getByLabelText('名称'), 'シフト勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '通常勤務')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByLabelText('シフト制'))
    await userEvent.selectOptions(screen.getByLabelText('法定休日の与え方'), '4週4日以上(変形休日制)')
    await pickDate(userEvent.setup(), '4週間の起算日', '2026-06-01')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({
          is_shift_based: true,
          legal_holiday_rule: 'four_weeks_four_days',
          four_week_period_start_date: '2026-06-01',
        }),
      ),
    )
  }, 15000)

  it('creates a flex work style with core time and flexible time settings', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-4', code: 'flex' })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新規登録' }))
    await userEvent.type(await screen.findByLabelText('コード'), 'flex')
    await userEvent.type(screen.getByLabelText('名称'), 'フレックスタイム制')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), 'フレックスタイム制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await pickTime(userEvent.setup(), '勤務可能開始時刻', '05:00')
    await pickTime(userEvent.setup(), '勤務可能終了時刻', '22:00')
    await userEvent.click(screen.getByLabelText('コアタイムあり'))
    await pickTime(userEvent.setup(), 'コアタイム開始時刻', '10:00')
    await pickTime(userEvent.setup(), 'コアタイム終了時刻', '15:00')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

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
  }, 15000)

  it('paginates the work style list on the client side', async () => {
    const manyWorkStyles = Array.from({ length: 12 }, (_, i) => ({
      ...workStyle,
      id: `work-style-${i}`,
      code: `code-${i}`,
      name: `勤務形態${i}`,
      is_default: i === 0,
    }))
    renderPage({ workStyles: manyWorkStyles })

    await screen.findByText('勤務形態0', { selector: 'strong, td' })
    const table = screen.getByRole('table')
    expect(within(table).queryByText('勤務形態10')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    expect(await within(table).findByText('勤務形態10')).toBeInTheDocument()
    expect(within(table).queryByText('勤務形態0')).not.toBeInTheDocument()
  })

  it('assigns a monthly work style to a user and shows the assignment history', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'assignUserWorkStyleForMonth').mockResolvedValue({
      id: 'monthly-assignment-1',
      user_id: 'user-5',
      year_month: '2026-11',
      work_style_id: 'work-style-1',
      work_style: { id: 'work-style-1', code: 'standard', name: '標準勤務' },
      assigned_by_user_id: 'admin-1',
    })
    renderPage()
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
    await screen.findByText('標準勤務', { selector: 'strong, td' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await pickYearMonth(userEvent.setup(), '対象年月', '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))

    expect(await screen.findByText('変更内容の確認')).toBeInTheDocument()
    expect(screen.getByText('未設定(会社のデフォルトにフォールバック)')).toBeInTheDocument()

    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([
      {
        id: 'monthly-assignment-1',
        user_id: 'user-5',
        year_month: '2026-11',
        work_style_id: 'work-style-1',
        work_style: { id: 'work-style-1', code: 'standard', name: '標準勤務' },
        assigned_by_user_id: 'admin-1',
      },
    ])
    await userEvent.click(screen.getByRole('button', { name: 'この内容で保存する' }))

    await waitFor(() =>
      expect(userWorkStyleMonthlyAssignmentsApi.assignUserWorkStyleForMonth).toHaveBeenCalledWith({
        user_id: 'user-5',
        year_month: '2026-11',
        work_style_id: 'work-style-1',
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
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([
      {
        id: 'monthly-assignment-1',
        user_id: 'user-5',
        year_month: '2026-11',
        work_style_id: 'work-style-1',
        work_style: { id: 'work-style-1', code: 'standard', name: '標準勤務' },
        assigned_by_user_id: 'admin-1',
      },
    ])
    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong, td' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await pickYearMonth(userEvent.setup(), '対象年月', '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))

    expect(await screen.findByText('変更内容の確認')).toBeInTheDocument()
    expect(screen.getAllByText('標準勤務').some((el) => el.tagName === 'DD')).toBe(true)

    await pickYearMonth(userEvent.setup(), '対象年月', '2026-12')

    expect(screen.queryByText('変更内容の確認')).not.toBeInTheDocument()
  })

  it('auto-generates shift assignments for the month when the option is checked', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(usersApi, 'fetchUser').mockResolvedValue(targetUser)
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'fetchUserWorkStyleMonthlyAssignments').mockResolvedValue([])
    vi.spyOn(userWorkStyleMonthlyAssignmentsApi, 'assignUserWorkStyleForMonth').mockResolvedValue({
      id: 'monthly-assignment-2',
      user_id: 'user-5',
      year_month: '2026-11',
      work_style_id: 'work-style-1',
      work_style: { id: 'work-style-1', code: 'standard', name: '標準勤務' },
      assigned_by_user_id: 'admin-1',
    })
    vi.spyOn(employeeShiftAssignmentsApi, 'generateShiftAssignments').mockResolvedValue([])
    renderPage()
    await screen.findByText('標準勤務', { selector: 'strong, td' })

    await userEvent.click(screen.getByRole('combobox', { name: '働き方の対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await pickYearMonth(userEvent.setup(), '対象年月', '2026-11')
    await userEvent.selectOptions(screen.getByLabelText('働き方'), '標準勤務')
    await userEvent.click(screen.getByRole('button', { name: '変更内容を確認する' }))
    await userEvent.click(await screen.findByLabelText(/この働き方をもとに2026-11の勤務予定を自動生成する/))
    await userEvent.click(screen.getByRole('button', { name: 'この内容で保存する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generateShiftAssignments).toHaveBeenCalledWith({
        user_id: 'user-5',
        work_style_id: 'work-style-1',
        from: '2026-11-01',
        to: '2026-11-30',
      }),
    )
  })
})
