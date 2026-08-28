import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { ExternalIntegrationConnection } from '../../api/types'
import { ExternalIntegrationConnectionsPage } from './ExternalIntegrationConnectionsPage'

const connections: ExternalIntegrationConnection[] = [
  {
    id: 'conn-1',
    provider: 'freee',
    name: 'freee本社経理',
    auth_type: 'oauth2',
    status: 'connected',
    enabled: true,
    external_office_id: '12345',
    custom_settings: null,
    has_client_id: true,
    has_client_secret: true,
    has_api_key: false,
    client_id_masked: '****ab12',
    api_key_masked: null,
    connected_by_user_id: 'user-1',
    connected_at: '2026-07-01T00:00:00+09:00',
    created_at: '2026-06-01T00:00:00+09:00',
    updated_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 'conn-2',
    provider: 'moneyforward',
    name: 'マネーフォワード大阪支店',
    auth_type: 'api_key',
    status: 'unconfigured',
    enabled: false,
    external_office_id: null,
    custom_settings: { tax_category: '10' },
    has_client_id: false,
    has_client_secret: false,
    has_api_key: false,
    client_id_masked: null,
    api_key_masked: null,
    connected_by_user_id: null,
    connected_at: null,
    created_at: '2026-07-10T00:00:00+09:00',
    updated_at: '2026-07-10T00:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['external-integration-connections'], { data: connections })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExternalIntegrationConnectionsPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Admin/ExternalIntegrationConnectionsPage',
  component: ExternalIntegrationConnectionsPage,
} satisfies Meta<typeof ExternalIntegrationConnectionsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}

export const Empty: Story = {
  render: () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
    queryClient.setQueryData(['external-integration-connections'], { data: [] })
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExternalIntegrationConnectionsPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  },
}
