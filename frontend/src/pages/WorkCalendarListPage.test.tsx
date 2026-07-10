import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../api/workCalendars'
import type { WorkCalendar } from '../api/types'
import { WorkCalendarListPage } from './WorkCalendarListPage'

const draftCalendar: WorkCalendar = {
  id: 1,
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'draft',
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars']}>
        <Routes>
          <Route path="/admin/work-calendars" element={<WorkCalendarListPage />} />
          <Route path="/admin/work-calendars/:id/days" element={<p>カレンダー日別編集ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarListPage', () => {
  it('shows the calendar list with a draft badge and publish button', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([draftCalendar])

    renderPage()

    expect(await screen.findByText('2026年度カレンダー')).toBeInTheDocument()
    expect(screen.getByText('未公開')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '公開する' })).toBeInTheDocument()
  })

  it('creates a calendar with the entered values', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue({
      ...draftCalendar,
      id: 2,
    })

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2026年度カレンダー')
    await userEvent.type(screen.getByLabelText('年度'), '2026')
    await userEvent.type(screen.getByLabelText('開始日'), '2026-04-01')
    await userEvent.type(screen.getByLabelText('終了日'), '2027-03-31')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2026年度カレンダー',
        fiscal_year: 2026,
        starts_on: '2026-04-01',
        ends_on: '2027-03-31',
        week_starts_on: undefined,
      }),
    )
  })

  it('publishes a draft calendar when the publish button is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([draftCalendar])
    vi.spyOn(workCalendarsApi, 'publishWorkCalendar').mockResolvedValue({
      ...draftCalendar,
      status: 'published',
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '公開する' }))

    await waitFor(() => expect(workCalendarsApi.publishWorkCalendar).toHaveBeenCalledWith(1))
  })

  it('navigates to the day editor when the calendar name is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([draftCalendar])

    renderPage()

    await userEvent.click(await screen.findByText('2026年度カレンダー'))

    expect(await screen.findByText('カレンダー日別編集ページ')).toBeInTheDocument()
  })
})
