import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
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
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
  allow_daily_holiday_override: true,
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
    // CreateCompanyCalendarModalは(未オープン時も含め)常にマウントされ、休日iCalendarソース
    // 一覧を取得するため、モーダル操作を検証しないテストでも既定で空配列を返しておく。
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
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
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue({
      ...calendar,
      id: 'calendar-2',
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新規作成' }))
    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        week_starts_on: undefined,
        fiscal_year_start_month: undefined,
        fiscal_year_start_day: undefined,
        weekday_holiday_pattern: {
          '1': 'working',
          '2': 'working',
          '3': 'working',
          '4': 'working',
          '5': 'working',
          '6': 'company_holiday',
          '7': 'legal_holiday',
        },
        allow_daily_holiday_override: false,
      }),
    )
  })

  it('sends the full weekday pattern once the disclosure is opened and edited', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([]))
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue({
      ...calendar,
      id: 'calendar-2',
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新規作成' }))
    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.selectOptions(screen.getByLabelText('日曜日'), 'company_holiday')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        week_starts_on: undefined,
        fiscal_year_start_month: undefined,
        fiscal_year_start_day: undefined,
        weekday_holiday_pattern: {
          '1': 'working',
          '2': 'working',
          '3': 'working',
          '4': 'working',
          '5': 'working',
          '6': 'company_holiday',
          '7': 'company_holiday',
        },
        allow_daily_holiday_override: false,
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

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))
    expect(await screen.findByText('「2026年度カレンダー」を削除しますか?')).toBeInTheDocument()
    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    await waitFor(() => expect(workCalendarsApi.deleteWorkCalendar).toHaveBeenCalledWith('calendar-1'))
  })

  it('does not delete when the confirmation is cancelled', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))
    vi.spyOn(workCalendarsApi, 'deleteWorkCalendar').mockResolvedValue(undefined)

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

    expect(workCalendarsApi.deleteWorkCalendar).not.toHaveBeenCalled()
  })

  it('shows the server error message when deletion fails', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarsPage').mockResolvedValue(page([calendar]))
    vi.spyOn(workCalendarsApi, 'deleteWorkCalendar').mockRejectedValue(
      new ApiError(422, 'デフォルトカレンダーは削除できません。'),
    )

    renderPage()

    await screen.findByText('2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    expect(await screen.findByText('デフォルトカレンダーは削除できません。')).toBeInTheDocument()
  })
})
