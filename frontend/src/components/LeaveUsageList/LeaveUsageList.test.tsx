import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { LeaveUsageList, type LeaveUsageRow } from './LeaveUsageList'

const baseUsage: LeaveUsageRow = {
  id: 'usage-1',
  usedOn: '2026-08-10',
  usedDays: 1,
  usedMinutes: null,
  usageType: 'full',
  requestStatus: 'approved',
  requestId: 'request-1',
}

describe('LeaveUsageList', () => {
  it('shows an empty state when there are no usages', () => {
    render(<LeaveUsageList usages={[]} isLoading={false} onCancelRequest={vi.fn()} />)
    expect(screen.getByText('使用状況はまだありません。')).toBeInTheDocument()
  })

  it('renders one cancel button per distinct request and calls onCancelRequest on confirm', async () => {
    const onCancelRequest = vi.fn().mockResolvedValue(undefined)
    render(
      <LeaveUsageList
        usages={[
          baseUsage,
          { ...baseUsage, id: 'usage-2', usedOn: '2026-08-05', usedDays: 0, requestId: 'request-1' },
        ]}
        isLoading={false}
        onCancelRequest={onCancelRequest}
      />,
    )

    const cancelButtons = screen.getAllByRole('button', { name: '取消' })
    expect(cancelButtons).toHaveLength(1)

    await userEvent.click(cancelButtons[0])
    expect(await screen.findByText(/2件のうち、2件/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '取消する' }))
    expect(onCancelRequest).toHaveBeenCalledWith('request-1')
  })

  it('disables the cancel action with a reason when the request is not approved', () => {
    render(
      <LeaveUsageList
        usages={[{ ...baseUsage, requestStatus: 'cancelled' }]}
        isLoading={false}
        onCancelRequest={vi.fn()}
      />,
    )

    const button = screen.getByRole('button', { name: '取消' })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('title', '承認済みの申請のみ取り消せます。')
  })
})
