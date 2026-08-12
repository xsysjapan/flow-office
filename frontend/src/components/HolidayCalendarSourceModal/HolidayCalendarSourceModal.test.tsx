import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import * as workCalendarsApi from '../../api/workCalendars'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { HolidayCalendarSourceModal } from './HolidayCalendarSourceModal'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 1,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
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
}

function renderModal(companyCalendar: WorkCalendar = calendar) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <HolidayCalendarSourceModal companyCalendar={companyCalendar} open onOpenChange={() => {}} />
    </QueryClientProvider>,
  )
}

describe('HolidayCalendarSourceModal', () => {
  it('shows an empty state before anything is registered', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])

    renderModal()

    expect(await screen.findByText(/まだ登録されていません/)).toBeInTheDocument()
    expect(screen.getByText('本社カレンダー の祝日iCalendar同期')).toBeInTheDocument()
  })

  it('registers a source and shows it in the list', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([source])
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockResolvedValue(source)

    renderModal()
    await screen.findByText(/まだ登録されていません/)

    await userEvent.type(screen.getByLabelText('名称'), '内閣府祝日カレンダー')
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/holidays.ics')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.createHolidayCalendarSource).toHaveBeenCalledWith({
        name: '内閣府祝日カレンダー',
        ics_url: 'https://example.com/holidays.ics',
      }),
    )
    expect(await screen.findByText('内閣府祝日カレンダー')).toBeInTheDocument()
  })

  it('assigns a source to the calendar and can unassign it again', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      holiday_calendar_source_id: source.id,
    })

    renderModal()
    await screen.findByText('内閣府祝日カレンダー')

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

  it('shows the assigned badge and unassign action for the currently assigned source', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([source])

    renderModal({ ...calendar, holiday_calendar_source_id: source.id })

    expect(await screen.findByText('このカレンダーに設定中')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '設定を解除する' })).toBeInTheDocument()
  })
})
