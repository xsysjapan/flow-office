import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fn } from 'storybook/test'
import type { Asset, Paginated } from '../../api/types'
import { AssetPicker } from './AssetPicker'

const assets: Asset[] = [
  {
    id: 'asset-1',
    asset_no: 'EQ-00121',
    name: 'ThinkPad X1',
    category: 'ノートPC',
    serial_number: null,
    management_type: 'lending',
    lending_status: 'available',
    installation_status: null,
    lending_method: 'self_service',
    default_location_text: '本社4F',
    qr_token: 'qr-token-1',
    qr_url: 'https://example.com/assets/qr/qr-token-1',
    current_loan_id: null,
    notes: null,
    created_at: '2026-08-01T00:00:00+09:00',
    updated_at: '2026-08-01T00:00:00+09:00',
  },
]

const paginatedAssets: Paginated<Asset> = {
  data: assets,
  meta: { current_page: 1, last_page: 1, total: assets.length },
  links: { next: null, prev: null },
}

function withSeeded(props: Partial<React.ComponentProps<typeof AssetPicker>> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['assets', 'search', { asset_no: undefined, per_page: 20 }], paginatedAssets)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AssetPicker id="asset-picker" label="貸出対象に追加する備品" onSubmit={fn()} {...props} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/AssetPicker',
  component: AssetPicker,
} satisfies Meta<typeof AssetPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { id: 'asset-picker', label: '貸出対象に追加する備品', onSubmit: fn() },
  render: withSeeded(),
}

export const SingleSelect: Story = {
  args: { id: 'asset-picker', label: '対象備品', onSubmit: fn(), allowContinuousScan: false },
  render: withSeeded({ label: '対象備品', allowContinuousScan: false }),
}

export const Disabled: Story = {
  args: {
    id: 'asset-picker',
    label: '貸出対象に追加する備品',
    onSubmit: fn(),
    disabled: true,
    disabledReason: '先に貸出先ユーザーを選択してください。',
  },
  render: withSeeded({ disabled: true, disabledReason: '先に貸出先ユーザーを選択してください。' }),
}
