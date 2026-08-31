import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { SelfBulkLoanPage } from './SelfBulkLoanPage'

const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('../../../auth/useAuth', () => ({
  useAuth: () => ({ user: { id: 'user-1', name: '山田太郎', effective_permissions: [] } }),
}))

vi.mock('../../../components/AssetPicker/AssetPicker', () => ({
  AssetPicker: ({
    id,
    label,
    onSubmit,
    isPending,
    disabled,
    disabledReason,
  }: {
    id: string
    label: string
    onSubmit: (value: string) => Promise<void> | void
    isPending?: boolean
    disabled?: boolean
    disabledReason?: string
  }) => {
    const [value, setValue] = useState('')
    return (
      <div>
        <label htmlFor={id}>{label}</label>
        <input id={id} value={value} onChange={(e) => setValue(e.target.value)} disabled={disabled} />
        <button
          type="button"
          disabled={disabled || isPending || !value.trim()}
          onClick={async () => {
            if (!value.trim() || isPending || disabled) return
            await onSubmit(value)
            setValue('')
          }}
        >
          追加
        </button>
        {disabled && disabledReason && <p>{disabledReason}</p>}
      </div>
    )
  },
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <SelfBulkLoanPage />
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
  lending_method: 'self_service',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: null,
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('SelfBulkLoanPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
  })

  it('スキャン入力で有効な備品が対象リストに追加される', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)

    renderPage()

    await userEvent.type(screen.getByLabelText('貸出対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText('EQ-00121')).toBeInTheDocument()
    expect(screen.getByText('ThinkPad X1')).toBeInTheDocument()
  })

  it('セルフ貸出対象外の備品はエラーを表示しリストに追加されない', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue({
      ...asset,
      lending_method: 'backoffice',
    })

    renderPage()

    await userEvent.type(screen.getByLabelText('貸出対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText(/セルフ貸出の対象外です/)).toBeInTheDocument()
    expect(screen.queryByText('ThinkPad X1')).not.toBeInTheDocument()
  })

  it('確定ボタンでbulkAssetOperationがself_loanとして呼ばれる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    const bulkSpy = vi.spyOn(assetApi, 'bulkAssetOperation').mockResolvedValue({
      operation: 'self_loan',
      results: [{ asset_id: 'asset-1', success: true, result: 'loaned' }],
      succeeded_count: 1,
      failed_count: 0,
    })

    renderPage()

    await userEvent.type(screen.getByLabelText('貸出対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await screen.findByText('ThinkPad X1')

    await userEvent.click(screen.getByRole('button', { name: '1件を確定' }))

    expect(bulkSpy).toHaveBeenCalledWith({ operation: 'self_loan', asset_ids: ['asset-1'] })
    expect(await screen.findByText(/成功 1件/)).toBeInTheDocument()
  })
})
