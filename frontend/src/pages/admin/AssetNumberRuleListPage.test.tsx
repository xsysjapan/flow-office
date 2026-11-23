import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetNumberRulesApi from '../../api/assetNumberRules'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { notify } from '../../lib/notify'
import { AssetNumberRuleListPage } from './AssetNumberRuleListPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const categoryRule: AssetNumberRule = {
  category: 'ノートPC',
  prefix: 'NPC',
  digit_count: 5,
  next_number: 3,
  is_active: true,
  is_default: false,
}

const defaultRule: AssetNumberRule = {
  category: null,
  prefix: 'AST',
  digit_count: 5,
  next_number: 10,
  is_active: true,
  is_default: true,
}

function renderPage(rules: AssetNumberRule[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(assetNumberRulesApi, 'fetchAssetNumberRules').mockResolvedValue(rules)

  return render(
    <QueryClientProvider client={queryClient}>
      <AssetNumberRuleListPage />
    </QueryClientProvider>,
  )
}

describe('AssetNumberRuleListPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('shows an unset state and a create button when there is no default rule', async () => {
    renderPage([categoryRule])

    expect(await screen.findByText('未設定です。カテゴリ一致ルールが無い場合、常に手入力になります。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'デフォルトルールを作成' })).toBeInTheDocument()
  })

  it('shows the default rule prefix, digit count, and enabled badge when set', async () => {
    renderPage([defaultRule])

    expect(await screen.findByText('AST')).toBeInTheDocument()
    expect(screen.getByText('桁数: 5')).toBeInTheDocument()
    expect(screen.getByText('次番号(参考): 00010')).toBeInTheDocument()
    expect(screen.queryByLabelText('プレフィックス')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '保存' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: '編集' })).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
  })

  it('lists category rules with their application status', async () => {
    renderPage([categoryRule, defaultRule])

    expect(await screen.findByText('ノートPC')).toBeInTheDocument()
    expect(screen.getByText('NPC')).toBeInTheDocument()
    expect(screen.getByText('00003')).toBeInTheDocument()
    expect(screen.getAllByText('5').length).toBeGreaterThan(0)
    expect(screen.getByText('このルールで採番')).toBeInTheDocument()
  })

  it('shows "falls back to default" status for a disabled category rule when a default rule is enabled', async () => {
    renderPage([{ ...categoryRule, is_active: false }, defaultRule])

    expect(await screen.findByText('デフォルトにフォールバック')).toBeInTheDocument()
  })

  it('shows "manual input" status for a disabled category rule when no default rule exists', async () => {
    renderPage([{ ...categoryRule, is_active: false }])

    expect(await screen.findByText('無効化中(手入力)')).toBeInTheDocument()
  })

  it('saves an edited category rule', async () => {
    const updateSpy = vi.spyOn(assetNumberRulesApi, 'updateAssetNumberRule').mockResolvedValue(categoryRule)
    renderPage([categoryRule])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    const prefixInput = screen.getByLabelText('ノートPCのプレフィックス')
    await userEvent.clear(prefixInput)
    await userEvent.type(prefixInput, 'PC')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(updateSpy).toHaveBeenCalledWith('ノートPC', { prefix: 'PC', digit_count: 5, is_active: true }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('ノートPCの採番ルールを保存しました'))
  })

  it('keeps is_active=false when saving an edited disabled rule', async () => {
    const disabled = { ...categoryRule, digit_count: 4, is_active: false }
    const updateSpy = vi.spyOn(assetNumberRulesApi, 'updateAssetNumberRule').mockResolvedValue(disabled)
    renderPage([disabled])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    expect(screen.getByLabelText('ノートPCの桁数')).toHaveValue(4)
    expect(screen.getByLabelText('ノートPCの有効/無効')).not.toBeChecked()
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(updateSpy).toHaveBeenCalledWith('ノートPC', { prefix: 'NPC', digit_count: 4, is_active: false }),
    )
  })

  it('initialises the default rule edit form from the loaded values and keeps is_active=false', async () => {
    const disabledDefault = { ...defaultRule, digit_count: 6, is_active: false }
    const updateDefaultSpy = vi.spyOn(assetNumberRulesApi, 'updateDefaultAssetNumberRule').mockResolvedValue(disabledDefault)
    renderPage([disabledDefault])

    await userEvent.click(await screen.findByRole('button', { name: '編集' }))
    expect(screen.getByLabelText('プレフィックス')).toHaveValue('AST')
    expect(screen.getByLabelText('桁数')).toHaveValue(6)
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(updateDefaultSpy).toHaveBeenCalledWith({ prefix: 'AST', digit_count: 6, is_active: false }),
    )
  })

  it('adds a new category rule', async () => {
    const updateSpy = vi.spyOn(assetNumberRulesApi, 'updateAssetNumberRule').mockResolvedValue(categoryRule)
    renderPage([])

    await screen.findByText('カテゴリ別ルールはまだありません。上の行から追加できます。')
    await userEvent.type(screen.getByLabelText('新規カテゴリ名'), 'モニター')
    await userEvent.type(screen.getByLabelText('新規カテゴリのプレフィックス'), 'MON')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    await waitFor(() =>
      expect(updateSpy).toHaveBeenCalledWith('モニター', { prefix: 'MON', digit_count: 5, is_active: true }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('モニターの採番ルールを追加しました'))
  })

  it('creates a default rule', async () => {
    const updateDefaultSpy = vi.spyOn(assetNumberRulesApi, 'updateDefaultAssetNumberRule').mockResolvedValue(defaultRule)
    renderPage([])

    await userEvent.click(await screen.findByRole('button', { name: 'デフォルトルールを作成' }))
    await userEvent.type(screen.getByLabelText('プレフィックス'), 'AST')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(updateDefaultSpy).toHaveBeenCalledWith({ prefix: 'AST', digit_count: 5, is_active: true }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('デフォルトの採番ルールを保存しました'))
  })
})
