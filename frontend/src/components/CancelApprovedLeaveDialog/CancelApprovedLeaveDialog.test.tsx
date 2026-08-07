import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as compensatoryLeaveApi from '../../api/compensatoryLeave'
import * as paidLeaveApi from '../../api/paidLeave'
import * as specialLeaveApi from '../../api/specialLeave'
import type { CompensatoryLeaveRequest, PaidLeaveRequest, SpecialLeaveRequest } from '../../api/types'
import { CancelApprovedLeaveDialog, type ApprovedLeaveTarget } from './CancelApprovedLeaveDialog'

const cancelledPaidLeaveRequest: PaidLeaveRequest = {
  id: 'paid-leave-request-1',
  user_id: 'user-1',
  status: 'cancelled',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  reason: null,
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: '2026-08-02T00:00:00+09:00',
  returned_at: null,
  cancelled_at: '2026-08-05T00:00:00+09:00',
}

const cancelledSpecialLeaveRequest: SpecialLeaveRequest = {
  id: 'special-leave-request-1',
  user_id: 'user-1',
  special_leave_type_id: 1,
  status: 'cancelled',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  reason: null,
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: '2026-08-02T00:00:00+09:00',
  returned_at: null,
  cancelled_at: '2026-08-05T00:00:00+09:00',
}

const cancelledCompensatoryLeaveRequest: CompensatoryLeaveRequest = {
  id: 'compensatory-leave-request-1',
  user_id: 'user-1',
  status: 'cancelled',
  leave_type: 'full',
  target_date: '2026-08-10',
  hours: null,
  requested_days: 1,
  requested_minutes: null,
  reason: null,
  submitted_at: '2026-08-01T00:00:00+09:00',
  approved_at: '2026-08-02T00:00:00+09:00',
  returned_at: null,
  cancelled_at: '2026-08-05T00:00:00+09:00',
}

function renderDialog(target: ApprovedLeaveTarget | null, overrides: { onOpenChange?: () => void; onCancelled?: () => void } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onOpenChange = overrides.onOpenChange ?? vi.fn()
  const onCancelled = overrides.onCancelled ?? vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <CancelApprovedLeaveDialog target={target} onOpenChange={onOpenChange} onCancelled={onCancelled} />
    </QueryClientProvider>,
  )

  return { onOpenChange, onCancelled }
}

describe('CancelApprovedLeaveDialog', () => {
  it('is closed when target is null', () => {
    renderDialog(null)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows the target label in the confirmation title', () => {
    renderDialog({ kind: 'paid', id: 'paid-leave-request-1', label: '有給休暇' })
    expect(screen.getByText('有給休暇の承認を取り消しますか?')).toBeInTheDocument()
  })

  it('cancels a paid leave request and reports completion', async () => {
    vi.spyOn(paidLeaveApi, 'cancelPaidLeaveRequest').mockResolvedValue(cancelledPaidLeaveRequest)
    const { onCancelled } = renderDialog({ kind: 'paid', id: 'paid-leave-request-1', label: '有給休暇' })

    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    await waitFor(() => expect(paidLeaveApi.cancelPaidLeaveRequest).toHaveBeenCalledWith('paid-leave-request-1'))
    await waitFor(() => expect(onCancelled).toHaveBeenCalled())
  })

  it('cancels a special leave request using the special-leave API', async () => {
    vi.spyOn(specialLeaveApi, 'cancelSpecialLeaveRequest').mockResolvedValue(cancelledSpecialLeaveRequest)
    renderDialog({ kind: 'special', id: 'special-leave-request-1', label: '特別休暇' })

    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    await waitFor(() => expect(specialLeaveApi.cancelSpecialLeaveRequest).toHaveBeenCalledWith('special-leave-request-1'))
  })

  it('cancels a compensatory leave request using the compensatory-leave API', async () => {
    vi.spyOn(compensatoryLeaveApi, 'cancelCompensatoryLeaveRequest').mockResolvedValue(cancelledCompensatoryLeaveRequest)
    renderDialog({ kind: 'compensatory', id: 'compensatory-leave-request-1', label: '代休' })

    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    await waitFor(() =>
      expect(compensatoryLeaveApi.cancelCompensatoryLeaveRequest).toHaveBeenCalledWith('compensatory-leave-request-1'),
    )
  })

  it('shows an error message when cancellation fails', async () => {
    vi.spyOn(paidLeaveApi, 'cancelPaidLeaveRequest').mockRejectedValue(new Error('月次勤怠が既に確定済みのため取り消せません。'))
    renderDialog({ kind: 'paid', id: 'paid-leave-request-1', label: '有給休暇' })

    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    expect(await screen.findByText('月次勤怠が既に確定済みのため取り消せません。')).toBeInTheDocument()
  })

  it('calls onOpenChange(false) when キャンセル is clicked', async () => {
    const { onOpenChange } = renderDialog({ kind: 'paid', id: 'paid-leave-request-1', label: '有給休暇' })

    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
