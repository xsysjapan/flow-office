import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import * as assetNumberRulesApi from '../../api/assetNumberRules'
import type { Asset } from '../../api/types'
import { renderWithGuard } from '../../test/renderWithGuard'
import { AssetEditPage } from './AssetEditPage'
import { AssetRegisterPage } from './AssetRegisterPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const asset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: 'SN-001',
  management_type: 'lending',
  lending_status: 'available',
  installation_status: null,
  lending_method: 'backoffice',
  default_location_text: null,
  qr_token: 'qr-token-1',
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: null,
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

function ui(element: React.ReactNode) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return (
    <QueryClientProvider client={queryClient}>
      <Routes>
        <Route path="/assets/new" element={element} />
        <Route path="/assets/:id/edit" element={element} />
        <Route path="/assets/:id" element={<p>備品詳細ページ</p>} />
      </Routes>
    </QueryClientProvider>
  )
}

describe('asset pages leave guard', () => {
  afterEach(() => vi.restoreAllMocks())

  it('AssetEditPage: save navigates to the detail page without the confirm dialog', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    vi.spyOn(assetApi, 'updateAssetDetails').mockResolvedValue({ ...asset, name: '更新後' })
    renderWithGuard(ui(<AssetEditPage />), { initialEntries: ['/assets/asset-1/edit'] })

    const nameInput = await screen.findByLabelText('名称')
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, '更新後')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    expect(await screen.findByText('備品詳細ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('AssetRegisterPage: register navigates to the detail page without the confirm dialog', async () => {
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue([])
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRuleCategories').mockResolvedValue([])
    vi.spyOn(assetApi, 'registerAsset').mockResolvedValue({ ...asset, id: 'asset-new' })
    renderWithGuard(ui(<AssetRegisterPage />), { initialEntries: ['/assets/new'] })

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(await screen.findByText('備品詳細ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
