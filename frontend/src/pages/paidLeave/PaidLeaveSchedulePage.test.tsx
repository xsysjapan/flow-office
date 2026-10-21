import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as paidLeaveApi from '../../api/paidLeave'
import type {
  PaidLeaveScheduleAssessmentRunStatus,
  PaidLeaveScheduleBulkGrantResponse,
  PaidLeaveScheduleEntry,
  PaidLeaveScheduleEntryDetail,
} from '../../api/types'
import { PaidLeaveSchedulePage } from './PaidLeaveSchedulePage'

const eligibleEntry: PaidLeaveScheduleEntry = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '鈴木一郎',
  scheduled_on: '2026-10-01',
  category: 'normal',
  candidate_grant_days: 11,
  nominal_service_months: null,
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
  nominal_service_months: null,
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
  nominal_service_months: null,
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
      deemed_attendance_days: 0,
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

/** 既定の自動判定の状態: 前回は完了、次回は11月1日 3:00。 */
const defaultAssessmentRunStatus: PaidLeaveScheduleAssessmentRunStatus = {
  next_run_at: '2026-11-01T03:00:00',
  latest_run: {
    id: 'run-1',
    status: 'succeeded',
    trigger: 'schedule',
    requested_by_name: null,
    started_at: '2026-10-01T03:00:00',
    finished_at: '2026-10-01T03:02:00',
    summary: { targets: 12, assessed: 12, eligible: 10, not_eligible: 1, needs_review: 1, failed: 0 },
    error_message: null,
  },
}

/** 同じ社員・同じ付与予定日で、名目6か月と18か月の組(どちらも付与対象)。 */
const pairFirst: PaidLeaveScheduleEntry = {
  ...eligibleEntry,
  id: 'pair-6',
  user_id: 'user-pair',
  user_name: '佐藤三郎',
  scheduled_on: '2026-04-01',
  candidate_grant_days: 10,
  nominal_service_months: 6,
}
const pairSecond: PaidLeaveScheduleEntry = { ...pairFirst, id: 'pair-18', candidate_grant_days: 11, nominal_service_months: 18 }

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
  assessmentRunStatus = defaultAssessmentRunStatus,
}: {
  entries?: PaidLeaveScheduleEntry[]
  overdue?: PaidLeaveScheduleEntry[]
  initialPath?: string
  assessmentRunStatus?: PaidLeaveScheduleAssessmentRunStatus
} = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntries').mockImplementation(async (params) =>
    params.filter === 'overdue' ? overdue : entries,
  )
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleAssessmentRunStatus').mockResolvedValue(assessmentRunStatus)

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

  it('shows the nominal service months of same-day rows in the order the API returns them', async () => {
    const sameDay6 = { ...eligibleEntry, id: 'e6', user_name: '佐藤三郎', scheduled_on: '2026-04-01', candidate_grant_days: 10, nominal_service_months: 6 }
    const sameDay18 = { ...sameDay6, id: 'e18', candidate_grant_days: 11, nominal_service_months: 18 }
    renderPage({ entries: [sameDay6, sameDay18, needsReviewEntry] })

    const first = await screen.findByText('継続6か月分')
    const second = screen.getByText('継続18か月分')
    expect(first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    // 名目の月数が無い行には表示しない。
    expect(screen.getAllByText(/継続\d+か月分/)).toHaveLength(2)
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

  it('edits the category and days from the detail panel, sends a PATCH, and reloads the list', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)
    const editSpy = vi.spyOn(paidLeaveApi, 'editPaidLeaveScheduleEntry').mockResolvedValue({ ...eligibleEntry, candidate_grant_days: 12, is_manually_overridden: true })

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))
    await screen.findByText('勤怠データを確認')

    const countAllFetches = () =>
      vi.mocked(paidLeaveApi.fetchPaidLeaveScheduleEntries).mock.calls.filter(([params]) => params.filter === 'all').length
    const fetchesBefore = countAllFetches()

    await userEvent.clear(screen.getByLabelText('付与候補日数'))
    await userEvent.type(screen.getByLabelText('付与候補日数'), '12')
    await userEvent.type(screen.getByLabelText('修正理由'), '勤務実績に合わせて日数を補正')
    await userEvent.click(screen.getByRole('button', { name: '区分・日数を修正する' }))

    await waitFor(() =>
      expect(editSpy).toHaveBeenCalledWith('entry-1', { candidate_grant_days: 12, reason: '勤務実績に合わせて日数を補正' }),
    )
    await waitFor(() => expect(countAllFetches()).toBeGreaterThan(fetchesBefore))
  })

  it('asks for confirmation before closing the detail sheet with unsaved edit input', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntry').mockResolvedValue(detail)
    const editSpy = vi.spyOn(paidLeaveApi, 'editPaidLeaveScheduleEntry')
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderPage()
    await userEvent.click(await screen.findByText('鈴木一郎'))
    await userEvent.type(await screen.findByLabelText('修正理由'), '記録漏れ')

    await userEvent.keyboard('{Escape}')

    expect(confirmSpy).toHaveBeenCalledWith('入力中の内容は破棄されます。閉じますか?')
    expect(screen.getByText('勤怠データを確認')).toBeInTheDocument()
    expect(currentLocation()).toContain('scheduleEntryId=entry-1')
    expect(editSpy).not.toHaveBeenCalled()
  })

  it('shows the automatic assessment card with the next run and the previous result above the list', async () => {
    renderPage()

    const card = await screen.findByRole('region', { name: '自動判定' })
    expect(within(card).getByText('次回の自動判定: 11月1日 3:00')).toBeInTheDocument()
    expect(within(card).getByText('前回: 10月1日 3:02 完了(対象12件 / 付与対象10件 / 対象外1件 / 要確認1件 / 失敗0件)')).toBeInTheDocument()
  })

  it('starts an assessment run after the confirmation and fetches the run status again', async () => {
    const startSpy = vi.spyOn(paidLeaveApi, 'startPaidLeaveScheduleAssessmentRun').mockResolvedValue(undefined)

    renderPage()
    const card = await screen.findByRole('region', { name: '自動判定' })
    const statusFetches = () => vi.mocked(paidLeaveApi.fetchPaidLeaveScheduleAssessmentRunStatus).mock.calls.length
    const before = statusFetches()

    await userEvent.click(within(card).getByRole('button', { name: '今すぐ判定する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('付与予定日を過ぎた『予定』の行を、今すぐ判定します。')).toBeInTheDocument()
    expect(startSpy).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: '判定する' }))

    await waitFor(() => expect(startSpy).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(statusFetches()).toBeGreaterThan(before))
  })

  it('shows the 409 error inside the confirmation dialog when a run is already in progress', async () => {
    vi.spyOn(paidLeaveApi, 'startPaidLeaveScheduleAssessmentRun').mockRejectedValue(new ApiError(409, '判定は既に実行中です。'))

    renderPage()
    const card = await screen.findByRole('region', { name: '自動判定' })
    await userEvent.click(within(card).getByRole('button', { name: '今すぐ判定する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: '判定する' }))

    expect(await within(dialog).findByText('判定は既に実行中です。')).toBeInTheDocument()
  })

  it('disables the run button and shows that the run is in progress', async () => {
    renderPage({
      assessmentRunStatus: {
        next_run_at: '2026-11-01T03:00:00',
        latest_run: { ...defaultAssessmentRunStatus.latest_run!, status: 'running', finished_at: null, summary: null },
      },
    })

    const card = await screen.findByRole('region', { name: '自動判定' })
    expect(within(card).getByText('判定を実行中です')).toBeInTheDocument()
    expect(within(card).getByRole('button', { name: '今すぐ判定する' })).toBeDisabled()
  })

  it('selects both rows of a pair when one is selected, and clears both when one is cleared', async () => {
    renderPage({ entries: [pairFirst, pairSecond] })
    await screen.findAllByText('継続6か月分')

    const checkboxes = screen.getAllByRole('checkbox', { name: '佐藤三郎を選択' })
    expect(checkboxes).toHaveLength(2)

    await userEvent.click(checkboxes[0])
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    expect(screen.getAllByRole('checkbox', { name: '佐藤三郎を選択' }).every((box) => box.getAttribute('data-state') === 'checked')).toBe(true)

    await userEvent.click(screen.getAllByRole('checkbox', { name: '佐藤三郎を選択' })[1])
    expect(screen.queryByText(/件を選択中/)).not.toBeInTheDocument()
  })

  it('counts a selected pair as two entries in the bulk grant confirmation and submits both ids', async () => {
    const bulkSpy = vi.spyOn(paidLeaveApi, 'bulkGrantPaidLeaveScheduleEntries').mockResolvedValue({
      success_count: 2,
      failure_count: 0,
      results: [
        { schedule_entry_id: 'pair-6', success: true, message: null, grant_id: 'g1' },
        { schedule_entry_id: 'pair-18', success: true, message: null, grant_id: 'g2' },
      ],
    } satisfies PaidLeaveScheduleBulkGrantResponse)

    renderPage({ entries: [pairSecond, pairFirst] })
    await screen.findAllByText('継続6か月分')
    await userEvent.click(screen.getAllByRole('checkbox', { name: '佐藤三郎を選択' })[0])
    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('2件(合計21日)を付与します。')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: '一括付与する' }))

    await waitFor(() => expect(bulkSpy).toHaveBeenCalledWith(expect.arrayContaining(['pair-6', 'pair-18'])))
    expect(bulkSpy.mock.calls[0][0]).toHaveLength(2)
  })
})
