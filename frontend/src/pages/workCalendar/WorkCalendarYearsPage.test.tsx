import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar, WorkCalendarYear } from '../../api/types'
import { pickDate } from '../../test-support/pickerInteractions'
import { WorkCalendarYearsPage } from './WorkCalendarYearsPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
}

const draftYear: WorkCalendarYear = {
  id: 'year-1',
  company_calendar_id: 'calendar-1',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  status: 'draft',
  generated_from: 'manual',
  published_at: null,
  published_by_user_id: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/years']}>
        <Routes>
          <Route path="/admin/work-calendars/:id/years" element={<WorkCalendarYearsPage />} />
          <Route path="/admin/work-calendar-years/:yearId/days" element={<p>日別編集ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarYearsPage', () => {
  it('shows the year list with a draft badge', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])

    renderPage()

    expect(await screen.findByText('2026年度')).toBeInTheDocument()
    expect(screen.getByText('未公開')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '公開する' })).toBeInTheDocument()
  })

  it('auto-calculates the start/end dates from the fiscal year and the calendar settings', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendarYear').mockResolvedValue(draftYear)

    renderPage()

    await userEvent.type(await screen.findByLabelText('年度'), '2026')
    await userEvent.click(screen.getByRole('button', { name: '年度を作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendarYear).toHaveBeenCalledWith('calendar-1', {
        fiscal_year: 2026,
        starts_on: '2026-04-01',
        ends_on: '2027-03-31',
      }),
    )
  })

  it('allows customizing the auto-calculated start/end dates individually', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendarYear').mockResolvedValue(draftYear)

    renderPage()

    await userEvent.type(await screen.findByLabelText('年度'), '2026')
    await pickDate(userEvent.setup(), '開始日', '2026-04-05')
    await userEvent.click(screen.getByRole('button', { name: '年度を作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendarYear).toHaveBeenCalledWith('calendar-1', {
        fiscal_year: 2026,
        starts_on: '2026-04-05',
        ends_on: '2027-03-31',
      }),
    )
  })

  it('publishes a draft year when the publish button is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])
    vi.spyOn(workCalendarsApi, 'publishWorkCalendarYear').mockResolvedValue({ ...draftYear, status: 'published' })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '公開する' }))

    await waitFor(() => expect(workCalendarsApi.publishWorkCalendarYear).toHaveBeenCalledWith('year-1'))
  })

  it('duplicates a year when the duplicate button is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])
    vi.spyOn(workCalendarsApi, 'duplicateWorkCalendarYear').mockResolvedValue({
      ...draftYear,
      id: 'year-2',
      fiscal_year: 2027,
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '複製して翌年度を作成' }))

    await waitFor(() => expect(workCalendarsApi.duplicateWorkCalendarYear).toHaveBeenCalledWith('year-1'))
  })

  it('navigates to the day editor when the year link is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])

    renderPage()

    await userEvent.click(await screen.findByText('2026年度'))

    expect(await screen.findByText('日別編集ページ')).toBeInTheDocument()
  })
})
