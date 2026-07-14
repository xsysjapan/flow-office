import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../api/attendance'
import type { AttendanceMonth, User } from '../api/types'
import { MonthsToApprovePage } from './MonthsToApprovePage'

const approverUser: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  roles: ['employee'],
  last_login_at: null,
}

const hrStaffUser: User = {
  ...approverUser,
  id: 3,
  name: '人事一郎',
  roles: ['hr_staff'],
}

let currentUser: User = approverUser

vi.mock('../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const submittedMonth: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: '2026-07',
  status: 'submitted',
  approver: approverUser,
  submitted_at: '2026-07-05T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const approvedMonth: AttendanceMonth = {
  ...submittedMonth,
  id: 2,
  status: 'approved',
  approved_at: '2026-07-06T00:00:00+09:00',
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MonthsToApprovePage />
    </QueryClientProvider>,
  )
}

describe('MonthsToApprovePage', () => {
  beforeEach(() => {
    currentUser = approverUser
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no months to approve', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('承認待ちの勤怠月次はありません。')).toBeInTheDocument()
  })

  it('shows approve and return actions for a submitted month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([submittedMonth])

    renderPage()

    expect(await screen.findByRole('button', { name: '承認する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '差戻す' })).toBeInTheDocument()
  })

  it('approves the month when the approver clicks approve', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([submittedMonth])
    vi.spyOn(attendanceApi, 'approveMonth').mockResolvedValue({ ...submittedMonth, status: 'approved' })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '承認する' }))

    await waitFor(() => expect(attendanceApi.approveMonth).toHaveBeenCalledWith(1))
  })

  it('disables the return button until a comment is entered', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([submittedMonth])

    renderPage()

    expect(await screen.findByRole('button', { name: '差戻す' })).toBeDisabled()
  })

  it('returns the month with a comment', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([submittedMonth])
    vi.spyOn(attendanceApi, 'returnMonth').mockResolvedValue({ ...submittedMonth, status: 'returned' })

    renderPage()
    await userEvent.type(await screen.findByPlaceholderText('差戻しコメント'), '不備があります')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() => expect(attendanceApi.returnMonth).toHaveBeenCalledWith(1, '不備があります'))
  })

  it('does not show a close button for a regular approver', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([approvedMonth])

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('button', { name: '締め処理' })).not.toBeInTheDocument()
  })

  it('shows a close button for hr_staff on an approved month', async () => {
    currentUser = hrStaffUser
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([approvedMonth])

    renderPage()

    expect(await screen.findByRole('button', { name: '締め処理' })).toBeInTheDocument()
  })

  it('closes the month when hr_staff clicks close', async () => {
    currentUser = hrStaffUser
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([approvedMonth])
    vi.spyOn(attendanceApi, 'closeMonth').mockResolvedValue({ ...approvedMonth, status: 'closed' })

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: '締め処理' }))

    await waitFor(() => expect(attendanceApi.closeMonth).toHaveBeenCalledWith(2))
  })

  it('shows an error message when the initial fetch fails', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('network down')
  })

  it('bulk-approves selected submitted months', async () => {
    const secondSubmittedMonth: AttendanceMonth = { ...submittedMonth, id: 4, year_month: '2026-06', user_id: 2 }
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([submittedMonth, secondSubmittedMonth])
    const approveSpy = vi.spyOn(attendanceApi, 'approveMonth').mockResolvedValue({ ...submittedMonth, status: 'approved' })

    renderPage()
    await screen.findByText('2026-07')

    await userEvent.click(screen.getByRole('checkbox', { name: '2026-07(社員ID: 1)を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-06(社員ID: 2)を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'まとめて承認する' }))

    await waitFor(() => expect(approveSpy).toHaveBeenCalledTimes(2))
    expect(approveSpy).toHaveBeenCalledWith(1)
    expect(approveSpy).toHaveBeenCalledWith(4)
  })

  it('does not show a selection checkbox for an already-approved month', async () => {
    vi.spyOn(attendanceApi, 'fetchMonthsToApprove').mockResolvedValue([approvedMonth])

    renderPage()

    await screen.findByText('2026-07')
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })
})
