import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { CreateCompanyCalendarModal } from './CreateCompanyCalendarModal'

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

function renderModal() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onOpenChange = vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <CreateCompanyCalendarModal open onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )

  return { onOpenChange }
}

describe('CreateCompanyCalendarModal', () => {
  it('creates a calendar with only the name when no optional field is touched', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    const { onOpenChange } = renderModal()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        allow_daily_holiday_override: true,
      }),
    )
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  })

  it('sends the full weekday pattern once the disclosure is opened and edited', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderModal()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '曜日ごとの休日設定を変更する' }))
    await userEvent.selectOptions(screen.getByLabelText('月曜日'), 'company_holiday')
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
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

  it('does not send the override-lock flag as true when the checkbox is unchecked', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderModal()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.click(screen.getByLabelText('曜日ごとの休日設定を日ごとに個別変更できるようにする'))
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        allow_daily_holiday_override: false,
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

    renderModal()

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

    await waitFor(() =>
      expect(screen.getByLabelText('休日iCalendarソース')).toHaveValue(createdSource.id),
    )

    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        holiday_calendar_source_id: createdSource.id,
        allow_daily_holiday_override: true,
      }),
    )
  })

  it('sends the selected holiday calendar source', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'createWorkCalendar').mockResolvedValue(calendar)

    renderModal()

    await userEvent.type(await screen.findByLabelText('カレンダー名'), '2027年度カレンダー')
    await userEvent.selectOptions(await screen.findByLabelText('休日iCalendarソース'), source.id)
    await userEvent.click(screen.getByRole('button', { name: '作成する' }))

    await waitFor(() =>
      expect(workCalendarsApi.createWorkCalendar).toHaveBeenCalledWith({
        name: '2027年度カレンダー',
        holiday_calendar_source_id: source.id,
        allow_daily_holiday_override: true,
      }),
    )
  })
})
