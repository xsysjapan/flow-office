import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as api from '../../api/readModelRebuilds'
import type { ReadModelRebuildRun } from '../../api/readModelRebuilds'
import { ReadModelRebuildPage } from './ReadModelRebuildPage'

afterEach(() => vi.restoreAllMocks())

function run(overrides: Partial<ReadModelRebuildRun> = {}): ReadModelRebuildRun {
  return {
    id: 'run-1', dry_run: true, status: 'succeeded', requested_by: { id: 'u1', name: '管理者' },
    requested_at: '2026-10-25T01:00:00+09:00', started_at: '2026-10-25T01:01:00+09:00', finished_at: '2026-10-25T01:05:00+09:00',
    error_message: null, summary: null, ...overrides,
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><ReadModelRebuildPage /></QueryClientProvider>)
}

it('describes the operation and lists the history', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [run(), run({ id: 'run-2', dry_run: false, status: 'failed', error_message: '時間の上限を超えたため中止しました。' })] })
  renderPage()

  expect(await screen.findByText('試し実行', { selector: 'td' })).toBeInTheDocument()
  expect(screen.getByText('本実行')).toBeInTheDocument()
  expect(screen.getByText('時間の上限を超えたため中止しました。')).toBeInTheDocument()
  expect(screen.getByText(/日次バッチの時刻/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: '試し実行' })).toBeEnabled()
})

it('requests a dry run after confirmation', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [] })
  const post = vi.spyOn(api, 'requestReadModelRebuild').mockResolvedValue({ data: run({ status: 'queued' }) })
  renderPage()
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: '試し実行' }))
  expect(post).not.toHaveBeenCalled()
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: '試し実行する' }))

  await waitFor(() => expect(post).toHaveBeenCalledWith(true))
})

it('requests a real rebuild with dry_run=false after confirmation', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [] })
  const post = vi.spyOn(api, 'requestReadModelRebuild').mockResolvedValue({ data: run({ dry_run: false, status: 'queued' }) })
  renderPage()
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: '作り直す' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: '作り直す' }))

  await waitFor(() => expect(post).toHaveBeenCalledWith(false))
})

it('shows the 409 error in the dialog', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [] })
  vi.spyOn(api, 'requestReadModelRebuild').mockRejectedValue(new ApiError(409, '実行中の作り直しがあります。'))
  renderPage()
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: '作り直す' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: '作り直す' }))

  expect(await screen.findByText('実行中の作り直しがあります。')).toBeInTheDocument()
})

it('disables both buttons while a run is queued or running', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [run({ status: 'running', finished_at: null })] })
  renderPage()

  expect(await screen.findByText(/新しく依頼できません/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: '試し実行' })).toBeDisabled()
  expect(screen.getByRole('button', { name: '作り直す' })).toBeDisabled()
})

it('shows the summary with changed tables first and warns about account tables', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [run()] })
  const detail = vi.spyOn(api, 'fetchReadModelRebuild').mockResolvedValue({ data: run({ summary: {
    dry_run: true, changed_tables: 2,
    tables: [
      { table: 'attendance_days', before: 10, after: 10, changed: false },
      { table: 'paid_leave_grants', before: 5, after: 5, changed: true },
      { table: 'permission_role', before: 20, after: 18, changed: true },
    ],
  } }) })
  renderPage()
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: '結果を見る' }))

  expect(await screen.findByText('利用者・権限のデータが変わります。本実行の前に内容を確認してください。')).toBeInTheDocument()
  expect(detail).toHaveBeenCalledWith('run-1')
  const rows = screen.getAllByRole('row').map((row) => row.textContent ?? '')
  const names = ['paid_leave_grants', 'permission_role', 'attendance_days']
  const indexes = names.map((name) => rows.findIndex((text) => text.startsWith(name)))
  expect(indexes.every((index) => index >= 0)).toBe(true)
  expect(indexes[2]).toBeGreaterThan(indexes[0])
  expect(indexes[2]).toBeGreaterThan(indexes[1])
})

it('does not warn when no account table changed', async () => {
  vi.spyOn(api, 'fetchReadModelRebuilds').mockResolvedValue({ data: [run()] })
  vi.spyOn(api, 'fetchReadModelRebuild').mockResolvedValue({ data: run({ summary: {
    dry_run: true, changed_tables: 1,
    tables: [{ table: 'paid_leave_grants', before: 5, after: 5, changed: true }],
  } }) })
  renderPage()
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: '結果を見る' }))

  expect(await screen.findByText('paid_leave_grants')).toBeInTheDocument()
  expect(screen.queryByText(/利用者・権限のデータが変わります/)).not.toBeInTheDocument()
})
