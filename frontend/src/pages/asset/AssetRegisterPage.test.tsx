import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import type { Asset } from '../../api/types'
import { AssetRegisterPage } from './AssetRegisterPage'

const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AssetRegisterPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const createdAsset: Asset = {
  id: 'asset-new',
  asset_no: 'EQ-00999',
  name: 'MacBook Air',
  category: 'ノートPC',
  serial_number: null,
  management_type: 'lending',
  lending_status: 'available',
  installation_status: null,
  lending_method: 'backoffice',
  default_location_text: null,
  qr_token: 'qr-new',
  qr_url: 'https://example.com/assets/qr/qr-new',
  current_loan_id: null,
  notes: null,
  created_at: '2026-08-30T00:00:00+09:00',
  updated_at: '2026-08-30T00:00:00+09:00',
}

describe('AssetRegisterPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
  })

  it('disables the submit button until required fields are filled', async () => {
    renderPage()

    expect(screen.getByRole('button', { name: '作成' })).toBeDisabled()

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')

    expect(screen.getByRole('button', { name: '作成' })).toBeEnabled()
  })

  it('requires a default location when the lending method is self_service', async () => {
    renderPage()

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')
    await userEvent.selectOptions(screen.getByLabelText('貸出方式'), 'セルフ貸出')

    expect(screen.getByRole('button', { name: '作成' })).toBeDisabled()

    await userEvent.type(screen.getByLabelText('通常配置場所'), '本社4F')

    expect(screen.getByRole('button', { name: '作成' })).toBeEnabled()
  })

  it('registers the asset and navigates to its detail page', async () => {
    const registerSpy = vi.spyOn(assetApi, 'registerAsset').mockResolvedValue(createdAsset)
    renderPage()

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(registerSpy).toHaveBeenCalledWith(
      expect.objectContaining({ asset_no: 'EQ-00999', name: 'MacBook Air', category: 'ノートPC' }),
    )
    expect(navigate).toHaveBeenCalledWith('/assets/asset-new')
  })
})
