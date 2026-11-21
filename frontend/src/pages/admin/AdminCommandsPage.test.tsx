import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import * as api from '../../api/adminCommands'
import { AdminCommandsPage } from './AdminCommandsPage'

it('公開されたコマンドのArtisanメタデータをフォームとして表示する', async () => {
  vi.spyOn(api, 'fetchAdminCommands').mockResolvedValue({ data: [{
    name: 'attendance:recalculate-month-snapshots', label: '月次勤怠スナップショット再計算',
    description: '提出済みの月次勤怠を再計算する', without_overlapping: true,
    parameters: [{ name: 'year-month', kind: 'option', required: false, array: false, accepts_value: true, value_required: false, default: null, description: '対象年月', ui: { control: 'year-month' } }],
  }] })
  vi.spyOn(api, 'fetchAdminCommandRuns').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 }, links: { next: null, prev: null } })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={client}><MemoryRouter><AdminCommandsPage /></MemoryRouter></QueryClientProvider>)
  expect(await screen.findByText('提出済みの月次勤怠を再計算する')).toBeInTheDocument()
  expect(screen.getByText('対象年月')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: '実行' })).toBeInTheDocument()
})

it('shows the requester of a system run (requested_by_user_id is null) as システム', async () => {
  vi.spyOn(api, 'fetchAdminCommands').mockResolvedValue({ data: [] })
  vi.spyOn(api, 'fetchAdminCommandRuns').mockResolvedValue({ data: [{
    id: 'run-1', command_name: 'paid-leave:assess-schedules', parameters: {}, status: 'succeeded',
    requested_by_user_id: null, requested_by_user: null, started_at: null, finished_at: null,
    exit_code: 0, output: null, error_message: null, created_at: '2026-10-01T03:00:00+09:00',
  }], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 }, links: { next: null, prev: null } })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={client}><MemoryRouter><AdminCommandsPage /></MemoryRouter></QueryClientProvider>)

  expect(await screen.findByText('paid-leave:assess-schedules')).toBeInTheDocument()
  expect(screen.getByText(/^依頼: システム \/ /)).toBeInTheDocument()
})

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function mockTwoCommands() {
  const base = { without_overlapping: true, parameters: [] }
  vi.spyOn(api, 'fetchAdminCommands').mockResolvedValue({ data: [
    { ...base, name: 'cmd:first', label: '最初のコマンド', description: '最初の説明' },
    { ...base, name: 'cmd:second', label: '2番目のコマンド', description: '2番目の説明' },
  ] })
  vi.spyOn(api, 'fetchAdminCommandRuns').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 }, links: { next: null, prev: null } })
}

function renderAt(entry: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[entry]}><AdminCommandsPage /><LocationProbe /></MemoryRouter></QueryClientProvider>)
}

it('shows the first command without writing it to the URL when command is absent', async () => {
  mockTwoCommands()
  renderAt('/admin/commands')

  expect(await screen.findByText('最初の説明')).toBeInTheDocument()
  expect(screen.getByTestId('location-search')).toBeEmptyDOMElement()
})

it('restores the selected command from ?command', async () => {
  mockTwoCommands()
  renderAt('/admin/commands?command=cmd:second')

  expect(await screen.findByText('2番目の説明')).toBeInTheDocument()
  expect(screen.getByTestId('location-search')).toHaveTextContent('?command=cmd:second')
})

it('falls back to the first command and removes an unknown ?command', async () => {
  mockTwoCommands()
  renderAt('/admin/commands?command=nope')

  expect(await screen.findByText('最初の説明')).toBeInTheDocument()
  await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
})

it('writes the selected command to the URL', async () => {
  mockTwoCommands()
  renderAt('/admin/commands')
  await screen.findByText('最初の説明')

  await userEvent.selectOptions(screen.getByLabelText('コマンド'), 'cmd:second')

  expect(await screen.findByText('2番目の説明')).toBeInTheDocument()
  expect(screen.getByTestId('location-search')).toHaveTextContent('?command=cmd%3Asecond')
})
