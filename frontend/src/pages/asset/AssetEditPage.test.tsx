import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import type { Asset } from '../../api/types'
import { AssetEditPage } from './AssetEditPage'

const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

function renderPage(id = 'asset-1') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/assets/${id}/edit`]}>
        <Routes>
          <Route path="/assets/:id/edit" element={<AssetEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

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
  current_loan_id: null,
  notes: '付属品: 充電器',
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

describe('AssetEditPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
  })

  it('prefills the form with the current asset details', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)

    renderPage()

    expect(await screen.findByLabelText('名称')).toHaveValue('ThinkPad X1')
    expect(screen.getByLabelText('カテゴリ')).toHaveValue('ノートPC')
    expect(screen.getByLabelText('シリアル番号')).toHaveValue('SN-001')
  })

  it('saves the edited details and navigates back to the detail page', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    const updateSpy = vi.spyOn(assetApi, 'updateAssetDetails').mockResolvedValue({ ...asset, name: 'ThinkPad X1 Gen2' })

    renderPage()

    const nameInput = await screen.findByLabelText('名称')
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, 'ThinkPad X1 Gen2')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    expect(updateSpy).toHaveBeenCalledWith('asset-1', {
      name: 'ThinkPad X1 Gen2',
      category: 'ノートPC',
      serial_number: 'SN-001',
      notes: '付属品: 充電器',
    })
    expect(navigate).toHaveBeenCalledWith('/assets/asset-1')
  })
})
