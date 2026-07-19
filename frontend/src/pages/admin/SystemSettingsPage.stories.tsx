import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { SystemSettings } from '../../api/types'
import { SystemSettingsPage } from './SystemSettingsPage'

const settings: SystemSettings = {
  default_timezone: 'Asia/Tokyo',
  default_work_style_id: 1,
  attendance_submission_deadline_day: 5,
  attendance_month_close_deadline_day: 10,
  notification_mail_enabled: false,
  notification_mail_tenant_id: null,
  notification_mail_client_id: null,
  notification_mail_client_secret_configured: false,
  notification_mail_sender_address: null,
  notification_mail_sender_name: null,
}

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
  title: 'Pages/Admin/SystemSettingsPage',
  component: SystemSettingsPage,
} satisfies Meta<typeof SystemSettingsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
