import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as assetNumberRulesApi from '../../api/assetNumberRules'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { AssetNumberRuleListPage } from './AssetNumberRuleListPage'

const categoryRule: AssetNumberRule = {
  category: 'ノートPC',
  prefix: 'NPC',
  digitCount: 5,
  nextNumber: 3,
  enabled: true,
  isDefault: false,
}

const defaultRule: AssetNumberRule = {
  category: null,
  prefix: 'AST',
  digitCount: 5,
  nextNumber: 10,
  enabled: true,
  isDefault: true,
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
  it('shows an unset state and a create button when there is no default rule', async () => {
    renderPage([categoryRule])

    expect(await screen.findByText('未設定です。カテゴリ一致ルールが無い場合、常に手入力になります。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'デフォルトルールを作成' })).toBeInTheDocument()
  })

  it('shows the default rule prefix, digit count, and enabled badge when set', async () => {
    renderPage([defaultRule])

    expect(await screen.findByText('AST')).toBeInTheDocument()
    expect(screen.getByText('桁数: 5')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
  })

  it('lists category rules with their application status', async () => {
    renderPage([categoryRule, defaultRule])

    expect(await screen.findByText('ノートPC')).toBeInTheDocument()
    expect(screen.getByText('NPC')).toBeInTheDocument()
    expect(screen.getByText('このルールで採番')).toBeInTheDocument()
  })

  it('shows "falls back to default" status for a disabled category rule when a default rule is enabled', async () => {
    renderPage([{ ...categoryRule, enabled: false }, defaultRule])

    expect(await screen.findByText('デフォルトにフォールバック')).toBeInTheDocument()
  })

  it('shows "manual input" status for a disabled category rule when no default rule exists', async () => {
    renderPage([{ ...categoryRule, enabled: false }])

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
      expect(updateSpy).toHaveBeenCalledWith('ノートPC', { prefix: 'PC', digitCount: 5, enabled: true }),
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
      expect(updateSpy).toHaveBeenCalledWith('モニター', { prefix: 'MON', digitCount: 5, enabled: true }),
    )
  })

  it('creates a default rule', async () => {
    const updateDefaultSpy = vi.spyOn(assetNumberRulesApi, 'updateDefaultAssetNumberRule').mockResolvedValue(defaultRule)
    renderPage([])

    await userEvent.click(await screen.findByRole('button', { name: 'デフォルトルールを作成' }))
    await userEvent.type(screen.getByLabelText('プレフィックス'), 'AST')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(updateDefaultSpy).toHaveBeenCalledWith({ prefix: 'AST', digitCount: 5, enabled: true }),
    )
  })
})
