import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import * as usersApi from '../api/users'
import type { AttendanceMonth, Paginated, User } from '../api/types'
import { AttendanceMonthsPage } from './AttendanceMonthsPage'

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
      <AttendanceMonthsPage />
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

  it('lists months with their status', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      { ...notSubmittedMonth, id: 2, year_month: '2026-06', status: 'closed' },
    ])

    renderPage()

    expect(await screen.findByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('締め済み')).toBeInTheDocument()
  })

  it('shows a submit control for a not_submitted month', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([notSubmittedMonth])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)

    renderPage()

    expect(await screen.findByRole('button', { name: '提出する' })).toBeInTheDocument()
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('shows a submit control for a returned month', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      { ...notSubmittedMonth, status: 'returned' },
    ])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)

    renderPage()

    expect(await screen.findByRole('button', { name: '提出する' })).toBeInTheDocument()
  })

  it('does not show a submit control for an approved month', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([
      { ...notSubmittedMonth, status: 'approved' },
    ])

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('button', { name: '提出する' })).not.toBeInTheDocument()
  })

  it('disables the submit button until an approver is picked', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([notSubmittedMonth])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)

    renderPage()

    expect(await screen.findByRole('button', { name: '提出する' })).toBeDisabled()
  })

  it('submits the month with the picked approver', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockResolvedValue([notSubmittedMonth])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedApprover)
    vi.spyOn(attendanceApi, 'submitMonth').mockResolvedValue({
      ...notSubmittedMonth,
      status: 'submitted',
    })

    renderPage()

    await userEvent.click(await screen.findByRole('combobox'))
    await userEvent.type(
      await screen.findByPlaceholderText('氏名またはメールアドレスで検索'),
      '花子',
    )
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '提出する' }))

    await waitFor(() => expect(attendanceApi.submitMonth).toHaveBeenCalledWith('2026-07', 2))
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMyMonths').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })
})
