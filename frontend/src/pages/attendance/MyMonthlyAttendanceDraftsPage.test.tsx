import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as monthlyAttendanceDraftsApi from '../../api/monthlyAttendanceDrafts'
import type { MonthlyAttendanceDraft } from '../../api/types'
import { MyMonthlyAttendanceDraftsPage } from './MyMonthlyAttendanceDraftsPage'

const drafts: MonthlyAttendanceDraft[] = [
  {
    id: 2,
    user_id: 42,
    target_month: '2026-07',
    status: 'needs_review',
    version: 3,
    source_type: 'work_report',
    source_reference: null,
    submitted_at: null,
    created_at: '2026-07-15T09:00:00+09:00',
  },
  {
    id: 1,
    user_id: 42,
    target_month: '2026-06',
    status: 'submitted',
    version: 5,
    source_type: 'work_report',
    source_reference: null,
    submitted_at: '2026-06-30T09:00:00+09:00',
    created_at: '2026-06-15T09:00:00+09:00',
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(monthlyAttendanceDraftsApi, 'fetchMyMonthlyAttendanceDrafts').mockResolvedValue(drafts)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <MyMonthlyAttendanceDraftsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MyMonthlyAttendanceDraftsPage', () => {
  it('lists drafts with their status', async () => {
    renderPage()

    expect(await screen.findByText('2026-07')).toBeInTheDocument()
    expect(screen.getByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('要確認')).toBeInTheDocument()
    expect(screen.getByText('提出済み')).toBeInTheDocument()
  })

  it('links each draft to its review page', async () => {
    renderPage()

    expect(await screen.findByRole('link', { name: '2026-07' })).toHaveAttribute(
      'href',
      '/attendance/monthly-drafts/2',
    )
  })
})
