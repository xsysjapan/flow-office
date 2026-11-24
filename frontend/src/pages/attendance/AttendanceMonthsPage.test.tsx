import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { AttendanceMonth, User } from '../../api/types'
import { AttendanceMonthsPage } from './AttendanceMonthsPage'

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  hire_date: '2026-01-15',
  last_login_at: null,
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const notSubmittedMonth: AttendanceMonth = {
  id: 'month-1',
  user_id: 'user-1',
  year_month: '2026-07',
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  return_comment: null,
  revert_comment: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntries?: string[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={initialEntries}>
        <AttendanceMonthsPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AttendanceMonthsPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.setSystemTime(new Date('2026-07-15T12:00:00+09:00'))
    currentUser.hire_date = '2026-01-15'
    currentUser.termination_date = undefined
  })

  afterEach(() => vi.useRealTimers())

  it('shows all employment months and paginates them even when no month records exist', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage()

    expect(await screen.findByLabelText('年')).toHaveValue('')
    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    expect(screen.queryByText('2026-01')).not.toBeInTheDocument()
    expect(screen.getAllByText('未提出')).toHaveLength(6)
    expect(screen.getByText('7件中 1 / 2 ページ')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '前のページ' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    expect(screen.getByText('2026-01')).toBeInTheDocument()
    expect(screen.getByText('7件中 2 / 2 ページ')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '次のページ' })).toBeDisabled()
  })

  it('lists months with their status and links to the detail page', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      { ...notSubmittedMonth, id: 'month-2', year_month: '2026-06', status: 'closed' },
    ])

    renderPage()

    expect(await screen.findByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('締め済み')).toBeInTheDocument()
    expect(screen.getByText('2026-06').closest('a')).toHaveAttribute('href', '/attendance/months/2026-06')
  })

  it('switches from all employment months to the selected year', async () => {
    currentUser.hire_date = '2025-01-15'
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.getByLabelText('年')).toHaveValue('')
    await userEvent.selectOptions(screen.getByLabelText('年'), '2025')

    expect(screen.getByText('2025-12')).toBeInTheDocument()
    expect(screen.queryByText('2026-07')).not.toBeInTheDocument()
  })

  it('updates the URL on year change (dropping page) and clears with 検索条件をクリア', async () => {
    currentUser.hire_date = '2025-01-15'
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage(['/attendance/months?page=2'])

    await screen.findByLabelText('年')
    expect(screen.getByRole('option', { name: 'すべて' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '検索条件をクリア' })).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('年'), '2025')
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?year=2025$/))

    await userEvent.click(screen.getByRole('button', { name: '検索条件をクリア' }))
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
    expect(screen.getByLabelText('年')).toHaveValue('')
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
  it('treats an invalid year query param as unspecified (default display) and removes it from the URL', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage(['/attendance/months?year=abc'])

    expect(await screen.findByLabelText('年')).toHaveValue('')
    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('location-search').textContent).not.toContain('year'))
  })


  it('removes a year that is not among the options from the URL', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage(['/attendance/months?year=1999'])

    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
  })

  it('keeps a year that is among the options', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage(['/attendance/months?year=2026'])

    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    expect(screen.getByTestId('location-search')).toHaveTextContent('?year=2026')
  })

  it('adjusts a page beyond the last page to the last page, and removes an invalid page', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])
    currentUser.hire_date = '2025-01-15'

    renderPage(['/attendance/months?page=99'])
    await waitFor(() => expect(screen.getByTestId('location-search').textContent).toMatch(/^\?page=\d+$/))
    expect(screen.getByTestId('location-search').textContent).not.toBe('?page=99')
  })

  it('removes an invalid page from the URL', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage(['/attendance/months?page=abc'])

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
  })
})
