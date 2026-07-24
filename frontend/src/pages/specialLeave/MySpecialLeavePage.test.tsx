import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import * as usersApi from '../../api/users'
import type { Paginated, SpecialLeaveGrant, SpecialLeaveRequest, SpecialLeaveType, User } from '../../api/types'
import { MySpecialLeavePage } from './MySpecialLeavePage'

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

const birthdayType: SpecialLeaveType = { id: 1, name: '誕生日休暇', is_active: true }

const submittedRequest: SpecialLeaveRequest = {
  id: 'request-1',
  user_id: 'user-1',
  special_leave_type_id: birthdayType.id,
  special_leave_type_name: birthdayType.name,
  status: 'submitted',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  reason: null,
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
}

function renderPage(requests: SpecialLeaveRequest[] = [], types: SpecialLeaveType[] = [birthdayType]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(approverSearchResult)
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveTypes').mockResolvedValue(types)
  vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveRequests').mockResolvedValue(requests)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <MySpecialLeavePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MySpecialLeavePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no grants', async () => {
    vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveGrants').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('特別休暇の付与はまだありません。')).toBeInTheDocument()
  })

  it('shows the remaining days grouped by special leave type', async () => {
    const grants: SpecialLeaveGrant[] = [
      {
        id: 'grant-1',
        user_id: 'user-1',
        special_leave_type_id: birthdayType.id,
        special_leave_type_name: birthdayType.name,
        granted_on: '2026-07-01',
        expires_on: null,
        granted_days: 3,
        used_days: 1,
        remaining_days: 2,
        grant_reason: '誕生月付与',
      },
    ]
    vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveGrants').mockResolvedValue(grants)

    renderPage()

    expect(await screen.findByText(/誕生日休暇: 残り/)).toBeInTheDocument()
    expect(screen.getByText('2026-07-01')).toBeInTheDocument()
    expect(screen.getByText('なし')).toBeInTheDocument()
    expect(screen.getByText('誕生月付与')).toBeInTheDocument()
  })

  it('submits a full-day special leave request with the selected type', async () => {
    vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveGrants').mockResolvedValue([])
    vi.spyOn(specialLeaveApi, 'createSpecialLeaveRequest').mockResolvedValue(submittedRequest)

    renderPage()
    await screen.findByText('特別休暇申請はまだありません。')

    await userEvent.selectOptions(screen.getByLabelText('特別休暇の種類'), '誕生日休暇')
    await userEvent.type(screen.getByLabelText('対象日'), '2026-08-10')
    await userEvent.click(screen.getByLabelText('承認者'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '承認者')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '申請する' }))

    await waitFor(() =>
      expect(specialLeaveApi.createSpecialLeaveRequest).toHaveBeenCalledWith({
        special_leave_type_id: birthdayType.id,
        target_date: '2026-08-10',
        leave_type: 'full',
        hours: undefined,
        approver_user_id: approver.id,
        reason: undefined,
      }),
    )
  })

  it('shows submitted requests and cancels them', async () => {
    vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveGrants').mockResolvedValue([])
    vi.spyOn(specialLeaveApi, 'cancelSpecialLeaveRequest').mockResolvedValue({ ...submittedRequest, status: 'cancelled' })

    renderPage([submittedRequest])

    expect(await screen.findByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getAllByText('誕生日休暇').length).toBeGreaterThan(0)
    expect(screen.getByText('申請中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '取消' }))

    await waitFor(() => expect(specialLeaveApi.cancelSpecialLeaveRequest).toHaveBeenCalledWith(submittedRequest.id))
  })
})
