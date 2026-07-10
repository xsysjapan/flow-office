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
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 2, code: 'flex' })
    renderPage()

    await userEvent.type(await screen.findByLabelText('コード'), 'flex')
    await userEvent.type(screen.getByLabelText('名称'), 'フレックス勤務')
    await userEvent.type(screen.getByLabelText('労働時間制'), 'フレックスタイム制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith({
        code: 'flex',
        name: 'フレックス勤務',
        work_time_system: 'フレックスタイム制',
        prescribed_daily_minutes: 480,
        prescribed_weekly_minutes: 2400,
        default_start_time: undefined,
        default_end_time: undefined,
        default_break_minutes: undefined,
        calendar_id: 1,
        is_shift_based: false,
      }),
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

    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '対象')
    await userEvent.click(await screen.findByRole('button', { name: '対象社員(taisho@example.com)' }))
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
