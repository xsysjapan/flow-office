import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Device } from '../../api/types'
import { DeviceListPage } from './DeviceListPage'

const devices: Device[] = [
  {
    id: 'device-1',
    owner_type: 'organization_shared',
    owner_user_id: null,
    name: '本社1階受付',
    device_type: 'android',
    status: 'active',
    site_id: null,
    location_name: '本社1階受付カウンター',
    default_work_location_type: 'office',
    timezone: null,
    allowed_punch_types: null,
    allow_offline: true,
    require_location: false,
    auto_detect_punch_type: false,
    app_version: '1.2.0',
    last_seen_at: '2026-07-18T00:00:00+09:00',
    paired_at: '2026-07-01T00:00:00+09:00',
    disabled_at: null,
    revoked_at: null,
    deleted_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 'device-2',
    owner_type: 'organization_shared',
    owner_user_id: null,
    name: '大阪支店エントランス',
    device_type: 'nfc_reader',
    status: 'pending_pairing',
    site_id: null,
    location_name: '大阪支店エントランス',
    default_work_location_type: null,
    timezone: null,
    allowed_punch_types: null,
    allow_offline: true,
    require_location: false,
    auto_detect_punch_type: false,
    app_version: null,
    last_seen_at: null,
    paired_at: null,
    disabled_at: null,
    revoked_at: null,
    deleted_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-10T00:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['devices', 'organization_shared', 1, false], {
    data: devices,
    meta: { current_page: 1, last_page: 1, total: devices.length },
    links: { next: null, prev: null },
  })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DeviceListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Admin/DeviceListPage',
  component: DeviceListPage,
} satisfies Meta<typeof DeviceListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
