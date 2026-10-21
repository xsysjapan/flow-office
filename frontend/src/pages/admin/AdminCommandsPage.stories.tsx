import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AdminCommandsPage } from './AdminCommandsPage'

const client = new QueryClient()
client.setQueryData(['admin-commands'], { data: [{
  name: 'attendance:recalculate-month-snapshots', label: '月次勤怠スナップショット再計算',
  description: '提出済みの月次勤怠を現在の集計ロジックで再計算する', without_overlapping: true,
  parameters: [{ name: 'year-month', kind: 'option', required: false, array: false, accepts_value: true, value_required: false, default: null, description: '対象年月', ui: { control: 'year-month' } }, { name: 'dry-run', kind: 'option', required: false, array: false, accepts_value: false, value_required: false, default: false, description: '対象件数のみ確認する', ui: { control: 'checkbox' } }],
}] })
client.setQueryData(['admin-command-runs'], { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

const meta = { component: AdminCommandsPage, decorators: [(Story) => <QueryClientProvider client={client}><Story /></QueryClientProvider>] } satisfies Meta<typeof AdminCommandsPage>
export default meta
export const Default: StoryObj<typeof meta> = {}

/** 定期の実行(依頼者なし)の履歴は依頼者を「システム」と表示する。 */
const systemRunClient = new QueryClient()
systemRunClient.setQueryData(['admin-commands'], { data: [] })
systemRunClient.setQueryData(['admin-command-runs'], { data: [{
  id: 'run-1', command_name: 'paid-leave:assess-schedules', parameters: {}, status: 'succeeded',
  requested_by_user_id: null, requested_by_user: null, started_at: null, finished_at: null,
  exit_code: 0, output: null, error_message: null, created_at: '2026-10-01T03:00:00+09:00',
}], meta: { current_page: 1, last_page: 1, total: 1 }, links: { next: null, prev: null } })

export const SystemRun: StoryObj<typeof meta> = {
  decorators: [(Story) => <QueryClientProvider client={systemRunClient}><Story /></QueryClientProvider>],
}
