import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import type { AttendanceMonth, Paginated, User } from '../../api/types'
import { AttendanceMonthCloseoutPage } from './AttendanceMonthCloseoutPage'

const hrStaffUser: User = {
  id: 'hr-1',
  name: '人事一郎',
  email: 'ichiro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  roles: ['hr_staff'],
  last_login_at: null,
}

const employeeUser: User = {
  ...hrStaffUser,
  id: 'employee-1',
  name: '一般社員次郎',
  roles: ['employee'],
}

const targetEmployeeUser: User = {
  id: 'user-1',
  name: '対象社員太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

let currentUser: User = hrStaffUser

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const approvedMonth: AttendanceMonth = {
  id: 'month-1',
  user_id: 'user-1',
  year_month: '2026-07',
  status: 'approved',
  approver: hrStaffUser,
  submitted_at: '2026-07-01T00:00:00+09:00',
  approved_at: '2026-07-02T00:00:00+09:00',
  returned_at: null,
  return_comment: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

function paginated(data: AttendanceMonth[], overrides: Partial<Paginated<AttendanceMonth>['meta']> = {}): Paginated<AttendanceMonth> {
  return {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length, ...overrides },
    links: { next: null, prev: null },
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <AttendanceMonthCloseoutPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('AttendanceMonthCloseoutPage', () => {
  beforeEach(() => {
    currentUser = hrStaffUser
    vi.restoreAllMocks()
  })

  it('redirects away when the current user is not admin/hr_staff', async () => {
    currentUser = employeeUser
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([]))

    renderPage()

    expect(screen.queryByText('月次締め処理')).not.toBeInTheDocument()
  })

  it('requests approved months by default', async () => {
    const fetchSpy = vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))

    renderPage()

    await screen.findByText('2026-07')
    expect(fetchSpy).toHaveBeenCalledWith(expect.objectContaining({ status: 'approved', page: 1 }))
  })

  it('shows an empty state when there are no months to close', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([]))

    renderPage()

    expect(await screen.findByText('締め処理待ちの月次勤怠はありません。')).toBeInTheDocument()
  })

  it('shows the employee name when available, falling back to the employee id otherwise', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(
      paginated([{ ...approvedMonth, user: targetEmployeeUser }]),
    )

    renderPage()

    expect(await screen.findByText('対象社員太郎')).toBeInTheDocument()
  })

  it('does not show approve/return actions', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '差戻す' })).not.toBeInTheDocument()
  })

  it('closes the month when clicking close', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([approvedMonth]))
    vi.spyOn(attendanceApi, 'closeMonth').mockResolvedValue({ ...approvedMonth, status: 'closed' })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '締め処理' }))

    await waitFor(() => expect(attendanceApi.closeMonth).toHaveBeenCalledWith('month-1'))
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  it('expands the actual attendance record for the target employee', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue(paginated([{ ...approvedMonth, user: targetEmployeeUser }]))
    const fetchMonthSpy = vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: { ...approvedMonth, user: targetEmployeeUser },
      flex_settlement_summary: null,
      monthly_calculation_totals: {
        work_minutes: 0,
        payroll_work_minutes: 0,
        prescribed_work_minutes: 0,
        statutory_within_overtime_minutes: 0,
        statutory_excess_overtime_minutes: 0,
        statutory_excess_overtime_within_60h_minutes: 0,
        statutory_excess_overtime_over_60h_minutes: 0,
        late_night_work_minutes: 0,
        late_night_prescribed_work_minutes: 0,
        late_night_statutory_within_overtime_minutes: 0,
        late_night_statutory_excess_overtime_minutes: 0,
        legal_holiday_work_minutes: 0,
        prescribed_holiday_work_minutes: 0,
        late_night_legal_holiday_work_minutes: 0,
      },
    })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '実際の勤務表を確認' }))

    expect(await screen.findByRole('heading', { name: '月次勤怠' })).toBeInTheDocument()
    await waitFor(() => expect(fetchMonthSpy).toHaveBeenCalledWith('2026-07', 'user-1'))

    await userEvent.click(screen.getByRole('button', { name: '勤務表を閉じる' }))
    expect(screen.queryByRole('heading', { name: '月次勤怠' })).not.toBeInTheDocument()
  })

  it('shows pagination controls and requests the next page', async () => {
    const fetchSpy = vi
      .spyOn(attendanceApi, 'fetchMonthsToApprove')
      .mockResolvedValue(paginated([approvedMonth], { current_page: 1, last_page: 2, total: 21 }))

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.getByText('21件中 1 / 2 ページ')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    await waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })
})
