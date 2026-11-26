import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar } from '../../api/types'
import { renderWithGuard } from '../../test/renderWithGuard'
import { WorkCalendarCreatePage } from './WorkCalendarCreatePage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2027年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: false,
  status: 'active',
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
  allows_daily_holiday_override: true,
}

describe('WorkCalendarCreatePage leave guard', () => {
  it('moves to the detail page after creating without the unsaved-changes dialog', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/admin/work-calendars/new" element={<WorkCalendarCreatePage />} />
          <Route path="/admin/work-calendars/:id" element={<p>カレンダー詳細ページ</p>} />
        </Routes>
      </QueryClientProvider>,
      { initialEntries: ['/admin/work-calendars/new'] },
    )

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(await screen.findByText('カレンダー詳細ページ')).toBeInTheDocument()
    await waitFor(() => expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalled())
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
