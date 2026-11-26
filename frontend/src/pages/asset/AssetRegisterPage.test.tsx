import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as assetApi from '../../api/asset'
import * as assetNumberRulesApi from '../../api/assetNumberRules'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import type { Asset } from '../../api/types'
import { notify } from '../../lib/notify'
import { AssetRegisterPage } from './AssetRegisterPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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

const noRules: AssetNumberRule[] = []

describe('AssetRegisterPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
    vi.restoreAllMocks()
    navigate.mockClear()
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue(noRules)
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRuleCategories').mockResolvedValue([])
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
    expect(navigate).toHaveBeenCalledWith('/assets/asset-new', { state: undefined })
    expect(notify.success).toHaveBeenCalledWith('MacBook Airを登録しました')
  })

  it('switches the asset-no field to read-only and sends asset_no=null when the category matches an is_active rule', async () => {
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue([
      { category: 'ノートPC', prefix: 'NPC', digit_count: 5, next_number: 3, is_active: true, is_default: false },
    ])
    const registerSpy = vi.spyOn(assetApi, 'registerAsset').mockResolvedValue(createdAsset)
    renderPage()

    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')

    await waitFor(() => expect(screen.getByLabelText('管理番号')).toBeDisabled())
    expect(screen.getByLabelText('管理番号')).toHaveAttribute('placeholder', '保存時に自動採番されます')
    expect(screen.getByRole('button', { name: '作成' })).toBeEnabled()

    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(registerSpy).toHaveBeenCalledWith(expect.objectContaining({ asset_no: null, category: 'ノートPC' }))
  })

  it('falls back to the default rule when the category has no matching rule', async () => {
    vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue([
      { category: null, prefix: 'AST', digit_count: 5, next_number: 10, is_active: true, is_default: true },
    ])
    renderPage()

    await userEvent.type(screen.getByLabelText('カテゴリ'), '未登録カテゴリ')

    await waitFor(() => expect(screen.getByLabelText('管理番号')).toBeDisabled())
  })

  it('keeps manual input when the matching category rule is disabled, even with a default rule available', async () => {
    const rulesSpy = vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue([
      { category: 'ノートPC', prefix: 'NPC', digit_count: 5, next_number: 3, is_active: false, is_default: false },
      { category: null, prefix: 'AST', digit_count: 5, next_number: 10, is_active: true, is_default: true },
    ])
    renderPage()

    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')

    await waitFor(() => expect(rulesSpy).toHaveBeenCalled())
    expect(screen.getByLabelText('管理番号')).toBeEnabled()
    expect(screen.getByLabelText('管理番号')).not.toHaveAttribute('placeholder', '保存時に自動採番されます')
  })

  it('shows each 422 message once under its field (manual numbering, lending)', async () => {
    vi.spyOn(assetApi, 'registerAsset').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        asset_no: ['管理番号のエラー'],
        name: ['名称のエラー'],
        category: ['カテゴリのエラー'],
        serial_number: ['シリアルのエラー'],
        management_type: ['管理区分のエラー'],
        lending_method: ['貸出方式のエラー'],
        default_location_text: ['通常配置場所のエラー'],
        notes: ['備考のエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage()

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    for (const message of [
      '管理番号のエラー',
      '名称のエラー',
      'カテゴリのエラー',
      'シリアルのエラー',
      '管理区分のエラー',
      '貸出方式のエラー',
      '通常配置場所のエラー',
      '備考のエラー',
      'その他のエラー',
    ]) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('keeps the lending keys in the heading list when they are not drawn (installation)', async () => {
    vi.spyOn(assetApi, 'registerAsset').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        lending_method: ['貸出方式のエラー'],
        default_location_text: ['通常配置場所のエラー'],
        name: ['名称のエラー'],
      }),
    )
    renderPage()

    await userEvent.type(screen.getByLabelText('管理番号'), 'EQ-00999')
    await userEvent.type(screen.getByLabelText('名称'), 'MacBook Air')
    await userEvent.type(screen.getByLabelText('カテゴリ'), 'ノートPC')
    await userEvent.selectOptions(screen.getByLabelText('管理区分'), '設置品')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    // 描いていない項目のキーは、まとめての表示に残る(エラーが見えなくならない)。
    expect(await screen.findAllByText('名称のエラー')).toHaveLength(1)
    expect(screen.getAllByText('貸出方式のエラー')).toHaveLength(1)
    expect(screen.getAllByText('通常配置場所のエラー')).toHaveLength(1)
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
  })
})
