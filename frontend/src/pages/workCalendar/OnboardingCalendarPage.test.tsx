import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar } from '../../api/types'
import { OnboardingCalendarPage } from './OnboardingCalendarPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <OnboardingCalendarPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('OnboardingCalendarPage', () => {
  it('prompts to create a company calendar first when none exist', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText(/まず会社カレンダー本体を作成してください/)).toBeInTheDocument()
  })

  it('generates calendar years and shows the completion state', async () => {
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])
    vi.spyOn(workCalendarsApi, 'generateCompanyCalendarYearsNow').mockResolvedValue({
      generated_company_calendar_year_ids: ['year-1'],
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '今すぐ生成する' }))

    await waitFor(() => expect(workCalendarsApi.generateCompanyCalendarYearsNow).toHaveBeenCalled())
    expect(await screen.findByText('1件のカレンダー年度を生成しました。')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'この設定で開始する' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'カレンダーを確認する' })).toBeInTheDocument()
  })
})
