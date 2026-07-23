import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import type { SpecialLeaveRequest } from '../../api/types'
import { SpecialLeaveRequestsToApprovePage } from './SpecialLeaveRequestsToApprovePage'

const request: SpecialLeaveRequest = {
  id: 'request-1',
  user_id: 'user-1',
  user: {
    id: 'user-1',
    name: '申請者太郎',
    email: 'taro@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
  special_leave_type_id: 1,
  special_leave_type_name: '誕生日休暇',
  status: 'submitted',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  reason: '誕生日のため',
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <SpecialLeaveRequestsToApprovePage />
    </QueryClientProvider>,
  )
}

describe('SpecialLeaveRequestsToApprovePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no requests', async () => {
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveRequestsToApprove').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('承認待ちの特別休暇申請はありません。')).toBeInTheDocument()
  })

  it('shows the requesters name, special leave type, leave unit, and reason', async () => {
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveRequestsToApprove').mockResolvedValue([request])

    renderPage()

    expect(await screen.findByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getByText('申請者太郎')).toBeInTheDocument()
    expect(screen.getByText('誕生日休暇')).toBeInTheDocument()
    expect(screen.getByText('全休(1日)')).toBeInTheDocument()
    expect(screen.getByText('理由: 誕生日のため')).toBeInTheDocument()
  })

  it('approves a request when the button is clicked', async () => {
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveRequestsToApprove').mockResolvedValue([request])
    vi.spyOn(specialLeaveApi, 'approveSpecialLeaveRequest').mockResolvedValue({ ...request, status: 'approved' })

    renderPage()
    await screen.findByText('2026-08-10')

    await userEvent.click(screen.getByRole('button', { name: '承認する' }))

    await waitFor(() => expect(specialLeaveApi.approveSpecialLeaveRequest).toHaveBeenCalledWith(request.id))
  })

  it('requires a comment before returning a request', async () => {
    vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveRequestsToApprove').mockResolvedValue([request])
    vi.spyOn(specialLeaveApi, 'returnSpecialLeaveRequest').mockResolvedValue({ ...request, status: 'returned' })

    renderPage()
    await screen.findByText('2026-08-10')

    expect(screen.getByRole('button', { name: '差戻す' })).toBeDisabled()

    await userEvent.type(screen.getByPlaceholderText('差戻しコメント'), '日程を確認してください')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() =>
      expect(specialLeaveApi.returnSpecialLeaveRequest).toHaveBeenCalledWith(request.id, '日程を確認してください'),
    )
  })
})
