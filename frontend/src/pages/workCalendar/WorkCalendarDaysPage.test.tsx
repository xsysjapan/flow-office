import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar } from '../../api/types'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'draft',
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/days']}>
        <Routes>
          <Route path="/admin/work-calendars/:id/days" element={<WorkCalendarDaysPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarDaysPage', () => {
  it('shows the calendar context', async () => {
    renderPage()

    expect(await screen.findByText('2026年度カレンダー の日別編集')).toBeInTheDocument()
    expect(screen.getByText('2026')).toBeInTheDocument()
  })

  it('adds a row and saves the entered days', async () => {
    vi.spyOn(workCalendarsApi, 'putWorkCalendarDays').mockResolvedValue([])
    renderPage()

    await screen.findByText('2026年度カレンダー の日別編集')
    await userEvent.click(screen.getByRole('button', { name: '行を追加' }))

    await userEvent.type(screen.getByLabelText('日付'), '2026-05-05')
    await userEvent.type(screen.getByLabelText('区分'), '祝日')
    await userEvent.click(screen.getByLabelText('稼働日'))
    await userEvent.click(screen.getByLabelText('法定休日'))

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(workCalendarsApi.putWorkCalendarDays).toHaveBeenCalledWith('calendar-1', [
        {
          date: '2026-05-05',
          day_type: '祝日',
          is_working_day: false,
          is_legal_holiday: true,
          is_company_holiday: false,
          note: undefined,
        },
      ]),
    )
  })
})
