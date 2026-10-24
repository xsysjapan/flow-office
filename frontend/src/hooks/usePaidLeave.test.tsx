import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../api/paidLeave'
import type { PaidLeaveScheduleAssessmentRunStatus } from '../api/types'
import { usePaidLeaveScheduleAssessmentRunStatus, usePaidLeaveScheduleEntries } from './usePaidLeave'

const runningStatus: PaidLeaveScheduleAssessmentRunStatus = {
  next_run_at: '2026-11-01T03:00:00',
  latest_run: {
    id: 'run-2',
    status: 'running',
    trigger: 'manual',
    requested_by_name: '山田花子',
    started_at: '2026-10-20T14:00:00',
    finished_at: null,
    summary: null,
    error_message: null,
  },
}

const succeededStatus: PaidLeaveScheduleAssessmentRunStatus = {
  ...runningStatus,
  latest_run: {
    ...runningStatus.latest_run!,
    status: 'succeeded',
    finished_at: '2026-10-20T14:02:00',
    summary: { targets: 1, assessed: 1, eligible: 1, not_eligible: 0, needs_review: 0, failed: 0 },
  },
}

function renderStatusHook() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
  return renderHook(
    () => ({
      status: usePaidLeaveScheduleAssessmentRunStatus(),
      // 一覧のキャッシュが無効化されて取り直されることを見るため、一覧も購読しておく。
      entries: usePaidLeaveScheduleEntries({ filter: 'all' }),
    }),
    { wrapper },
  )
}

describe('usePaidLeaveScheduleAssessmentRunStatus', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleEntries').mockResolvedValue([])
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('polls the status every 5 seconds while a run is in progress', async () => {
    const statusSpy = vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleAssessmentRunStatus').mockResolvedValue(runningStatus)
    renderStatusHook()

    await vi.advanceTimersByTimeAsync(0)
    expect(statusSpy).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(5000)
    expect(statusSpy).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(5000)
    expect(statusSpy).toHaveBeenCalledTimes(3)
  })

  it('does not poll when no run is in progress', async () => {
    const statusSpy = vi.spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleAssessmentRunStatus').mockResolvedValue(succeededStatus)
    renderStatusHook()

    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(20000)

    expect(statusSpy).toHaveBeenCalledTimes(1)
  })

  it('refetches the schedule entries when an in-progress run has finished', async () => {
    const statusSpy = vi
      .spyOn(paidLeaveApi, 'fetchPaidLeaveScheduleAssessmentRunStatus')
      .mockResolvedValueOnce(runningStatus)
      .mockResolvedValue(succeededStatus)
    const entriesSpy = vi.mocked(paidLeaveApi.fetchPaidLeaveScheduleEntries)
    renderStatusHook()

    await vi.advanceTimersByTimeAsync(0)
    const entriesBefore = entriesSpy.mock.calls.length

    // 5秒後の取り直しで完了が返り、一覧(付与予定のキャッシュ)が取り直される。
    await vi.advanceTimersByTimeAsync(5000)
    // 取り直しの結果の反映と、無効化による一覧の再取得を待つ(React Queryの通知は次のタイマーで届く)。
    await vi.advanceTimersByTimeAsync(10)

    expect(statusSpy).toHaveBeenCalledTimes(2)
    expect(entriesSpy.mock.calls.length).toBeGreaterThan(entriesBefore)
  })
})
