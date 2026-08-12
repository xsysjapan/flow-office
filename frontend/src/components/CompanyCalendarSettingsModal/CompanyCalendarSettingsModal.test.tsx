import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar } from '../../api/types'
import { CompanyCalendarSettingsModal } from './CompanyCalendarSettingsModal'

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

function renderModal() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <CompanyCalendarSettingsModal companyCalendar={calendar} open onOpenChange={() => {}} />
    </QueryClientProvider>,
  )
}

describe('CompanyCalendarSettingsModal', () => {
  it('prefills the form with the current calendar settings', () => {
    renderModal()

    expect(screen.getByLabelText('カレンダー名')).toHaveValue('本社カレンダー')
    expect(screen.getByLabelText('週の開始日(0=日曜)')).toHaveValue(1)
    expect(screen.getByLabelText('年度開始月')).toHaveValue(4)
    expect(screen.getByLabelText('年度開始日')).toHaveValue(1)
  })

  it('saves the edited settings', async () => {
    vi.spyOn(workCalendarsApi, 'updateWorkCalendar').mockResolvedValue({
      ...calendar,
      name: '名古屋事業所カレンダー',
      fiscal_year_start_month: 1,
    })

    renderModal()

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
})
