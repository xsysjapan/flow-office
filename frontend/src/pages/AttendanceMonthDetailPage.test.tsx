import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import * as usersApi from '../api/users'
import type { AttendanceDay, AttendanceMonth, Paginated, User } from '../api/types'
import { AttendanceMonthDetailPage } from './AttendanceMonthDetailPage'

const yearMonth = '2026-07'

const approver: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const paginatedApprover: Paginated<User> = {
  data: [approver],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

const notSubmittedMonth: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: yearMonth,
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const dayRecord: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: '2026-07-01',
  status: 'clocked_out',
  actual_start_at: '2026-07-01T09:00:00+09:00',
  actual_end_at: '2026-07-01T18:00:00+09:00',
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/attendance/months/${yearMonth}`]}>
        <Routes>
          <Route path="/attendance/months/:yearMonth" element={<AttendanceMonthDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AttendanceMonthDetailPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the days of the month with links to each day page', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [dayRecord],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
    })

    renderPage()

    expect(await screen.findByText('2026-07-01(水)')).toBeInTheDocument()
    const link = screen.getByText('2026-07-01(水)').closest('a')
    expect(link).toHaveAttribute('href', '/attendance/days/2026-07-01')
    expect(screen.getAllByText('未入力').length).toBeGreaterThan(0)
  })

  it('shows a submit control for a not_submitted month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
    })
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)

    renderPage()

    expect(await screen.findByRole('button', { name: '提出する' })).toBeDisabled()
  })

  it('does not show a submit control for an approved month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...notSubmittedMonth, status: 'approved' },
      flex_settlement_summary: null,
    })

    renderPage()

    await screen.findByText(`${yearMonth}の勤怠月次`)
    expect(screen.queryByRole('button', { name: '提出する' })).not.toBeInTheDocument()
  })

  it('submits the month with the picked approver', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: notSubmittedMonth,
      flex_settlement_summary: null,
    })
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)
    vi.spyOn(attendanceApi, 'submitMonth').mockResolvedValue({ ...notSubmittedMonth, status: 'submitted' })

    renderPage()

    await userEvent.click(await screen.findByRole('combobox'))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '提出する' }))

    await waitFor(() => expect(attendanceApi.submitMonth).toHaveBeenCalledWith(yearMonth, 2))
  })

  it('shows legal holiday warning badges', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: {
        ...notSubmittedMonth,
        legal_holiday_warnings: [
          { rule: 'weekly', period_start: '2026-07-01', period_end: '2026-07-31', legal_holiday_count: 2, required_count: 4 },
        ],
      },
      flex_settlement_summary: null,
    })

    renderPage()

    expect(await screen.findByText(/法定休日不足/)).toBeInTheDocument()
  })

  it('shows an error message when the fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMonth').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })
})
