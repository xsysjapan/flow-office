import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { ApplicationIntegration } from '../../api/types'
import { MyIntegrationsPage } from './MyIntegrationsPage'

const integrations: ApplicationIntegration[] = [
  {
    id: 'integration-1',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'mcp_client',
    client_name: 'Claude Desktop',
    purpose: '月次勤怠の下書き作成',
    status: 'active',
    last_used_at: '2026-07-18T09:00:00+09:00',
    scopes: ['profile:self:read', 'attendance:self:read', 'attendance:self:draft'],
    created_at: '2026-07-01T09:00:00+09:00',
  },
  {
    id: 'integration-2',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'api_client',
    client_name: '旧連携',
    purpose: null,
    status: 'revoked',
    last_used_at: null,
    scopes: ['profile:self:read'],
    created_at: '2026-06-01T09:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['integrations', 'me'], integrations)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MyIntegrationsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Integrations/MyIntegrationsPage',
  component: MyIntegrationsPage,
} satisfies Meta<typeof MyIntegrationsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
