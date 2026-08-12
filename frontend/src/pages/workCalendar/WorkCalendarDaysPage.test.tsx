import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar, WorkCalendarDay, WorkCalendarYear } from '../../api/types'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
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
}

const year: WorkCalendarYear = {
  id: 'year-1',
  company_calendar_id: 'calendar-1',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2026-04-30',
  status: 'draft',
  generated_from: 'manual',
  published_at: null,
  published_by_user_id: null,
}

/** 2026-04(30日)を全てWORKで埋めた既存データ。 */
function buildAprilDays(): WorkCalendarDay[] {
  return Array.from({ length: 30 }, (_, i) => {
    const date = `2026-04-${String(i + 1).padStart(2, '0')}`
    return {
      id: i + 1,
      date,
      day_type: 'weekday',
      is_working_day: true,
      is_legal_holiday: false,
      is_company_holiday: false,
      is_public_holiday: false,
      public_holiday_name: null,
      schedule_state: 'WORK' as const,
      note: null,
    }
  })
}

function renderPage() {
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([year])

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendar-years/year-1/days']}>
        <Routes>
          <Route path="/admin/work-calendar-years/:yearId/days" element={<WorkCalendarDaysPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarDaysPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads existing days and renders them in the grid with correct status coloring', async () => {
    const days = buildAprilDays()
    days[3] = { ...days[3], schedule_state: 'OFF', is_public_holiday: true, public_holiday_name: '昭和の日改め' }
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(days)

    renderPage()

    expect(await screen.findByText('2026年度')).toBeInTheDocument()
    expect(screen.getByText('2026-04-01〜2026-04-30')).toBeInTheDocument()

    const workCell = await screen.findByRole('button', { name: '2026-04-01 勤務日' })
    expect(workCell).toBeInTheDocument()

    const holidayCell = screen.getByRole('button', { name: '2026-04-04 祝日(昭和の日改め)' })
    expect(holidayCell).toBeInTheDocument()

    expect(await screen.findByText('29日')).toBeInTheDocument()
  })

  it('edits a day (toggle to OFF, mark a public holiday) and recomputes stats, then saves', async () => {
    const days = buildAprilDays()
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(days)
    vi.spyOn(workCalendarsApi, 'putWorkCalendarDays').mockResolvedValue([])

    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText('30日')).toBeInTheDocument()
    expect(screen.getByText('240時間')).toBeInTheDocument()

    await user.click(await screen.findByRole('button', { name: '2026-04-05 勤務日' }))
    await user.selectOptions(screen.getByLabelText('2026-04-05の勤務区分'), 'OFF')
    await user.click(screen.getByLabelText('2026-04-05の祝日'))
    await user.type(screen.getByLabelText('2026-04-05の祝日名'), 'こどもの日')
    await user.keyboard('{Escape}')

    expect(await screen.findByText('29日')).toBeInTheDocument()
    expect(screen.getByText('232時間')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() => expect(workCalendarsApi.putWorkCalendarDays).toHaveBeenCalled())
    const [, savedDays] = vi.mocked(workCalendarsApi.putWorkCalendarDays).mock.calls[0]
    const edited = savedDays.find((d) => d.date === '2026-04-05')
    expect(edited).toEqual({
      date: '2026-04-05',
      day_type: 'public_holiday',
      schedule_state: 'OFF',
      is_public_holiday: true,
      public_holiday_name: 'こどもの日',
      note: undefined,
    })
  })

  it('shows an empty grid without blocking when the year has no days yet', async () => {
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue([])

    renderPage()

    const cell = await screen.findByRole('button', { name: '2026-04-01 勤務日' })
    expect(within(cell).getByText('1')).toBeInTheDocument()
  })
})
