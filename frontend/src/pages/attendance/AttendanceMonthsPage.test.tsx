import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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
    currentUser.hire_date = '2026-01-15'
    currentUser.termination_date = undefined
  })

  it('shows all employment months and paginates them even when no month records exist', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([])

    renderPage()

    expect(await screen.findByLabelText('表示する年')).toHaveValue('')
    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    expect(screen.queryByText('2026-01')).not.toBeInTheDocument()
    expect(screen.getAllByText('未提出')).toHaveLength(6)
    expect(screen.getByText('7件 (1/2ページ)')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '前のページ' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    expect(screen.getByText('2026-01')).toBeInTheDocument()
    expect(screen.getByText('7件 (2/2ページ)')).toBeInTheDocument()
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
    expect(screen.getByLabelText('表示する年')).toHaveValue('')
    await userEvent.selectOptions(screen.getByLabelText('表示する年'), '2025')

    expect(screen.getByText('2025-12')).toBeInTheDocument()
    expect(screen.queryByText('2026-07')).not.toBeInTheDocument()
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
