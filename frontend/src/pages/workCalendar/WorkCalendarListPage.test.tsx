import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar } from '../../api/types'
import { WorkCalendarListPage } from './WorkCalendarListPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars']}>
        <Routes>
          <Route path="/admin/work-calendars" element={<WorkCalendarListPage />} />
          <Route path="/admin/work-calendars/:id/years" element={<p>カレンダー年度一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarListPage', () => {
  it('shows the calendar list', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])

    renderPage()

    expect(await screen.findByText('2026年度カレンダー')).toBeInTheDocument()
    expect(screen.getByText(/週開始: 0/)).toBeInTheDocument()
  })

  it('creates a calendar with the entered values', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue({
      ...calendar,
      id: 'calendar-2',
    })

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        week_starts_on: undefined,
        fiscal_year_start_month: undefined,
        fiscal_year_start_day: undefined,
      }),
    )
  })

  it('navigates to the year list when the calendar name is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])

    renderPage()

    await userEvent.click(await screen.findByText('2026年度カレンダー'))

    expect(await screen.findByText('カレンダー年度一覧ページ')).toBeInTheDocument()
  })
})
