import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { BulkRelocatePage } from './BulkRelocatePage'

const navigate = vi.fn()
let currentUser: { id: string; effective_permissions: string[] } | null = {
  id: 'manager-1',
  effective_permissions: ['asset.manage'],
}

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('../../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <BulkRelocatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const asset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00200',
  name: 'プロジェクター',
  category: 'AV機器',
  serial_number: null,
  management_type: 'installation',
  lending_status: null,
  installation_status: 'installed',
  lending_method: null,
  default_location_text: null,
  qr_token: 'qr-token-2',
  current_loan_id: null,
  current_placement: { location_text: '3階会議室A', started_at: '2026-08-01T00:00:00+09:00' },
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('BulkRelocatePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
    currentUser = { id: 'manager-1', effective_permissions: ['asset.manage'] }
  })

  it('asset.manageを持たないユーザーには権限エラーが表示される', () => {
    currentUser = { id: 'user-1', effective_permissions: [] }
    renderPage()
    expect(screen.getByText(/権限がありません/)).toBeInTheDocument()
  })

  it('移設先未入力の間はスキャン入力が無効化される', () => {
    renderPage()
    expect(screen.getByLabelText('移設対象に追加する備品')).toBeDisabled()
  })

  it('移設先入力後、設置備品が対象に追加され、確定でrelocate操作が呼ばれる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    const bulkSpy = vi.spyOn(assetApi, 'bulkAssetOperation').mockResolvedValue({
      operation: 'relocate',
      results: [{ asset_id: 'asset-1', success: true, result: 'relocated' }],
      succeeded_count: 1,
      failed_count: 0,
    })

    renderPage()
    await userEvent.type(screen.getByLabelText('移設先'), '3階会議室B')

    const scanInput = screen.getByLabelText('移設対象に追加する備品')
    await userEvent.type(scanInput, 'EQ-00200')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await screen.findByText('プロジェクター')

    await userEvent.click(screen.getByRole('button', { name: '1件を確定' }))

    expect(bulkSpy).toHaveBeenCalledWith({
      operation: 'relocate',
      asset_ids: ['asset-1'],
      location_text: '3階会議室B',
    })
    expect(await screen.findByText(/成功 1件/)).toBeInTheDocument()
  })

  it('貸出品(installationでない)を追加しようとするとエラーになる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue({ ...asset, management_type: 'lending' })

    renderPage()
    await userEvent.type(screen.getByLabelText('移設先'), '3階会議室B')
    const scanInput = screen.getByLabelText('移設対象に追加する備品')
    await userEvent.type(scanInput, 'EQ-00200')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText(/設置備品ではないため移設できません/)).toBeInTheDocument()
  })
})
