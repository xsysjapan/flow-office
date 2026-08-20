import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
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
  render(<QueryClientProvider client={client}><AdminCommandsPage /></QueryClientProvider>)
  expect(await screen.findByText('提出済みの月次勤怠を再計算する')).toBeInTheDocument()
  expect(screen.getByText('対象年月')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: '実行' })).toBeInTheDocument()
})
