import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

/** 付与予定日が今日(2026-10-20)より後の行。日付前の警告の確認に使う。 */
const futureEntry: PaidLeaveScheduleEntry = {
  id: 'entry-5',
  user_id: 'user-5',
  user_name: '山田花子',
  scheduled_on: '2026-10-25',
  category: 'normal',
  candidate_grant_days: 5,
  status: 'Eligible',
  latest_assessment_id: 'assessment-5',
  is_manually_overridden: false,
  attendance_rate: 90,
}

const overdueEntry: PaidLeaveScheduleEntry = {
  ...eligibleEntry,
  id: 'entry-overdue',
  user_name: '高橋美咲',
  scheduled_on: '2026-09-20',
}

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

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{`${location.pathname}${location.search}`}</output>
}

function currentLocation() {
  return screen.getByTestId('location').textContent ?? ''
}

function renderPage({
  entries = [eligibleEntry, needsReviewEntry],
  overdue = [],
  initialPath = '/admin/paid-leave/schedule',
}: { entries?: PaidLeaveScheduleEntry[]; overdue?: PaidLeaveScheduleEntry[]; initialPath?: string } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntries').mockImplementation(async (params) =>
    params.filter === 'overdue' ? overdue : entries,
  )

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route
            path="/admin/paid-leave/schedule"
            element={
              <>
                <PaidLeaveSchedulePage />
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PaidLeaveSchedulePage', () => {
  beforeEach(() => {
    // 付与予定日の「今日より前/後」の判定を固定する(ローカル日付 2026-10-20)。
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-20T10:00:00'))
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('links back to the paid leave page', async () => {
    renderPage()
    expect(await screen.findByRole('link', { name: '← 有給休暇' })).toHaveAttribute('href', '/admin/paid-leave')
  })

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

  it('changes the URL and the API filter when the status select changes', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')

    await userEvent.selectOptions(screen.getByLabelText('状態'), 'needs_review')

    await waitFor(() => expect(currentLocation()).toContain('filter=needs_review'))
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'needs_review' }))
  })

  it('applies the user name filter on Enter and keeps it in the URL', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')

    await userEvent.type(screen.getByLabelText('社員名'), '田中{Enter}')

    await waitFor(() => expect(currentLocation()).toContain('user_name=%E7%94%B0%E4%B8%AD'))
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenLastCalledWith(expect.objectContaining({ user_name: '田中' }))
  })

  it('changes the URL and the API params when the scheduled date range is picked', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')

    await userEvent.click(screen.getByRole('button', { name: '付与予定日' }))
    await userEvent.click(await screen.findByText('15'))
    await userEvent.click(await screen.findByText('19'))

    await waitFor(() => expect(currentLocation()).toContain('scheduled_on_to=2026-10-19'))
    expect(currentLocation()).toContain('scheduled_on_from=2026-10-15')
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenLastCalledWith(
      expect.objectContaining({ scheduled_on_from: '2026-10-15', scheduled_on_to: '2026-10-19' }),
    )
  })

  it('reads the filters from the URL and passes them all to the API', async () => {
    renderPage({
      initialPath: '/admin/paid-leave/schedule?filter=eligible&user_name=%E9%88%B4%E6%9C%A8&scheduled_on_from=2026-10-01&scheduled_on_to=2026-10-31',
    })
    await screen.findByText('鈴木一郎')

    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenCalledWith({
      filter: 'eligible',
      user_name: '鈴木',
      scheduled_on_from: '2026-10-01',
      scheduled_on_to: '2026-10-31',
    })
    expect(screen.getByLabelText('社員名')).toHaveValue('鈴木')
  })

  it('shows the initial empty message when no condition is set', async () => {
    renderPage({ entries: [] })
    expect(await screen.findByText('付与予定はまだありません。')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '検索条件をクリア' })).not.toBeInTheDocument()
  })

  it('shows the filtered empty message with a clear button that removes the URL conditions', async () => {
    renderPage({ entries: [], initialPath: '/admin/paid-leave/schedule?filter=eligible&user_name=%E9%88%B4%E6%9C%A8' })

    expect(await screen.findByText('条件に一致する付与予定はありません。')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '検索条件をクリア' }))

    await waitFor(() => expect(currentLocation()).toBe('/admin/paid-leave/schedule'))
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'all', user_name: undefined }))
  })

  it('shows an alert for overdue entries and switches to the overdue filter from it', async () => {
    renderPage({ overdue: [overdueEntry] })

    const alert = await screen.findByRole('alert')
    expect(within(alert).getByText('付与予定日を過ぎて未付与の付与予定が1件あります。')).toBeInTheDocument()

    await userEvent.click(within(alert).getByRole('button', { name: '表示する' }))

    await waitFor(() => expect(currentLocation()).toContain('filter=overdue'))
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntries).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'overdue' }))
  })

  it('shows no overdue alert when there is no overdue entry', async () => {
    renderPage({ overdue: [] })
    await screen.findByText('鈴木一郎')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows the bulk grant bar only when eligible rows are selected, and submits the selected ids after confirmation', async () => {
    const bulkResult: PaidLeaveScheduleBulkGrantResponse = {
      success_count: 1,
      failure_count: 0,
      results: [{ schedule_entry_id: 'entry-1', success: true, message: null, grant_id: 'grant-1' }],
    }
    const bulkSpy = vi.spyOn(paidLeaveApi, 'bulkGrantPaidLeaveScheduleEntries').mockResolvedValue(bulkResult)

    renderPage()
    await screen.findByText('鈴木一郎')

    expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('付与予定を一括付与しますか?')).toBeInTheDocument()
    expect(within(dialog).getByText('1件(合計11日)を付与します。')).toBeInTheDocument()
    expect(within(dialog).getByText('付与予定日: 2026-10-01')).toBeInTheDocument()
    expect(within(dialog).getByText(/付与後は付与予定画面から取り消せません。/)).toBeInTheDocument()
    expect(bulkSpy).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: '一括付与する' }))

    await waitFor(() => expect(bulkSpy).toHaveBeenCalledWith(['entry-1']))
    expect(await screen.findByText('1件成功 / 0件失敗')).toBeInTheDocument()
  })

  it('does not call the bulk grant API when the confirmation is cancelled', async () => {
    const bulkSpy = vi.spyOn(paidLeaveApi, 'bulkGrantPaidLeaveScheduleEntries')

    renderPage()
    await screen.findByText('鈴木一郎')
    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'キャンセル' }))

    expect(bulkSpy).not.toHaveBeenCalled()
  })

  it('warns in the confirmation when a selected entry is scheduled after today', async () => {
    vi.spyOn(paidLeaveApi, 'bulkGrantPaidLeaveScheduleEntries')

    renderPage({ entries: [eligibleEntry, futureEntry] })
    await screen.findByText('山田花子')
    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '山田花子を選択' }))
    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('2件(合計16日)を付与します。')).toBeInTheDocument()
    expect(
      within(dialog).getByText('付与予定日前の付与予定が1件含まれます。出勤率の判定期間にまだ経過していない日が含まれています。'),
    ).toBeInTheDocument()
  })

  it('does not warn when every selected entry is on or before today', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')
    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByText(/付与予定日前の付与予定が/)).not.toBeInTheDocument()
  })

  it('clears the selection when the filter changes', async () => {
    renderPage()
    await screen.findByText('鈴木一郎')
    await userEvent.click(screen.getByRole('checkbox', { name: '鈴木一郎を選択' }))
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('状態'), 'eligible')

    await waitFor(() => expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument())
  })

  it('opens the detail sheet when a row is clicked', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))

    expect(await screen.findByText('勤怠データを確認')).toBeInTheDocument()
    expect(paidLeaveApi.fetchPaidLeaveScheduleEntry).toHaveBeenCalledWith('entry-1')
  })

  it('asks for confirmation before closing the detail sheet with unsaved override input', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))
    await userEvent.type(await screen.findByLabelText('上書き理由'), '記録漏れ')

    await userEvent.keyboard('{Escape}')

    expect(confirmSpy).toHaveBeenCalledWith('入力中の内容は破棄されます。閉じますか?')
    expect(screen.getByText('勤怠データを確認')).toBeInTheDocument()
    expect(currentLocation()).toContain('scheduleEntryId=entry-1')

    confirmSpy.mockReturnValue(true)
    await userEvent.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByText('勤怠データを確認')).not.toBeInTheDocument())
    expect(currentLocation()).not.toContain('scheduleEntryId')
  })

  it('closes the detail sheet without asking when nothing was entered', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)
    const confirmSpy = vi.spyOn(window, 'confirm')

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))
    await screen.findByText('勤怠データを確認')

    await userEvent.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByText('勤怠データを確認')).not.toBeInTheDocument())
    expect(confirmSpy).not.toHaveBeenCalled()
  })
})
