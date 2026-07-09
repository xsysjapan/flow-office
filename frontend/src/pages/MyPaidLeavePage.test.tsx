import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../api/paidLeave'
import type { PaidLeaveGrant } from '../api/types'
import { MyPaidLeavePage } from './MyPaidLeavePage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MyPaidLeavePage />
    </QueryClientProvider>,
  )
}

describe('MyPaidLeavePage', () => {
  it('shows an empty state when there are no grants', async () => {
    vi.spyOn(paidLeaveApi, 'fetchMyPaidLeaveGrants').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('有給の付与はまだありません。')).toBeInTheDocument()
  })

  it('shows the total remaining days and each grant', async () => {
    const grants: PaidLeaveGrant[] = [
      {
        id: 1,
        user_id: 1,
        granted_on: '2025-04-01',
        expires_on: '2027-03-31',
        granted_days: 10,
        used_days: 3,
        remaining_days: 7,
        grant_reason: '法定付与',
      },
      {
        id: 2,
        user_id: 1,
        granted_on: '2026-04-01',
        expires_on: '2028-03-31',
        granted_days: 11,
        used_days: 0,
        remaining_days: 11,
        grant_reason: null,
      },
    ]
    vi.spyOn(paidLeaveApi, 'fetchMyPaidLeaveGrants').mockResolvedValue(grants)

    renderPage()

    expect(await screen.findByText('18')).toBeInTheDocument()
    expect(screen.getByText('2025-04-01')).toBeInTheDocument()
    expect(screen.getByText('法定付与')).toBeInTheDocument()
    expect(screen.getByText('2026-04-01')).toBeInTheDocument()
  })
})
