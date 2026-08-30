import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Asset, Paginated } from '../../api/types'
import { AssetListPage } from './AssetListPage'

const lendingAsset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: 'SN-001',
  management_type: 'lending',
  lending_status: 'loaned',
  installation_status: null,
  lending_method: 'self_service',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  current_loan_id: 'loan-1',
  notes: null,
  current_loan: {
    id: 'loan-1',
    asset_id: 'asset-1',
    user_id: 'user-1',
    borrower: {
      id: 'user-1',
      name: '山田太郎',
      email: 'yamada@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    },
    loan_request_id: null,
    loaned_at: '2026-08-01T00:00:00+09:00',
    expected_return_at: null,
    loaned_by_user_id: 'user-1',
    returned_at: null,
    returned_by_user_id: null,
    return_note: null,
  },
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

const availableAsset: Asset = {
  ...lendingAsset,
  id: 'asset-2',
  asset_no: 'EQ-00122',
  name: 'MacBook Pro',
  lending_status: 'available',
  current_loan_id: null,
  current_loan: null,
}

const installationAsset: Asset = {
  id: 'asset-3',
  asset_no: 'EQ-00200',
  name: '会議室モニター',
  category: 'モニター',
  serial_number: null,
  management_type: 'installation',
  lending_status: null,
  installation_status: 'installed',
  lending_method: null,
  default_location_text: null,
  qr_token: 'qr-token-3',
  current_loan_id: null,
  notes: null,
  current_placement: { location_text: '会議室A', started_at: '2026-08-01T00:00:00+09:00' },
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

function withSeeded(page: Paginated<Asset>) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['assets', 'search', { page: 1 }], page)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/assets']}>
          <AssetListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Asset/AssetListPage',
  component: AssetListPage,
} satisfies Meta<typeof AssetListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded({
    data: [lendingAsset, availableAsset, installationAsset],
    meta: { current_page: 1, last_page: 1, total: 3 },
    links: { next: null, prev: null },
  }),
}

export const Empty: Story = {
  render: withSeeded({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }),
}
