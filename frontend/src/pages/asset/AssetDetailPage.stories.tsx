import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { Asset, StoredEvent, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AssetDetailPage } from './AssetDetailPage'

const managerUser: User = {
  id: 'user-manager',
  name: '管理担当者',
  email: 'manager@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const managerAuthValue: AuthContextValue = {
  user: { ...managerUser, effective_permissions: ['asset.manage'] },
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const staffAuthValue: AuthContextValue = {
  user: { id: 'user-1', name: '山田太郎', email: 'yamada@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null, effective_permissions: [] },
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

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
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: 'loan-1',
  notes: '付属品: 充電器',
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

const installationAsset: Asset = {
  id: 'asset-2',
  asset_no: 'EQ-00200',
  name: '会議室モニター',
  category: 'モニター',
  serial_number: null,
  management_type: 'installation',
  lending_status: null,
  installation_status: 'installed',
  lending_method: null,
  default_location_text: null,
  qr_token: 'qr-token-2',
  qr_url: 'https://example.com/assets/qr/qr-token-2',
  current_loan_id: null,
  notes: null,
  current_placement: { location_text: '会議室A', started_at: '2026-08-01T00:00:00+09:00' },
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

const history: StoredEvent[] = [
  {
    id: '1',
    event_id: '1',
    aggregate_type: 'asset',
    aggregate_id: 'asset-1',
    version: 1,
    event_type: 'asset.registered',
    payload: {},
    occurred_at: '2026-08-01T00:00:00+09:00',
  },
  {
    id: '2',
    event_id: '2',
    aggregate_type: 'asset',
    aggregate_id: 'asset-1',
    version: 2,
    event_type: 'asset.loaned',
    payload: {},
    occurred_at: '2026-08-02T00:00:00+09:00',
  },
]

function withSeeded(asset: Asset, events: StoredEvent[], authValue: AuthContextValue = managerAuthValue) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['assets', asset.id], asset)
  queryClient.setQueryData(['assets', asset.id, 'history'], events)

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[`/assets/${asset.id}`]}>
            <Routes>
              <Route path="/assets/:id" element={<AssetDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Asset/AssetDetailPage',
  component: AssetDetailPage,
} satisfies Meta<typeof AssetDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const LendingAssetLoaned: Story = {
  render: withSeeded(lendingAsset, history),
}

export const InstallationAssetInstalled: Story = {
  render: withSeeded(installationAsset, []),
}

const availableSelfServiceAsset: Asset = {
  ...lendingAsset,
  id: 'asset-3',
  lending_status: 'available',
  current_loan_id: null,
  current_loan: null,
}

export const LendingAssetAvailableAsManager: Story = {
  render: withSeeded(availableSelfServiceAsset, [], managerAuthValue),
}

export const LendingAssetAvailableAsStaff: Story = {
  render: withSeeded(availableSelfServiceAsset, [], staffAuthValue),
}
