import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import { pickDate } from '../../test-support/pickerInteractions'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

function renderPage() {
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
  it('shows the day editor', async () => {
    renderPage()

    expect(await screen.findByText('カレンダー年度の日別編集')).toBeInTheDocument()
  })

  it('adds a row, marks it as a public holiday, and saves', async () => {
    vi.spyOn(workCalendarsApi, 'putWorkCalendarDays').mockResolvedValue([])
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '行を追加' }))

    await pickDate(userEvent.setup(), '日付', '2026-05-05')
    await userEvent.selectOptions(screen.getByLabelText('勤務区分'), 'OFF')
    await userEvent.click(screen.getByLabelText('祝日'))
    await userEvent.type(screen.getByLabelText('祝日名'), 'こどもの日')

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(workCalendarsApi.putWorkCalendarDays).toHaveBeenCalledWith('year-1', [
        {
          date: '2026-05-05',
          day_type: 'public_holiday',
          schedule_state: 'OFF',
          is_public_holiday: true,
          public_holiday_name: 'こどもの日',
          note: undefined,
        },
      ]),
    )
  })
})
