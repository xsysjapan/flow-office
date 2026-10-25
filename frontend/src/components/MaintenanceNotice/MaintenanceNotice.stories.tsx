import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MaintenanceNotice } from './MaintenanceNotice'

const client = new QueryClient({ defaultOptions: { queries: { enabled: false } } })

const meta = {
  title: 'Components/MaintenanceNotice',
  component: MaintenanceNotice,
  tags: ['autodocs'],
  decorators: [(Story) => <QueryClientProvider client={client}><Story /></QueryClientProvider>],
} satisfies Meta<typeof MaintenanceNotice>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { message: 'システムのメンテナンス(データの作り直し)中です。しばらくしてから再度お試しください。' },
}
