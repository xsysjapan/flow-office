import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../../api/paidLeave'
import type { PaidLeaveRequest } from '../../api/types'
import { PaidLeaveRequestsToApprovePage } from './PaidLeaveRequestsToApprovePage'

const request: PaidLeaveRequest = {
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
  status: 'submitted',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  reason: '私用のため',
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <PaidLeaveRequestsToApprovePage />
    </QueryClientProvider>,
  )
}

describe('PaidLeaveRequestsToApprovePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no requests', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveRequestsToApprove').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('承認待ちの有給申請はありません。')).toBeInTheDocument()
  })

  it('shows the requesters name, leave type, and reason', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveRequestsToApprove').mockResolvedValue([request])

    renderPage()

    expect(await screen.findByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getByText('申請者太郎')).toBeInTheDocument()
    expect(screen.getByText('全休(1日)')).toBeInTheDocument()
    expect(screen.getByText('理由: 私用のため')).toBeInTheDocument()
  })

  it('approves a request when the button is clicked', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveRequestsToApprove').mockResolvedValue([request])
    vi.spyOn(paidLeaveApi, 'approvePaidLeaveRequest').mockResolvedValue({ ...request, status: 'approved' })

    renderPage()
    await screen.findByText('2026-08-10')

    await userEvent.click(screen.getByRole('button', { name: '承認する' }))

    await waitFor(() => expect(paidLeaveApi.approvePaidLeaveRequest).toHaveBeenCalledWith(request.id))
  })

  it('requires a comment before returning a request', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveRequestsToApprove').mockResolvedValue([request])
    vi.spyOn(paidLeaveApi, 'returnPaidLeaveRequest').mockResolvedValue({ ...request, status: 'returned' })

    renderPage()
    await screen.findByText('2026-08-10')

    expect(screen.getByRole('button', { name: '差戻す' })).toBeDisabled()

    await userEvent.type(screen.getByPlaceholderText('差戻しコメント'), '日程を確認してください')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() =>
      expect(paidLeaveApi.returnPaidLeaveRequest).toHaveBeenCalledWith(request.id, '日程を確認してください'),
    )
  })

  it('bulk-approves selected requests', async () => {
    const secondRequest: PaidLeaveRequest = { ...request, id: 'request-2', target_date: '2026-08-11' }
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveRequestsToApprove').mockResolvedValue([request, secondRequest])
    const approveSpy = vi.spyOn(paidLeaveApi, 'approvePaidLeaveRequest').mockResolvedValue({
      ...request,
      status: 'approved',
    })

    renderPage()
    await screen.findByText('2026-08-10')

    await userEvent.click(screen.getByRole('checkbox', { name: '2026-08-10の申請者太郎の申請を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '2026-08-11の申請者太郎の申請を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'まとめて承認する' }))

    await waitFor(() => expect(approveSpy).toHaveBeenCalledTimes(2))
    expect(approveSpy).toHaveBeenCalledWith('request-1')
    expect(approveSpy).toHaveBeenCalledWith('request-2')
  })
})
