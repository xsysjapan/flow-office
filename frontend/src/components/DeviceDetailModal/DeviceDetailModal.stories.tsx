import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Device } from '../../api/types'
import { DeviceDetailModal } from './DeviceDetailModal'

const baseDevice: Device = {
  id: 1,
  owner_type: 'organization_shared',
  owner_user_id: null,
  name: '本社1階受付',
  device_type: 'android',
  status: 'active',
  site_id: null,
  location_name: '本社1階受付カウンター',
  default_work_location_type: 'office',
  timezone: 'Asia/Tokyo',
  allowed_punch_types: null,
  allow_offline: true,
  require_location: false,
  auto_detect_punch_type: false,
  app_version: '1.2.0',
  last_seen_at: '2026-07-18T09:00:00+09:00',
  paired_at: '2026-07-01T09:00:00+09:00',
  disabled_at: null,
  revoked_at: null,
  deleted_at: null,
  roles: ['attendance_reader'],
  scopes: [],
  created_at: '2026-07-01T00:00:00+09:00',
}

const meta = {
  title: 'Components/DeviceDetailModal',
  component: DeviceDetailModal,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <Story />
      </QueryClientProvider>
    ),
  ],
  parameters: {
    docs: {
      description: {
        component:
          '管理画面の端末一覧から端末詳細をモーダルで表示する。ペアリング用QRの発行・表示と、設置場所などの設定変更をここで行う。',
      },
    },
  },
} satisfies Meta<typeof DeviceDetailModal>

export default meta
type Story = StoryObj<typeof meta>

export const Active: Story = {
  args: {
    device: baseDevice,
    open: true,
  },
}

export const PendingPairing: Story = {
  args: {
    device: { ...baseDevice, status: 'pending_pairing', paired_at: null, last_seen_at: null },
    open: true,
  },
}
