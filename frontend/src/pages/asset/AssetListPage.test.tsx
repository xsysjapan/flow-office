import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import type { Asset, Paginated } from '../../api/types'
import { AssetListPage } from './AssetListPage'

const navigate = vi.fn()
let currentUser: { id: string; effective_permissions: string[] } | null = {
  id: 'user-1',
  effective_permissions: ['asset.manage'],
}

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AssetListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
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

function pageOf(data: Asset[]): Paginated<Asset> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

describe('AssetListPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
    currentUser = { id: 'user-1', effective_permissions: ['asset.manage'] }
  })

  it('shows a register button for asset.manage holders and navigates to the create page', async () => {
    vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([]))

    renderPage()

    const button = await screen.findByRole('button', { name: '新規登録' })
    await userEvent.click(button)

    expect(navigate).toHaveBeenCalledWith('/assets/new')
  })

  it('hides the register button for users without asset.manage', async () => {
    currentUser = { id: 'user-2', effective_permissions: [] }
    vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([]))

    renderPage()

    await screen.findByText('登録されている備品がまだありません。')
    expect(screen.queryByRole('button', { name: '新規登録' })).not.toBeInTheDocument()
  })

  it('shows an empty state when there are no assets', async () => {
    vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([]))

    renderPage()

    expect(await screen.findByText('登録されている備品がまだありません。')).toBeInTheDocument()
  })

  it('lists assets with management type and current status summary', async () => {
    vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([lendingAsset, installationAsset]))

    renderPage()

    const table = await screen.findByRole('table')
    expect(within(table).getByText('EQ-00121')).toBeInTheDocument()
    expect(within(table).getByRole('link', { name: 'ThinkPad X1' })).toHaveAttribute('href', '/assets/asset-1')
    expect(within(table).getByText('貸出品')).toBeInTheDocument()
    expect(within(table).getByText('貸出中: 山田太郎')).toBeInTheDocument()

    expect(within(table).getByText('設置品')).toBeInTheDocument()
    expect(within(table).getByText('設置中: 会議室A')).toBeInTheDocument()
  })

  it('navigates to the detail page when a row is clicked', async () => {
    vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([lendingAsset]))

    renderPage()

    const row = await screen.findByRole('row', { name: /ThinkPad X1の詳細を開く/ })
    await userEvent.click(row)

    expect(navigate).toHaveBeenCalledWith('/assets/asset-1')
  })

  it('sends search filters to the API', async () => {
    const spy = vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(pageOf([]))

    renderPage()

    await screen.findByText('登録されている備品がまだありません。')
    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-001')

    expect(await screen.findByLabelText('管理番号')).toHaveValue('EQ-001')
    expect(spy).toHaveBeenCalled()
  })
})
