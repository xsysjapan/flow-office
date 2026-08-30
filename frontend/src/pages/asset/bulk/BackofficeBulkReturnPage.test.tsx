import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { BackofficeBulkReturnPage } from './BackofficeBulkReturnPage'

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
        <BackofficeBulkReturnPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const asset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: null,
  management_type: 'lending',
  lending_status: 'loaned',
  installation_status: null,
  lending_method: 'backoffice',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  current_loan_id: 'loan-1',
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('BackofficeBulkReturnPage', () => {
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

  it('貸出中の備品が対象リストに追加され、確定でreturn操作が呼ばれる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    const bulkSpy = vi.spyOn(assetApi, 'bulkAssetOperation').mockResolvedValue({
      operation: 'return',
      results: [{ asset_id: 'asset-1', success: true, result: 'returned' }],
      succeeded_count: 1,
      failed_count: 0,
    })

    renderPage()
    await userEvent.type(screen.getByLabelText('返却対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await screen.findByText('ThinkPad X1')

    await userEvent.click(screen.getByRole('button', { name: '1件を確定' }))

    expect(bulkSpy).toHaveBeenCalledWith({ operation: 'return', asset_ids: ['asset-1'] })
    expect(await screen.findByText(/成功 1件/)).toBeInTheDocument()
  })

  it('貸出中でない備品はエラーになる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue({ ...asset, current_loan_id: null })

    renderPage()
    await userEvent.type(screen.getByLabelText('返却対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText(/現在貸出中ではありません/)).toBeInTheDocument()
  })
})
