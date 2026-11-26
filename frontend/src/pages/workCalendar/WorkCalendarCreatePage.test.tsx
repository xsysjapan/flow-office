import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { WorkCalendarCreatePage } from './WorkCalendarCreatePage'
import { notify } from '../../lib/notify'

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
  weekday_holiday_pattern: {
    '1': 'working',
    '2': 'working',
    '3': 'working',
    '4': 'working',
    '5': 'working',
    '6': 'company_holiday',
    '7': 'legal_holiday',
  },
  allows_daily_holiday_override: true,
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
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('creates a calendar and navigates to the created calendar detail page', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

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
        allows_daily_holiday_override: true,
      }),
    )

    expect(await screen.findByText('カレンダー詳細ページ')).toBeInTheDocument()
    expect(notify.success).toHaveBeenCalledWith(`「${calendar.name}」を作成しました`)
  })

  it('sends the full weekday pattern once the disclosure is opened and edited', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.selectOptions(screen.getByLabelText('月曜日'), 'company_holiday')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

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
        allows_daily_holiday_override: true,
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

    await userEvent.click(screen.getByRole('button', { name: '作成' }))

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
        allows_daily_holiday_override: true,
        holiday_calendar_source_id: createdSource.id,
      }),
    )
  })

  it('shows the disabled reason when no calendar name is entered', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])

    renderPage()

    expect(await screen.findByText('カレンダー名を入力してください。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '作成' })).toBeDisabled()

    await userEvent.type(screen.getByLabelText('カレンダー名'), '2027年度カレンダー')

    expect(screen.queryByText('カレンダー名を入力してください。')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '作成' })).toBeEnabled()
  })

  it('navigates to the list page when cancelled', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(await screen.findByText('カレンダー一覧ページ')).toBeInTheDocument()
  })

  it('shows each 422 message once under its field and the heading above the buttons', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        name: ['名称のエラー'],
        week_starts_on: ['週開始のエラー'],
        fiscal_year_start_month: ['年度開始月のエラー'],
        fiscal_year_start_day: ['年度開始日のエラー'],
        weekday_holiday_pattern: ['曜日区分のエラー'],
        allows_daily_holiday_override: ['個別変更のエラー'],
        holiday_calendar_source_id: ['ソースのエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), 'x')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    for (const message of [
      '名称のエラー',
      '週開始のエラー',
      '年度開始月のエラー',
      '年度開始日のエラー',
      '曜日区分のエラー',
      '個別変更のエラー',
      'ソースのエラー',
      'その他のエラー',
    ]) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })

  it('shows the source registration 422 messages under the expanded source fields', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        name: ['ソース名のエラー'],
        ics_url: ['URLのエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '新しいiCalendarを登録する' }))
    await userEvent.type(screen.getByLabelText('名称'), 'a')
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/a.ics')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    for (const message of ['ソース名のエラー', 'URLのエラー', 'その他のエラー']) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })
})
