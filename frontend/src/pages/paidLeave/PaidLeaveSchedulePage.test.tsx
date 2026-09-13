import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../../api/paidLeave'
import type { PaidLeaveScheduleBulkGrantResponse, PaidLeaveScheduleEntry, PaidLeaveScheduleEntryDetail } from '../../api/types'
import { PaidLeaveSchedulePage } from './PaidLeaveSchedulePage'

const eligibleEntry: PaidLeaveScheduleEntry = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '鈴木一郎',
  scheduled_on: '2026-10-01',
  category: 'normal',
  candidate_grant_days: 11,
  status: 'Eligible',
  latest_assessment_id: 'assessment-1',
  is_manually_overridden: false,
  attendance_rate: 92,
}

const needsReviewEntry: PaidLeaveScheduleEntry = {
  id: 'entry-3',
  user_id: 'user-3',
  user_name: '田中次郎',
  scheduled_on: '2026-10-10',
  category: 'needs_review',
  candidate_grant_days: null,
  status: 'NeedsReview',
  latest_assessment_id: null,
  is_manually_overridden: false,
  attendance_rate: null,
}

function renderPage(entries: PaidLeaveScheduleEntry[] = [eligibleEntry, needsReviewEntry]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue(entries)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/paid-leave/schedule']}>
        <PaidLeaveSchedulePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PaidLeaveSchedulePage', () => {
  it('lists schedule entries with category/status/attendance rate columns', async () => {
    renderPage()

    expect(await screen.findByText('鈴木一郎')).toBeInTheDocument()
    expect(screen.getByText('通常')).toBeInTheDocument()
    expect(screen.getByText('92%')).toBeInTheDocument()
    expect(screen.getByRole('status', { name: '付与対象' })).toBeInTheDocument()
  })

  it('shows a WorkStyle settings link only for NeedsReview rows, and no checkbox for them', async () => {
    renderPage()

    await screen.findByText('田中次郎')
    expect(screen.getByRole('link', { name: '勤務形態を確認' })).toHaveAttribute('href', '/admin/work-styles')
    expect(screen.getByRole('checkbox', { name: '鈴木一郎を選択' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: '田中次郎を選択' })).not.toBeInTheDocument()
  })

  it('refetches with the selected filter when a filter tab is clicked', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')

    await userEvent.click(screen.getByRole('button', { name: '要確認' }))

    await waitFor(() => expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenCalledWith('needs_review'))
  })

  it('shows the bulk grant bar only when eligible rows are selected, and submits the selected ids', async () => {
    const detail: PaidLeaveScheduleEntryDetail = {
      ...eligibleEntry,
      manual_override_reason: null,
      cancelled_reason: null,
      grant_id: null,
      assessments: [],
    }
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)
    const bulkResult: PaidLeaveScheduleBulkGrantResponse = {
      success_count: 1,
      failure_count: 0,
      results: [{ schedule_entry_id: 'entry-1', success: true, message: null, grant_id: 'grant-1' }],
    }
    vi.spyOn(paidLeaveApi, 'bulkGrantPaidLeaveScheduleEntries').mockResolvedValue(bulkResult)

    renderPage()
    await screen.findByText('鈴木一郎')

    expect(screen.queryByRole('button', { name: '一括付与する' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    await userEvent.click(await screen.findByRole('button', { name: '一括付与する' }))

    await waitFor(() => expect(paidLeaveApi.bulkGrantPaidLeaveScheduleEntries).toHaveBeenCalledWith(['entry-1']))
    expect(await screen.findByText('1件成功 / 0件失敗')).toBeInTheDocument()
  })

  it('opens the detail sheet when a row is clicked', async () => {
    const detail: PaidLeaveScheduleEntryDetail = {
      ...eligibleEntry,
      manual_override_reason: null,
      cancelled_reason: null,
      grant_id: null,
      assessments: [
        {
          id: 'assessment-1',
          period_start: '2025-10-01',
          period_end: '2026-10-01',
          denominator_days: 240,
          attendance_days: 220,
          excluded_days: 0,
          attendance_rate: 92,
          policy_version: 'v1',
          automatic_result: 'Eligible',
          final_result: 'Eligible',
          override_reason: null,
          overridden_by_user_id: null,
          created_at: '2026-09-01T00:00:00Z',
        },
      ],
    }
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))

    expect(await screen.findByText('勤怠データを確認')).toBeInTheDocument()
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntry).toHaveBeenCalledWith('entry-1')
  })
})
