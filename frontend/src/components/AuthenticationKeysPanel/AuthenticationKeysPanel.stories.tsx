import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { AuthenticationKey } from '../../api/types'
import { AuthenticationKeysPanel } from './AuthenticationKeysPanel'

const keys: AuthenticationKey[] = [
  {
    id: 'auth-key-1',
    user_id: 42,
    key_type: 'nfc_uid',
    display_name: '本社ICカード',
    status: 'active',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: 42,
    registered_at: '2026-07-01T09:00:00+09:00',
    disabled_at: null,
  },
  {
    id: 'auth-key-2',
    user_id: 42,
    key_type: 'fingerprint_external_id',
    display_name: '右手人差し指',
    status: 'disabled',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: 42,
    registered_at: '2026-06-01T09:00:00+09:00',
    disabled_at: '2026-06-15T09:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['authentication-keys', 42], keys)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AuthenticationKeysPanel userId={42} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/AuthenticationKeysPanel',
  component: AuthenticationKeysPanel,
} satisfies Meta<typeof AuthenticationKeysPanel>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { userId: 42 },
  render: withSeeded(),
}
