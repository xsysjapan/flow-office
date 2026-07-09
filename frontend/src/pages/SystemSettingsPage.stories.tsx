import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { SystemSettings } from '../api/types'
import { SystemSettingsPage } from './SystemSettingsPage'

const settings: SystemSettings = { default_timezone: 'Asia/Tokyo' }

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['system-settings'], settings)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <SystemSettingsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/SystemSettingsPage',
  component: SystemSettingsPage,
} satisfies Meta<typeof SystemSettingsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
