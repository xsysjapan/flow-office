import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as calendarBulkOperationsApi from '../../api/calendarBulkOperations'
import * as usersApi from '../../api/users'
import * as workStylesApi from '../../api/workStyles'
import { pickDate } from '../../test-support/pickerInteractions'
import type { CalendarBulkOperation, CalendarBulkOperationPreview, Paginated, User, WorkStyle } from '../../api/types'
import { CalendarBulkOperationsPage } from './CalendarBulkOperationsPage'

const user: User = {
  id: 'user-1',
  name: '対象社員一郎',
  email: 'ichiro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const paginatedUsers: Paginated<User> = {
  data: [user],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

const workStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '標準勤務',
  work_time_system: 'fixed',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  deemed_daily_minutes: null,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: 'nearest',
  default_break_start_time: null,
  default_break_end_time: null,
  auto_break_enabled: false,
  company_calendar_id: 'calendar-1',
  is_shift_based: false,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  is_default: false,
  system_generated: false,
  applied_employee_count: 3,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

const previewResult: CalendarBulkOperationPreview = {
  targets: [
    {
      user_id: 'user-1',
      work_date: '2026-09-01',
      conflict: false,
      guard_blocked: false,
      attributes: {},
      result: 'applied',
    },
  ],
  conflict_count: 0,
  executable: true,
}

const appliedOperation: CalendarBulkOperation = {
  id: 'bulk-op-1',
  operation_type: 'calendar_apply',
  target_scope: {},
  conflict_policy: 'skip_existing',
  status: 'applied',
  requested_by_user_id: 'admin-1',
  applied_at: '2026-08-11T00:00:00+09:00',
  reverted_at: null,
  reason: '9月分の勤務予定を一括生成',
}

function renderPage(history: CalendarBulkOperation[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
  vi.spyOn(workStylesApi, 'fetchWorkStyles').mockResolvedValue([workStyle])
  vi.spyOn(calendarBulkOperationsApi, 'fetchCalendarBulkOperations').mockResolvedValue(history)

  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarBulkOperationsPage />
    </QueryClientProvider>,
  )
}

describe('CalendarBulkOperationsPage', () => {
  it('shows an empty history state', async () => {
    renderPage()

    expect(await screen.findByText('一括操作の履歴はまだありません。')).toBeInTheDocument()
  })

  it('previews and then applies a calendar_apply operation', async () => {
    vi.spyOn(calendarBulkOperationsApi, 'previewCalendarBulkOperation').mockResolvedValue(previewResult)
    vi.spyOn(calendarBulkOperationsApi, 'createCalendarBulkOperation').mockResolvedValue(appliedOperation)

    renderPage()

    await userEvent.type(screen.getByLabelText('理由'), '9月分の勤務予定を一括生成')
    await pickDate(userEvent.setup(), '対象期間(開始)', '2026-09-01')
    await pickDate(userEvent.setup(), '対象期間(終了)', '2026-09-30')
    await userEvent.selectOptions(screen.getByLabelText('勤務形態'), 'work-style-1')

    const comboboxes = screen.getAllByRole('combobox')
    await userEvent.click(comboboxes[comboboxes.length - 1])
    await userEvent.click(await screen.findByRole('option', { name: '対象社員一郎(ichiro@example.com)' }))

    await userEvent.click(screen.getByRole('button', { name: 'プレビューする' }))

    await waitFor(() =>
      expect(calendarBulkOperationsApi.previewCalendarBulkOperation).toHaveBeenCalledWith({
        operation_type: 'calendar_apply',
        target_scope: { user_ids: ['user-1'], from: '2026-09-01', to: '2026-09-30', work_style_id: 'work-style-1' },
        conflict_policy: 'skip_existing',
        reason: '9月分の勤務予定を一括生成',
      }),
    )

    expect(await screen.findByText('実行可能')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'この内容で確定適用する' }))

    await waitFor(() => expect(calendarBulkOperationsApi.createCalendarBulkOperation).toHaveBeenCalled())
  })

  it('reverts an applied operation from the history list', async () => {
    vi.spyOn(calendarBulkOperationsApi, 'revertCalendarBulkOperation').mockResolvedValue({
      ...appliedOperation,
      status: 'reverted',
    })

    renderPage([appliedOperation])

    await userEvent.click(await screen.findByRole('button', { name: '取消す' }))
    await userEvent.click(await screen.findByRole('button', { name: '取り消す' }))

    await waitFor(() => expect(calendarBulkOperationsApi.revertCalendarBulkOperation).toHaveBeenCalledWith('bulk-op-1'))
  })
})
