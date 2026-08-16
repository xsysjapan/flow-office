import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { pickYearMonth } from '../../test-support/pickerInteractions'
import { describe, expect, it, vi } from 'vitest'
import * as employeeRotationAssignmentsApi from '../../api/employeeRotationAssignments'
import * as employeeShiftAssignmentsApi from '../../api/employeeShiftAssignments'
import * as rotationPatternsApi from '../../api/rotationPatterns'
import * as shiftPatternsApi from '../../api/shiftPatterns'
import * as usersApi from '../../api/users'
import * as workStylesApi from '../../api/workStyles'
import type { Paginated, RotationPattern, ShiftPattern, User, WorkStyle } from '../../api/types'
import { pickDate, pickTime } from '../../test-support/pickerInteractions'
import { ShiftsPage } from './ShiftsPage'

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
  vi.spyOn(shiftPatternsApi, 'fetchShiftPatterns').mockResolvedValue(shiftPatterns)
  vi.spyOn(rotationPatternsApi, 'fetchRotationPatterns').mockResolvedValue(rotationPatterns)
  vi.spyOn(employeeRotationAssignmentsApi, 'fetchEmployeeRotationAssignment').mockResolvedValue(null)

  return render(
    <QueryClientProvider client={queryClient}>
      <ShiftsPage />
    </QueryClientProvider>,
  )
}

describe('ShiftsPage', () => {
  it('generates and shows shifts for the selected user and period', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'generateShiftAssignments').mockResolvedValue([
      {
        id: 'assignment-1',
        user_id: 'user-5',
        work_date: '2026-08-01',
        work_style_id: 'work-style-1',
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
    await screen.findByRole('heading', { name: 'シフト生成・確認' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態'), '標準勤務')
    await pickDate(userEvent.setup(), '開始日', '2026-08-01')
    await pickDate(userEvent.setup(), '終了日', '2026-08-31')
    await userEvent.click(screen.getByRole('button', { name: '生成する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generateShiftAssignments).toHaveBeenCalledWith({
        user_id: 'user-5',
        work_style_id: 'work-style-1',
        from: '2026-08-01',
        to: '2026-08-31',
      }),
    )
  }, 15000)

  it('creates a shift pattern with the entered values', async () => {
    const pattern = {
      id: 'shift-pattern-1',
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
    await screen.findByRole('heading', { name: 'シフトパターン' })

    await userEvent.type(screen.getByLabelText('パターンコード'), 'night_shift')
    await userEvent.type(screen.getByLabelText('パターン名称'), '深夜勤')
    await pickTime(userEvent.setup(), '開始時刻', '22:00')
    await pickTime(userEvent.setup(), '終了時刻', '06:00')
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
  }, 15000)

  it('assigns a shift pattern to an employee day on the shift schedule board', async () => {
    const shiftWorkStyle: WorkStyle = {
      ...workStyle,
      id: 'work-style-2',
      code: 'shift-3',
      name: '3交代制',
      is_shift_based: true,
      is_default: false,
      system_generated: false,
    }
    const pattern = {
      id: 'shift-pattern-1',
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
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'assignShiftPatternDay').mockResolvedValue({
      id: 'assignment-10',
      user_id: 'user-5',
      work_date: '2026-08-10',
      work_style_id: 'work-style-2',
      shift_pattern_id: 'shift-pattern-1',
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
    await screen.findByRole('heading', { name: '3交代制シフト表' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(シフト表)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態(シフト表)'), '3交代制')
    await pickDate(userEvent.setup(), '対象日', '2026-08-10')
    await userEvent.selectOptions(screen.getByLabelText('シフトパターン'), '日勤')
    await userEvent.click(screen.getByRole('button', { name: '割り当てる(下書き)' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.assignShiftPatternDay).toHaveBeenCalledWith({
        user_id: 'user-5',
        work_style_id: 'work-style-2',
        work_date: '2026-08-10',
        shift_pattern_id: 'shift-pattern-1',
        is_legal_holiday: false,
      }),
    )
  }, 15000)

  it('creates a rotation pattern from a sequence of shift patterns', async () => {
    const shiftWorkStyle: WorkStyle = { ...workStyle, id: 'work-style-2', code: 'shift-3', name: '3交代制', is_shift_based: true }
    const aShift: ShiftPattern = {
      id: 'shift-pattern-1',
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
      id: 'shift-pattern-2',
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
      id: 'rotation-pattern-1',
      work_style_id: 'work-style-2',
      name: '2交代3班ローテーション',
      cycle_length: 2,
      items: [],
    })
    renderPage({ workStyles: [workStyle, shiftWorkStyle], shiftPatterns: [aShift, offShift] })
    await screen.findByRole('heading', { name: 'ローテーションパターン' })
    const workStyleSelect = screen.getByLabelText('対象の働き方(シフト制のみ)')
    await within(workStyleSelect).findByRole('option', { name: '3交代制' })

    await userEvent.selectOptions(workStyleSelect, '3交代制')
    await userEvent.type(screen.getByLabelText('ローテーションパターン名称'), '2交代3班ローテーション')
    await userEvent.selectOptions(screen.getByLabelText('1日目のシフトパターン'), 'A勤')
    await userEvent.click(screen.getByRole('button', { name: '周期に追加する' }))
    await userEvent.selectOptions(screen.getByLabelText('2日目のシフトパターン'), '休日')
    await userEvent.click(screen.getByRole('button', { name: 'ローテーションパターンを作成する' }))

    await waitFor(() =>
      expect(rotationPatternsApi.createRotationPattern).toHaveBeenCalledWith({
        work_style_id: 'work-style-2',
        name: '2交代3班ローテーション',
        items: [
          { sequence: 0, shift_pattern_id: 'shift-pattern-1' },
          { sequence: 1, shift_pattern_id: 'shift-pattern-2' },
        ],
      }),
    )
  })

  it('assigns a rotation, previews it, and generates shifts for the period', async () => {
    const pattern: RotationPattern = {
      id: 'rotation-pattern-1',
      work_style_id: 'work-style-2',
      name: '2交代3班ローテーション',
      cycle_length: 2,
      items: [
        { sequence: 0, shift_pattern_id: 'shift-pattern-1', shift_pattern_name: 'A勤', shift_pattern_code: 'a-shift' },
        { sequence: 1, shift_pattern_id: 'shift-pattern-2', shift_pattern_name: '休日', shift_pattern_code: 'off' },
      ],
    }
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeRotationAssignmentsApi, 'assignEmployeeRotation').mockResolvedValue({
      id: 'rotation-assignment-1',
      user_id: 'user-5',
      rotation_pattern_id: 'rotation-pattern-1',
      rotation_pattern_name: '2交代3班ローテーション',
      rotation_start_date: '2026-08-01',
      rotation_start_position: 0,
    })
    vi.spyOn(rotationPatternsApi, 'previewRotationPattern').mockResolvedValue({
      days: [
        { date: '2026-08-01', sequence: 0, shift_pattern_id: 'shift-pattern-1', shift_pattern_name: 'A勤', shift_pattern_code: 'a-shift' },
        { date: '2026-08-02', sequence: 1, shift_pattern_id: 'shift-pattern-2', shift_pattern_name: '休日', shift_pattern_code: 'off' },
      ],
    })
    vi.spyOn(employeeRotationAssignmentsApi, 'generateRotationShiftAssignments').mockResolvedValue({
      generated: [],
      generated_count: 2,
      skipped_dates: [],
    })
    renderPage({ rotationPatterns: [pattern] })
    await screen.findByRole('heading', { name: 'ローテーションの割当・生成' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(ローテーション)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('ローテーションパターン'), '2交代3班ローテーション')
    await pickDate(userEvent.setup(), 'ローテーション開始日', '2026-08-01')
    await userEvent.click(screen.getByRole('button', { name: 'ローテーションを割り当てる' }))

    await waitFor(() =>
      expect(employeeRotationAssignmentsApi.assignEmployeeRotation).toHaveBeenCalledWith({
        user_id: 'user-5',
        rotation_pattern_id: 'rotation-pattern-1',
        rotation_start_date: '2026-08-01',
        rotation_start_position: 0,
      }),
    )

    await pickDate(userEvent.setup(), '生成開始日', '2026-08-01')
    await pickDate(userEvent.setup(), '生成終了日', '2026-08-02')
    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    expect(await screen.findByText('2026-08-01: A勤')).toBeInTheDocument()
    expect(screen.getByText('2026-08-02: 休日')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '勤務予定を生成する' }))

    await waitFor(() =>
      expect(employeeRotationAssignmentsApi.generateRotationShiftAssignments).toHaveBeenCalledWith({
        user_id: 'user-5',
        from: '2026-08-01',
        to: '2026-08-02',
        overwrite_mode: 'skip_edited',
      }),
    )
    expect(await screen.findByText('2件生成しました。')).toBeInTheDocument()
  }, 15000)

  it('previews and confirms a weekly shift pattern for the selected user and period', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'previewPatternShiftAssignments').mockResolvedValue({
      days: [
        { date: '2026-08-03', weekday: 1, is_working_day: true, start_time: '09:00', end_time: '18:00', break_minutes: 60, source: 'weekly_pattern' },
        { date: '2026-08-08', weekday: 6, is_working_day: false, start_time: null, end_time: null, break_minutes: 0, source: 'weekly_pattern' },
      ],
    })
    vi.spyOn(employeeShiftAssignmentsApi, 'generatePatternShiftAssignments').mockResolvedValue({
      generated: [],
      generated_count: 5,
      skipped_dates: [],
    })

    renderPage()
    await screen.findByRole('heading', { name: '週次の一括入力' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(週次)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態(週次)'), '標準勤務')
    await pickDate(userEvent.setup(), '適用開始日', '2026-08-01')
    await pickDate(userEvent.setup(), '適用終了日', '2026-08-31')
    await userEvent.click(screen.getByRole('button', { name: '週次パターンをプレビューする' }))

    const mondayToFriday = { start_time: '09:00', end_time: '18:00', break_minutes: 60 }
    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.previewPatternShiftAssignments).toHaveBeenCalledWith({
        from: '2026-08-01',
        to: '2026-08-31',
        weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
      }),
    )
    expect(await screen.findByText('2026-08-03: 09:00〜18:00')).toBeInTheDocument()
    expect(screen.getByText('2026-08-08: 休み')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '週次パターンで確定する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generatePatternShiftAssignments).toHaveBeenCalledWith({
        user_id: 'user-5',
        work_style_id: 'work-style-1',
        from: '2026-08-01',
        to: '2026-08-31',
        weekly_pattern: { 1: mondayToFriday, 2: mondayToFriday, 3: mondayToFriday, 4: mondayToFriday, 5: mondayToFriday, 6: null, 7: null },
        overwrite_mode: 'skip_edited',
      }),
    )
    expect(await screen.findByText('5件生成しました。')).toBeInTheDocument()
  }, 15000)

  it('overrides a single day when confirming a monthly shift pattern', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(employeeShiftAssignmentsApi, 'previewPatternShiftAssignments').mockResolvedValue({
      days: [{ date: '2026-08-03', weekday: 1, is_working_day: false, start_time: null, end_time: null, break_minutes: 0, source: 'day_override' }],
    })
    vi.spyOn(employeeShiftAssignmentsApi, 'generatePatternShiftAssignments').mockResolvedValue({
      generated: [],
      generated_count: 30,
      skipped_dates: ['2026-08-03'],
    })

    renderPage()
    await screen.findByRole('heading', { name: '月次の一括入力(日単位の個別設定つき)' })

    await userEvent.click(screen.getByRole('combobox', { name: '対象社員(月次)' }))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('option', { name: '対象社員(taisho@example.com)' }))
    await userEvent.selectOptions(screen.getByLabelText('勤務形態(月次)'), '標準勤務')
    await pickYearMonth(userEvent.setup(), '対象年月(月次パターン)', '2026-08')

    await userEvent.click(await screen.findByRole('checkbox', { name: '2026-08-03(月)' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '休みにする' }))
    await userEvent.click(screen.getByRole('button', { name: '月次パターンをプレビューする' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.previewPatternShiftAssignments).toHaveBeenCalledWith(
        expect.objectContaining({
          from: '2026-08-01',
          to: '2026-08-31',
          day_overrides: { '2026-08-03': null },
        }),
      ),
    )
    expect(await screen.findByText('2026-08-03: 休み')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '月次パターンで確定する' }))

    await waitFor(() =>
      expect(employeeShiftAssignmentsApi.generatePatternShiftAssignments).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: 'user-5',
          work_style_id: 'work-style-1',
          day_overrides: { '2026-08-03': null },
        }),
      ),
    )
    expect(await screen.findByText('30件生成しました。(実績・締め済み・個別上書きのため1件をスキップしました)')).toBeInTheDocument()
  }, 15000)
})
