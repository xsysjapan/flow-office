import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { BackofficeBulkLendPage } from './BackofficeBulkLendPage'

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

vi.mock('../../../components/UserPicker/UserPicker', () => ({
  UserPicker: ({ id, onChange }: { id: string; onChange: (v: string | undefined) => void }) => (
    <input id={id} aria-label="貸出先" onChange={() => onChange('borrower-1')} />
  ),
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <BackofficeBulkLendPage />
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
  lending_status: 'available',
  installation_status: null,
  lending_method: 'backoffice',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  current_loan_id: null,
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('BackofficeBulkLendPage', () => {
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

  it('貸出先未選択の間はスキャン入力が無効化される', () => {
    renderPage()
    expect(screen.getByLabelText('貸与対象に追加する備品')).toBeDisabled()
    expect(screen.getByText('先に貸出先ユーザーを選択してください。')).toBeInTheDocument()
  })

  it('貸出先選択後、貸出可否検証を通った備品が対象リストに追加される', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    vi.spyOn(assetApi, 'getAssetLoanEligibility').mockResolvedValue({
      asset_id: 'asset-1',
      management_type: 'lending',
      lending_method: 'backoffice',
      lending_status: 'available',
      eligible: true,
      requires_approval: false,
      approved_loan_request_id: null,
      reason: null,
    })

    renderPage()
    await userEvent.type(screen.getByLabelText('貸出先'), 'x')

    const scanInput = screen.getByLabelText('貸与対象に追加する備品')
    expect(scanInput).toBeEnabled()
    await userEvent.type(scanInput, 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText('EQ-00121')).toBeInTheDocument()
  })

  it('貸出不可の備品はエラー理由を表示しリストに追加されない', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    vi.spyOn(assetApi, 'getAssetLoanEligibility').mockResolvedValue({
      asset_id: 'asset-1',
      management_type: 'lending',
      lending_method: 'approval',
      lending_status: 'available',
      eligible: false,
      requires_approval: true,
      approved_loan_request_id: null,
      reason: '承認済みの貸出申請がありません。',
    })

    renderPage()
    await userEvent.type(screen.getByLabelText('貸出先'), 'x')

    const scanInput = screen.getByLabelText('貸与対象に追加する備品')
    await userEvent.type(scanInput, 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText(/承認済みの貸出申請がありません/)).toBeInTheDocument()
    expect(screen.queryByText('EQ-00121')).not.toBeInTheDocument()
  })
})
