import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReadModelRebuildRun } from '../../api/readModelRebuilds'
import { ReadModelRebuildPage } from './ReadModelRebuildPage'

const base: ReadModelRebuildRun = {
  id: 'run-1', dry_run: true, status: 'succeeded', requested_by: { id: 'u1', name: '管理者' },
  requested_at: '2026-10-25T01:00:00+09:00', started_at: '2026-10-25T01:01:00+09:00', finished_at: '2026-10-25T01:05:00+09:00',
  error_message: null, summary: null,
}

function clientWith(runs: ReadModelRebuildRun[]) {
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity } } })
  client.setQueryData(['read-model-rebuilds', 'list'], { data: runs })
  return client
}

const meta = { component: ReadModelRebuildPage } satisfies Meta<typeof ReadModelRebuildPage>
export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  decorators: [(Story) => <QueryClientProvider client={clientWith([base, { ...base, id: 'run-2', dry_run: false, status: 'failed', error_message: '時間の上限を超えたため中止しました。' }])}><Story /></QueryClientProvider>],
}

/** 実行中は両方のボタンが押せない。 */
export const Running: Story = {
  decorators: [(Story) => <QueryClientProvider client={clientWith([{ ...base, dry_run: false, status: 'running', finished_at: null }])}><Story /></QueryClientProvider>],
}

export const Empty: Story = {
  decorators: [(Story) => <QueryClientProvider client={clientWith([])}><Story /></QueryClientProvider>],
}
