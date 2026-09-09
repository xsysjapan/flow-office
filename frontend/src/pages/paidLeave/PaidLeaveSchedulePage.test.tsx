import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as paidLeaveScheduleApi from '../../api/paidLeaveSchedule'
import type { ApplyScheduledGrantsResult, Paginated, PaidLeaveScheduleEntry } from '../../api/types'
import { PaidLeaveSchedulePage } from './PaidLeaveSchedulePage'

const eligibleEntry: PaidLeaveScheduleEntry = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '高橋 太郎',
  scheduled_on: '2026-10-01',
  category: 'regular',
  candidate_grant_days: 11,
  status: 'Eligible',
  needs_review_due_to_conflict: false,
  manual_override_reason: null,
  manual_override_by_user_id: null,
  manual_override_at: null,
  granted_paid_leave_grant_id: null,
  assessment: {
    period_start: '2025-10-01',
    period_end: '2026-09-30',
    denominator_days: 240,
    attendance_days: 221,
    excluded_days: 3,
    attendance_rate: 0.92,
    policy_version: 'v1',
    automatic_result: 'Eligible',
    final_result: 'Eligible',
    override_reason: null,
  },
}

const needsReviewEntry: PaidLeaveScheduleEntry = {
  ...eligibleEntry,
  id: 'entry-2',
  user_id: 'user-2',
  user_name: '加藤 由美',
  scheduled_on: '2026-10-12',
  status: 'NeedsReview',
  needs_review_due_to_conflict: true,
  assessment: { ...eligibleEntry.assessment, automatic_result: 'NeedsReview', final_result: 'NeedsReview', attendance_days: null, attendance_rate: null },
}

function paginated(entries: PaidLeaveScheduleEntry[]): Paginated<PaidLeaveScheduleEntry> {
  return { data: entries, meta: { current_page: 1, last_page: 1, total: entries.length }, links: { next: null, prev: null } }
}

function renderPage(initialPath = '/admin/paid-leave/schedule') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <QueryClientProvider client={queryClient}>
        <PaidLeaveSchedulePage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('PaidLeaveSchedulePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('一覧を表示し、フィルタタブでstatusクエリが切り替わる', async () => {
    const fetchSpy = vi.spyOn(paidLeaveScheduleApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue(paginated([eligibleEntry, needsReviewEntry]))

    renderPage()

    await waitFor(() => expect(screen.getByText('高橋 太郎')).toBeInTheDocument())
    expect(screen.getByText('加藤 由美')).toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('tab', { name: '要確認' }))

    await waitFor(() =>
      expect(fetchSpy).toHaveBeenCalledWith(expect.objectContaining({ status: 'needs_review' })),
    )
  })

  it('Eligible行のみ選択可能で、選択するとBulk Action Barが表示される', async () => {
    vi.spyOn(paidLeaveScheduleApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue(paginated([eligibleEntry, needsReviewEntry]))

    renderPage()
    await waitFor(() => expect(screen.getByText('高橋 太郎')).toBeInTheDocument())

    expect(screen.getByLabelText('加藤 由美を選択(付与対象のみ選択できます)')).toBeDisabled()

    const user = userEvent.setup()
    await user.click(screen.getByLabelText('高橋 太郎を選択'))

    expect(await screen.findByText('1件選択中')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '一括付与' })).toBeInTheDocument()
  })

  it('一括付与ボタンでapplyScheduledGrantsが選択したentry_idで呼ばれ、確認ダイアログを出さない', async () => {
    vi.spyOn(paidLeaveScheduleApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue(paginated([eligibleEntry]))
    const result: ApplyScheduledGrantsResult = { results: [{ entry_id: 'entry-1', status: 'granted' }], success_count: 1, failure_count: 0 }
    const applySpy = vi.spyOn(paidLeaveScheduleApi, 'applyScheduledGrants').mockResolvedValue(result)

    renderPage()
    await waitFor(() => expect(screen.getByText('高橋 太郎')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText('高橋 太郎を選択'))
    await user.click(screen.getByRole('button', { name: '一括付与' }))

    await waitFor(() => expect(applySpy).toHaveBeenCalledWith(['entry-1']))
    expect(screen.queryByRole('dialog', { name: /確認/ })).not.toBeInTheDocument()
  })

  it('行クリックで詳細Sheetが開き、Assessment内訳が表示される', async () => {
    vi.spyOn(paidLeaveScheduleApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue(paginated([needsReviewEntry]))

    renderPage()
    await waitFor(() => expect(screen.getByText('加藤 由美')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByText('加藤 由美'))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getAllByText('要確認').length).toBeGreaterThan(0)
    expect(within(dialog).getByText('2025-10-01 〜 2026-09-30')).toBeInTheDocument()
  })
})
