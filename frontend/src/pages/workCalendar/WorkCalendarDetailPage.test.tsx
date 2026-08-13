import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar, WorkCalendarYear } from '../../api/types'
import { pickDate } from '../../test-support/pickerInteractions'
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
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
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

const uploadSource: HolidayCalendarSource = {
  id: 'source-2',
  name: 'アップロード祝日カレンダー',
  source_kind: 'upload',
  ics_url: null,
  uploaded_ics_filename: 'holidays.ics',
  sync_status: 'pending',
  last_synced_at: null,
  last_error: null,
  disabled_at: null,
  last_sync_summary: null,
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

function renderPage(calendars: WorkCalendar[] = [calendar]) {
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue(calendars)

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1']}>
        <Routes>
          <Route path="/admin/work-calendars/:id" element={<WorkCalendarDetailPage />} />
          <Route path="/admin/work-calendars/:calendarId/years/:yearId/days" element={<p>日別編集ページ</p>} />
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
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])

    renderPage()

    expect(await screen.findByLabelText('カレンダー名')).toHaveValue('本社カレンダー')
    expect(screen.getByLabelText('週の開始日(0=日曜)')).toHaveValue(1)
    expect(screen.getByLabelText('年度開始月')).toHaveValue(4)
    expect(screen.getByLabelText('年度開始日')).toHaveValue(1)
  })

  it('shows the header with the calendar name and the default badge/button, top-right', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole('heading', { name: '本社カレンダー', level: 1 })).toBeInTheDocument()
    expect(screen.getByText('非デフォルト')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'デフォルトに設定する' })).toBeInTheDocument()
  })

  it('saves the edited settings', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
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
        allow_daily_holiday_override: true,
      }),
    )
  })

  it('sends the full weekday pattern once the disclosure is opened and edited', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue(calendar)

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.click(screen.getByRole('button', { name: '曜日ごとの休日設定を変更する' }))
    await userEvent.selectOptions(screen.getByLabelText('月曜日'), 'company_holiday')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '本社カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 4,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: null,
        allow_daily_holiday_override: true,
        weekday_holiday_pattern: {
          '1': 'company_holiday',
          '2': 'working',
          '3': 'working',
          '4': 'working',
          '5': 'working',
          '6': 'company_holiday',
          '7': 'legal_holiday',
        },
      }),
    )
  })

  it('unchecks the override-lock checkbox and saves it as false', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      allow_daily_holiday_override: false,
    })

    renderPage()

    await screen.findByLabelText('カレンダー名')
    await userEvent.click(screen.getByRole('button', { name: '曜日ごとの休日設定を変更する' }))
    await userEvent.click(screen.getByLabelText('曜日ごとの休日設定を日ごとに個別変更できるようにする'))
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '本社カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 4,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: null,
        weekday_holiday_pattern: calendar.weekday_holiday_pattern,
        allow_daily_holiday_override: false,
      }),
    )
  })

  it('sets the calendar as default from the header button', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'setDefaultWorkCalendar').mockResolvedValue({ ...calendar, is_default: true })

    renderPage()

    await screen.findByText('非デフォルト')
    await userEvent.click(screen.getByRole('button', { name: 'デフォルトに設定する' }))

    await waitFor(() => expect(workCalendarsApi.setDefaultWorkCalendar).toHaveBeenCalledWith('calendar-1'))
  })

  it('assigns an existing source to the calendar', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      holiday_calendar_source_id: source.id,
    })

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), source.id)
    await userEvent.click(screen.getByRole('button', { name: 'このカレンダーに設定する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '本社カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 4,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: 'source-1',
      }),
    )
  })

  it('clears the assigned source back to "登録しない"', async () => {
    const assignedCalendar = { ...calendar, holiday_calendar_source_id: source.id }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...assignedCalendar,
      holiday_calendar_source_id: null,
    })

    renderPage([assignedCalendar])

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), '')
    await userEvent.click(screen.getByRole('button', { name: 'このカレンダーに設定する' }))

    await waitFor(() =>
      expect(workCalendarsApi.updateWorkCalendar).toHaveBeenCalledWith('calendar-1', {
        name: '本社カレンダー',
        week_starts_on: 1,
        fiscal_year_start_month: 4,
        fiscal_year_start_day: 1,
        holiday_calendar_source_id: null,
      }),
    )
  })

  it('registers a brand-new source inline and auto-selects it', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
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

  it('registers a brand-new source via file upload', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([uploadSource])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockResolvedValue(uploadSource)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.click(screen.getByRole('button', { name: '新しいiCalendarを登録する' }))

    await userEvent.click(screen.getByLabelText('ファイルをアップロード'))
    await userEvent.type(screen.getByLabelText('名称'), 'アップロード祝日カレンダー')

    const file = new File(['BEGIN:VCALENDAR'], 'holidays.ics', { type: 'text/calendar' })
    await userEvent.upload(screen.getByLabelText('iCalendarファイル'), file)

    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.createHolidayCalendarSource).toHaveBeenCalledWith({
        name: 'アップロード祝日カレンダー',
        ics_file: file,
      }),
    )
  })

  it('edits an existing url-kind source and updates its URL', async () => {
    const updatedSource = { ...source, ics_url: 'https://example.com/updated.ics' }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([source])
      .mockResolvedValue([updatedSource])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'updateHolidayCalendarSource').mockResolvedValue(updatedSource)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), source.id)

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    await userEvent.clear(screen.getByLabelText('iCalendar URL'))
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/updated.ics')

    await userEvent.click(screen.getByRole('button', { name: '更新する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.updateHolidayCalendarSource).toHaveBeenCalledWith(source.id, {
        name: source.name,
        ics_url: 'https://example.com/updated.ics',
      }),
    )

    expect(await screen.findByText('変更を反映するには同期してください。')).toBeInTheDocument()
  })

  it('edits an existing upload-kind source by replacing its file', async () => {
    const updatedSource = { ...uploadSource, uploaded_ics_filename: 'new-holidays.ics' }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([uploadSource])
      .mockResolvedValue([updatedSource])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'updateHolidayCalendarSource').mockResolvedValue(updatedSource)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), uploadSource.id)

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))

    const file = new File(['BEGIN:VCALENDAR'], 'new-holidays.ics', { type: 'text/calendar' })
    await userEvent.upload(screen.getByLabelText('iCalendarファイル(置き換え)'), file)

    await userEvent.click(screen.getByRole('button', { name: '更新する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.updateHolidayCalendarSource).toHaveBeenCalledWith(uploadSource.id, {
        name: uploadSource.name,
        ics_file: file,
      }),
    )
  })

  it('deletes a source when the delete button is confirmed, then clears the selection', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([source])
      .mockResolvedValue([])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'deleteHolidayCalendarSource').mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), source.id)

    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    await waitFor(() => expect(holidayCalendarSourcesApi.deleteHolidayCalendarSource).toHaveBeenCalledWith(source.id))

    await waitFor(() => expect(screen.getByLabelText('使用する祝日iCalendarソース')).toHaveValue(''))
  })

  it('does not delete a source when the confirmation is dismissed', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
    vi.spyOn(holidayCalendarSourcesApi, 'deleteHolidayCalendarSource').mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderPage()

    await screen.findByLabelText('使用する祝日iCalendarソース')
    await userEvent.selectOptions(screen.getByLabelText('使用する祝日iCalendarソース'), source.id)

    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    expect(holidayCalendarSourcesApi.deleteHolidayCalendarSource).not.toHaveBeenCalled()
  })

  describe('カレンダー年度(旧WorkCalendarYearsPageの統合分)', () => {
    it('shows the year list with a draft badge', async () => {
      vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])

      renderPage()

      expect(await screen.findByText('2026年度')).toBeInTheDocument()
      expect(screen.getByText('2026-04-01〜2027-03-31')).toBeInTheDocument()
      expect(screen.getByText('未公開')).toBeInTheDocument()
    })

    it('auto-calculates the start/end dates from the fiscal year and the calendar settings', async () => {
      vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'createWorkCalendarYear').mockResolvedValue(draftYear)

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '新規作成' }))
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
      vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'createWorkCalendarYear').mockResolvedValue(draftYear)

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '新規作成' }))
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

    it('navigates to the day editor when the year link is clicked', async () => {
      vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])

      renderPage()

      await userEvent.click(await screen.findByText('2026年度'))

      expect(await screen.findByText('日別編集ページ')).toBeInTheDocument()
    })

    it('does not render the year lifecycle action buttons in the list row (moved to the year detail page)', async () => {
      vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])
      vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([draftYear])

      renderPage()

      await screen.findByText('2026年度')

      expect(screen.queryByRole('button', { name: '公開する' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: '公開を取消す' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: '廃止する' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: '複製して翌年度を作成' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'この年度を祝日と同期する' })).not.toBeInTheDocument()
    })
  })
})
