import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../../api/asset'
import { ApiError } from '../../../api/client'
import type { Asset } from '../../../api/types'
import { BULK_REJECTED_NOTICE } from '../../../components/BulkActionDialog/BulkActionDialog'
import { notify } from '../../../lib/notify'

vi.mock('../../../lib/notify', () => ({ notify: { success: vi.fn() } }))
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
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: 'loan-1',
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('BackofficeBulkReturnPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
    vi.restoreAllMocks()
    navigate.mockClear()
    currentUser = { id: 'manager-1', effective_permissions: ['asset.manage'] }
  })

  it('「戻る」ボタンは無く「キャンセル」で備品一覧へreplace遷移する', async () => {
    renderPage()

    expect(screen.queryByRole('button', { name: '戻る' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(navigate).toHaveBeenCalledWith('/assets', { state: undefined, replace: true })
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
      data: [{ asset_id: 'asset-1', result: 'done' }],
    })

    renderPage()
    await userEvent.type(screen.getByLabelText('返却対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await screen.findByText('ThinkPad X1')

    await userEvent.click(screen.getByRole('button', { name: '1件を確定' }))

    expect(bulkSpy).toHaveBeenCalledWith({ operation: 'return', asset_ids: ['asset-1'] })
    expect(await screen.findByText('1件を返却しました。')).toBeInTheDocument()
    // 全件成功なので対象は空になる。
    expect(screen.getByRole('button', { name: '0件を確定' })).toBeDisabled()
    expect(notify.success).toHaveBeenCalledWith('1件を返却しました')
  })

  it('貸出中でない備品はエラーになる', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue({ ...asset, current_loan_id: null })

    renderPage()
    await userEvent.type(screen.getByLabelText('返却対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(await screen.findByText(/現在貸出中ではありません/)).toBeInTheDocument()
  })

  it('1件でも処理できないと何も確定せず、失敗した備品と理由を出して対象を残す', async () => {
    vi.spyOn(assetApi, 'resolveAssetByScanInput').mockResolvedValue(asset)
    const bulkSpy = vi.spyOn(assetApi, 'bulkAssetOperation').mockRejectedValue(
      new ApiError(
        422,
        '選んだ対象のうち、処理できないものがあります。',
        { 'items.asset-1': ['処理できない状態です。'] },
        'bulk_operation_rejected',
        [{ id: 'asset-1', message: '処理できない状態です。' }],
      ),
    )

    renderPage()
    await userEvent.type(screen.getByLabelText('返却対象に追加する備品'), 'EQ-00121')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))
    await screen.findByText('ThinkPad X1')

    await userEvent.click(screen.getByRole('button', { name: '1件を確定' }))

    expect(bulkSpy).toHaveBeenCalledTimes(1)
    expect(await screen.findByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(screen.getByText('ThinkPad X1(EQ-00121): 処理できない状態です。')).toBeInTheDocument()
    expect(notify.success).not.toHaveBeenCalled()
    // 何も確定していないので、対象は残る。
    expect(screen.getByRole('button', { name: '1件を確定' })).toBeEnabled()
  })
})
