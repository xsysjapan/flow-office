import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import { ApiError } from '../../api/client'
import type { Paginated, WorkCalendar } from '../../api/types'
import { WorkCalendarListPage } from './WorkCalendarListPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: false,
  status: 'active',
}

function page(calendars: WorkCalendar[], overrides: Partial<Paginated<WorkCalendar>['meta']> = {}): Paginated<WorkCalendar> {
  return {
    data: calendars,
    meta: { current_page: 1, last_page: 1, total: calendars.length, per_page: 20, ...overrides },
    links: { next: null, prev: null },
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars']}>
        <Routes>
          <Route path="/admin/work-calendars" element={<WorkCalendarListPage />} />
          <Route path="/admin/work-calendars/:id" element={<p>カレンダー詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the calendar list', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))

    renderPage()

    expect(await screen.findByText('2026年度カレンダー')).toBeInTheDocument()
    expect(screen.getByText(/週開始: 0/)).toBeInTheDocument()
    expect(workCalendarsApi.fetchWorkCalendarsPage).toHaveBeenCalledWith({ page: 1, per_page: 20 })
  })

  it('renders pagination controls and moves to the next page', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(
      page([calendar], { current_page: 1, last_page: 3, total: 45 }),
    )

    renderPage()

    await screen.findByText('2026年度カレンダー')
    expect(screen.getByText(/45件中 1 \/ 3 ページ/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '前のページ' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    await waitFor(() =>
      expect(workCalendarsApi.fetchWorkCalendarsPage).toHaveBeenCalledWith({ page: 2, per_page: 20 }),
    )
  })

  it('creates a calendar with the entered values', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([]))
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
      }),
    )
  })

  it('navigates to the detail page when the calendar name is clicked', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))

    renderPage()

    await userEvent.click(await screen.findByText('2026年度カレンダー'))

    expect(await screen.findByText('カレンダー詳細ページ')).toBeInTheDocument()
  })

  it('deletes a calendar after confirmation', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))
    vi.spyOn(workCalendarsApi, 'deleteWorkCalendar').mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))

    await waitFor(() => expect(workCalendarsApi.deleteWorkCalendar).toHaveBeenCalledWith('calendar-1'))
  })

  it('does not delete when the confirmation is cancelled', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))
    vi.spyOn(workCalendarsApi, 'deleteWorkCalendar').mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))

    expect(workCalendarsApi.deleteWorkCalendar).not.toHaveBeenCalled()
  })

  it('shows the server error message when deletion fails', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))
    vi.spyOn(workCalendarsApi, 'deleteWorkCalendar').mockRejectedValue(
      new ApiError(422, 'デフォルトカレンダーは削除できません。'),
    )
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))

    expect(await screen.findByText('デフォルトカレンダーは削除できません。')).toBeInTheDocument()
  })
})
