import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as employeeShiftAssignmentsApi from '../api/employeeShiftAssignments'
import * as usersApi from '../api/users'
import * as workCalendarsApi from '../api/workCalendars'
import * as workStylesApi from '../api/workStyles'
import type { Paginated, User, WorkCalendar, WorkStyle } from '../api/types'
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
  calendar_id: 1,
  is_shift_based: false,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
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

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workStylesApi, 'fetchWorkStyles').mockResolvedValue([workStyle])
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])

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
        day_type: 'weekday',
        is_working_day: true,
        is_legal_holiday: false,
        is_company_holiday: false,
        planned_start_at: '2026-08-01T09:00:00+09:00',
        planned_end_at: '2026-08-01T18:00:00+09:00',
        planned_break_minutes: 60,
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
})
