import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { MaintenanceGate } from './MaintenanceGate'

const client = new QueryClient({ defaultOptions: { queries: { enabled: false } } })

const meta = {
  title: 'Components/MaintenanceGate',
  component: MaintenanceGate,
  tags: ['autodocs'],
  decorators: [(Story) => <QueryClientProvider client={client}><MemoryRouter><Story /></MemoryRouter></QueryClientProvider>],
} satisfies Meta<typeof MaintenanceGate>

export default meta
type Story = StoryObj<typeof meta>

/** メンテナンスのエラーを受けるまでは子をそのまま表示する。 */
export const Default: Story = {
  args: { children: <p>通常の画面</p> },
}
