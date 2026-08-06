import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as compensatoryLeaveApi from '../../api/compensatoryLeave'
import * as usersApi from '../../api/users'
import type { CompensatoryLeaveGrant, CompensatoryLeaveRequest, Paginated, User } from '../../api/types'
import { AppSettingsContext } from '../../contexts/AppSettingsContext'
import { pickDate } from '../../test-support/pickerInteractions'
import { MyCompensatoryLeavePage } from './MyCompensatoryLeavePage'

const approver: User = {
  id: 'approver-1',
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const approverSearchResult: Paginated<User> = {
  data: [approver],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

const submittedRequest: CompensatoryLeaveRequest = {
  id: 'request-1',
  user_id: 'user-1',
  status: 'submitted',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  requested_minutes: null,
  reason: null,
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
}

function renderPage(requests: CompensatoryLeaveRequest[] = [], compensatoryLeaveRequiresApproval = true) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(approverSearchResult)
  vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveRequests').mockResolvedValue(requests)

  return render(
    <QueryClientProvider client={queryClient}>
      <AppSettingsContext.Provider
        value={{
          systemSettings: {
            paid_leave_requires_approval: true,
            special_leave_requires_approval: true,
            shift_swap_requires_approval: true,
            attendance_requires_approval: true,
            expense_claim_requires_approval: true,
            compensatory_leave_requires_approval: compensatoryLeaveRequiresApproval,
            default_timezone: 'Asia/Tokyo',
            default_work_style_id: null,
            default_work_style: null,
            attendance_submission_deadline_day: 5,
            attendance_month_close_deadline_day: 10,
          },
          isLoading: false,
        }}
      >
        <MemoryRouter>
          <MyCompensatoryLeavePage />
        </MemoryRouter>
      </AppSettingsContext.Provider>
    </QueryClientProvider>,
  )
}

describe('MyCompensatoryLeavePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no grants', async () => {
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('代休の付与はまだありません。')).toBeInTheDocument()
  })

  it('shows the total remaining days, days column, and minutes column (hyphen when null)', async () => {
    const grants: CompensatoryLeaveGrant[] = [
      {
        id: 'grant-1',
        user_id: 'user-1',
        attendance_day_id: 'day-1',
        work_date: '2026-07-05',
        status: 'confirmed',
        granted_days: 1,
        granted_minutes: null,
        used_days: 0,
        used_minutes: null,
        remaining_days: 1,
        remaining_minutes: null,
        confirmed_at: '2026-07-06T00:00:00+09:00',
        expires_on: '2026-12-31',
      },
      {
        id: 'grant-2',
        user_id: 'user-1',
        attendance_day_id: 'day-2',
        work_date: '2026-07-20',
        status: 'confirmed',
        granted_days: 0.25,
        granted_minutes: 120,
        used_days: 0,
        used_minutes: 0,
        remaining_days: 0.25,
        remaining_minutes: 120,
        confirmed_at: '2026-07-21T00:00:00+09:00',
        expires_on: '2026-12-31',
      },
    ]
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue(grants)

    renderPage()

    expect(await screen.findByText('1.25')).toBeInTheDocument()
    expect(screen.getByText('2026-07-05')).toBeInTheDocument()
    expect(screen.getByText('2026-07-20')).toBeInTheDocument()
    expect(screen.getByText('120')).toBeInTheDocument()
    expect(screen.getByText('-')).toBeInTheDocument()
  })

  it('shows an empty state when there are no requests', async () => {
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('代休申請はまだありません。')).toBeInTheDocument()
  })

  it('submits a full-day leave request with the entered values', async () => {
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue([])
    vi.spyOn(compensatoryLeaveApi, 'createCompensatoryLeaveRequest').mockResolvedValue(submittedRequest)

    renderPage()
    await screen.findByText('代休申請はまだありません。')

    await pickDate(userEvent.setup(), '対象日', '2026-08-10')
    await userEvent.click(screen.getByLabelText('承認者'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '承認者')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '申請する' }))

    await waitFor(() =>
      expect(compensatoryLeaveApi.createCompensatoryLeaveRequest).toHaveBeenCalledWith({
        target_date: '2026-08-10',
        leave_type: 'full',
        hours: undefined,
        approver_user_id: approver.id,
        reason: undefined,
      }),
    )
  })

  it('allows submitting without an approver when approval is not required', async () => {
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue([])
    vi.spyOn(compensatoryLeaveApi, 'createCompensatoryLeaveRequest').mockResolvedValue(submittedRequest)

    renderPage([], false)
    await screen.findByText('代休申請はまだありません。')

    expect(screen.getByText('承認者(任意)')).toBeInTheDocument()

    await pickDate(userEvent.setup(), '対象日', '2026-08-10')
    await userEvent.click(screen.getByRole('button', { name: '申請する' }))

    await waitFor(() =>
      expect(compensatoryLeaveApi.createCompensatoryLeaveRequest).toHaveBeenCalledWith({
        target_date: '2026-08-10',
        leave_type: 'full',
        hours: undefined,
        approver_user_id: undefined,
        reason: undefined,
      }),
    )
  })

  it('shows submitted requests and cancels them', async () => {
    vi.spyOn(compensatoryLeaveApi, 'fetchMyCompensatoryLeaveGrants').mockResolvedValue([])
    vi.spyOn(compensatoryLeaveApi, 'cancelCompensatoryLeaveRequest').mockResolvedValue({
      ...submittedRequest,
      status: 'cancelled',
    })

    renderPage([submittedRequest])

    expect(await screen.findByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getByText('申請中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '取消' }))

    await waitFor(() => expect(compensatoryLeaveApi.cancelCompensatoryLeaveRequest).toHaveBeenCalledWith(submittedRequest.id))
  })
})
