import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceMonth } from '../api/types'
import { AttendanceMonthsPage } from './AttendanceMonthsPage'

const notSubmittedMonth: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: '2026-07',
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AttendanceMonthsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AttendanceMonthsPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no months', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('勤怠月次はまだありません。')).toBeInTheDocument()
  })

  it('lists months with their status and links to the detail page', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      { ...notSubmittedMonth, id: 2, year_month: '2026-06', status: 'closed' },
    ])

    renderPage()

    expect(await screen.findByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('締め済み')).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/attendance/months/2026-06')
  })

  it('shows legal holiday warning badges', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      {
        ...notSubmittedMonth,
        legal_holiday_warnings: [
          { rule: 'weekly', period_start: '2026-07-01', period_end: '2026-07-31', legal_holiday_count: 2, required_count: 4 },
        ],
      },
    ])

    renderPage()

    expect(await screen.findByText(/法定休日不足/)).toBeInTheDocument()
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })
})
