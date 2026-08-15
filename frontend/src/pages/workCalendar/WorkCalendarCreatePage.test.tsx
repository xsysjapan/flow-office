import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { WorkCalendarCreatePage } from './WorkCalendarCreatePage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2027年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: false,
  status: 'active',
  weekday_holiday_pattern: {
    '1': 'working',
    '2': 'working',
    '3': 'working',
    '4': 'working',
    '5': 'working',
    '6': 'company_holiday',
    '7': 'legal_holiday',
  },
  allow_daily_holiday_override: true,
}

const source: HolidayCalendarSource = {
  id: 'source-1',
  name: '内閣府祝日カレンダー',
  source_kind: 'url',
  ics_url: 'https://example.com/holidays.ics',
  uploaded_ics_filename: null,
  sync_status: 'pending',
  last_synced_at: null,
  last_error: null,
  disabled_at: null,
  last_sync_summary: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/new']}>
        <Routes>
          <Route path="/admin/work-calendars/new" element={<WorkCalendarCreatePage />} />
          <Route path="/admin/work-calendars/:id" element={<p>カレンダー詳細ページ</p>} />
          <Route path="/admin/work-calendars" element={<p>カレンダー一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarCreatePage', () => {
  it('creates a calendar and navigates to the created calendar detail page', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderPage()

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
        allow_daily_holiday_override: true,
      }),
    )

    expect(await screen.findByText('カレンダー詳細ページ')).toBeInTheDocument()
  })

  it('sends the full weekday pattern once the disclosure is opened and edited', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.selectOptions(screen.getByLabelText('月曜日'), 'company_holiday')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        week_starts_on: undefined,
        fiscal_year_start_month: undefined,
        fiscal_year_start_day: undefined,
        weekday_holiday_pattern: {
          '1': 'company_holiday',
          '2': 'working',
          '3': 'working',
          '4': 'working',
          '5': 'working',
          '6': 'company_holiday',
          '7': 'legal_holiday',
        },
        allow_daily_holiday_override: true,
      }),
    )
  })

  it('registers a brand-new holiday calendar source inline and auto-selects it for creation', async () => {
    const createdSource: HolidayCalendarSource = {
      id: 'source-2',
      name: '新規祝日カレンダー',
      source_kind: 'url',
      ics_url: 'https://example.com/new-holidays.ics',
      uploaded_ics_filename: null,
      sync_status: 'pending',
      last_synced_at: null,
      last_error: null,
      disabled_at: null,
      last_sync_summary: null,
    }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([createdSource])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockResolvedValue(createdSource)

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '新しいiCalendarを登録する' }))

    await userEvent.type(screen.getByLabelText('名称'), '新規祝日カレンダー')
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/new-holidays.ics')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.createHolidayCalendarSource).toHaveBeenCalledWith({
        name: '新規祝日カレンダー',
        ics_url: 'https://example.com/new-holidays.ics',
      }),
    )

    await waitFor(() => expect(screen.getByLabelText('休日iCalendarソース')).toHaveValue(createdSource.id))

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
        allow_daily_holiday_override: true,
        holiday_calendar_source_id: createdSource.id,
      }),
    )
  })

  it('shows the disabled reason when no calendar name is entered', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])

    renderPage()

    expect(await screen.findByText('カレンダー名を入力してください。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '作成する' })).toBeDisabled()

    await userEvent.type(screen.getByLabelText('カレンダー名'), '2027年度カレンダー')

    expect(screen.queryByText('カレンダー名を入力してください。')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '作成する' })).toBeEnabled()
  })

  it('navigates to the list page when cancelled', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(await screen.findByText('カレンダー一覧ページ')).toBeInTheDocument()
  })
})
