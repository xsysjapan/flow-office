import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { WorkCalendarDetailPage } from './WorkCalendarDetailPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 1,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: false,
  status: 'active',
}

const source: HolidayCalendarSource = {
  id: 'source-1',
  name: '内閣府祝日カレンダー',
  ics_url: 'https://example.com/holidays.ics',
  sync_status: 'pending',
  last_synced_at: null,
  last_error: null,
  disabled_at: null,
  last_sync_summary: null,
}

function renderPage(calendars: WorkCalendar[] = [calendar]) {
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue(calendars)

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1']}>
        <Routes>
          <Route path="/admin/work-calendars/:id" element={<WorkCalendarDetailPage />} />
          <Route path="/admin/work-calendars/:id/years" element={<p>カレンダー年度一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the settings form prefilled with the current calendar', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])

    renderPage()

    expect(await screen.findByLabelText('カレンダー名')).toHaveValue('本社カレンダー')
    expect(screen.getByLabelText('週の開始日(0=日曜)')).toHaveValue(1)
    expect(screen.getByLabelText('年度開始月')).toHaveValue(4)
    expect(screen.getByLabelText('年度開始日')).toHaveValue(1)
    expect(screen.getByText('非デフォルト')).toBeInTheDocument()
  })

  it('saves the edited settings', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      name: '名古屋事業所カレンダー',
      fiscal_year_start_month: 1,
    })

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.clear(screen.getByLabelText('カレンダー名'))
    await userEvent.type(screen.getByLabelText('カレンダー名'), '名古屋事業所カレンダー')
    await userEvent.clear(screen.getByLabelText('年度開始月'))
    await userEvent.type(screen.getByLabelText('年度開始月'), '1')

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '名古屋事業所カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 1,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: null,
      }),
    )
  })

  it('sets the calendar as default', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'setDefaultWorkCalendar').mockResolvedValue({ ...calendar, is_default: true })

    renderPage()

    await screen.findByText('非デフォルト')
    await userEvent.click(screen.getByRole('button', { name: 'デフォルトに設定する' }))

    await waitFor(() => expect(workCalendarsApi.setDefaultWorkCalendar).toHaveBeenCalledWith('calendar-1'))
  })

  it('assigns an existing source and syncs it, showing the reflected summary', async () => {
    const syncedSource = {
      ...source,
      sync_status: 'synced',
      last_synced_at: '2026-08-12T00:00:00+09:00',
      last_sync_summary: { added: 3, updated: 1, removed: 0, applied: 3, protected_conflicts: 1 },
    }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([source])
      .mockResolvedValue([syncedSource])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      holiday_calendar_source_id: source.id,
    })
    vi.spyOn(holidayCalendarSourcesApi, 'syncHolidayCalendarSource').mockResolvedValue(syncedSource)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), source.id)
    await userEvent.click(screen.getByRole('button', { name: '選択して同期する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '本社カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 4,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: 'source-1',
      }),
    )
    await waitFor(() => expect(holidayCalendarSourcesApi.syncHolidayCalendarSource).toHaveBeenCalledWith('source-1'))

    expect(
      await screen.findByText('追加 3件・更新 1件・削除 0件・カレンダーに反映 3件(手動変更保護のためスキップ 1件)'),
    ).toBeInTheDocument()
  })

  it('registers a brand-new source inline and auto-selects it', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([source])
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockResolvedValue(source)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.click(screen.getByRole('button', { name: '新しいiCalendarを登録する' }))

    await userEvent.type(screen.getByLabelText('名称'), '内閣府祝日カレンダー')
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/holidays.ics')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.createHolidayCalendarSource).toHaveBeenCalledWith({
        name: '内閣府祝日カレンダー',
        ics_url: 'https://example.com/holidays.ics',
      }),
    )

    await waitFor(() =>
      expect(screen.getByLabelText('使用する祝日iCalendarソース')).toHaveValue(source.id),
    )
  })

  it('links to the year list', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.click(screen.getByRole('link', { name: '年度一覧を見る' }))

    expect(await screen.findByText('カレンダー年度一覧ページ')).toBeInTheDocument()
  })
})
