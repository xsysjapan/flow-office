import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as integrationsApi from '../../api/integrations'
import type { ApplicationIntegration } from '../../api/types'
import { notify } from '../../lib/notify'
import { MyIntegrationsPage } from './MyIntegrationsPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const integrations: ApplicationIntegration[] = [
  {
    id: 'integration-1',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'mcp_client',
    client_name: 'Claude Desktop',
    purpose: '月次勤怠の下書き作成',
    status: 'active',
    last_used_at: '2026-07-18T09:00:00+09:00',
    scopes: ['profile:self:read', 'attendance:self:read'],
    created_at: '2026-07-01T09:00:00+09:00',
  },
  {
    id: 'integration-2',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'api_client',
    client_name: '旧連携',
    purpose: null,
    status: 'revoked',
    last_used_at: null,
    scopes: ['profile:self:read'],
    created_at: '2026-06-01T09:00:00+09:00',
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(integrationsApi, 'fetchMyIntegrations').mockResolvedValue(integrations)

  return render(
    <QueryClientProvider client={queryClient}>
      <MyIntegrationsPage />
    </QueryClientProvider>,
  )
}

describe('MyIntegrationsPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('lists integrations with type and status', async () => {
    renderPage()

    expect(await screen.findByText('Claude Desktop')).toBeInTheDocument()
    expect(screen.getByText('旧連携')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
    expect(screen.getByText('停止済み')).toBeInTheDocument()
  })

  it('only offers the reissue/stop menu for active integrations', async () => {
    renderPage()

    await screen.findByText('Claude Desktop')

    expect(screen.getAllByRole('button', { name: /の操作$/ })).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'トークン再発行' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '停止する' })).not.toBeInTheDocument()
  })

  it('registers a new integration and displays the issued token once', async () => {
    const user = userEvent.setup()
    vi.spyOn(integrationsApi, 'registerIntegration').mockResolvedValue({
      integration: integrations[0],
      token: 'plain-text-token-xyz',
    })
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: '新規登録' }))
    await user.type(screen.getByLabelText('名称'), 'Claude Code')
    await user.click(screen.getByLabelText('自分の勤怠を閲覧する'))
    await user.click(screen.getByRole('button', { name: '登録する' }))

    expect(await screen.findByText('plain-text-token-xyz')).toBeInTheDocument()
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('Claude Codeを登録しました')
    })
  })

  it('confirms before reissuing the token and shows a toast without replacing the issued token display', async () => {
    const user = userEvent.setup()
    const reissueSpy = vi.spyOn(integrationsApi, 'reissueIntegrationToken').mockResolvedValue({
      integration: integrations[0],
      token: 'reissued-token-abc',
    })
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: 'Claude Desktopの操作' }))
    await user.click(await screen.findByRole('menuitem', { name: 'トークン再発行' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('トークンを再発行しますか?')).toBeInTheDocument()
    expect(within(dialog).getByText('再発行すると今のトークンは使えなくなります。')).toBeInTheDocument()
    expect(reissueSpy).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole('button', { name: '再発行する' }))

    expect(reissueSpy).toHaveBeenCalledWith('integration-1')
    expect(await screen.findByText('reissued-token-abc')).toBeInTheDocument()
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('トークンを再発行しました')
    })
  })

  it('opens a confirmation dialog from the menu before stopping an integration and shows a toast', async () => {
    const user = userEvent.setup()
    const revokeSpy = vi.spyOn(integrationsApi, 'revokeIntegration').mockResolvedValue(integrations[0])
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: 'Claude Desktopの操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '停止する' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('連携を停止しますか?')).toBeInTheDocument()
    expect(revokeSpy).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole('button', { name: '停止する' }))

    expect(revokeSpy).toHaveBeenCalledWith('integration-1')
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('Claude Desktopを停止しました')
    })
  })

  it('shows each 422 field error once under its field and the rest in the summary', async () => {
    const user = userEvent.setup()
    const errors = {
      client_type: ['種別のエラー'],
      client_name: ['名称のエラー'],
      purpose: ['用途のエラー'],
      scopes: ['許可する操作のエラー'],
      'scopes.0': ['許可する操作の0番のエラー'],
      unknown_key: ['未知のキーのエラー'],
    }
    vi.spyOn(integrationsApi, 'registerIntegration').mockRejectedValue(new ApiError(422, '最初のエラーの文', errors))
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: '新規登録' }))
    await user.type(screen.getByLabelText('名称'), 'Claude Code')
    await user.click(screen.getByLabelText('自分の勤怠を閲覧する'))
    await user.click(screen.getByRole('button', { name: '登録する' }))

    expect(await screen.findByText('入力内容を確認してください。')).toBeInTheDocument()
    for (const messages of Object.values(errors)) {
      expect(screen.getAllByText(messages[0])).toHaveLength(1)
    }
    expect(screen.getByLabelText('名称')).toHaveAccessibleDescription('名称のエラー')
    expect(screen.queryByText('最初のエラーの文')).not.toBeInTheDocument()
  })
})
